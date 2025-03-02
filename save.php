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
                $rows[] = array_combine($headers, $data);
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

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'getRecords') {
    $date = $_POST['date'];
    $filename = $dataDir . '/' . $date . '.csv';
    $records = readCsvToArray($filename);
    // Initialize default row if file is empty.
    if (count($records) === 0) {
        $records[] = [
            'starttime' => '00:00',
            'endtime'   => '23:59',
            'category'  => '',
            'subcategory' => '',
            'detail'    => ''
        ];
        writeArrayToCsv($filename, $records);
    }
    echo json_encode(['status' => 'ok', 'data' => $records]);
    exit;
} elseif ($action === 'newRecord') {
    $date = $_POST['date'];
    $filename = $dataDir . '/' . $date . '.csv';
    $records = readCsvToArray($filename);
    $now = date('H:i');
    if (count($records) === 0) {
        $records[] = [
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
    $rowIndex = intval($_POST['rowIndex']);
    $field = $_POST['field'];
    $value = $_POST['value'];
    $filename = $dataDir . '/' . $date . '.csv';
    $records = readCsvToArray($filename);
    
    // Initialize array if empty
    if (empty($records)) {
        $records = [[
            'starttime' => '00:00',
            'endtime'   => '23:59',
            'category'  => '',
            'subcategory' => '',
            'detail'    => ''
        ]];
    }
    
    // Ensure the row exists by extending the array if needed
    while (count($records) <= $rowIndex) {
        $prevIndex = count($records) - 1;
        $lastRecord = ($prevIndex >= 0) ? $records[$prevIndex] : null;
        
        if ($lastRecord) {
            // Get the last non-23:59 endtime
            $lastValidTime = '00:00';
            for ($i = $prevIndex; $i >= 0; $i--) {
                if ($records[$i]['endtime'] !== '23:59') {
                    $lastValidTime = $records[$i]['endtime'];
                    break;
                }
            }
            
            // For subsequent rows, use last valid endtime
            $records[] = [
                'starttime' => $lastValidTime,
                'endtime'   => '23:59',
                'category'  => '',
                'subcategory' => '',
                'detail'    => ''
            ];
        } else {
            // For the first row
            $records[] = [
                'starttime' => '00:00',
                'endtime'   => '23:59',
                'category'  => '',
                'subcategory' => '',
                'detail'    => ''
            ];
        }
    }
    
    $validFields = ['starttime', 'endtime', 'category', 'subcategory', 'detail'];
    if (!in_array($field, $validFields)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid field.']);
        exit;
    }
    
    // Update the specified field
    $records[$rowIndex][$field] = $value;
    
    // Write the changes immediately
    writeArrayToCsv($filename, $records);
    echo json_encode(['status' => 'ok']);
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
