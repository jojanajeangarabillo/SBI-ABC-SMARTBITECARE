<?php

session_start();

require_once 'sources/db_connect.php';


/* =========================================================
   ACCESS CONTROL
   ========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    (int)$_SESSION['role_id'] !== 5
) {
    header("Location: login.php");
    exit();
}


/* =========================================================
   ESCAPE HELPER
   ========================================================= */

function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   CSRF TOKEN
   ========================================================= */

if (empty($_SESSION['inventory_items_csrf'])) {
    $_SESSION['inventory_items_csrf'] =
        bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['inventory_items_csrf'];


/* =========================================================
   BASIC VARIABLES
   ========================================================= */

$success_msg = '';
$error_msg = '';

$user_id = (int)$_SESSION['user_id'];

$username = 'Inventory Officer';
$branch_id = null;
$branch_name = 'No Branch Assigned';


/* =========================================================
   LOAD CURRENT INVENTORY OFFICER
   ========================================================= */

$userQuery = "
    SELECT
        u.user_id,
        u.username,
        u.branch_id,
        b.branch_name
    FROM users u

    LEFT JOIN branches b
        ON u.branch_id = b.branch_id

    WHERE u.user_id = ?
      AND u.role_id = 5

    LIMIT 1
";

$userStmt = $conn->prepare($userQuery);

if (!$userStmt) {
    die("Database error while loading user information.");
}

$userStmt->bind_param(
    "i",
    $user_id
);

$userStmt->execute();

$userResult = $userStmt->get_result();

if ($userResult->num_rows === 1) {

    $userData = $userResult->fetch_assoc();

    $username =
        $userData['username']
        ?? 'Inventory Officer';

    $branch_id =
        $userData['branch_id']
        ?? null;

    $branch_name =
        $userData['branch_name']
        ?? 'No Branch Assigned';
}

$userStmt->close();


if (empty($branch_id)) {
    $branch_name = 'No Branch Assigned';
}


/* =========================================================
   AUDIT LOG
   ========================================================= */

function addInventoryAuditLog(
    $conn,
    $user_id,
    $branch_id,
    $action
) {
    $module = 'Inventory Items';

    $sql = "
        INSERT INTO audit_logs
        (
            user_id,
            branch_id,
            action,
            module
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?
        )
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "isss",
        $user_id,
        $branch_id,
        $action,
        $module
    );

    $result = $stmt->execute();

    $stmt->close();

    return $result;
}


/* =========================================================
   REDIRECT HELPER
   ========================================================= */

function redirectWithMessage(
    $type,
    $message
) {
    $url = $_SERVER['PHP_SELF'];

    if ($type === 'success') {

        $url .= '?success=' .
            urlencode($message);

    } else {

        $url .= '?error=' .
            urlencode($message);
    }

    header("Location: " . $url);
    exit();
}


/* =========================================================
   DISPLAY MESSAGES
   ========================================================= */

if (isset($_GET['success'])) {
    $success_msg =
        trim($_GET['success']);
}

if (isset($_GET['error'])) {
    $error_msg =
        trim($_GET['error']);
}


