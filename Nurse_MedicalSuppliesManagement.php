<?php
session_start();
require_once 'sources/db_connect.php';

// ============================================================
// NURSE - MEDICAL SUPPLIES MANAGEMENT
// ============================================================
// IMPORTANT INVENTORY RULES:
//
// inventory_items
//      = master item information
//
// inventory_stocks
//      = actual branch/batch stock
//
// stock_transactions
//      = stock movement history
//
// inventory_usage_history
//      = usage / consumption history
//
// Vaccines are already categorized under:
//      Medical Supplies
//
// Medical supply usage uses FEFO:
//      First Expiring, First Out
//
// Expired stock is never administered to patients.
// ============================================================


// ============================================================
// ACCESS CONTROL
// Nurse = role_id 3
// Super Admin = role_id 1
// ============================================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    !in_array((int) $_SESSION['role_id'], [1, 3], true)
) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];


// ============================================================
// CSRF TOKEN
// ============================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// ============================================================
// HELPERS
// ============================================================

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


function verifyCsrf()
{
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'] ?? '',
            (string) $_POST['csrf_token']
        )
    ) {
        throw new Exception(
            'Invalid request token. Please refresh the page and try again.'
        );
    }
}


function validDate($date)
{
    $d = DateTime::createFromFormat('Y-m-d', $date);

    return $d && $d->format('Y-m-d') === $date;
}


// ============================================================
// FLASH MESSAGE
// ============================================================

