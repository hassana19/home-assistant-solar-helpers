<?php
header('Content-Type: application/json');

$username="abcdef";
$password="123456";
/**
 * Generic cURL GET function with mobile User-Agent
 */
function curl_get($url)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: SolarApp/2.0 (Linux; Android 14; Pixel 7 Pro) Mobile',
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return null;
    }

    return $response;
}

// --- Step 1: Read input parameters ---
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');

// --- Step 2: Validate ---
if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
    echo json_encode([
        "code" => 1,
        "msg" => "INVALID_INPUT",
        "count" => 0,
        "data" => new stdClass()
    ]);
    exit;
}

// --- Step 3: Initialize month data with 0 ---
$totalDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$data = [];

for ($day = 1; $day <= $totalDays; $day++) {
    $dateKey = sprintf("%04d-%02d-%02d", $year, $month, $day);
    $data[$dateKey] = 0;
}

// --- Step 4: Build API URL and fetch data ---
$dateParam = sprintf("%04d-%02d", $year, $month);
$apiUrl = "https://www.aotaicloud.com/solarweb/user/getMonthBar?atun=" . urlencode($username) . "&atpd=" . urlencode($password) . "&date=" . $dateParam;

$response = curl_get($apiUrl);

// --- Step 5: Merge data ---
if ($response) {
    $apiData = json_decode($response, true);

    if (isset($apiData['code']) && $apiData['code'] === 0 && isset($apiData['data'])) {
        foreach ($apiData['data'] as $dateKey => $value) {
            if (isset($data[$dateKey])) {
                $data[$dateKey] = $value;
            }
        }
    }
}

// --- Step 6: Final response ---
$result = [
    "code" => 0,
    "msg" => "SUCCESS",
    "count" => count($data),
    "data" => $data
];

echo json_encode($result, JSON_PRETTY_PRINT);