/* =========================================================
   HANDLE POST REQUESTS
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /* =====================================================
       CSRF CHECK
       ===================================================== */

    $postedToken =
        $_POST['csrf_token']
        ?? '';

    if (
        !isset($_SESSION['inventory_items_csrf']) ||
        !hash_equals(
            $_SESSION['inventory_items_csrf'],
            $postedToken
        )
    ) {
        redirectWithMessage(
            'error',
            'Invalid request. Please refresh the page and try again.'
        );
    }


    $action =
        $_POST['action']
        ?? '';


    /* =====================================================
       ADD INVENTORY ITEM
       ===================================================== */

    if ($action === 'add') {

        $category_id =
            filter_input(
                INPUT_POST,
                'category_id',
                FILTER_VALIDATE_INT
            );

        $unit_id =
            filter_input(
                INPUT_POST,
                'unit_id',
                FILTER_VALIDATE_INT
            );

        $item_name =
            trim(
                $_POST['item_name']
                ?? ''
            );

        $minimum_stock =
            filter_input(
                INPUT_POST,
                'minimum_stock',
                FILTER_VALIDATE_INT
            );

        $description =
            trim(
                $_POST['description']
                ?? ''
            );

        $is_predictable =
            isset($_POST['is_predictable'])
            ? 1
            : 0;


        /* -----------------------------------------------
           VALIDATION
           ----------------------------------------------- */

        if (
            !$category_id ||
            $category_id <= 0
        ) {
            redirectWithMessage(
                'error',
                'Please select a valid category.'
            );
        }


        if (
            !$unit_id ||
            $unit_id <= 0
        ) {
            redirectWithMessage(
                'error',
                'Please select a valid unit.'
            );
        }


        if ($item_name === '') {

            redirectWithMessage(
                'error',
                'Item name is required.'
            );
        }


        if (
            $minimum_stock === false ||
            $minimum_stock === null ||
            $minimum_stock < 0
        ) {
            redirectWithMessage(
                'error',
                'Minimum stock must be zero or greater.'
            );
        }


        /* -----------------------------------------------
           CHECK CATEGORY
           ----------------------------------------------- */

        $categoryCheck =
            $conn->prepare(
                "
                SELECT category_id
                FROM inventory_categories
                WHERE category_id = ?
                LIMIT 1
                "
            );

        if (!$categoryCheck) {

            redirectWithMessage(
                'error',
                'Unable to validate category.'
            );
        }

        $categoryCheck->bind_param(
            "i",
            $category_id
        );

        $categoryCheck->execute();

        $categoryExists =
            $categoryCheck
                ->get_result()
                ->num_rows > 0;

        $categoryCheck->close();


        if (!$categoryExists) {

            redirectWithMessage(
                'error',
                'Selected category does not exist.'
            );
        }


        /* -----------------------------------------------
           CHECK UNIT
           ----------------------------------------------- */

        $unitCheck =
            $conn->prepare(
                "
                SELECT unit_id
                FROM units
                WHERE unit_id = ?
                LIMIT 1
                "
            );

        if (!$unitCheck) {

            redirectWithMessage(
                'error',
                'Unable to validate unit.'
            );
        }

        $unitCheck->bind_param(
            "i",
            $unit_id
        );

        $unitCheck->execute();

        $unitExists =
            $unitCheck
                ->get_result()
                ->num_rows > 0;

        $unitCheck->close();


        if (!$unitExists) {

            redirectWithMessage(
                'error',
                'Selected unit does not exist.'
            );
        }


        /* -----------------------------------------------
           PREVENT DUPLICATE ITEM
           ----------------------------------------------- */

        $duplicateCheck =
            $conn->prepare(
                "
                SELECT item_id
                FROM inventory_items
                WHERE LOWER(TRIM(item_name))
                    = LOWER(TRIM(?))
                LIMIT 1
                "
            );

        if (!$duplicateCheck) {

            redirectWithMessage(
                'error',
                'Unable to check duplicate item.'
            );
        }


        $duplicateCheck->bind_param(
            "s",
            $item_name
        );

        $duplicateCheck->execute();

        $duplicateExists =
            $duplicateCheck
                ->get_result()
                ->num_rows > 0;

        $duplicateCheck->close();


        if ($duplicateExists) {

            redirectWithMessage(
                'error',
                'An inventory item with this name already exists.'
            );
        }


        /* -----------------------------------------------
           INSERT ITEM
           ----------------------------------------------- */

        $insertSQL = "
            INSERT INTO inventory_items
            (
                category_id,
                unit_id,
                item_name,
                minimum_stock,
                description,
                is_predictable
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ";

        $insertStmt =
            $conn->prepare($insertSQL);

        if (!$insertStmt) {

            redirectWithMessage(
                'error',
                'Unable to prepare item insertion.'
            );
        }


        $insertStmt->bind_param(
            "iisisi",
            $category_id,
            $unit_id,
            $item_name,
            $minimum_stock,
            $description,
            $is_predictable
        );


        if ($insertStmt->execute()) {

            $newItemId =
                $conn->insert_id;

            addInventoryAuditLog(
                $conn,
                $user_id,
                $branch_id,
                "Added inventory item: {$item_name} (ID: {$newItemId})"
            );

            $insertStmt->close();

            redirectWithMessage(
                'success',
                'Inventory item added successfully.'
            );

        } else {

            $dbError =
                $insertStmt->error;

            $insertStmt->close();

            redirectWithMessage(
                'error',
                'Unable to add inventory item: ' .
                $dbError
            );
        }
    }


    /* =====================================================
       UPDATE INVENTORY ITEM
       ===================================================== */

    if ($action === 'update') {

        $item_id =
            filter_input(
                INPUT_POST,
                'item_id',
                FILTER_VALIDATE_INT
            );

        $category_id =
            filter_input(
                INPUT_POST,
                'category_id',
                FILTER_VALIDATE_INT
            );

        $unit_id =
            filter_input(
                INPUT_POST,
                'unit_id',
                FILTER_VALIDATE_INT
            );

        $item_name =
            trim(
                $_POST['item_name']
                ?? ''
            );

        $minimum_stock =
            filter_input(
                INPUT_POST,
                'minimum_stock',
                FILTER_VALIDATE_INT
            );

        $description =
            trim(
                $_POST['description']
                ?? ''
            );

        $is_predictable =
            isset($_POST['is_predictable'])
            ? 1
            : 0;


        if (
            !$item_id ||
            $item_id <= 0
        ) {
            redirectWithMessage(
                'error',
                'Invalid inventory item.'
            );
        }


        if (
            !$category_id ||
            $category_id <= 0
        ) {
            redirectWithMessage(
                'error',
                'Please select a valid category.'
            );
        }


        if (
            !$unit_id ||
            $unit_id <= 0
        ) {
            redirectWithMessage(
                'error',
                'Please select a valid unit.'
            );
        }


        if ($item_name === '') {

            redirectWithMessage(
                'error',
                'Item name is required.'
            );
        }


        if (
            $minimum_stock === false ||
            $minimum_stock === null ||
            $minimum_stock < 0
        ) {
            redirectWithMessage(
                'error',
                'Minimum stock must be zero or greater.'
            );
        }


        /* -----------------------------------------------
           LOAD OLD ITEM
           ----------------------------------------------- */

        $oldStmt =
            $conn->prepare(
                "
                SELECT
                    item_name,
                    category_id,
                    unit_id,
                    minimum_stock,
                    description,
                    is_predictable
                FROM inventory_items
                WHERE item_id = ?
                LIMIT 1
                "
            );

        if (!$oldStmt) {

            redirectWithMessage(
                'error',
                'Unable to retrieve existing item.'
            );
        }


        $oldStmt->bind_param(
            "i",
            $item_id
        );

        $oldStmt->execute();

        $oldItem =
            $oldStmt
                ->get_result()
                ->fetch_assoc();

        $oldStmt->close();


        if (!$oldItem) {

            redirectWithMessage(
                'error',
                'Inventory item not found.'
            );
        }


        /* -----------------------------------------------
           VERIFY CATEGORY
           ----------------------------------------------- */

        $categoryCheck =
            $conn->prepare(
                "
                SELECT category_id
                FROM inventory_categories
                WHERE category_id = ?
                LIMIT 1
                "
            );

        if (!$categoryCheck) {

            redirectWithMessage(
                'error',
                'Unable to validate category.'
            );
        }


        $categoryCheck->bind_param(
            "i",
            $category_id
        );

        $categoryCheck->execute();

        $categoryExists =
            $categoryCheck
                ->get_result()
                ->num_rows > 0;

        $categoryCheck->close();


        if (!$categoryExists) {

            redirectWithMessage(
                'error',
                'Selected category does not exist.'
            );
        }


        /* -----------------------------------------------
           VERIFY UNIT
           ----------------------------------------------- */

        $unitCheck =
            $conn->prepare(
                "
                SELECT unit_id
                FROM units
                WHERE unit_id = ?
                LIMIT 1
                "
            );

        if (!$unitCheck) {

            redirectWithMessage(
                'error',
                'Unable to validate unit.'
            );
        }


        $unitCheck->bind_param(
            "i",
            $unit_id
        );

        $unitCheck->execute();

        $unitExists =
            $unitCheck
                ->get_result()
                ->num_rows > 0;

        $unitCheck->close();


        if (!$unitExists) {

            redirectWithMessage(
                'error',
                'Selected unit does not exist.'
            );
        }


        /* -----------------------------------------------
           DUPLICATE NAME CHECK
           ----------------------------------------------- */

        $duplicateCheck =
            $conn->prepare(
                "
                SELECT item_id
                FROM inventory_items
                WHERE LOWER(TRIM(item_name))
                    = LOWER(TRIM(?))
                  AND item_id <> ?
                LIMIT 1
                "
            );

        if (!$duplicateCheck) {

            redirectWithMessage(
                'error',
                'Unable to check duplicate item.'
            );
        }


        $duplicateCheck->bind_param(
            "si",
            $item_name,
            $item_id
        );

        $duplicateCheck->execute();

        $duplicateExists =
            $duplicateCheck
                ->get_result()
                ->num_rows > 0;

        $duplicateCheck->close();


        if ($duplicateExists) {

            redirectWithMessage(
                'error',
                'Another inventory item already uses this name.'
            );
        }


        /* -----------------------------------------------
           UPDATE ITEM
           ----------------------------------------------- */

        $updateSQL = "
            UPDATE inventory_items

            SET
                category_id = ?,
                unit_id = ?,
                item_name = ?,
                minimum_stock = ?,
                description = ?,
                is_predictable = ?

            WHERE item_id = ?
        ";

        $updateStmt =
            $conn->prepare($updateSQL);

        if (!$updateStmt) {

            redirectWithMessage(
                'error',
                'Unable to prepare item update.'
            );
        }


        $updateStmt->bind_param(
            "iisisii",
            $category_id,
            $unit_id,
            $item_name,
            $minimum_stock,
            $description,
            $is_predictable,
            $item_id
        );


        if ($updateStmt->execute()) {

            $changes = [];


            if (
                (int)$oldItem['category_id']
                !== $category_id
            ) {
                $changes[] =
                    'category changed';
            }


            if (
                (int)$oldItem['unit_id']
                !== $unit_id
            ) {
                $changes[] =
                    'unit changed';
            }


            if (
                $oldItem['item_name']
                !== $item_name
            ) {
                $changes[] =
                    'name changed';
            }


            if (
                (int)$oldItem['minimum_stock']
                !== $minimum_stock
            ) {
                $changes[] =
                    'minimum stock changed';
            }


            if (
                (string)$oldItem['description']
                !== $description
            ) {
                $changes[] =
                    'description changed';
            }


            if (
                (int)$oldItem['is_predictable']
                !== $is_predictable
            ) {
                $changes[] =
                    'prediction setting changed';
            }


            $changeText =
                empty($changes)
                ? 'No field changes'
                : implode(
                    ', ',
                    $changes
                );


            addInventoryAuditLog(
                $conn,
                $user_id,
                $branch_id,
                "Updated inventory item: {$item_name} (ID: {$item_id}) - {$changeText}"
            );


            $updateStmt->close();


            redirectWithMessage(
                'success',
                'Inventory item updated successfully.'
            );

        } else {

            $dbError =
                $updateStmt->error;

            $updateStmt->close();

            redirectWithMessage(
                'error',
                'Unable to update inventory item: ' .
                $dbError
            );
        }
    }


    /* =====================================================
       DELETE INVENTORY ITEM
       ===================================================== */

    if ($action === 'delete') {

        $item_id =
            filter_input(
                INPUT_POST,
                'item_id',
                FILTER_VALIDATE_INT
            );


        if (
            !$item_id ||
            $item_id <= 0
        ) {
            redirectWithMessage(
                'error',
                'Invalid inventory item.'
            );
        }


        /* -----------------------------------------------
           LOAD ITEM
           ----------------------------------------------- */

        $itemStmt =
            $conn->prepare(
                "
                SELECT item_name
                FROM inventory_items
                WHERE item_id = ?
                LIMIT 1
                "
            );


        if (!$itemStmt) {

            redirectWithMessage(
                'error',
                'Unable to retrieve inventory item.'
            );
        }


        $itemStmt->bind_param(
            "i",
            $item_id
        );

        $itemStmt->execute();

        $itemData =
            $itemStmt
                ->get_result()
                ->fetch_assoc();

        $itemStmt->close();


        if (!$itemData) {

            redirectWithMessage(
                'error',
                'Inventory item not found.'
            );
        }


        /* -----------------------------------------------
           CHECK STOCK RECORDS
           ----------------------------------------------- */

        $stockCheck =
            $conn->prepare(
                "
                SELECT COUNT(*) AS total
                FROM inventory_stocks
                WHERE item_id = ?
                "
            );

        if (!$stockCheck) {

            redirectWithMessage(
                'error',
                'Unable to check item stock records.'
            );
        }


        $stockCheck->bind_param(
            "i",
            $item_id
        );

        $stockCheck->execute();

        $stockCount =
            (int)(
                $stockCheck
                    ->get_result()
                    ->fetch_assoc()['total']
                ?? 0
            );

        $stockCheck->close();


        if ($stockCount > 0) {

            redirectWithMessage(
                'error',
                'This item cannot be deleted because it has existing stock records.'
            );
        }


        /* -----------------------------------------------
           CHECK STOCK TRANSACTIONS
           ----------------------------------------------- */

        $transactionCheck =
            $conn->prepare(
                "
                SELECT COUNT(*) AS total
                FROM stock_transactions
                WHERE item_id = ?
                "
            );


        if (!$transactionCheck) {

            redirectWithMessage(
                'error',
                'Unable to check item transaction records.'
            );
        }


        $transactionCheck->bind_param(
            "i",
            $item_id
        );

        $transactionCheck->execute();

        $transactionCount =
            (int)(
                $transactionCheck
                    ->get_result()
                    ->fetch_assoc()['total']
                ?? 0
            );

        $transactionCheck->close();


        if ($transactionCount > 0) {

            redirectWithMessage(
                'error',
                'This item cannot be deleted because it has existing stock transaction records.'
            );
        }


        /* -----------------------------------------------
           DELETE
           ----------------------------------------------- */

        $deleteStmt =
            $conn->prepare(
                "
                DELETE FROM inventory_items
                WHERE item_id = ?
                "
            );


        if (!$deleteStmt) {

            redirectWithMessage(
                'error',
                'Unable to prepare item deletion.'
            );
        }


        $deleteStmt->bind_param(
            "i",
            $item_id
        );


        if ($deleteStmt->execute()) {

            addInventoryAuditLog(
                $conn,
                $user_id,
                $branch_id,
                "Deleted inventory item: {$itemData['item_name']} (ID: {$item_id})"
            );

            $deleteStmt->close();

            redirectWithMessage(
                'success',
                'Inventory item deleted successfully.'
            );

        } else {

            $dbError =
                $deleteStmt->error;

            $deleteStmt->close();

            redirectWithMessage(
                'error',
                'Unable to delete inventory item: ' .
                $dbError
            );
        }
    }
}