function setFlash($type, $message)
{
    $_SESSION['medical_supplies_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}


function redirectSelf()
{
    header("Location: Nurse_MedicalSuppliesManagement.php");
    exit();
}


// ============================================================
// AUDIT LOG
// ============================================================

function addAuditLog(
    $conn,
    $user_id,
    $action,
    $module = 'Medical Supplies'
) {
    $branch_id = null;

    $user_sql = "
        SELECT branch_id
        FROM users
        WHERE user_id = ?
    ";

    $user_stmt = $conn->prepare($user_sql);

    if ($user_stmt) {

        $user_stmt->bind_param(
            "i",
            $user_id
        );

        $user_stmt->execute();

        $user_result = $user_stmt->get_result();

        if ($user_row = $user_result->fetch_assoc()) {
            $branch_id = $user_row['branch_id'];
        }

        $user_stmt->close();
    }


    $log_sql = "
        INSERT INTO audit_logs
        (
            user_id,
            branch_id,
            action,
            module
        )
        VALUES (?, ?, ?, ?)
    ";

    $log_stmt = $conn->prepare($log_sql);

    if (!$log_stmt) {
        return false;
    }


    $log_stmt->bind_param(
        "isss",
        $user_id,
        $branch_id,
        $action,
        $module
    );

    $result = $log_stmt->execute();

    $log_stmt->close();

    return $result;
}


// ============================================================
// GET LOGGED-IN USER AND BRANCH
// ============================================================

$user_sql = "
    SELECT
        u.user_id,
        u.username,
        u.branch_id,
        b.branch_name
    FROM users u
    LEFT JOIN branches b
        ON u.branch_id = b.branch_id
    WHERE u.user_id = ?
    LIMIT 1
";


$user_stmt = $conn->prepare($user_sql);

$user_stmt->bind_param(
    "i",
    $user_id
);

$user_stmt->execute();

$user_result = $user_stmt->get_result();

$current_user = $user_result->fetch_assoc();

$user_stmt->close();


if (!$current_user || empty($current_user['branch_id'])) {

    die(
        "Your account is not assigned to a branch."
    );
}


$branch_id = $current_user['branch_id'];

$branch_name =
    $current_user['branch_name']
    ?? $branch_id;

$username =
    $current_user['username']
    ?? 'Nurse';


$_SESSION['branch_id'] = $branch_id;

$_SESSION['branch_name'] = $branch_name;


// ============================================================
// GET MEDICAL SUPPLY ITEM
// ============================================================

function getMedicalSupplyItem(
    $conn,
    $item_id
) {

    $sql = "
        SELECT
            i.item_id,
            i.item_name,
            i.minimum_stock,
            c.category_name,
            u.unit_name
        FROM inventory_items i

        INNER JOIN inventory_categories c
            ON i.category_id = c.category_id

        INNER JOIN units u
            ON i.unit_id = u.unit_id

        WHERE i.item_id = ?

        AND c.category_name = 'Medical Supplies'

        LIMIT 1
    ";


    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "i",
        $item_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $stmt->close();


    return $row ?: null;
}


// ============================================================
// GET TOTAL USABLE / NON-EXPIRED STOCK
// ============================================================

function getUsableStock(
    $conn,
    $item_id,
    $branch_id
) {

    $sql = "
        SELECT
            COALESCE(
                SUM(quantity_available),
                0
            ) AS total_stock

        FROM inventory_stocks

        WHERE item_id = ?

        AND branch_id = ?

        AND quantity_available > 0

        AND
        (
            expiration_date IS NULL
            OR expiration_date >= CURDATE()
        )
    ";


    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "is",
        $item_id,
        $branch_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $row = $result->fetch_assoc();

    $stmt->close();


    return (int) ($row['total_stock'] ?? 0);
}


// ============================================================
// FEFO STOCK DEDUCTION
// ============================================================
//
// Example:
//
// Batch A
// Expiry: 2026-10-01
// Qty: 2
//
// Batch B
// Expiry: 2027-01-01
// Qty: 10
//
// Nurse uses 4:
//
// Batch A = 0
// Batch B = 8
//
// ============================================================

function deductStockFEFO(
    $conn,
    $item_id,
    $branch_id,
    $quantity_needed
) {

    if ($quantity_needed <= 0) {

        throw new Exception(
            'Quantity must be greater than zero.'
        );
    }


    $sql = "
        SELECT
            stock_id,
            batch_lot_no,
            quantity_available,
            expiration_date

        FROM inventory_stocks

        WHERE item_id = ?

        AND branch_id = ?

        AND quantity_available > 0

        AND
        (
            expiration_date IS NULL
            OR expiration_date >= CURDATE()
        )

        ORDER BY

            CASE
                WHEN expiration_date IS NULL
                THEN 1
                ELSE 0
            END ASC,

            expiration_date ASC,

            stock_id ASC

        FOR UPDATE
    ";


    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "is",
        $item_id,
        $branch_id
    );

    $stmt->execute();

    $result = $stmt->get_result();


    $stocks = [];

    $total_available = 0;


    while ($row = $result->fetch_assoc()) {

        $stocks[] = $row;

        $total_available +=
            (int) $row['quantity_available'];
    }


    $stmt->close();


    if ($total_available < $quantity_needed) {

        throw new Exception(
            "Insufficient usable stock. " .
            "Available non-expired stock: " .
            $total_available
        );
    }


    $remaining = $quantity_needed;

    $used_batches = [];


    foreach ($stocks as $stock) {

        if ($remaining <= 0) {
            break;
        }


        $stock_id =
            (int) $stock['stock_id'];

        $batch_available =
            (int) $stock['quantity_available'];


        $take =
            min(
                $batch_available,
                $remaining
            );


        $update_sql = "
            UPDATE inventory_stocks

            SET
                quantity_available =
                quantity_available - ?,

                last_updated =
                CURRENT_TIMESTAMP

            WHERE stock_id = ?
        ";


        $update_stmt =
            $conn->prepare($update_sql);


        $update_stmt->bind_param(
            "ii",
            $take,
            $stock_id
        );


        $update_stmt->execute();

        $update_stmt->close();


        $used_batches[] = [

            'stock_id' =>
                $stock_id,

            'batch_lot_no' =>
                $stock['batch_lot_no']
                ?: 'N/A',

            'quantity' =>
                $take,

            'expiration_date' =>
                $stock['expiration_date']
        ];


        $remaining -= $take;
    }


    return $used_batches;
}


// ============================================================
// CREATE BATCH SUMMARY FOR TRANSACTION REMARKS
// ============================================================

function createBatchSummary(
    $batches
) {

    $summary = [];


    foreach ($batches as $batch) {

        $summary[] =
            $batch['batch_lot_no']
            . ': '
            . $batch['quantity'];
    }


    return implode(
        ', ',
        $summary
    );
}


// ============================================================
// RECORD MEDICAL SUPPLY USAGE
// ============================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['record_usage'])
) {

    try {

        verifyCsrf();


        $patient_id =
            (int) ($_POST['patient_id'] ?? 0);

        $case_id =
            (int) ($_POST['case_id'] ?? 0);

        $item_id =
            (int) ($_POST['item_id'] ?? 0);

        $quantity_used =
            (int) ($_POST['quantity_used'] ?? 0);

        $reason =
            trim(
                $_POST['reason']
                ?? ''
            );

        $dose_number =
            (int) (
                $_POST['dose_number']
                ?? 1
            );

        $date_administered =
            trim(
                $_POST['date_administered']
                ?? date('Y-m-d')
            );


        // ----------------------------------------------------
        // BASIC VALIDATION
        // ----------------------------------------------------

        if (
            $patient_id <= 0
            ||
            $item_id <= 0
            ||
            $quantity_used <= 0
        ) {

            throw new Exception(
                'Please fill in all required fields.'
            );
        }


        if (!validDate($date_administered)) {

            throw new Exception(
                'Invalid usage date.'
            );
        }


        $allowed_reasons = [

            'Vaccination',

            'Wound Care',

            'Consultation',

            'Emergency',

            'Other'
        ];


        if (
            !in_array(
                $reason,
                $allowed_reasons,
                true
            )
        ) {

            throw new Exception(
                'Invalid usage reason.'
            );
        }


        // ----------------------------------------------------
        // VERIFY ITEM IS MEDICAL SUPPLIES
        // ----------------------------------------------------

        $item_data =
            getMedicalSupplyItem(
                $conn,
                $item_id
            );


        if (!$item_data) {

            throw new Exception(
                'Selected item is not a Medical Supplies item.'
            );
        }


        // ----------------------------------------------------
        // VERIFY PATIENT
        // ----------------------------------------------------

        $patient_sql = "
            SELECT
                patient_id,
                full_name

            FROM patients

            WHERE patient_id = ?

            LIMIT 1
        ";


        $patient_stmt =
            $conn->prepare(
                $patient_sql
            );


        $patient_stmt->bind_param(
            "i",
            $patient_id
        );


        $patient_stmt->execute();


        $patient_result =
            $patient_stmt->get_result();


        $patient_data =
            $patient_result->fetch_assoc();


        $patient_stmt->close();


        if (!$patient_data) {

            throw new Exception(
                'Patient not found.'
            );
        }


        // ----------------------------------------------------
        // VERIFY CASE
        // ----------------------------------------------------

        $case_id_db = null;


        if ($case_id > 0) {

            $case_sql = "
                SELECT
                    case_id

                FROM animal_bite_cases

                WHERE case_id = ?

                AND patient_id = ?

                AND branch_id = ?

                LIMIT 1
            ";


            $case_stmt =
                $conn->prepare(
                    $case_sql
                );


            $case_stmt->bind_param(
                "iis",
                $case_id,
                $patient_id,
                $branch_id
            );


            $case_stmt->execute();


            $case_result =
                $case_stmt->get_result();


            $case_data =
                $case_result->fetch_assoc();


            $case_stmt->close();


            if (!$case_data) {

                throw new Exception(
                    'The selected case does not belong to this patient or branch.'
                );
            }


            $case_id_db =
                $case_id;
        }


        // ----------------------------------------------------
        // CHECK AVAILABLE NON-EXPIRED STOCK
        // ----------------------------------------------------

        $usable_stock =
            getUsableStock(
                $conn,
                $item_id,
                $branch_id
            );


        if (
            $usable_stock
            <
            $quantity_used
        ) {

            throw new Exception(
                "Insufficient usable stock. " .
                "Available non-expired stock: " .
                $usable_stock
            );
        }


        // ----------------------------------------------------
        // BEGIN TRANSACTION
        // ----------------------------------------------------

        $conn->begin_transaction();


        $vaccination_id = null;


        // ====================================================
        // ONLY CREATE VACCINATION RECORD
        // WHEN REASON IS VACCINATION
        // ====================================================

        if ($reason === 'Vaccination') {


            if ($dose_number <= 0) {

                throw new Exception(
                    'Please select a valid dose number.'
                );
            }


            $vaccination_sql = "
                INSERT INTO vaccination_records
                (
                    patient_id,
                    case_id,
                    item_id,
                    branch_id,
                    dose_number,
                    date_administered,
                    vaccination_status,
                    is_final_dose,
                    nurse_id
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'Completed',
                    0,
                    ?
                )
            ";


            $vaccination_stmt =
                $conn->prepare(
                    $vaccination_sql
                );


            $vaccination_stmt->bind_param(
                "iiisisi",
                $patient_id,
                $case_id_db,
                $item_id,
                $branch_id,
                $dose_number,
                $date_administered,
                $user_id
            );


            $vaccination_stmt->execute();


            $vaccination_id =
                $conn->insert_id;


            $vaccination_stmt->close();
        }


        // ====================================================
        // DEDUCT STOCK USING FEFO
        // ====================================================

        $used_batches =
            deductStockFEFO(
                $conn,
                $item_id,
                $branch_id,
                $quantity_used
            );


        $batch_summary =
            createBatchSummary(
                $used_batches
            );


        // ====================================================
        // STOCK TRANSACTION
        // ====================================================

        $remarks =

            $reason

            . " - Patient: "

            . (
                $patient_data['full_name']
                ?? 'Unknown'
            );


        if ($reason === 'Vaccination') {

            $remarks .=
                " - Dose: "
                . $dose_number;
        }


        $remarks .=
            " - Batch allocation: "
            . $batch_summary;


        $transaction_date =
            $date_administered
            . ' '
            . date('H:i:s');


        $transaction_sql = "
            INSERT INTO stock_transactions
            (
                item_id,
                user_id,
                vaccination_id,
                branch_id,
                transaction_type,
                quantity,
                remarks,
                transaction_date
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                'OUT',
                ?,
                ?,
                ?
            )
        ";


        $transaction_stmt =
            $conn->prepare(
                $transaction_sql
            );


        $transaction_stmt->bind_param(
            "iiisiss",
            $item_id,
            $user_id,
            $vaccination_id,
            $branch_id,
            $quantity_used,
            $remarks,
            $transaction_date
        );


        $transaction_stmt->execute();

        $transaction_stmt->close();


        // ====================================================
        // INVENTORY USAGE HISTORY
        // ====================================================

        $usage_sql = "
            INSERT INTO inventory_usage_history
            (
                item_id,
                branch_id,
                usage_date,
                quantity_used,
                patient_count
            )

            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                1
            )
        ";


        $usage_stmt =
            $conn->prepare(
                $usage_sql
            );


        $usage_stmt->bind_param(
            "issi",
            $item_id,
            $branch_id,
            $date_administered,
            $quantity_used
        );


        $usage_stmt->execute();

        $usage_stmt->close();


        // ====================================================
        // COMMIT
        // ====================================================

        $conn->commit();


        addAuditLog(

            $conn,

            $user_id,

            "Recorded "
            . $reason
            . " usage of "
            . $item_data['item_name']
            . ": "
            . $quantity_used
            . " "
            . $item_data['unit_name']
            . " for patient "
            . $patient_data['full_name']
            . ". Batch(es): "
            . $batch_summary
        );


        setFlash(
            'success',
            'Medical supply usage recorded successfully.'
        );


        redirectSelf();


    } catch (Throwable $e) {


        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }


        error_log(
            "Medical supply usage error: "
            . $e->getMessage()
        );


        addAuditLog(

            $conn,

            $user_id,

            "Failed to record medical supply usage: "
            . $e->getMessage()
        );


        setFlash(
            'error',
            $e->getMessage()
        );


        redirectSelf();
    }
}


// ============================================================
// DAILY CONSUMPTION SUMMARY
// ============================================================
//
// IMPORTANT:
// This does NOT deduct stock.
//
// Actual stock deduction happens in:
//
//      Record Usage
//
// This prevents nurses from accidentally subtracting
// the same vaccine / supply twice.
//
// ============================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['save_daily_consumption'])
) {

    try {

        verifyCsrf();


        $consumption_date =
            trim(
                $_POST['consumption_date']
                ?? date('Y-m-d')
            );


        $patient_count =
            (int) (
                $_POST['patient_count']
                ?? 0
            );


        if (
            !validDate(
                $consumption_date
            )
        ) {

            throw new Exception(
                'Invalid consumption date.'
            );
        }


        if ($patient_count < 0) {

            throw new Exception(
                'Invalid patient count.'
            );
        }


        $consumption =
            $_POST['consumption']
            ?? [];


        if (!is_array($consumption)) {

            throw new Exception(
                'Invalid consumption data.'
            );
        }


        $conn->begin_transaction();


        $saved = 0;


        foreach (
            $consumption
            as $item_id => $quantity
        ) {

            $item_id =
                (int) $item_id;


            $quantity =
                (int) $quantity;


            if (
                $item_id <= 0
                ||
                $quantity <= 0
            ) {
                continue;
            }


            $item_data =
                getMedicalSupplyItem(
                    $conn,
                    $item_id
                );


            if (!$item_data) {
                continue;
            }


            $usage_sql = "
                INSERT INTO inventory_usage_history
                (
                    item_id,
                    branch_id,
                    usage_date,
                    quantity_used,
                    patient_count
                )

                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ";


            $usage_stmt =
                $conn->prepare(
                    $usage_sql
                );


            $usage_stmt->bind_param(
                "issii",
                $item_id,
                $branch_id,
                $consumption_date,
                $quantity,
                $patient_count
            );


            $usage_stmt->execute();

            $usage_stmt->close();


            $saved++;
        }


        $conn->commit();


        addAuditLog(

            $conn,

            $user_id,

            "Recorded daily consumption summary for "
            . $consumption_date
            . ". "
            . $saved
            . " supply item(s) recorded."
        );


        setFlash(

            'success',

            'Daily consumption summary saved. Stock was not deducted again.'
        );


        redirectSelf();


    } catch (Throwable $e) {


        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }


        error_log(
            "Daily consumption error: "
            . $e->getMessage()
        );


        setFlash(
            'error',
            $e->getMessage()
        );


        redirectSelf();
    }
}


