<?php
declare(strict_types=1);

const FORECAST_EVALUATION_VERSION = 'sbc-evaluation-v1';

function forecastAccuracyTableDefinitions(): array
{
    return [
        'forecast_evaluation_runs' => "CREATE TABLE forecast_evaluation_runs (
            evaluation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_key CHAR(64) NOT NULL,
            branch_id VARCHAR(10) NOT NULL,
            generated_by INT NOT NULL,
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            forecast_date DATE NOT NULL,
            generated_horizon INT NOT NULL,
            training_revision BIGINT UNSIGNED NOT NULL,
            evaluation_version VARCHAR(40) NOT NULL,
            source_hash CHAR(64) NOT NULL,
            model_version VARCHAR(80) NOT NULL,
            model_details LONGTEXT NOT NULL,
            training_records INT NOT NULL,
            testing_records INT NOT NULL,
            training_start DATE NULL,
            training_end DATE NULL,
            testing_start DATE NULL,
            testing_end DATE NULL,
            overall_smape_percent DECIMAL(9,4) NULL,
            PRIMARY KEY (evaluation_id), UNIQUE KEY uq_forecast_evaluation_run (run_key),
            KEY idx_forecast_evaluation_branch (branch_id, evaluation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'forecast_evaluation_items' => "CREATE TABLE forecast_evaluation_items (
            evaluation_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            evaluation_id BIGINT UNSIGNED NOT NULL,
            item_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            base_unit VARCHAR(100) NOT NULL,
            method VARCHAR(20) NOT NULL,
            horizon_days INT NOT NULL,
            evaluation_status VARCHAR(30) NOT NULL,
            reason VARCHAR(1000) NULL,
            training_records INT NOT NULL,
            testing_records INT NOT NULL,
            training_start DATE NULL,
            training_end DATE NULL,
            testing_start DATE NULL,
            testing_end DATE NULL,
            mae DECIMAL(20,8) NULL,
            rmse DECIMAL(20,8) NULL,
            smape_percent DECIMAL(9,4) NULL,
            actual_total DECIMAL(20,4) NULL,
            forecast_total DECIMAL(20,4) NULL,
            total_absolute_error DECIMAL(20,4) NULL,
            PRIMARY KEY (evaluation_item_id),
            UNIQUE KEY uq_forecast_evaluation_item (evaluation_id, item_id, method, horizon_days)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];
}

// Schema inspection is read-only. The CLI migration owns all CREATE statements.
function forecastAccuracySchemaProblems(mysqli $conn, bool $allowMissingTables = false): array
{
    $problems = [];
    foreach (forecastAccuracyTableDefinitions() as $table => $definition) {
        $stmt = $conn->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->bind_param('s', $table); $stmt->execute();
        $columns = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $columns[$row['COLUMN_NAME']] = $row;
        $stmt->close();
        if (!$columns) {
            if (!$allowMissingTables) $problems[] = "$table is missing";
            continue;
        }
        $engine=$conn->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $engine->bind_param('s',$table);$engine->execute();$tableEngine=$engine->get_result()->fetch_assoc()['ENGINE'] ?? '';$engine->close();
        if (strcasecmp($tableEngine,'InnoDB')!==0) $problems[] = "$table must use InnoDB for atomic saving";
        preg_match_all('/^\s+(\w+) (BIGINT UNSIGNED|INT|CHAR\(\d+\)|VARCHAR\(\d+\)|DATETIME|DATE|LONGTEXT|DECIMAL\(\d+,\d+\)) (NOT NULL|NULL)/m',
            $definition, $matches, PREG_SET_ORDER);
        foreach ($matches as $field) {
            $actual = $columns[$field[1]] ?? null;
            $type = $actual ? strtolower(preg_replace('/\b(bigint|int)\(\d+\)/i', '$1', $actual['COLUMN_TYPE'])) : '';
            if (!$actual || $type !== strtolower($field[2])
                || $actual['IS_NULLABLE'] !== ($field[3] === 'NULL' ? 'YES' : 'NO')) {
                $problems[] = "$table.{$field[1]} has a different definition";
            }
        }
        $id = $table === 'forecast_evaluation_runs' ? 'evaluation_id' : 'evaluation_item_id';
        if (!str_contains($columns[$id]['EXTRA'] ?? '', 'auto_increment')) $problems[] = "$table needs an auto-increment ID";
        $indexes = $conn->query('SHOW INDEX FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
        $unique = [];
        foreach ($indexes as $index) if ((int)$index['Non_unique'] === 0) $unique[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Column_name'];
        $expected = $table === 'forecast_evaluation_runs' ? ['run_key'] : ['evaluation_id','item_id','method','horizon_days'];
        $found = false;
        foreach ($unique as $fields) { ksort($fields); if (array_values($fields) === $expected) $found = true; }
        if (!$found) $problems[] = "$table needs the expected unique key";
    }
    return $problems;
}

function forecastAccuracyAssertSchema(mysqli $conn): void
{
    if (forecastAccuracySchemaProblems($conn)) {
        throw new RuntimeException('Forecast accuracy setup is incomplete. Preview and apply sources/migrate_forecast_accuracy.php.');
    }
}

function forecastAccuracyDate($value): ?string
{
    if ($value === null) return null;
    if (!is_string($value)) throw new RuntimeException('Invalid evaluation date.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Invalid evaluation date.');
    return $value;
}

function forecastAccuracyCount($value): int
{
    if (!is_int($value) || $value < 0 || $value > 2147483647) throw new RuntimeException('Invalid evaluation record count.');
    return $value;
}

function forecastAccuracyNumber($value, int $places, float $maximum): ?string
{
    if ($value === null) return null;
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > $maximum) {
        throw new RuntimeException('Invalid evaluation score.');
    }
    return number_format((float)$value, $places, '.', '');
}

/** Validate Python's entire evaluation batch before changing any cached forecast. */
function forecastAccuracyValidate(array $result, array $snapshot): array
{
    $data = $result['accuracy'] ?? null;
    if (!is_array($data) || ($data['version'] ?? '') !== FORECAST_EVALUATION_VERSION
        || !is_string($data['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['source_hash'])
        || !is_string($data['model_version'] ?? null) || $data['model_version'] === '' || strlen($data['model_version']) > 80
        || !is_array($data['model_parameters'] ?? null) || !is_array($data['feature_columns'] ?? null)
        || $data['feature_columns'] !== ['dow','month','day','is_weekend','patient_volume','supply_type','lag_1','lag_7','rolling_7']
        || !is_array($data['rows'] ?? null) || !array_is_list($data['rows']) || !$data['rows'] || count($data['rows']) > 4000) {
        throw new RuntimeException('The model did not return a valid forecast accuracy batch. Install all Step 12 files together.');
    }
    foreach (['training_records','testing_records'] as $key) $data[$key] = forecastAccuracyCount($data[$key] ?? null);
    foreach (['training_start','training_end','testing_start','testing_end'] as $key) $data[$key] = forecastAccuracyDate($data[$key] ?? null);
    $data['overall_smape_percent'] = forecastAccuracyNumber($data['overall_smape_percent'] ?? null, 4, 200);
    $hasSplit = $data['training_records'] > 0 && $data['testing_records'] > 0;
    if ($hasSplit && (!$data['training_start'] || !$data['training_end'] || !$data['testing_start'] || !$data['testing_end']
        || $data['training_start'] > $data['training_end'] || $data['training_end'] >= $data['testing_start']
        || $data['testing_start'] > $data['testing_end'] || $data['testing_end'] > $snapshot['database_today']
        || $data['overall_smape_percent'] === null)) {
        throw new RuntimeException('Evaluation dates overlap training or include future observations.');
    }
    if (!$hasSplit && ($data['training_records'] || $data['testing_records'] || $data['overall_smape_percent'] !== null
        || $data['training_start'] !== null || $data['training_end'] !== null
        || $data['testing_start'] !== null || $data['testing_end'] !== null)) {
        throw new RuntimeException('An unavailable evaluation must not report invented scores or dates.');
    }
    $seen = []; $rows = []; $trainingCount = 0; $testingCount = 0;
    foreach ($data['rows'] as $row) {
        $id = $row['item_id'] ?? null; $item = is_int($id) ? ($snapshot['catalog'][$id] ?? null) : null;
        $method = $row['method'] ?? ''; $days = $row['horizon_days'] ?? null;
        if (!$item || !(bool)$item['is_forecastable'] || ($row['item_name'] ?? '') !== trim((string)$item['item_name'])
            || ($row['base_unit'] ?? '') !== $item['accuracy_base_unit']
            || !is_int($days) || !in_array($days, $method === 'one_step' ? [1] : ($method === 'recursive' ? [7,14,30] : []), true)) {
            throw new RuntimeException('Evaluation item, unit or horizon does not match the captured inventory catalog.');
        }
        $key = "$id:$method:$days";
        if (isset($seen[$key])) throw new RuntimeException('Duplicate evaluation result.');
        $seen[$key] = true;
        foreach (['training_records','testing_records'] as $field) $row[$field] = forecastAccuracyCount($row[$field] ?? null);
        foreach (['training_start','training_end','testing_start','testing_end'] as $field) $row[$field] = forecastAccuracyDate($row[$field] ?? null);
        if (($row['training_records'] > 0 && (!$row['training_start'] || !$row['training_end']
            || !$hasSplit || $row['training_start'] < $data['training_start'] || $row['training_end'] > $data['training_end']
            || $row['training_start'] > $row['training_end']))
            || ($row['training_records'] === 0 && ($row['training_start'] !== null || $row['training_end'] !== null))) {
            throw new RuntimeException('Evaluation item training dates do not match its record counts.');
        }
        $status = $row['status'] ?? '';
        if (!in_array($status, ['Available','Insufficient History'], true)) throw new RuntimeException('Invalid evaluation status.');
        foreach (['mae','rmse','smape_percent','actual_total','forecast_total','total_absolute_error'] as $field) {
            $row[$field] = forecastAccuracyNumber($row[$field] ?? null, in_array($field,['mae','rmse'],true) ? 8 : 4,
                $field === 'smape_percent' ? 200 : 99999999999.0);
            if (($status === 'Available') !== ($row[$field] !== null)) throw new RuntimeException('Available scores must be calculated; unavailable scores must be empty.');
        }
        if ($status === 'Available') {
            if (!$hasSplit || !$row['testing_records'] || !$row['testing_start'] || !$row['testing_end']
                || $row['testing_start'] < $data['testing_start'] || $row['testing_end'] > $data['testing_end']
                || $row['testing_start'] > $row['testing_end'] || $row['reason'] !== null
                || ($row['training_end'] !== null && $row['training_end'] >= $row['testing_start'])) {
                throw new RuntimeException('Available evaluation has invalid testing metadata.');
            }
            if ($method === 'recursive' && ($row['testing_records'] !== $days
                || $row['testing_start'] !== $data['testing_start']
                || (new DateTimeImmutable($row['testing_start']))->modify('+' . ($days-1) . ' days')->format('Y-m-d') !== $row['testing_end'])) {
                throw new RuntimeException('Recursive evaluation must contain its complete daily holdout horizon.');
            }
        } else {
            if (!is_string($row['reason'] ?? null) || trim($row['reason']) === '' || strlen($row['reason']) > 1000) {
                throw new RuntimeException('Unavailable evaluation needs a reason.');
            }
        }
        if ($method === 'one_step') { $trainingCount += $row['training_records']; $testingCount += $row['testing_records']; }
        $rows[] = $row;
    }
    if ($trainingCount !== $data['training_records'] || $testingCount !== $data['testing_records']) {
        throw new RuntimeException('Evaluation summary counts differ from item records.');
    }
    foreach ($rows as $row) foreach (['one_step:1','recursive:7','recursive:14','recursive:30'] as $suffix) {
        if (!isset($seen[$row['item_id'] . ':' . $suffix])) throw new RuntimeException('Each evaluated item needs all four test outcomes.');
    }
    foreach ($result['forecasts'] as $forecast) if (!isset($seen[$forecast['item_id'] . ':one_step:1'])) {
        throw new RuntimeException('A forecasted item is missing its accuracy outcome.');
    }
    $data['rows'] = $rows;
    $data['model_details'] = json_encode(['parameters'=>$data['model_parameters'], 'features'=>$data['feature_columns']], JSON_THROW_ON_ERROR);
    return $data;
}

/** Caller owns the publication transaction; a logging failure rolls everything back. */
function forecastAccuracySave(mysqli $conn, string $branchId, int $days, int $userId, array $snapshot, array $data): int
{
    $runKey = hash('sha256', json_encode([$branchId, $days, $snapshot['revision'], $snapshot['signature'], $data], JSON_THROW_ON_ERROR));
    $stmt = $conn->prepare('INSERT INTO forecast_evaluation_runs
        (run_key,branch_id,generated_by,forecast_date,generated_horizon,training_revision,evaluation_version,
         source_hash,model_version,model_details,training_records,testing_records,training_start,training_end,
         testing_start,testing_end,overall_smape_percent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE evaluation_id=LAST_INSERT_ID(evaluation_id)');
    $date=$snapshot['database_today']; $revision=$snapshot['revision']; $version=$data['version'];
    $stmt->bind_param('ssisiissssiisssss', $runKey,$branchId,$userId,$date,$days,$revision,$version,
        $data['source_hash'],$data['model_version'],$data['model_details'],$data['training_records'],$data['testing_records'],
        $data['training_start'],$data['training_end'],$data['testing_start'],$data['testing_end'],$data['overall_smape_percent']);
    if (!$stmt->execute()) throw new RuntimeException('Unable to save forecast evaluation run.');
    $id = (int)$conn->insert_id; $new = $stmt->affected_rows === 1; $stmt->close();
    if (!$new) return $id;
    $insert = $conn->prepare('INSERT INTO forecast_evaluation_items
        (evaluation_id,item_id,item_name,base_unit,method,horizon_days,evaluation_status,reason,
         training_records,testing_records,training_start,training_end,testing_start,testing_end,
         mae,rmse,smape_percent,actual_total,forecast_total,total_absolute_error)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    try {
        foreach ($data['rows'] as $row) {
            $insert->bind_param('iisssissiissssssssss', $id,$row['item_id'],$row['item_name'],$row['base_unit'],$row['method'],
                $row['horizon_days'],$row['status'],$row['reason'],$row['training_records'],$row['testing_records'],
                $row['training_start'],$row['training_end'],$row['testing_start'],$row['testing_end'],$row['mae'],$row['rmse'],
                $row['smape_percent'],$row['actual_total'],$row['forecast_total'],$row['total_absolute_error']);
            if (!$insert->execute()) throw new RuntimeException('Unable to save item evaluation results.');
        }
    } finally { $insert->close(); }
    return $id;
}