/* =========================================================
   LOAD CATEGORIES
   ========================================================= */

$categories = [];

$categorySQL = "
    SELECT
        category_id,
        category_name
    FROM inventory_categories
    ORDER BY category_name ASC
";

$categoryStmt =
    $conn->prepare($categorySQL);

if ($categoryStmt) {

    $categoryStmt->execute();

    $categoryResult =
        $categoryStmt->get_result();

    while (
        $row =
        $categoryResult->fetch_assoc()
    ) {
        $categories[] = $row;
    }

    $categoryStmt->close();
}


/* =========================================================
   LOAD UNITS
   ========================================================= */

$units = [];

$unitSQL = "
    SELECT
        unit_id,
        unit_name
    FROM units
    ORDER BY unit_name ASC
";

$unitStmt =
    $conn->prepare($unitSQL);

if ($unitStmt) {

    $unitStmt->execute();

    $unitResult =
        $unitStmt->get_result();

    while (
        $row =
        $unitResult->fetch_assoc()
    ) {
        $units[] = $row;
    }

    $unitStmt->close();
}


/* =========================================================
   SEARCH
   ========================================================= */

$search =
    trim(
        $_GET['search']
        ?? ''
    );


/* =========================================================
   LOAD INVENTORY ITEMS

   IMPORTANT:

   inventory_items = master item

   inventory_stocks = batches/stock records

   SUM(quantity_available) combines all batches belonging
   to the same item for the current branch.
   ========================================================= */