// ============================================================
// RESTOCK REQUEST
// ============================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['request_restock'])
) {

    try {

        verifyCsrf();


        $item_id =
            (int) (
                $_POST['item_id']
                ?? 0
            );


        $requested_quantity =
            (int) (
                $_POST['requested_quantity']
                ?? 0
            );


        $reason =
            trim(
                $_POST['reason']
                ?? ''
            );


        if (
            $item_id <= 0
            ||
            $requested_quantity <= 0
        ) {

            throw new Exception(
                'Please select an item and enter a valid quantity.'
            );
        }


        if (
            mb_strlen($reason)
            >
            500
        ) {

            throw new Exception(
                'Restock reason must not exceed 500 characters.'
            );
        }


        $item_data =
            getMedicalSupplyItem(
                $conn,
                $item_id
            );


        if (!$item_data) {

            throw new Exception(
                'Selected item is not a Medical Supplies item.'
            );
        }


        // ====================================================
        // FIND INVENTORY OFFICER OF SAME BRANCH
        // role_id = 5
        // ====================================================

        $officer_sql = "
            SELECT
                user_id

            FROM users

            WHERE role_id = 5

            AND branch_id = ?

            AND status = 'Active'

            ORDER BY user_id ASC

            LIMIT 1
        ";


        $officer_stmt =
            $conn->prepare(
                $officer_sql
            );


        $officer_stmt->bind_param(
            "s",
            $branch_id
        );


        $officer_stmt->execute();


        $officer_result =
            $officer_stmt->get_result();


        $officer =
            $officer_result->fetch_assoc();


        $officer_stmt->close();


        $recipient_id = 0;


        if ($officer) {

            $recipient_id =
                (int) $officer['user_id'];

        } else {

            // ------------------------------------------------
            // FALLBACK TO SUPER ADMIN
            // ------------------------------------------------

            $admin_sql = "
                SELECT
                    user_id

                FROM users

                WHERE role_id = 1

                AND status = 'Active'

                ORDER BY user_id ASC

                LIMIT 1
            ";


            $admin_result =
                $conn->query(
                    $admin_sql
                );


            if ($admin_result) {

                $admin =
                    $admin_result->fetch_assoc();


                $recipient_id =
                    (int) (
                        $admin['user_id']
                        ?? 0
                    );
            }
        }


        if ($recipient_id <= 0) {

            throw new Exception(
                'No Inventory Officer or Super Admin is available to receive this request.'
            );
        }


        $current_stock =
            getUsableStock(
                $conn,
                $item_id,
                $branch_id
            );


        $title =
            "Restock Request: "
            . $item_data['item_name'];


        $message =

            "Requested Quantity: "
            . $requested_quantity
            . " "
            . $item_data['unit_name']

            . "\nCurrent Usable Stock: "
            . $current_stock
            . " "
            . $item_data['unit_name']

            . "\nReason: "
            . (
                $reason !== ''
                ? $reason
                : 'No reason provided'
            )

            . "\nBranch: "
            . $branch_name

            . "\nRequested By: "
            . $username;


        $notif_sql = "
            INSERT INTO notifications
            (
                user_id,
                title,
                message,
                notification_type
            )

            VALUES
            (
                ?,
                ?,
                ?,
                'restock_request'
            )
        ";


        $notif_stmt =
            $conn->prepare(
                $notif_sql
            );


        $notif_stmt->bind_param(
            "iss",
            $recipient_id,
            $title,
            $message
        );


        $notif_stmt->execute();

        $notif_stmt->close();


        addAuditLog(

            $conn,

            $user_id,

            "Requested restock for "
            . $item_data['item_name']
            . ": "
            . $requested_quantity
            . " "
            . $item_data['unit_name']
        );


        setFlash(
            'success',
            'Restock request sent to the Inventory Officer successfully.'
        );


        redirectSelf();


    } catch (Throwable $e) {


        error_log(
            "Restock request error: "
            . $e->getMessage()
        );


        setFlash(
            'error',
            $e->getMessage()
        );


        redirectSelf();
    }
}


// ============================================================
// GET FLASH
// ============================================================

$success_msg = null;

$error_msg = null;


if (
    isset(
        $_SESSION['medical_supplies_flash']
    )
) {

    $flash =
        $_SESSION[
            'medical_supplies_flash'
        ];


    unset(
        $_SESSION[
            'medical_supplies_flash'
        ]
    );


    if (
        ($flash['type'] ?? '')
        ===
        'success'
    ) {

        $success_msg =
            $flash['message'];

    } else {

        $error_msg =
            $flash['message'];
    }
}


// ============================================================
// GET MEDICAL SUPPLY ITEMS
// ============================================================
//
// IMPORTANT:
//
// Vaccine is no longer a separate category.
//
// Only:
//
//      Medical Supplies
//
// One item = one table row.
//
// Usable stock is SUMMED across NON-EXPIRED batches.
// Expired stock is tracked separately and never counted as usable.
//
// ============================================================

$items_sql = "
    SELECT

        i.item_id,

        i.item_name,

        i.minimum_stock,

        c.category_name,

        u.unit_name,

        /*
         * USABLE STOCK ONLY
         *
         * Expired batches remain in inventory_stocks for history,
         * but they must not be counted as stock that a nurse can use.
         */
        COALESCE(
            SUM(
                CASE
                    WHEN
                        s.quantity_available > 0
                        AND
                        (
                            s.expiration_date IS NULL
                            OR
                            s.expiration_date >= CURDATE()
                        )
                    THEN
                        s.quantity_available
                    ELSE
                        0
                END
            ),
            0
        ) AS current_stock,


        /*
         * Keep expired quantity visible separately.
         * This quantity is NOT usable and is NOT included in current_stock.
         */
        COALESCE(
            SUM(
                CASE
                    WHEN
                        s.quantity_available > 0
                        AND
                        s.expiration_date IS NOT NULL
                        AND
                        s.expiration_date < CURDATE()
                    THEN
                        s.quantity_available
                    ELSE
                        0
                END
            ),
            0
        ) AS expired_stock,


        /*
         * Nearest expiry must come from an active NON-EXPIRED batch only.
         */
        MIN(
            CASE

                WHEN
                    s.quantity_available > 0
                    AND
                    s.expiration_date IS NOT NULL
                    AND
                    s.expiration_date >= CURDATE()

                THEN
                    s.expiration_date

                ELSE
                    NULL

            END
        ) AS expiration_date,


        MAX(
            s.last_updated
        ) AS last_updated


    FROM inventory_items i


    INNER JOIN inventory_categories c

        ON
        i.category_id =
        c.category_id


    INNER JOIN units u

        ON
        i.unit_id =
        u.unit_id


    LEFT JOIN inventory_stocks s

        ON
        i.item_id =
        s.item_id

        AND
        s.branch_id = ?


    WHERE
        c.category_name =
        'Medical Supplies'


    GROUP BY

        i.item_id,

        i.item_name,

        i.minimum_stock,

        c.category_name,

        u.unit_name


    ORDER BY
        i.item_name ASC
";


$stmt =
    $conn->prepare(
        $items_sql
    );


$stmt->bind_param(
    "s",
    $branch_id
);


$stmt->execute();


$items_result =
    $stmt->get_result();


$items = [];


while (
    $row =
    $items_result->fetch_assoc()
) {

    $items[] = $row;
}


$stmt->close();


// ============================================================
// GET PATIENTS
// ============================================================

