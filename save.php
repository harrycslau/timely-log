<?php
header('Content-Type: application/json; charset=utf-8');

// Set timezone to Helsinki
date_default_timezone_set('Europe/Helsinki');

// Define the data directory
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) { mkdir($dataDir, 0777, true); }

// Path to categories.csv
$categoriesFile = __DIR__ . '/categories.csv';

// Helper function: read CSV into an array
function readCsvToArray($filePath) {
    $rows = array();
    if (!file_exists($filePath)) { return $rows; }
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === count($headers)) {
                $row = array_combine($headers, $data);
                // Ensure each row has an ID
                if (!isset($row['id'])) {
                    $row['id'] = uniqid();
                }
                $rows[] = $row;
            }
        }
        fclose($handle);
    }
    return $rows;
}

// Helper function: write an array to CSV (including header)
function writeArrayToCsv($filePath, $data) {
    if (empty($data)) { return; }
    $fp = fopen($filePath, 'w');
    $headers = array_keys($data[0]);
    fputcsv($fp, $headers);
    foreach ($data as $row) { fputcsv($fp, array_values($row)); }
    fclose($fp);
}

// Add version tracking function
function getCurrentVersion($date) {
    $filename = "data/{$date}.json";
    if (file_exists($filename)) {
        return filemtime($filename); // Use file modification time as version
    }
    return 0;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'getVersion') {
    $date = $_POST['date'];
    echo json_encode([
        'status' => 'ok',
        'version' => getCurrentVersion($date)
    ]);
    exit;
} elseif ($action === 'getRecords') {
    $date = $_POST['date'];
    $filename = $dataDir . '/' . $date . '.csv';
    $records = readCsvToArray($filename);
    // Initialize default row if file is empty.
    if (count($records) === 0) {
        $records[] = [
            'id' => uniqid(),
            'starttime' => '00:00',
            'endtime'   => '23:59',
            'category'  => '',
            'subcategory' => '',
            'detail'    => ''
        ];
        writeArrayToCsv($filename, $records);
    }
    echo json_encode([
        'status' => 'ok',
        'data' => $records,
        'version' => getCurrentVersion($date)
    ]);
    exit;
} elseif ($action === 'newRecord') {
    $date = $_POST['date'];
    $filename = $dataDir . '/' . $date . '.csv';
    $records = readCsvToArray($filename);
    $now = date('H:i');
    if (count($records) === 0) {
        $records[] = [
            'id' => uniqid(),
            'starttime' => '00:00',
            'endtime'   => '23:59',
            'category'  => '',
            'subcategory' => '',
            'detail'    => ''
        ];
    } else {
        $lastIndex = count($records) - 1;
        $records[$lastIndex]['endtime'] = $now;
        $records[] = [
            'id' => uniqid(),
            'starttime' => $now,
            'endtime'   => '23:59',
            'category'  => '',
            'subcategory' => '',
            'detail'    => ''
        ];
    }
    writeArrayToCsv($filename, $records);
    echo json_encode(['status' => 'ok']);
    exit;
} elseif ($action === 'updateField') {
    $date = $_POST['date'];
    $recordId = $_POST['recordId']; // Change from rowIndex to recordId
    $field = $_POST['field'];
    $value = $_POST['value'];
    $clientVersion = isset($_POST['version']) ? $_POST['version'] : 0;

    // Check version before update
    $currentVersion = getCurrentVersion($date);
    if ($clientVersion !== $currentVersion) {
        echo json_encode([
            'status' => 'version_mismatch',
            'message' => 'Data has been modified'
        ]);
        exit;
    }

    $filename = $dataDir . '/' . $date . '.csv';
    $records = readCsvToArray($filename);
    
    // Find record by ID instead of index
    $found = false;
    foreach ($records as &$record) {
        if ($record['id'] === $recordId) {
            $record[$field] = $value;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        echo json_encode(['status' => 'error', 'message' => 'Record not found']);
        exit;
    }
    
    writeArrayToCsv($filename, $records);
    echo json_encode([
        'status' => 'ok',
        'version' => getCurrentVersion($date)
    ]);
    exit;
} elseif ($action === 'getCategories') {
    $cats = readCsvToArray($categoriesFile);
    echo json_encode(['status' => 'ok', 'data' => $cats]);
    exit;
} elseif ($action === 'saveCategories') {
    $categoriesJson = isset($_POST['categories']) ? $_POST['categories'] : '[]';
    $categoriesArr = json_decode($categoriesJson, true);
    error_log('Received categories: ' . print_r($categoriesArr, true)); // Debugging line
    $dataToWrite = [];
    foreach ($categoriesArr as $cat) {
        $dataToWrite[] = [
            'Category' => $cat['Category'],
            'Color' => $cat['Color'],
            'Subcategories' => isset($cat['Subcategories']) ? $cat['Subcategories'] : ''
        ];
    }
    error_log('Data to write: ' . print_r($dataToWrite, true)); // Debugging line
    if (!empty($dataToWrite)) {
        $fp = fopen($categoriesFile, 'w');
        fputcsv($fp, ['Category','Color','Subcategories']);
        foreach ($dataToWrite as $row) {
            fputcsv($fp, [$row['Category'], $row['Color'], $row['Subcategories']]);
        }
        fclose($fp);
    } else {
        file_put_contents($categoriesFile, "Category,Color,Subcategories\n");
    }
    echo json_encode(['status' => 'ok']);
    exit;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
    exit;
}
?>