$items = [];


if (!empty($branch_id)) {

    $itemsSQL = "
        SELECT
            i.item_id,
            i.category_id,
            i.unit_id,
            i.item_name,
            i.minimum_stock,
            i.description,
            i.is_predictable,

            c.category_name,

            u.unit_name,

            COALESCE(
                SUM(s.quantity_available),
                0
            ) AS quantity_available

        FROM inventory_items i

        INNER JOIN inventory_categories c
            ON i.category_id = c.category_id

        INNER JOIN units u
            ON i.unit_id = u.unit_id

        LEFT JOIN inventory_stocks s
            ON i.item_id = s.item_id
            AND s.branch_id = ?

        WHERE 1 = ?
    ";


    $params = [
        $branch_id,
        1
    ];

    $types = "si";


    /* -----------------------------------------------------
       SEARCH FILTER
       ----------------------------------------------------- */

    if ($search !== '') {

        $itemsSQL .= "
            AND
            (
                i.item_name LIKE ?
                OR c.category_name LIKE ?
                OR u.unit_name LIKE ?
            )
        ";


        $searchParam =
            '%' .
            $search .
            '%';


        $params[] =
            $searchParam;

        $params[] =
            $searchParam;

        $params[] =
            $searchParam;


        $types .= "sss";
    }


    /* -----------------------------------------------------
       GROUP STOCK BATCHES BY ITEM
       ----------------------------------------------------- */

    $itemsSQL .= "

        GROUP BY
            i.item_id,
            i.category_id,
            i.unit_id,
            i.item_name,
            i.minimum_stock,
            i.description,
            i.is_predictable,
            c.category_name,
            u.unit_name

        ORDER BY
            i.item_name ASC
    ";


    $itemsStmt =
        $conn->prepare($itemsSQL);


    if ($itemsStmt) {

        $itemsStmt->bind_param(
            $types,
            ...$params
        );

        $itemsStmt->execute();

        $itemsResult =
            $itemsStmt->get_result();


        while (
            $row =
            $itemsResult->fetch_assoc()
        ) {

            $quantity =
                (int)$row[
                    'quantity_available'
                ];

            $minimum =
                (int)$row[
                    'minimum_stock'
                ];


            /*
             * Make sure the SUM result
             * is stored as an integer.
             */

            $row[
                'quantity_available'
            ] = $quantity;


            /* ---------------------------------------------
               STOCK STATUS
               --------------------------------------------- */

            if ($quantity <= 0) {

                $status =
                    'Critical';

                $statusClass =
                    'badge-critical';

            } elseif (
                $quantity < $minimum
            ) {

                $status =
                    'Low';

                $statusClass =
                    'badge-low';

            } else {

                $status =
                    'In Stock';

                $statusClass =
                    'badge-instock';
            }


            $row['status'] =
                $status;

            $row['status_class'] =
                $statusClass;


            $items[] =
                $row;
        }


        $itemsStmt->close();
    }
}


/* =========================================================
   LOAD ACTIVE STOCK BATCH EXPIRATION DATES FOR VIEW MODAL

   inventory_items is the master item, while expiration dates
   belong to inventory_stocks batches. Because one item can
   have multiple batches, the View modal shows every positive
   stock batch and its own expiration date.
   ========================================================= */

if (!empty($branch_id) && !empty($items)) {

    $batchMap = [];

    $batchSQL = "
        SELECT
            stock_id,
            item_id,
            batch_lot_no,
            quantity_available,
            expiration_date
        FROM inventory_stocks
        WHERE branch_id = ?
          AND quantity_available > 0
        ORDER BY
            item_id ASC,
            CASE
                WHEN expiration_date IS NULL THEN 1
                ELSE 0
            END ASC,
            expiration_date ASC,
            stock_id ASC
    ";

    $batchStmt =
        $conn->prepare($batchSQL);

    if ($batchStmt) {

        $batchStmt->bind_param(
            "s",
            $branch_id
        );

        $batchStmt->execute();

        $batchResult =
            $batchStmt->get_result();

        while (
            $batchRow =
            $batchResult->fetch_assoc()
        ) {

            $batchItemId =
                (int)$batchRow['item_id'];

            if (!isset($batchMap[$batchItemId])) {
                $batchMap[$batchItemId] = [];
            }

            $batchMap[$batchItemId][] = [
                'stock_id' =>
                    (int)$batchRow['stock_id'],

                'batch_lot_no' =>
                    $batchRow['batch_lot_no'],

                'quantity_available' =>
                    (int)$batchRow['quantity_available'],

                'expiration_date' =>
                    $batchRow['expiration_date']
            ];
        }

        $batchStmt->close();
    }


    foreach ($items as &$item) {

        $itemId =
            (int)$item['item_id'];

        $item['stock_batches'] =
            $batchMap[$itemId]
            ?? [];
    }

    unset($item);
}