$patients_sql = "
    SELECT
        patient_id,
        full_name

    FROM patients

    ORDER BY
        full_name ASC
";


$patients_result =
    $conn->query(
        $patients_sql
    );


// ============================================================
// GET ACTIVE CASES FOR CURRENT BRANCH
// ============================================================

$cases_sql = "
    SELECT
        case_id,
        patient_id

    FROM animal_bite_cases

    WHERE branch_id = ?

    AND case_status != 'Completed'

    ORDER BY
        case_id DESC
";


$cases_stmt =
    $conn->prepare(
        $cases_sql
    );


$cases_stmt->bind_param(
    "s",
    $branch_id
);


$cases_stmt->execute();


$cases_result =
    $cases_stmt->get_result();


$cases = [];


while (
    $row =
    $cases_result->fetch_assoc()
) {

    $cases[] = $row;
}


$cases_stmt->close();


// ============================================================
// MEDICAL SUPPLY STATISTICS
// ============================================================
//
// Stats are item-level.
//
// This prevents multiple batches from being counted
// as multiple inventory items.
//
// ============================================================

$stats_sql = "
    SELECT

        COUNT(*) AS total_supplies,


        SUM(
            CASE

                WHEN
                    x.current_stock > 0

                    AND

                    x.current_stock
                    <=
                    x.minimum_stock

                THEN 1

                ELSE 0

            END
        ) AS low_stock,


        SUM(
            CASE

                WHEN
                    x.current_stock <= 0

                THEN 1

                ELSE 0

            END
        ) AS out_of_stock,


        SUM(
            CASE

                WHEN
                    x.expiring_soon = 1

                THEN 1

                ELSE 0

            END
        ) AS expiring_soon


    FROM
    (
        SELECT

            i.item_id,

            i.minimum_stock,


            /*
             * Dashboard statistics must use USABLE / NON-EXPIRED stock.
             */
            COALESCE(
                SUM(
                    CASE
                        WHEN
                            s.quantity_available > 0
                            AND
                            (
                                s.expiration_date IS NULL
                                OR
                                s.expiration_date >= CURDATE()
                            )
                        THEN
                            s.quantity_available
                        ELSE
                            0
                    END
                ),
                0
            ) AS current_stock,


            MAX(
                CASE

                    WHEN

                        s.quantity_available > 0

                        AND

                        s.expiration_date >= CURDATE()

                        AND

                        s.expiration_date
                        <=
                        DATE_ADD(
                            CURDATE(),
                            INTERVAL 30 DAY
                        )

                    THEN 1

                    ELSE 0

                END
            ) AS expiring_soon


        FROM inventory_items i


        INNER JOIN inventory_categories c

            ON
            i.category_id =
            c.category_id


        LEFT JOIN inventory_stocks s

            ON
            i.item_id =
            s.item_id

            AND
            s.branch_id = ?


        WHERE
            c.category_name =
            'Medical Supplies'


        GROUP BY

            i.item_id,

            i.minimum_stock

    ) x
";


$stats_stmt =
    $conn->prepare(
        $stats_sql
    );


$stats_stmt->bind_param(
    "s",
    $branch_id
);


$stats_stmt->execute();


$stats_result =
    $stats_stmt->get_result();


$stats =
    $stats_result->fetch_assoc();


$stats_stmt->close();


// ============================================================
// TODAY'S TOTAL USAGE
// ============================================================

$today_sql = "
    SELECT

        COALESCE(
            SUM(quantity_used),
            0
        ) AS total_used

    FROM inventory_usage_history

    WHERE branch_id = ?

    AND usage_date = CURDATE()
";


$today_stmt =
    $conn->prepare(
        $today_sql
    );


$today_stmt->bind_param(
    "s",
    $branch_id
);


$today_stmt->execute();


$today_result =
    $today_stmt->get_result();


$today_stats =
    $today_result->fetch_assoc();


$today_stmt->close();


// ============================================================
// TODAY'S VACCINATED PATIENTS
// ============================================================

$patients_today_sql = "
    SELECT

        COUNT(
            DISTINCT patient_id
        ) AS patients_served

    FROM vaccination_records

    WHERE branch_id = ?

    AND date_administered = CURDATE()
";


$patients_today_stmt =
    $conn->prepare(
        $patients_today_sql
    );


$patients_today_stmt->bind_param(
    "s",
    $branch_id
);


$patients_today_stmt->execute();


$patients_today_result =
    $patients_today_stmt->get_result();


$patients_today =
    $patients_today_result->fetch_assoc();


$patients_today_stmt->close();


$today_stats['patients_served'] =
    $patients_today['patients_served']
    ?? 0;


// ============================================================
// RESTOCK REQUEST COUNT
// ============================================================
//
// notifications table in your supplied code does not show a
// branch_id column, so this keeps the existing unread count.
//
// ============================================================

$restock_sql = "
    SELECT

        COUNT(*) AS requests

    FROM notifications

    WHERE
        notification_type =
        'restock_request'

    AND is_read = 0
";


$restock_result =
    $conn->query(
        $restock_sql
    );


$restock_data =
    $restock_result->fetch_assoc();


// ============================================================
// DAILY CONSUMPTION ITEMS
// ============================================================

$consumption_items = [];


foreach ($items as $item) {

    if (
        (int) $item['current_stock']
        >
        0
    ) {

        $consumption_items[] =
            $item;
    }
}


// ============================================================
// ITEM DATA FOR JAVASCRIPT
// ============================================================

$item_stock_map = [];


