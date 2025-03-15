<?php
// Prevent PHP errors from being output to response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Start with a clean output buffer
if (ob_get_level()) ob_end_clean();
ob_start();

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Set timezone to Helsinki (matching save.php)
date_default_timezone_set('Europe/Helsinki');

// Database connection
$dataDir = __DIR__ . '/data';
$dbFile = $dataDir . '/app.db';

try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

function calculateHours($startTime, $endTime) {
    $start = strtotime($startTime);
    $end = strtotime($endTime);
    return ($end - $start) / 3600; // Convert seconds to hours
}

function getRecordsInDateRange($db, $startDate, $endDate) {
    $stmt = $db->prepare("SELECT * FROM records WHERE date >= ? AND date <= ? ORDER BY date, starttime");
    $stmt->execute([$startDate, $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCategories($db) {
    $stmt = $db->prepare("SELECT * FROM categories ORDER BY Category");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'getSummary') {
        $startDate = $_POST['startDate'];
        $endDate = $_POST['endDate'];
        
        $records = getRecordsInDateRange($db, $startDate, $endDate);
        $categories = getCategories($db);
        
        // Initialize data structures
        $categoryHours = [];
        $categoryColors = [];
        $subcategoryHours = [];
        
        // Process categories
        foreach ($categories as $cat) {
            $categoryHours[$cat['Category']] = 0;
            $categoryColors[$cat['Category']] = $cat['Color'];
            $subcategories = explode(',', $cat['Subcategories']);
            foreach ($subcategories as $sub) {
                $subcategoryHours[$cat['Category']][$sub] = 0;
            }
        }
        
        // Calculate hours for each record
        foreach ($records as $record) {
            $hours = calculateHours($record['starttime'], $record['endtime']);
            $category = $record['category'];
            $subcategory = $record['subcategory'];
            
            if (!empty($category)) {
                $categoryHours[$category] += $hours;
                if (!empty($subcategory)) {
                    $subcategoryHours[$category][$subcategory] += $hours;
                }
            }
        }
        
        // Prepare stacked bar chart data
        $subcategoryData = [];
        foreach ($categories as $cat) {
            $subcategories = array_filter(explode('|', $cat['Subcategories'])); // Remove empty values
            
            foreach ($subcategories as $index => $sub) {
                $sub = trim($sub); // Remove whitespace
                if (!empty($sub)) {
                    $color = adjustColor($categoryColors[$cat['Category']], $index, count($subcategories));
                    // Create unique label by combining category and subcategory
                    $uniqueLabel = $cat['Category'] . ": " . $sub;
                    $subcategoryData[] = [
                        'label' => $uniqueLabel,
                        'data' => array_map(function($category) use ($subcategoryHours, $sub, $cat) {
                            // Only return hours for matching category, zero for others
                            return ($category === $cat['Category'] && isset($subcategoryHours[$category][$sub])) ? 
                                   round($subcategoryHours[$category][$sub], 2) : 0;
                        }, array_keys($categoryHours)),
                        'backgroundColor' => $color
                    ];
                }
            }
        }

        $response = [
            'categoryLabels' => array_keys($categoryHours),
            'categoryHours' => array_map(function($hours) { 
                return round($hours, 2); 
            }, array_values($categoryHours)), // Fixed array_values syntax
            'categoryColors' => array_values($categoryColors),
            'subcategoryData' => $subcategoryData
        ];

        // Clear any previous output
        if (ob_get_level()) ob_end_clean();
        
        // Debug logging
        error_log('Response structure: ' . print_r($response, true));
        
        try {
            $jsonResponse = json_encode(['status' => 'ok', 'data' => $response]);
            if ($jsonResponse === false) {
                throw new Exception(json_last_error_msg());
            }
            echo $jsonResponse;
        } catch (Exception $e) {
            error_log('JSON encoding error: ' . $e->getMessage());
            echo json_encode([
                'status' => 'error', 
                'message' => 'JSON encoding failed: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}

// Update the color adjustment function
function adjustColor($hexColor, $index, $total) {
    $hex = str_replace('#', '', $hexColor);
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    // Calculate opacity based on index
    $factor = 1 - ($index / ($total + 1)) * 0.5;
    
    return sprintf('rgba(%d, %d, %d, %f)', 
        $r,
        $g,
        $b,
        $factor
    );
}
?>