/* =========================================================
   STATUS CLASS
   ========================================================= */

function itemStatusClass($status)
{
    switch ($status) {

        case 'Critical':

            return 'badge-critical';


        case 'Low':

            return 'badge-low';


        case 'In Stock':

            return 'badge-instock';


        default:

            return 'badge-low';
    }
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
    Inventory Items -
    <?php echo h($branch_name); ?>
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
}


body {
    background: #f0f2f5;
    font-family: 'Segoe UI', sans-serif;
}


.main {
    margin-left: 260px;
    min-height: 100vh;
}


.topbar {
    background: white;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 35px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}


.topbar h3 {
    font-size: 28px;
    font-weight: 700;
    color: var(--primary);
    margin: 0;
}


.topbar h3 small {
    font-size: 16px;
    font-weight: 400;
    color: #777;
    margin-left: 10px;
}


.profile {
    font-weight: 600;
    color: var(--primary);
}


.page-body {
    padding: 35px;
}


.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 22px;
    flex-wrap: wrap;
}


.search-box {
    position: relative;
    flex: 1;
    max-width: 340px;
}


.search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #9aa0c3;
}


.search-box input {
    width: 100%;
    padding: 10px 14px 10px 38px;
    border-radius: 10px;
    border: 1px solid #dcdee8;
    background: white;
    font-size: 14px;
}


.search-box input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(43,58,140,.12);
    outline: none;
}


.btn-custom {
    background: var(--primary);
    color: white;
    border-radius: 8px;
    padding: 10px 20px;
    border: none;
    font-weight: 600;
    font-size: 14px;
    white-space: nowrap;
}


.btn-custom:hover {
    background: #1d2863;
    color: white;
}


.btn-outline-custom {
    background: white;
    color: var(--primary);
    border: 1px solid var(--primary);
    border-radius: 8px;
    padding: 9px 19px;
    font-weight: 600;
    font-size: 14px;
}


.btn-outline-custom:hover {
    background: var(--primary);
    color: white;
}


.table-wrap {
    background: white;
    border-radius: 12px;
    border: 1px solid #dfe1ee;
    overflow: hidden;
}


.data-table {
    margin: 0;
}


.data-table thead th {
    background: var(--primary);
    color: white;
    font-weight: 600;
    font-size: 13px;
    border: none;
    padding: 14px;
    white-space: nowrap;
}


.data-table tbody td {
    font-size: 14px;
    color: #333;
    padding: 13px 14px;
    vertical-align: middle;
    border-bottom: 1px solid #eef0f7;
}


.data-table tbody tr:last-child td {
    border-bottom: none;
}


.data-table tbody tr:hover {
    background: #f7f8fc;
}


.badge-status {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}


.badge-low {
    background: #FFEAEA;
    color: var(--accent);
}


.badge-critical {
    background: var(--accent);
    color: white;
}


.badge-instock {
    background: #E6F4EA;
    color: #1E7B34;
}


.action-btn {
    border: 1px solid #dcdee8;
    background: white;
    color: var(--primary);
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin: 2px;
}


.action-btn:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}


.action-btn.delete:hover {
    background: var(--accent);
    border-color: var(--accent);
}


.empty-state {
    padding: 35px;
    text-align: center;
    color: #777;
}


.alert-custom {
    border-radius: 10px;
    border: none;
}


.modal-content {
    border-radius: 16px;
    border: none;
}


.modal-header {
    background: var(--primary);
    color: white;
}


.modal-title {
    font-weight: 700;
}


.form-label {
    color: #333;
}



.expiration-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
}

.expiration-row {
    display: grid;
    grid-template-columns: minmax(120px, 1fr) auto auto;
    gap: 10px;
    align-items: center;
    padding: 10px 12px;
    background: #f7f8fc;
    border: 1px solid #e4e7f2;
    border-radius: 10px;
}

.expiration-batch {
    font-weight: 600;
    color: #2f3b4d;
}

.expiration-qty {
    color: #5f6b85;
    font-size: 13px;
    white-space: nowrap;
}

.expiration-date {
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
}

.expiration-empty {
    padding: 10px 12px;
    border-radius: 10px;
    background: #f7f8fc;
    color: #777;
    border: 1px dashed #d8dce9;
}

.expiration-help {
    display: block;
    margin-top: 6px;
    color: #858ba0;
    font-size: 12px;
}

@media(max-width: 576px) {
    .expiration-row {
        grid-template-columns: 1fr;
        gap: 3px;
    }
}

@media(max-width: 991px) {

    .main {
        margin-left: 90px;
    }
}


@media(max-width: 576px) {

    .topbar {
        padding: 0 16px;
        height: 70px;
    }

    .topbar h3 {
        font-size: 20px;
    }

    .topbar h3 small {
        display: block;
        margin-left: 0;
        font-size: 12px;
    }

    .page-body {
        padding: 20px 16px;
    }

    .toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .search-box {
        max-width: 100%;
    }

    .table-wrap {
        overflow-x: auto;
    }
}

</style>

</head>


<body>


<!-- =====================================================
     SIDEBAR
     ===================================================== -->

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

                <a href="InventoryOfficer_Dashboard.php">

                    <i class="bi bi-grid-fill"></i>

                    <span>
                        Dashboard
                    </span>

                </a>

            </li>


            <li>

                <a
                    class="active"
                    href="InventoryOfficer_InventoryItems.php"
                >

                    <i class="bi bi-box-seam"></i>

                    <span>
                        Inventory Items
                    </span>

                </a>

            </li>


            <li>

                <a href="InventoryOfficer_Categories.php">

                    <i class="bi bi-tags"></i>

                    <span>
                        Categories & Units
                    </span>

                </a>

            </li>


            <li>

                <a href="InventoryOfficer_StockManagement.php">

                    <i class="bi bi-boxes"></i>

                    <span>
                        Stock Management
                    </span>

                </a>

            </li>


            <li>

                <a href="InventoryOfficer_StockTransactions.php">

                    <i class="bi bi-arrow-left-right"></i>

                    <span>
                        Stock Transactions
                    </span>

                </a>

            </li>


            <li>

                <a href="InventoryOfficer_Reports.php">

                    <i class="bi bi-file-earmark-bar-graph-fill"></i>

                    <span>
                        Inventory Reports
                    </span>

                </a>

            </li>


            <li>

                <a href="InventoryOfficer_Notifications.php">

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