foreach ($items as $item) {

    $item_stock_map[
        (int) $item['item_id']
    ] = [

        'stock' =>
            (int) $item['current_stock'],

        'minimum_stock' =>
            (int) $item['minimum_stock'],

        'unit' =>
            $item['unit_name']
            ?? ''
    ];
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >


    <title>
        Nurse - Medical Supplies Management
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >


    <link
        rel="stylesheet"
        href="sidebar.css"
    >


<style>

:root {

    --primary: #2B3A8C;

    --accent: #F21D2F;

    --bg: #F2F2F2;

    --card-bg: #ECEEF7;
}


* {

    box-sizing: border-box;
}


body {

    background: white;

    font-family:
        'Segoe UI',
        Roboto,
        system-ui,
        sans-serif;

    margin: 0;

    padding: 0;
}


.main {

    margin-left: 260px;

    min-height: 100vh;

    background: #f9faff;
}


.topbar {

    background: white;

    height: 80px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding: 0 35px;

    box-shadow:
        0 2px 8px
        rgba(0, 0, 0, 0.06);

    border-bottom:
        1px solid #e9edf5;
}


.topbar h3 {

    font-size: 28px;

    font-weight: 700;

    color: var(--primary);

    margin: 0;

    letter-spacing: -0.3px;
}


.branch-label {

    color: #7a85a8;

    font-size: 14px;

    margin-left: 12px;
}


.profile {

    font-weight: 600;

    color: var(--primary);

    display: flex;

    align-items: center;

    gap: 6px;
}


.content {

    padding: 35px 35px 40px;
}


.stat-card {

    background: #ffffff;

    border-radius: 16px;

    padding: 16px 20px;

    height: 100px;

    display: grid;

    grid-template-columns:
        42px 1fr;

    grid-template-rows:
        auto auto;

    column-gap: 12px;

    align-items: center;

    box-shadow:
        0 4px 12px
        rgba(0, 0, 0, 0.08);

    position: relative;

    overflow: hidden;

    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}


.stat-card.total {

    border-left:
        5px solid #28a745;
}


.stat-card.low {

    border-left:
        5px solid #F21D2F;
}


.stat-card.out {

    border-left:
        5px solid #F21D2F;
}


.stat-card.expiring {

    border-left:
        5px solid #17a2b8;
}


.stat-card.usage {

    border-left:
        5px solid #2B3A8C;
}


.stat-card.patients {

    border-left:
        5px solid #2B3A8C;
}


.stat-card.restock {

    border-left:
        5px solid #ffc107;
}


.stat-card .stat-icon {

    grid-column: 1;

    grid-row: 1 / 3;

    font-size: 30px;

    display: flex;

    align-items: center;

    justify-content: center;

    color: var(--primary);
}


.stat-card .stat-title {

    grid-column: 2;

    grid-row: 1;

    font-weight: 500;

    color: #536174;

    font-size: 13px;

    margin: 0;
}


.stat-card .stat-number {

    grid-column: 2;

    grid-row: 2;

    font-size: 28px;

    font-weight: 700;

    color: #1f2937;

    line-height: 1.1;

    margin: 0;
}


.stat-card:hover {

    transform:
        translateY(-3px);

    box-shadow:
        0 6px 16px
        rgba(0, 0, 0, 0.10);
}


.stat-card.total .stat-icon {

    color: #28a745;
}


.stat-card.low .stat-icon {

    color: #F21D2F;
}


.stat-card.out .stat-icon {

    color: #F21D2F;
}


.stat-card.expiring .stat-icon {

    color: #17a2b8;
}


.stat-card.usage .stat-icon {

    color: #2B3A8C;
}


.stat-card.patients .stat-icon {

    color: #2B3A8C;
}


.stat-card.restock .stat-icon {

    color: #ffc107;
}


.function-buttons {

    display: flex;

    flex-wrap: wrap;

    gap: 10px;

    margin-bottom: 24px;
}


.btn-function {

    background: var(--card-bg);

    color: var(--primary);

    border: none;

    border-radius: 40px;

    padding: 8px 20px;

    font-weight: 600;

    font-size: 14px;

    transition: 0.15s;
}


.btn-function:hover {

    background: #d7def0;
}


.btn-function i {

    margin-right: 6px;
}


.table-wrap {

    background: white;

    border-radius: 18px;

    box-shadow:
        0 3px 12px
        rgba(0, 0, 0, 0.05);

    overflow: hidden;

    padding: 0;
}


.table {

    margin-bottom: 0;

    border-collapse: separate;

    border-spacing: 0;
}


.table thead th {

    background:
        var(--primary);

    color: white;

    font-weight: 700;

    font-size: 15px;

    padding: 16px 20px;

    border-bottom:
        1px solid #e2e7f2;
}


.table tbody td {

    padding: 16px 20px;

    vertical-align: middle;

    border-bottom:
        1px solid #edf1f8;

    color: #1f2a4a;

    font-weight: 500;
}


.status-badge {

    display: inline-block;

    font-weight: 600;

    font-size: 13px;

    padding: 4px 16px;

    border-radius: 40px;
}


.status-badge.normal {

    background: #d4f0d4;

    color: #1a6e1a;
}


.status-badge.low {

    background: #fde8b0;

    color: #8a6d00;
}


.status-badge.critical {

    background: #fde8e8;

    color: var(--accent);
}


.expired-stock-note {

    display: block;

    margin-top: 4px;

    color: #dc3545;

    font-size: 12px;

    font-weight: 600;
}


.action-icons i {

    font-size: 18px;

    color: var(--primary);

    margin-right: 12px;

    cursor: pointer;

    opacity: 0.7;
}


.action-icons i:hover {

    opacity: 1;
}


.search-wrap {

    position: relative;

    max-width: 380px;

    margin-bottom: 16px;
}


.search-wrap i {

    position: absolute;

    left: 14px;

    top: 50%;

    transform:
        translateY(-50%);

    color: #7a85a8;

    font-size: 18px;
}


.search-wrap input {

    width: 100%;

    padding:
        10px
        12px
        10px
        44px;

    border:
        1px solid #d0d7e8;

    border-radius: 10px;

    font-size: 15px;

    background: white;

    outline: none;
}


.modal-content {

    border-radius: 18px;

    border: none;

    box-shadow:
        0 10px 40px
        rgba(0, 0, 0, 0.15);
}


.modal-header {

    background: var(--primary);

    border-bottom:
        1px solid #edf1f8;

    padding: 20px 28px;
}


.modal-header .modal-title {

    font-weight: 700;

    color: white;

    font-size: 20px;
}


.modal-body {

    padding: 24px 28px;
}


.modal-footer {

    border-top:
        1px solid #edf1f8;

    padding: 16px 28px;
}


.modal .form-label {

    font-weight: 600;

    color: var(--primary);

    font-size: 14px;
}


.modal .form-control,
.modal .form-select {

    border-radius: 10px;

    border:
        1px solid #d0d7e8;

    padding: 10px 16px;
}


.btn-save {

    background: var(--primary);

    color: white;

    border: none;

    border-radius: 40px;

    padding: 10px 32px;

    font-weight: 600;
}


.btn-save:hover {

    background: #1d2863;

    color: white;
}


.btn-cancel {

    background: var(--card-bg);

    color: var(--primary);

    border: none;

    border-radius: 40px;

    padding: 10px 28px;

    font-weight: 600;
}


.consumption-row {

    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 12px 20px;

    padding: 8px 0;

    border-bottom:
        1px solid #edf1f8;
}


.consumption-row .item-label {

    font-weight: 600;

    color: var(--primary);

    min-width: 120px;
}


.consumption-row .item-input {

    width: 100px;

    border:
        1px solid #d0d7e8;

    border-radius: 10px;

    padding: 6px 12px;

    text-align: center;
}


.info-box {

    background: #f4f6ff;

    border:
        1px solid #dce2f7;

    border-radius: 12px;

    padding: 12px 14px;

    color: #526079;

    font-size: 13px;
}


.toast-container {

    position: fixed;

    top: 20px;

    right: 20px;

    z-index: 9999;
}


.toast-custom {

    background: white;

    border-radius: 12px;

    padding: 16px 24px;

    box-shadow:
        0 8px 30px
        rgba(0,0,0,0.15);

    border-left:
        6px solid #28a745;

    display: flex;

    align-items: center;

    gap: 14px;

    min-width: 320px;

    margin-bottom: 10px;
}


.toast-custom.error {

    border-left-color:
        #dc3545;
}


.toast-custom .toast-icon {

    font-size: 28px;

    color: #28a745;
}


.toast-custom.error .toast-icon {

    color: #dc3545;
}


.toast-custom .toast-msg {

    font-weight: 500;

    color: #1f2a4a;

    flex: 1;
}


.toast-custom .toast-close {

    background: none;

    border: none;

    font-size: 22px;

    color: #999;

    cursor: pointer;
}


@media (max-width: 991px) {

    .main {

        margin-left: 90px;
    }
}


@media (max-width: 576px) {

    .topbar {

        padding: 0 16px;

        height: 70px;
    }


    .content {

        padding: 20px 16px;
    }


    .table-wrap {

        overflow-x: auto;
    }


    .search-wrap {

        max-width: 100%;
    }
}

</style>

</head>


<body>


<div
    class="toast-container"
    id="toastContainer">
</div>


<!-- ======================================================== -->
<!-- SIDEBAR -->
<!-- ======================================================== -->

<div class="sidebar">


    <div class="logo-area">


        <div class="logo-frame">

            <img
                src="logo.png"
                alt="Smart Bite Care Logo"
                class="logo"
            >

        </div>


        <div class="system-name">

            Smart Bite Care

        </div>

    </div>


    <nav class="nav-menu">

        <ul>


            <li>

                <a href="Nurse_Dashboard.php">

                    <i class="bi bi-grid-fill"></i>

                    <span>
                        Dashboard
                    </span>

                </a>

            </li>


            <li>

                <a href="Nurse_Patients.php">

                    <i class="bi bi-heart-pulse-fill"></i>

                    <span>
                        Patients
                    </span>

                </a>

            </li>


            <li>

                <a href="Nurse_Vaccination.php">

                    <i class="bi bi-shield-plus"></i>

                    <span>
                        Vaccination
                    </span>

                </a>

            </li>


            <li>

                <a
                    class="active"
                    href="Nurse_MedicalSuppliesManagement.php"
                >

                    <i class="bi bi-calendar-check"></i>

                    <span>
                        Medical Supplies Management
                    </span>

                </a>

            </li>


            <li>

                <a href="Nurse_Supplyforecasting.php">

                    <i class="bi bi-box-seam"></i>

                    <span>
                        Supply Forecasting
                    </span>

                </a>

            </li>


            <li>

                <a href="Nurse_Notification.php">

                    <i class="bi bi-bell-fill"></i>

                    <span>
                        Notifications
                    </span>

                </a>

            </li>


        </ul>

    </nav>


    <div class="logout">

        <a href="logout.php">

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </div>


</div>


<!-- ======================================================== -->
<!-- MAIN -->
<!-- ======================================================== -->

<div class="main">


    <div class="topbar">


        <div class="d-flex align-items-center">

            <h3>
                Medical Supplies Management
            </h3>


            <span class="branch-label">

                <?php
                echo h(
                    $branch_name
                );
                ?>

            </span>

        </div>


        <div class="profile">

            <i class="bi bi-person-circle"></i>


            <?php
            echo h(
                $username
            );
            ?>


            <span class="text-muted">

                | Nurse

            </span>

        </div>


    </div>


    <div class="content">


        <!-- FUNCTION BUTTONS -->

        <div class="function-buttons">


            <button
                class="btn-function"
                data-bs-toggle="modal"
                data-bs-target="#recordUsageModal"
            >

                <i class="bi bi-pencil-square"></i>

                Record Usage

            </button>


            <button
                class="btn-function"
                data-bs-toggle="modal"
                data-bs-target="#dailyConsumptionModal"
            >

                <i class="bi bi-clipboard2-check"></i>

                Record Daily Consumption

            </button>


            <button
                class="btn-function"
                data-bs-toggle="modal"
                data-bs-target="#requestRestockModal"
            >

                <i class="bi bi-box-arrow-up-right"></i>

                Request Restock

            </button>


        </div>


        <!-- ================================================= -->
        <!-- STATS -->
        <!-- ================================================= -->

        <div class="row g-4 mb-4">


            <div class="col-md-3 col-6">

                <div class="stat-card total">

                    <div class="stat-icon">

                        <i class="bi bi-box-seam"></i>

                    </div>


                    <div class="stat-title">

                        Total Supplies

                    </div>


                    <div class="stat-number">

                        <?php
                        echo
                        (int) (
                            $stats[
                                'total_supplies'
                            ]
                            ?? 0
                        );
                        ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3 col-6">

                <div class="stat-card low">

                    <div class="stat-icon">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                    </div>


                    <div class="stat-title">

                        Low Stocks

                    </div>


                    <div class="stat-number">

                        <?php
                        echo
                        (int) (
                            $stats[
                                'low_stock'
                            ]
                            ?? 0
                        );
                        ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3 col-6">

                <div class="stat-card out">

                    <div class="stat-icon">

                        <i class="bi bi-box-seam"></i>

                    </div>


                    <div class="stat-title">

                        Out of Stock

                    </div>


                    <div class="stat-number">

                        <?php
                        echo
                        (int) (
                            $stats[
                                'out_of_stock'
                            ]
                            ?? 0
                        );
                        ?>

                    </div>

                </div>

            </div>


            <div class="col-md-3 col-6">

                <div class="stat-card expiring">

                    <div class="stat-icon">

                        <i class="bi bi-clock"></i>

                    </div>


                    <div class="stat-title">

                        Expiring Stocks

                    </div>


                    <div class="stat-number">

                        <?php
                        echo
                        (int) (
                            $stats[
                                'expiring_soon'
                            ]
                            ?? 0
                        );
                        ?>

                    </div>

                </div>

            </div>


        </div>


        <div class="row g-4 mb-4">


            <div class="col-md-4 col-6">

                <div class="stat-card usage">

                    <div class="stat-icon">

                        <i class="bi bi-bar-chart"></i>

                    </div>


                    <div class="stat-title">

                        Today's Usage

                    </div>


                    <div class="stat-number">

                        <?php
                        echo
                        (int) (
                            $today_stats[
                                'total_used'
                            ]
                            ?? 0
                        );
                        ?>

                    </div>

                </div>

            </div>


            <div class="col-md-4 col-6">

                <div class="stat-card patients">

                    <div class="stat-icon">

                        <i class="bi bi-people"></i>

                    </div>


                    <div class="stat-title">

                        Today's Vaccinated Patients

                    </div>


                    <div class="stat-number">

                        <?php
                        echo
                        (int) (
                            $today_stats[
                                'patients_served'
                            ]
                            ?? 0
                        );
                        ?>

                    </div>

                </div>

            </div>


            <div class="col-md-4 col-6">

                <div class="stat-card restock">

                    <div class="stat-icon">

                        <i class="bi bi-arrow-repeat"></i>

                    </div>


                    <div class="stat-title">

                        Restock Requests

                    </div>


                    <div class="stat-number">

                        <?php
                        echo
                        (int) (
                            $restock_data[
                                'requests'
                            ]
                            ?? 0
                        );
                        ?>

                    </div>

                </div>

            </div>


        </div>


        <!-- SEARCH -->

        <div class="search-wrap">


            <i class="bi bi-search"></i>


            <input

                type="text"

                id="searchInput"

                placeholder="Search supplies..."

                onkeyup="filterTable()"

            >


        </div>


        <!-- ================================================= -->
        <!-- SUPPLIES TABLE -->
        <!-- ================================================= -->

        <div class="table-wrap">


            <table
                class="table align-middle"
                id="suppliesTable"
            >


                <thead>

                    <tr>

                        <th>
                            Item Name
                        </th>

                        <th>
                            Stock
                        </th>

                        <th>
                            Unit
                        </th>

                        <th>
                            Min Stock
                        </th>

                        <th>
                            Nearest Expiry
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Actions
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (count($items) > 0): ?>


                    <?php foreach ($items as $item): ?>


                        <?php


                        $current_stock =
                            (int) $item['current_stock'];


                        $minimum_stock =
                            (int) $item['minimum_stock'];


                        $status =
                            'Normal';


                        $status_class =
                            'normal';


                        if ($current_stock <= 0) {

                            $status =
                                'Out of Stock';

                            $status_class =
                                'critical';

                        } elseif (
                            $current_stock
                            <=
                            $minimum_stock
                        ) {

                            $status =
                                'Low';

                            $status_class =
                                'low';
                        }


                        $expiry_text =
                            'N/A';


                        $expiry_class =
                            '';


                        if (
                            !empty(
                                $item['expiration_date']
                            )
                        ) {

                            $expiry =
                                new DateTime(
                                    $item[
                                        'expiration_date'
                                    ]
                                );


                            $today =
                                new DateTime(
                                    'today'
                                );


                            if (
                                $expiry
                                <
                                $today
                            ) {

                                $expiry_text =
                                    'Expired';

                                $expiry_class =
                                    'text-danger fw-bold';

                            } else {


                                $days =
                                    (int)
                                    $today
                                    ->diff(
                                        $expiry
                                    )
                                    ->days;


                                $expiry_text =
                                    $expiry->format(
                                        'M d, Y'
                                    );


                                if ($days <= 30) {

                                    $expiry_text .=
                                        " ($days days)";

                                    $expiry_class =
                                        'text-danger fw-bold';
                                }
                            }
                        }


                        ?>


                        <tr>


                            <td>

                                <strong>

                                    <?php
                                    echo h(
                                        $item[
                                            'item_name'
                                        ]
                                    );
                                    ?>

                                </strong>

                            </td>


                            <td>

                                <?php
                                echo
                                $current_stock;
                                ?>

                                <?php if ((int)($item['expired_stock'] ?? 0) > 0): ?>

                                    <span class="expired-stock-note">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                        <?php echo (int)$item['expired_stock']; ?>
                                        <?php echo h($item['unit_name'] ?? ''); ?>
                                        expired
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php
                                echo h(
                                    $item[
                                        'unit_name'
                                    ]
                                    ?? 'N/A'
                                );
                                ?>

                            </td>


                            <td>

                                <?php
                                echo
                                $minimum_stock;
                                ?>

                            </td>


                            <td
                                class="<?php
                                echo h(
                                    $expiry_class
                                );
                                ?>"
                            >

                                <?php
                                echo h(
                                    $expiry_text
                                );
                                ?>

                            </td>


                            <td>

                                <span
                                    class="status-badge <?php
                                    echo h(
                                        $status_class
                                    );
                                    ?>"
                                >

                                    <?php
                                    echo h(
                                        $status
                                    );
                                    ?>

                                </span>

                            </td>


                            <td class="action-icons">


                                <i

                                    class="bi bi-pencil-square usage-action"

                                    data-item-id="<?php
                                    echo
                                    (int)
                                    $item['item_id'];
                                    ?>"

                                    data-bs-toggle="modal"

                                    data-bs-target="#recordUsageModal"

                                    title="Record Usage"

                                ></i>


                                <i

                                    class="bi bi-box-arrow-up-right restock-action"

                                    data-item-id="<?php
                                    echo
                                    (int)
                                    $item['item_id'];
                                    ?>"

                                    data-bs-toggle="modal"

                                    data-bs-target="#requestRestockModal"

                                    title="Request Restock"

                                ></i>


                            </td>


                        </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="7"
                            class="text-center py-4 text-muted"
                        >

                            <i
                                class="bi bi-inbox fs-2 d-block mb-2"
                            ></i>

                            No Medical Supplies items found.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>


            </table>


        </div>


    </div>

</div>


<!-- ======================================================== -->
<!-- RECORD USAGE MODAL -->
<!-- ======================================================== -->

<div
    class="modal fade"
    id="recordUsageModal"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog modal-dialog-centered">


        <div class="modal-content">


            <div class="modal-header">


                <h5 class="modal-title">

                    <i class="bi bi-pencil-square me-2"></i>

                    Record Medical Supply Usage

                </h5>


                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                ></button>


            </div>


            <form
                method="POST"
                action="Nurse_MedicalSuppliesManagement.php"
            >


                <div class="modal-body">


                    <input
                        type="hidden"
                        name="record_usage"
                        value="1"
                    >


                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php
                        echo h(
                            $_SESSION[
                                'csrf_token'
                            ]
                        );
                        ?>"
                    >


                    <div class="info-box mb-3">

                        Stock is automatically deducted from
                        the earliest valid batch first using
                        FEFO. Expired batches are not used.

                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Patient *

                        </label>


                        <select

                            class="form-select"

                            name="patient_id"

                            id="usagePatient"

                            required

                        >


                            <option value="">

                                Select Patient...

                            </option>


                            <?php
                            if ($patients_result):
                            ?>


                                <?php
                                while (
                                    $patient =
                                    $patients_result
                                    ->fetch_assoc()
                                ):
                                ?>


                                    <option
                                        value="<?php
                                        echo
                                        (int)
                                        $patient[
                                            'patient_id'
                                        ];
                                        ?>"
                                    >

                                        <?php
                                        echo h(
                                            $patient[
                                                'full_name'
                                            ]
                                        );
                                        ?>

                                    </option>


                                <?php endwhile; ?>


                            <?php endif; ?>


                        </select>


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Case

                        </label>


                        <select

                            class="form-select"

                            name="case_id"

                            id="usageCase"

                        >


                            <option value="0">

                                No Case

                            </option>


                            <?php foreach ($cases as $case): ?>


                                <option

                                    value="<?php
                                    echo
                                    (int)
                                    $case['case_id'];
                                    ?>"

                                    data-patient-id="<?php
                                    echo
                                    (int)
                                    $case['patient_id'];
                                    ?>"

                                >

                                    Case #<?php
                                    echo
                                    (int)
                                    $case['case_id'];
                                    ?>

                                </option>


                            <?php endforeach; ?>


                        </select>


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Supply *

                        </label>


                        <select

                            class="form-select"

                            name="item_id"

                            id="usageItem"

                            required

                        >


                            <option value="">

                                Select Supply...

                            </option>


                            <?php foreach ($items as $item): ?>


                                <option

                                    value="<?php
                                    echo
                                    (int)
                                    $item['item_id'];
                                    ?>"

                                >

                                    <?php
                                    echo h(
                                        $item['item_name']
                                    );
                                    ?>

                                    (Stock:

                                    <?php
                                    echo
                                    (int)
                                    $item[
                                        'current_stock'
                                    ];
                                    ?>

                                    <?php
                                    echo h(
                                        $item[
                                            'unit_name'
                                        ]
                                    );
                                    ?>)

                                </option>


                            <?php endforeach; ?>


                        </select>


                        <div
                            class="form-text"
                            id="usageStockText"
                        ></div>


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Reason *

                        </label>


                        <select

                            class="form-select"

                            name="reason"

                            id="usageReason"

                            required

                        >


                            <option value="Vaccination">

                                Vaccination

                            </option>


                            <option value="Wound Care">

                                Wound Care

                            </option>


                            <option value="Consultation">

                                Consultation

                            </option>


                            <option value="Emergency">

                                Emergency

                            </option>


                            <option value="Other">

                                Other

                            </option>


                        </select>


                    </div>


                    <div
                        class="mb-3"
                        id="doseWrapper"
                    >


                        <label class="form-label">

                            Dose Number *

                        </label>


                        <select

                            class="form-select"

                            name="dose_number"

                            id="doseNumber"

                        >


                            <option value="1">

                                Dose 1

                            </option>


                            <option value="2">

                                Dose 2

                            </option>


                            <option value="3">

                                Dose 3

                            </option>


                            <option value="4">

                                Booster

                            </option>


                        </select>


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Quantity Used *

                        </label>


                        <input

                            type="number"

                            class="form-control"

                            name="quantity_used"

                            id="usageQuantity"

                            value="1"

                            min="1"

                            required

                        >


                    </div>


                    <div class="mb-0">


                        <label class="form-label">

                            Date Administered / Used *

                        </label>


                        <input

                            type="date"

                            class="form-control"

                            name="date_administered"

                            value="<?php
                            echo date(
                                'Y-m-d'
                            );
                            ?>"

                            required

                        >


                    </div>


                </div>


                <div class="modal-footer">


                    <button

                        type="button"

                        class="btn-cancel"

                        data-bs-dismiss="modal"

                    >

                        Cancel

                    </button>


                    <button

                        type="submit"

                        class="btn-save"

                    >

                        <i class="bi bi-check-lg me-2"></i>

                        Save Usage

                    </button>


                </div>


            </form>


        </div>


    </div>


</div>


<!-- ======================================================== -->
<!-- DAILY CONSUMPTION MODAL -->
<!-- ======================================================== -->

<div
    class="modal fade"
    id="dailyConsumptionModal"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog modal-dialog-centered modal-lg">


        <div class="modal-content">


            <div class="modal-header">


                <h5 class="modal-title">

                    <i class="bi bi-clipboard2-check me-2"></i>

                    Daily Consumption Summary

                </h5>


                <button

                    type="button"

                    class="btn-close btn-close-white"

                    data-bs-dismiss="modal"

                ></button>


            </div>


            <form
                method="POST"
                action="Nurse_MedicalSuppliesManagement.php"
            >


                <div class="modal-body">


                    <input

                        type="hidden"

                        name="save_daily_consumption"

                        value="1"

                    >


                    <input

                        type="hidden"

                        name="csrf_token"

                        value="<?php
                        echo h(
                            $_SESSION[
                                'csrf_token'
                            ]
                        );
                        ?>"

                    >


                    <div class="info-box mb-3">

                        This form records a daily usage
                        summary only.

                        It does not subtract stock again.

                        Use Record Usage for actual
                        inventory deduction.

                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Consumption Date

                        </label>


                        <input

                            type="date"

                            class="form-control"

                            name="consumption_date"

                            value="<?php
                            echo date(
                                'Y-m-d'
                            );
                            ?>"

                            required

                        >


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Patients Served Today

                        </label>


                        <input

                            type="number"

                            class="form-control"

                            name="patient_count"

                            value="0"

                            min="0"

                            required

                        >


                    </div>


                    <hr>


                    <p class="text-muted small">

                        Enter summary quantity used
                        for each supply:

                    </p>


                    <?php foreach (
                        $consumption_items
                        as $item
                    ): ?>


                        <div class="consumption-row">


                            <span class="item-label">

                                <?php
                                echo h(
                                    $item[
                                        'item_name'
                                    ]
                                );
                                ?>

                            </span>


                            <span>

                                Used:

                            </span>


                            <input

                                type="number"

                                class="item-input"

                                name="consumption[<?php
                                echo
                                (int)
                                $item['item_id'];
                                ?>]"

                                value="0"

                                min="0"

                            >


                            <span
                                style="
                                font-size:12px;
                                color:#8a96b8;
                                "
                            >

                                (
                                <?php
                                echo
                                (int)
                                $item[
                                    'current_stock'
                                ];
                                ?>

                                <?php
                                echo h(
                                    $item[
                                        'unit_name'
                                    ]
                                );
                                ?>

                                currently available)

                            </span>


                        </div>


                    <?php endforeach; ?>


                </div>


                <div class="modal-footer">


                    <button

                        type="button"

                        class="btn-cancel"

                        data-bs-dismiss="modal"

                    >

                        Cancel

                    </button>


                    <button

                        type="submit"

                        class="btn-save"

                    >

                        <i class="bi bi-check-lg me-2"></i>

                        Save Summary

                    </button>


                </div>


            </form>


        </div>


    </div>


