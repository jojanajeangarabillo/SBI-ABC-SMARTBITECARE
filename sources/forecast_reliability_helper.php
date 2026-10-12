<?php
declare(strict_types=1);

function forecastReliabilityTableDefinitions(): array
{
    return [
        'forecast_reliability_runs' => "CREATE TABLE forecast_reliability_runs (
            reliability_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_key CHAR(64) NOT NULL,
            branch_id VARCHAR(10) NOT NULL,
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            evaluation_version VARCHAR(40) NOT NULL,
            source_hash CHAR(64) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            model_version VARCHAR(80) NOT NULL,
            as_of_date DATE NOT NULL,
            data_start DATE NOT NULL,
            data_end DATE NOT NULL,
            requested_windows INT NOT NULL,
            details LONGTEXT NOT NULL,
            PRIMARY KEY (reliability_id), UNIQUE KEY uq_reliability_run (run_key),
            KEY idx_reliability_branch (branch_id,reliability_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        'forecast_reliability_windows' => "CREATE TABLE forecast_reliability_windows (
            window_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reliability_id BIGINT UNSIGNED NOT NULL,
            fold_number INT NOT NULL,
            item_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            base_unit VARCHAR(100) NOT NULL,
            horizon_days INT NOT NULL,
            origin_date DATE NOT NULL,
            training_records INT NOT NULL,
            training_start DATE NULL,
            training_end DATE NULL,
            testing_start DATE NOT NULL,
            testing_end DATE NOT NULL,
            observed_records INT NOT NULL,
            status VARCHAR(30) NOT NULL,
            reason VARCHAR(1000) NULL,
            actual_total DECIMAL(20,4) NULL,
            xgb_mae DECIMAL(20,8) NULL,
            xgb_rmse DECIMAL(20,8) NULL,
            xgb_smape_percent DECIMAL(9,4) NULL,
            xgb_forecast_total DECIMAL(20,4) NULL,
            last_mae DECIMAL(20,8) NULL,
            last_rmse DECIMAL(20,8) NULL,
            last_smape_percent DECIMAL(9,4) NULL,
            last_forecast_total DECIMAL(20,4) NULL,
            mean_mae DECIMAL(20,8) NULL,
            mean_rmse DECIMAL(20,8) NULL,
            mean_smape_percent DECIMAL(9,4) NULL,
            mean_forecast_total DECIMAL(20,4) NULL,
            daily_results LONGTEXT NOT NULL,
            PRIMARY KEY (window_id),
            UNIQUE KEY uq_reliability_window (reliability_id,fold_number,item_id,horizon_days)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];
}

// Read-only schema check. Only the explicit CLI migration creates tables.
function forecastReliabilitySchemaProblems(mysqli $conn, bool $allowMissing=false): array
{
    $problems=[];
    foreach(forecastReliabilityTableDefinitions() as $table=>$definition){
        $stmt=$conn->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s',$table);$stmt->execute();$columns=[];
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r)$columns[$r['COLUMN_NAME']]=$r;$stmt->close();
        if(!$columns){if(!$allowMissing)$problems[]="$table is missing";continue;}
        $stmt=$conn->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s',$table);$stmt->execute();$engine=$stmt->get_result()->fetch_assoc()['ENGINE']??'';$stmt->close();
        if(strcasecmp($engine,'InnoDB')!==0)$problems[]="$table must use InnoDB";
        preg_match_all('/^\s+(\w+) (BIGINT UNSIGNED|INT|CHAR\(\d+\)|VARCHAR\(\d+\)|DATETIME|DATE|LONGTEXT|DECIMAL\(\d+,\d+\)) (NOT NULL|NULL)/m',$definition,$matches,PREG_SET_ORDER);
        foreach($matches as $field){
            $actual=$columns[$field[1]]??null;
            $type=$actual?strtolower(preg_replace('/\b(bigint|int)\(\d+\)/i','$1',$actual['COLUMN_TYPE'])):'';
            if(!$actual||$type!==strtolower($field[2])||$actual['IS_NULLABLE']!==($field[3]==='NULL'?'YES':'NO'))$problems[]="$table.{$field[1]} is incompatible";
        }
        $id=$table==='forecast_reliability_runs'?'reliability_id':'window_id';
        if(!str_contains($columns[$id]['EXTRA']??'','auto_increment'))$problems[]="$table needs an auto-increment ID";
        $expected=$table==='forecast_reliability_runs'?['run_key']:['reliability_id','fold_number','item_id','horizon_days'];
        $indexes=[];foreach($conn->query('SHOW INDEX FROM '.$table)->fetch_all(MYSQLI_ASSOC) as $r)if((int)$r['Non_unique']===0)$indexes[$r['Key_name']][(int)$r['Seq_in_index']]=$r['Column_name'];
        $found=false;foreach($indexes as $fields){ksort($fields);if(array_values($fields)===$expected)$found=true;}
        if(!$found)$problems[]="$table needs the expected unique key";
    }
    return $problems;
}

function forecastReliabilityComparison(array $r): array
{
    if((int)$r['complete_windows']===0)return ['unrated','No complete windows'];
    if((float)$r['actual_total']===0.0)return ['unrated','No observed demand'];
    // A presentation rule, not a statistical confidence or significance test.
    if((int)$r['complete_windows']<3)return ['unrated','Limited windows'];
    $x=(float)$r['xgb_mae'];$last=(float)$r['last_mae'];$mean=(float)$r['mean_mae'];
    if($x<$last&&$x<$mean)return ['high','Lower MAE than both'];
    if($x<$last||$x<$mean)return ['moderate','Mixed comparison'];
    return ['low','Baselines match or beat model'];
}

function forecastReliabilitySkill($baseline,$error): string
{
    if($baseline===null||$error===null)return '—';
    if((float)$baseline===0.0)return 'Undefined: baseline error is zero';
    return number_format(100*(1-(float)$error/(float)$baseline),2,'.',',').'%';
}