<!-- =====================================================
     MAIN CONTENT
     ===================================================== -->

<div class="main">


    <!-- TOP BAR -->

    <div class="topbar">

        <h3>

            Inventory Items

            <small>
                <?php echo h($branch_name); ?>
            </small>

        </h3>


        <div class="profile">

            <i class="bi bi-person-circle"></i>

            <?php echo h($username); ?>

            <span
                style="
                    font-size: 12px;
                    color: #adb5bd;
                    font-weight: 400;
                    margin-left: 4px;
                "
            >
                | Inventory Officer
            </span>

        </div>

    </div>


    <!-- PAGE BODY -->

    <div class="page-body">


        <!-- SUCCESS MESSAGE -->

        <?php if ($success_msg !== ''): ?>

            <div
                class="alert alert-success alert-custom mb-4"
                role="alert"
            >

                <i class="bi bi-check-circle me-2"></i>

                <?php echo h($success_msg); ?>

            </div>

        <?php endif; ?>


        <!-- ERROR MESSAGE -->

        <?php if ($error_msg !== ''): ?>

            <div
                class="alert alert-danger alert-custom mb-4"
                role="alert"
            >

                <i class="bi bi-exclamation-circle me-2"></i>

                <?php echo h($error_msg); ?>

            </div>

        <?php endif; ?>


        <!-- TOOLBAR -->

        <div class="toolbar">


            <!-- SEARCH -->

            <form
                method="GET"
                action="<?php echo h($_SERVER['PHP_SELF']); ?>"
                class="search-box"
            >

                <i class="bi bi-search"></i>

                <input
                    type="text"
                    name="search"
                    value="<?php echo h($search); ?>"
                    placeholder="Search Item..."
                    autocomplete="off"
                >

            </form>


            <!-- ADD ITEM -->

            <button
                type="button"
                class="btn-custom"
                data-bs-toggle="modal"
                data-bs-target="#addItemModal"
            >

                <i class="bi bi-plus-lg me-1"></i>

                Add Item

            </button>

        </div>


        <!-- =================================================
             INVENTORY TABLE
             ================================================= -->

        <div class="table-wrap">

            <table class="table data-table">

                <thead>

                    <tr>

                        <th>
                            Category
                        </th>

                        <th>
                            Item Name
                        </th>

                        <th>
                            Stock
                        </th>

                        <th>
                            Status
                        </th>

                        <th class="text-center">
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (empty($items)): ?>

                    <tr>

                        <td
                            colspan="5"
                            class="empty-state"
                        >

                            <?php if ($search !== ''): ?>

                                No inventory items found
                                matching
                                "<strong><?php echo h($search); ?></strong>".

                            <?php elseif (empty($branch_id)): ?>

                                Your account has no branch assigned.

                            <?php else: ?>

                                No inventory items found.

                            <?php endif; ?>

                        </td>

                    </tr>


                <?php else: ?>


                    <?php foreach ($items as $item): ?>

                        <tr>


                            <!-- CATEGORY -->

                            <td>

                                <?php
                                echo h(
                                    $item['category_name']
                                );
                                ?>

                            </td>


                            <!-- ITEM NAME -->

                            <td>

                                <strong>

                                    <?php
                                    echo h(
                                        $item['item_name']
                                    );
                                    ?>

                                </strong>

                            </td>


                            <!-- TOTAL STOCK -->

                            <td>

                                <?php
                                echo h(
                                    $item[
                                        'quantity_available'
                                    ]
                                );
                                ?>

                                <?php
                                echo h(
                                    $item[
                                        'unit_name'
                                    ]
                                );
                                ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span
                                    class="badge-status <?php
                                    echo h(
                                        itemStatusClass(
                                            $item['status']
                                        )
                                    );
                                    ?>"
                                >

                                    <?php
                                    echo h(
                                        $item['status']
                                    );
                                    ?>

                                </span>

                            </td>


                            <!-- ACTION -->

                            <td class="text-center">


                                <!-- VIEW -->

                                <button
                                    type="button"
                                    class="action-btn"
                                    title="View Item"
                                    onclick='viewItem(
                                        <?php
                                        echo json_encode(
                                            [
                                                'item_id' =>
                                                    (int)$item['item_id'],

                                                'category_id' =>
                                                    (int)$item['category_id'],

                                                'category_name' =>
                                                    $item['category_name'],

                                                'unit_id' =>
                                                    (int)$item['unit_id'],

                                                'unit_name' =>
                                                    $item['unit_name'],

                                                'item_name' =>
                                                    $item['item_name'],

                                                'minimum_stock' =>
                                                    (int)$item['minimum_stock'],

                                                'description' =>
                                                    $item['description'],

                                                'is_predictable' =>
                                                    (int)$item['is_predictable'],

                                                'quantity_available' =>
                                                    (int)$item['quantity_available'],

                                                'status' =>
                                                    $item['status'],

                                                'stock_batches' =>
                                                    $item['stock_batches']
                                                    ?? []
                                            ],

                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_QUOT |
                                            JSON_HEX_AMP
                                        );
                                        ?>
                                    )'
                                >

                                    <i class="bi bi-eye"></i>

                                </button>


                                <!-- EDIT -->

                                <button
                                    type="button"
                                    class="action-btn"
                                    title="Edit Item"
                                    onclick='editItem(
                                        <?php
                                        echo json_encode(
                                            [
                                                'item_id' =>
                                                    (int)$item['item_id'],

                                                'category_id' =>
                                                    (int)$item['category_id'],

                                                'unit_id' =>
                                                    (int)$item['unit_id'],

                                                'item_name' =>
                                                    $item['item_name'],

                                                'minimum_stock' =>
                                                    (int)$item['minimum_stock'],

                                                'description' =>
                                                    $item['description'],

                                                'is_predictable' =>
                                                    (int)$item['is_predictable']
                                            ],

                                            JSON_HEX_TAG |
                                            JSON_HEX_APOS |
                                            JSON_HEX_QUOT |
                                            JSON_HEX_AMP
                                        );
                                        ?>
                                    )'
                                >

                                    <i class="bi bi-pencil"></i>

                                </button>


                                <!-- DELETE -->

                                <form
                                    method="POST"
                                    action="<?php echo h($_SERVER['PHP_SELF']); ?>"
                                    style="display: inline;"
                                    onsubmit="
                                        return confirm(
                                            'Are you sure you want to delete this inventory item?'
                                        );
                                    "
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php echo h($csrf_token); ?>"
                                    >


                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >


                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?php
                                        echo (int)$item['item_id'];
                                        ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="action-btn delete"
                                        title="Delete Item"
                                    >

                                        <i class="bi bi-trash"></i>

                                    </button>

                                </form>


                            </td>

                        </tr>

                    <?php endforeach; ?>


                <?php endif; ?>


                </tbody>

            </table>

        </div>


    </div>