</div>


<!-- ======================================================== -->
<!-- RESTOCK MODAL -->
<!-- ======================================================== -->

<div
    class="modal fade"
    id="requestRestockModal"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog modal-dialog-centered">


        <div class="modal-content">


            <div class="modal-header">


                <h5 class="modal-title">

                    <i class="bi bi-box-arrow-up-right me-2"></i>

                    Request Restock

                </h5>


                <button

                    type="button"

                    class="btn-close btn-close-white"

                    data-bs-dismiss="modal"

                ></button>


            </div>


            <form
                method="POST"
                action="Nurse_MedicalSuppliesManagement.php"
            >


                <div class="modal-body">


                    <input

                        type="hidden"

                        name="request_restock"

                        value="1"

                    >


                    <input

                        type="hidden"

                        name="csrf_token"

                        value="<?php
                        echo h(
                            $_SESSION[
                                'csrf_token'
                            ]
                        );
                        ?>"

                    >


                    <div class="info-box mb-3">

                        The request will be sent to
                        the Inventory Officer assigned
                        to

                        <strong>
                            <?php
                            echo h(
                                $branch_name
                            );
                            ?>
                        </strong>.

                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Item *

                        </label>


                        <select

                            class="form-select"

                            name="item_id"

                            id="restockItem"

                            required

                        >


                            <option value="">

                                Select Item...

                            </option>


                            <?php foreach ($items as $item): ?>


                                <option

                                    value="<?php
                                    echo
                                    (int)
                                    $item['item_id'];
                                    ?>"

                                >

                                    <?php
                                    echo h(
                                        $item[
                                            'item_name'
                                        ]
                                    );
                                    ?>

                                </option>


                            <?php endforeach; ?>


                        </select>


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Current Stock

                        </label>


                        <input

                            type="text"

                            class="form-control"

                            id="currentStockDisplay"

                            disabled

                        >


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Minimum Stock

                        </label>


                        <input

                            type="text"

                            class="form-control"

                            id="minimumStockDisplay"

                            disabled

                        >


                    </div>


                    <div class="mb-3">


                        <label class="form-label">

                            Requested Quantity *

                        </label>


                        <input

                            type="number"

                            class="form-control"

                            name="requested_quantity"

                            value="50"

                            min="1"

                            required

                        >


                    </div>


                    <div class="mb-0">


                        <label class="form-label">

                            Reason

                        </label>


                        <textarea

                            class="form-control"

                            name="reason"

                            rows="3"

                            maxlength="500"

                            placeholder="Why do you need this restock?"

                        >Low stock due to increasing patient cases.</textarea>


                    </div>


                </div>


                <div class="modal-footer">


                    <button

                        type="button"

                        class="btn-cancel"

                        data-bs-dismiss="modal"

                    >

                        Cancel

                    </button>


                    <button

                        type="submit"

                        class="btn-save"

                    >

                        <i class="bi bi-send me-2"></i>

                        Submit Request

                    </button>


                </div>


            </form>


        </div>


    </div>


