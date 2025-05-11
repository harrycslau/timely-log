<?php
header('Content-Type: application/json; charset=utf-8');

// Set timezone to Helsinki
date_default_timezone_set('Europe/Helsinki');

// Define the data directory and ensure it exists.
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) { mkdir($dataDir, 0777, true); }

// Path to SQLite database file.
$dbFile = $dataDir . '/app.db';

try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Create tables if they do not exist.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT,
        starttime TEXT,
        endtime TEXT,
        category TEXT,
        subcategory TEXT,
        detail TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        Category TEXT,
        Color TEXT,
        Subcategories TEXT
    )");
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database initialization failed: ' . $e->getMessage()]);
    exit;
}

// Helper function: get all records for a given date ordered by insertion.
function getRecords($db, $date) {
    $stmt = $db->prepare("SELECT * FROM records WHERE date = ? ORDER BY id ASC");
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Helper function: insert a record.
function insertRecord($db, $date, $starttime, $endtime, $category = '', $subcategory = '', $detail = '') {
    $stmt = $db->prepare("INSERT INTO records (date, starttime, endtime, category, subcategory, detail) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$date, $starttime, $endtime, $category, $subcategory, $detail]);
    return $db->lastInsertId();
}

// Helper function: update a specific field in a record (field name is validated before use).
function updateRecordField($db, $id, $field, $value) {
    $stmt = $db->prepare("UPDATE records SET $field = ? WHERE id = ?");
    $stmt->execute([$value, $id]);
}

// Helper function: delete a record by ID.
function deleteRecordById($db, $id) {
    $stmt = $db->prepare("DELETE FROM records WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// Helper functions for categories.
function getCategories($db) {
    $stmt = $db->prepare("SELECT Category, Color, Subcategories FROM categories ORDER BY id ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function clearCategories($db) {
    $db->exec("DELETE FROM categories");
}

function insertCategory($db, $Category, $Color, $Subcategories) {
    $stmt = $db->prepare("INSERT INTO categories (Category, Color, Subcategories) VALUES (?, ?, ?)");
    $stmt->execute([$Category, $Color, $Subcategories]);
}

// Determine the action.
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'getRecords') {
    $date = $_POST['date'];
    $records = getRecords($db, $date);
    // If no records exist, insert a default row.
    if (count($records) === 0) {
        insertRecord($db, $date, '00:00', '23:59', '', '', '');
        $records = getRecords($db, $date);
    }
    echo json_encode(['status' => 'ok', 'data' => $records]);
    exit;
} elseif ($action === 'newRecord') {
    $date = $_POST['date'];
    $records = getRecords($db, $date);
    $now = date('H:i');
    if (count($records) === 0) {
        // No record exists: insert a default row.
        insertRecord($db, $date, '00:00', '23:59', '', '', '');
    } else {
        // Update the last record's endtime to the current time.
        $lastRecord = end($records);
        $lastId = $lastRecord['id'];
        $stmt = $db->prepare("UPDATE records SET endtime = ? WHERE id = ?");
        $stmt->execute([$now, $lastId]);
        // Append a new record starting from the current time.
        insertRecord($db, $date, $now, '23:59', '', '', '');
    }
    echo json_encode(['status' => 'ok']);
    exit;
} elseif ($action === 'updateField') {
    $date = $_POST['date'];
    $rowIndex = intval($_POST['rowIndex']);
    $field = $_POST['field'];
    $value = $_POST['value'];
    
    $validFields = ['starttime', 'endtime', 'category', 'subcategory', 'detail'];
    if (!in_array($field, $validFields)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid field.']);
        exit;
    }
    
    // Retrieve current records for the date.
    $records = getRecords($db, $date);
    // If the requested row index is out of range, extend the records.
    while (count($records) <= $rowIndex) {
        $prevCount = count($records);
        if ($prevCount > 0) {
            // Get the last record and determine a valid start time.
            $lastRecord = $records[$prevCount - 1];
            $lastValidTime = '00:00';
            for ($i = $prevCount - 1; $i >= 0; $i--) {
                if ($records[$i]['endtime'] !== '23:59') {
                    $lastValidTime = $records[$i]['endtime'];
                    break;
                }
            }
            insertRecord($db, $date, $lastValidTime, '23:59', '', '', '');
        } else {
            insertRecord($db, $date, '00:00', '23:59', '', '', '');
        }
        $records = getRecords($db, $date);
    }
    
    // Update the specified field in the record corresponding to rowIndex.
    $record = $records[$rowIndex];
    updateRecordField($db, $record['id'], $field, $value);
    
    echo json_encode(['status' => 'ok']);
    exit;
} elseif ($action === 'getCategories') {
    $cats = getCategories($db);
    echo json_encode(['status' => 'ok', 'data' => $cats]);
    exit;
} elseif ($action === 'saveCategories') {
    $categoriesJson = isset($_POST['categories']) ? $_POST['categories'] : '[]';
    $categoriesArr = json_decode($categoriesJson, true);
    error_log('Received categories: ' . print_r($categoriesArr, true)); // Debugging line

    // Clear existing categories.
    clearCategories($db);
    foreach ($categoriesArr as $cat) {
        $Category = isset($cat['Category']) ? $cat['Category'] : '';
        $Color = isset($cat['Color']) ? $cat['Color'] : '';
        $Subcategories = isset($cat['Subcategories']) ? $cat['Subcategories'] : '';
        insertCategory($db, $Category, $Color, $Subcategories);
    }
    echo json_encode(['status' => 'ok']);
    exit;
} elseif ($action === 'deleteRecord') {
    $date = $_POST['date'];
    $rowIndex = intval($_POST['rowIndex']);
    
    // Retrieve current records for the date.
    $records = getRecords($db, $date);
    
    // Check if the rowIndex is valid.
    if ($rowIndex >= 0 && $rowIndex < count($records)) {
        $recordId = $records[$rowIndex]['id'];
        
        if (deleteRecordById($db, $recordId)) {
            // If there are no more records for this date, create a default one
            $remainingRecords = getRecords($db, $date);
            if (count($remainingRecords) === 0) {
                insertRecord($db, $date, '00:00', '23:59', '', '', '');
            }
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete record.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid record index.']);
    }
    exit;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    exit;
}
?>