</div>


<!-- =====================================================
     ADD ITEM MODAL
     ===================================================== -->

<div
    class="modal fade"
    id="addItemModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">


            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-box-seam me-2"></i>

                    Add Inventory Item

                </h5>


                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form
                method="POST"
                action="<?php echo h($_SERVER['PHP_SELF']); ?>"
            >

                <div class="modal-body">


                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo h($csrf_token); ?>"
                    >


                    <input
                        type="hidden"
                        name="action"
                        value="add"
                    >


                    <!-- ITEM NAME -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Item Name
                        </label>


                        <input
                            type="text"
                            name="item_name"
                            class="form-control"
                            placeholder="Enter item name"
                            maxlength="255"
                            required
                        >

                    </div>


                    <!-- CATEGORY -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Category
                        </label>


                        <select
                            name="category_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select category...
                            </option>


                            <?php foreach ($categories as $category): ?>

                                <option
                                    value="<?php
                                    echo (int)$category['category_id'];
                                    ?>"
                                >

                                    <?php
                                    echo h(
                                        $category['category_name']
                                    );
                                    ?>

                                </option>

                            <?php endforeach; ?>


                        </select>

                    </div>


                    <!-- UNIT + MINIMUM STOCK -->

                    <div class="row">

                        <div class="col-6 mb-3">

                            <label
                                class="form-label fw-semibold"
                            >
                                Unit
                            </label>


                            <select
                                name="unit_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select unit...
                                </option>


                                <?php foreach ($units as $unit): ?>

                                    <option
                                        value="<?php
                                        echo (int)$unit['unit_id'];
                                        ?>"
                                    >

                                        <?php
                                        echo h(
                                            $unit['unit_name']
                                        );
                                        ?>

                                    </option>

                                <?php endforeach; ?>


                            </select>

                        </div>


                        <div class="col-6 mb-3">

                            <label
                                class="form-label fw-semibold"
                            >
                                Minimum Stock
                            </label>


                            <input
                                type="number"
                                name="minimum_stock"
                                class="form-control"
                                min="0"
                                step="1"
                                value="0"
                                required
                            >

                        </div>

                    </div>


                    <!-- DESCRIPTION -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Description
                        </label>


                        <textarea
                            name="description"
                            class="form-control"
                            rows="2"
                            placeholder="Optional notes about this item"
                        ></textarea>

                    </div>


                    <!-- SHORTAGE PREDICTION -->

                    <div class="form-check">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="is_predictable"
                            id="addPredictable"
                            value="1"
                            checked
                        >


                        <label
                            class="form-check-label"
                            for="addPredictable"
                        >
                            Include in shortage prediction model
                        </label>

                    </div>


                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn-outline-custom"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn-custom"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Save Item

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =====================================================
     EDIT ITEM MODAL
     ===================================================== -->

<div
    class="modal fade"
    id="editItemModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">


            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-pencil me-2"></i>

                    Edit Inventory Item

                </h5>


                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form
                method="POST"
                action="<?php echo h($_SERVER['PHP_SELF']); ?>"
            >

                <div class="modal-body">


                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo h($csrf_token); ?>"
                    >


                    <input
                        type="hidden"
                        name="action"
                        value="update"
                    >


                    <input
                        type="hidden"
                        name="item_id"
                        id="editItemId"
                    >


                    <!-- ITEM NAME -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Item Name
                        </label>


                        <input
                            type="text"
                            name="item_name"
                            id="editItemName"
                            class="form-control"
                            maxlength="255"
                            required
                        >

                    </div>


                    <!-- CATEGORY -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Category
                        </label>


                        <select
                            name="category_id"
                            id="editCategoryId"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select category...
                            </option>


                            <?php foreach ($categories as $category): ?>

                                <option
                                    value="<?php
                                    echo (int)$category['category_id'];
                                    ?>"
                                >

                                    <?php
                                    echo h(
                                        $category['category_name']
                                    );
                                    ?>

                                </option>

                            <?php endforeach; ?>


                        </select>

                    </div>


                    <!-- UNIT + MINIMUM STOCK -->

                    <div class="row">

                        <div class="col-6 mb-3">

                            <label
                                class="form-label fw-semibold"
                            >
                                Unit
                            </label>


                            <select
                                name="unit_id"
                                id="editUnitId"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select unit...
                                </option>


                                <?php foreach ($units as $unit): ?>

                                    <option
                                        value="<?php
                                        echo (int)$unit['unit_id'];
                                        ?>"
                                    >

                                        <?php
                                        echo h(
                                            $unit['unit_name']
                                        );
                                        ?>

                                    </option>

                                <?php endforeach; ?>


                            </select>

                        </div>


                        <div class="col-6 mb-3">

                            <label
                                class="form-label fw-semibold"
                            >
                                Minimum Stock
                            </label>


                            <input
                                type="number"
                                name="minimum_stock"
                                id="editMinimumStock"
                                class="form-control"
                                min="0"
                                step="1"
                                required
                            >

                        </div>

                    </div>


                    <!-- DESCRIPTION -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Description
                        </label>


                        <textarea
                            name="description"
                            id="editDescription"
                            class="form-control"
                            rows="2"
                        ></textarea>

                    </div>


                    <!-- PREDICTION -->

                    <div class="form-check">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="is_predictable"
                            id="editPredictable"
                            value="1"
                        >


                        <label
                            class="form-check-label"
                            for="editPredictable"
                        >
                            Include in shortage prediction model
                        </label>

                    </div>


                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn-outline-custom"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn-custom"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Update Item

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =====================================================
     VIEW ITEM MODAL
     ===================================================== -->