</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>


<script>


// ============================================================
// INVENTORY ITEM DATA
// ============================================================

const itemStockMap = <?php

echo json_encode(

    $item_stock_map,

    JSON_HEX_TAG
    |
    JSON_HEX_APOS
    |
    JSON_HEX_AMP
    |
    JSON_HEX_QUOT

);

?>;


// ============================================================
// SEARCH TABLE
// ============================================================

function filterTable() {


    const input =
        document.getElementById(
            'searchInput'
        );


    const filter =
        input.value
        .toLowerCase();


    const table =
        document.getElementById(
            'suppliesTable'
        );


    const rows =
        table
        .getElementsByTagName(
            'tr'
        );


    for (
        let i = 1;
        i < rows.length;
        i++
    ) {


        const cells =
            rows[i]
            .getElementsByTagName(
                'td'
            );


        let found =
            false;


        for (
            let j = 0;
            j < cells.length - 1;
            j++
        ) {


            const text =
                cells[j]
                .textContent
                .toLowerCase();


            if (
                text.indexOf(
                    filter
                )
                >
                -1
            ) {

                found = true;

                break;
            }
        }


        rows[i].style.display =
            found
            ? ''
            : 'none';
    }
}


// ============================================================
// USAGE MODAL
// ============================================================

const usageItem =
    document.getElementById(
        'usageItem'
    );


