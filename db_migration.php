<?php
// migration.php - One-time migration script to import CSV data into SQLite

$dataDir = __DIR__ . '/data';
$dbFile = $dataDir . '/app.db';

// Check if database already exists
if (file_exists($dbFile)) {
    die("Error: Database file already exists at $dbFile\nPlease backup or remove the existing database before running the migration.\n");
}

try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create tables if they do not exist.
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
    echo "Database error: " . $e->getMessage();
    exit;
}

// Function to import a records CSV file.
// Assumes the filename is in the format "YYYY-MM-DD.csv" and contains headers: 
// starttime, endtime, category, subcategory, detail.
function importRecordsCsv($filePath, $db) {
    $filename = basename($filePath);
    $date = pathinfo($filename, PATHINFO_FILENAME);
    if (($handle = fopen($filePath, "r")) !== false) {
        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === count($headers)) {
                $row = array_combine($headers, $data);
                $stmt = $db->prepare("INSERT INTO records (date, starttime, endtime, category, subcategory, detail) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $date,
                    isset($row['starttime']) ? $row['starttime'] : '',
                    isset($row['endtime']) ? $row['endtime'] : '',
                    isset($row['category']) ? $row['category'] : '',
                    isset($row['subcategory']) ? $row['subcategory'] : '',
                    isset($row['detail']) ? $row['detail'] : ''
                ]);
            }
        }
        fclose($handle);
    }
}

// Function to import categories from categories.csv.
// Expects headers: Category,Color,Subcategories.
function importCategoriesCsv($filePath, $db) {
    if (($handle = fopen($filePath, "r")) !== false) {
        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === count($headers)) {
                $row = array_combine($headers, $data);
                $stmt = $db->prepare("INSERT INTO categories (Category, Color, Subcategories) VALUES (?, ?, ?)");
                $stmt->execute([
                    isset($row['Category']) ? $row['Category'] : '',
                    isset($row['Color']) ? $row['Color'] : '',
                    isset($row['Subcategories']) ? $row['Subcategories'] : ''
                ]);
            }
        }
        fclose($handle);
    }
}

// Import all records CSV files in the data directory (skip categories.csv).
foreach (glob($dataDir . '/*.csv') as $file) {
    if (basename($file) === 'categories.csv') {
        continue;
    }
    importRecordsCsv($file, $db);
}

// Import categories.csv from the root folder (if it exists).
$categoriesFile = __DIR__ . '/categories.csv';
if (file_exists($categoriesFile)) {
    importCategoriesCsv($categoriesFile, $db);
    echo "Categories imported successfully.\n";
} else {
    echo "No categories.csv file found.\n";
}

echo "Migration completed successfully.\n";
?>