<div
    class="modal fade"
    id="viewItemModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">


            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-eye me-2"></i>

                    Inventory Item Details

                </h5>


                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body">

                <div class="row g-3">


                    <div class="col-12">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Item Name
                        </label>

                        <div
                            id="viewItemName"
                            class="fw-semibold"
                        ></div>

                    </div>


                    <div class="col-6">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Category
                        </label>

                        <div id="viewCategory"></div>

                    </div>


                    <div class="col-6">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Unit
                        </label>

                        <div id="viewUnit"></div>

                    </div>


                    <div class="col-6">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Current Stock
                        </label>

                        <div
                            id="viewStock"
                            class="fw-semibold"
                        ></div>

                    </div>


                    <div class="col-6">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Minimum Stock
                        </label>

                        <div id="viewMinimumStock"></div>

                    </div>


                    <div class="col-12">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Expiration Date(s)
                        </label>

                        <div
                            id="viewExpirationDates"
                            class="expiration-list"
                        ></div>

                        <small class="expiration-help">
                            Expiration dates are shown per active stock batch in this branch.
                        </small>

                    </div>


                    <div class="col-6">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Status
                        </label>

                        <div id="viewStatus"></div>

                    </div>


                    <div class="col-6">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Shortage Prediction
                        </label>

                        <div id="viewPredictable"></div>

                    </div>


                    <div class="col-12">

                        <label
                            class="text-muted small fw-bold"
                        >
                            Description
                        </label>

                        <div id="viewDescription"></div>

                    </div>


                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn-outline-custom"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>

            </div>


        </div>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/* =========================================================
   VIEW ITEM
   ========================================================= */

function viewItem(item)
{
    document
        .getElementById('viewItemName')
        .textContent =
            item.item_name || '';


    document
        .getElementById('viewCategory')
        .textContent =
            item.category_name || '';


    document
        .getElementById('viewUnit')
        .textContent =
            item.unit_name || '';


    document
        .getElementById('viewStock')
        .textContent =
            (item.quantity_available ?? 0) +
            ' ' +
            (item.unit_name || '');


    document
        .getElementById('viewMinimumStock')
        .textContent =
            item.minimum_stock ?? 0;


    const expirationContainer =
        document.getElementById(
            'viewExpirationDates'
        );

    expirationContainer.innerHTML = '';

    const stockBatches =
        Array.isArray(item.stock_batches)
        ? item.stock_batches
        : [];

    if (stockBatches.length === 0) {

        const emptyMessage =
            document.createElement('div');

        emptyMessage.className =
            'expiration-empty';

        emptyMessage.textContent =
            'No active stock batches with remaining quantity.';

        expirationContainer.appendChild(
            emptyMessage
        );

    } else {

        stockBatches.forEach(
            function(batch)
            {
                const row =
                    document.createElement('div');

                row.className =
                    'expiration-row';


                const batchName =
                    document.createElement('div');

                batchName.className =
                    'expiration-batch';

                batchName.textContent =
                    batch.batch_lot_no
                    ? 'Batch/Lot: ' + batch.batch_lot_no
                    : 'Batch/Lot: N/A';


                const quantity =
                    document.createElement('div');

                quantity.className =
                    'expiration-qty';

                quantity.textContent =
                    (batch.quantity_available ?? 0) +
                    ' ' +
                    (item.unit_name || '');


                const expiration =
                    document.createElement('div');

                expiration.className =
                    'expiration-date';

                expiration.textContent =
                    batch.expiration_date
                    ? formatInventoryDate(
                        batch.expiration_date
                    )
                    : 'No expiration date';


                row.appendChild(
                    batchName
                );

                row.appendChild(
                    quantity
                );

                row.appendChild(
                    expiration
                );

                expirationContainer.appendChild(
                    row
                );
            }
        );
    }


    document
        .getElementById('viewStatus')
        .innerHTML =
            '<span class="badge-status ' +
            getStatusClass(
                item.status
            ) +
            '">' +
            escapeHtml(
                item.status || ''
            ) +
            '</span>';


    document
        .getElementById('viewPredictable')
        .textContent =
            Number(
                item.is_predictable
            ) === 1
            ? 'Included'
            : 'Not Included';


    document
        .getElementById('viewDescription')
        .textContent =
            item.description ||
            'No description provided.';


    const modal =
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById(
                'viewItemModal'
            )
        );


    modal.show();
}


/* =========================================================
   EDIT ITEM
   ========================================================= */

function editItem(item)
{
    document
        .getElementById('editItemId')
        .value =
            item.item_id;


    document
        .getElementById('editItemName')
        .value =
            item.item_name || '';


    document
        .getElementById('editCategoryId')
        .value =
            item.category_id;


    document
        .getElementById('editUnitId')
        .value =
            item.unit_id;


    document
        .getElementById('editMinimumStock')
        .value =
            item.minimum_stock ?? 0;


    document
        .getElementById('editDescription')
        .value =
            item.description || '';


    document
        .getElementById('editPredictable')
        .checked =
            Number(
                item.is_predictable
            ) === 1;


    const modal =
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById(
                'editItemModal'
            )
        );


    modal.show();
}


/* =========================================================
   FORMAT INVENTORY DATE
   ========================================================= */

function formatInventoryDate(value)
{
    if (!value) {
        return 'No expiration date';
    }

    const parts =
        String(value).split('-');

    if (parts.length !== 3) {
        return value;
    }

    const year =
        Number(parts[0]);

    const month =
        Number(parts[1]);

    const day =
        Number(parts[2]);

    if (!year || !month || !day) {
        return value;
    }

    const date =
        new Date(
            year,
            month - 1,
            day
        );

    return date.toLocaleDateString(
        'en-US',
        {
            month: 'short',
            day: '2-digit',
            year: 'numeric'
        }
    );
}


/* =========================================================
   STATUS CLASS
   ========================================================= */

function getStatusClass(status)
{
    switch (status) {

        case 'Critical':

            return 'badge-critical';


        case 'Low':

            return 'badge-low';


        case 'In Stock':

            return 'badge-instock';


        default:

            return 'badge-low';
    }
}


/* =========================================================
   ESCAPE HTML
   ========================================================= */

function escapeHtml(value)
{
    const div =
        document.createElement(
            'div'
        );

    div.textContent =
        value;

    return div.innerHTML;
}


/* =========================================================
   AUTO-HIDE ALERTS
   ========================================================= */

setTimeout(
    function()
    {
        const alerts =
            document.querySelectorAll(
                '.alert-custom'
            );


        alerts.forEach(
            function(alert)
            {
                alert.style.transition =
                    'opacity .4s';

                alert.style.opacity =
                    '0';


                setTimeout(
                    function()
                    {
                        alert.remove();
                    },
                    400
                );
            }
        );

    },
    4000
);

</script>


</body>

</html>