const usageReason =
    document.getElementById(
        'usageReason'
    );


const doseWrapper =
    document.getElementById(
        'doseWrapper'
    );


const doseNumber =
    document.getElementById(
        'doseNumber'
    );


const usageStockText =
    document.getElementById(
        'usageStockText'
    );


const usageQuantity =
    document.getElementById(
        'usageQuantity'
    );


function updateUsageReasonUI() {


    const vaccination =
        usageReason.value
        ===
        'Vaccination';


    doseWrapper.style.display =
        vaccination
        ? ''
        : 'none';


    doseNumber.required =
        vaccination;
}


function updateUsageStock() {


    const itemId =
        usageItem.value;


    if (
        !itemId
        ||
        !itemStockMap[itemId]
    ) {

        usageStockText.textContent =
            '';

        usageQuantity
        .removeAttribute(
            'max'
        );

        return;
    }


    const data =
        itemStockMap[itemId];


    usageStockText.textContent =

        'Available: '
        +
        data.stock
        +
        ' '
        +
        data.unit

        +
        ' | Minimum Stock: '
        +
        data.minimum_stock
        +
        ' '
        +
        data.unit;


    usageQuantity.max =
        data.stock;
}


usageReason
.addEventListener(
    'change',
    updateUsageReasonUI
);


usageItem
.addEventListener(
    'change',
    updateUsageStock
);


updateUsageReasonUI();

updateUsageStock();


// ============================================================
// FILTER CASES BY PATIENT
// ============================================================

const usagePatient =
    document.getElementById(
        'usagePatient'
    );


const usageCase =
    document.getElementById(
        'usageCase'
    );


usagePatient
.addEventListener(
    'change',
    function () {


        const patientId =
            parseInt(
                this.value
                || '0',
                10
            );


        for (
            const option
            of usageCase.options
        ) {


            if (
                option.value
                ===
                '0'
            ) {

                option.hidden =
                    false;

                continue;
            }


            const optionPatient =
                parseInt(
                    option.dataset.patientId
                    || '0',
                    10
                );


            option.hidden =

                patientId > 0

                &&

                optionPatient
                !==
                patientId;
        }


        const selected =
            usageCase.options[
                usageCase.selectedIndex
            ];


        if (
            selected
            &&
            selected.value !== '0'
            &&
            parseInt(
                selected.dataset.patientId
                || '0',
                10
            )
            !==
            patientId
        ) {

            usageCase.value =
                '0';
        }
    }
);


// ============================================================
// RESTOCK MODAL
// ============================================================

const restockItem =
    document.getElementById(
        'restockItem'
    );


const currentStockDisplay =
    document.getElementById(
        'currentStockDisplay'
    );


const minimumStockDisplay =
    document.getElementById(
        'minimumStockDisplay'
    );


function updateRestockDisplay() {


    const itemId =
        restockItem.value;


    if (
        !itemId
        ||
        !itemStockMap[itemId]
    ) {

        currentStockDisplay.value =
            '';

        minimumStockDisplay.value =
            '';

        return;
    }


    const data =
        itemStockMap[itemId];


    currentStockDisplay.value =

        data.stock
        +
        ' '
        +
        data.unit;


    minimumStockDisplay.value =

        data.minimum_stock
        +
        ' '
        +
        data.unit;
}


restockItem
.addEventListener(
    'change',
    updateRestockDisplay
);


// ============================================================
// TABLE ACTION ICONS
// ============================================================

document
.querySelectorAll(
    '.usage-action'
)
.forEach(
    function (icon) {


        icon.addEventListener(
            'click',
            function () {


                usageItem.value =
                    this.dataset.itemId;


                updateUsageStock();
            }
        );
    }
);


document
.querySelectorAll(
    '.restock-action'
)
.forEach(
    function (icon) {


        icon.addEventListener(
            'click',
            function () {


                restockItem.value =
                    this.dataset.itemId;


                updateRestockDisplay();
            }
        );
    }
);


// ============================================================
// TOAST
// ============================================================

function showToast(
    message,
    type = 'success'
) {


    const container =
        document.getElementById(
            'toastContainer'
        );


    const toast =
        document.createElement(
            'div'
        );


    toast.className =
        'toast-custom'
        +
        (
            type === 'error'
            ?
            ' error'
            :
            ''
        );


    const icon =
        document.createElement(
            'span'
        );


    icon.className =
        'toast-icon';


    icon.innerHTML =

        type === 'error'

        ?

        '<i class="bi bi-x-circle-fill"></i>'

        :

        '<i class="bi bi-check-circle-fill"></i>';


    const msg =
        document.createElement(
            'span'
        );


    msg.className =
        'toast-msg';


    msg.textContent =
        message;


    const close =
        document.createElement(
            'button'
        );


    close.className =
        'toast-close';


    close.innerHTML =
        '&times;';


    close.onclick =
        function () {

            toast.remove();
        };


    toast.appendChild(
        icon
    );


    toast.appendChild(
        msg
    );


    toast.appendChild(
        close
    );


    container.appendChild(
        toast
    );


    setTimeout(
        function () {

            if (
                toast.parentElement
            ) {

                toast.remove();
            }

        },
        5000
    );
}


<?php if ($success_msg): ?>


showToast(

    <?php
    echo json_encode(
        $success_msg
    );
    ?>,

    'success'

);


<?php endif; ?>


<?php if ($error_msg): ?>


showToast(

    <?php
    echo json_encode(
        $error_msg
    );
    ?>,

    'error'

);


<?php endif; ?>


</script>


</body>

</html>