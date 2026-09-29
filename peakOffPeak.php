<?php

session_start();
date_default_timezone_set('Asia/Karachi');

header('Content-Type: application/json');

$url = 'https://bill.pitc.com.pk/peak-offpeak-timing';

$html = fetchWithCache($url);

if (!$html) {
    echo json_encode([
        'date' => date('Y-m-d H:i:s'),
        'current_status' => 'UNKNOWN',
        'peak_timing' => null,
        'error' => 'Unable to fetch remote data and no cache available'
    ], JSON_PRETTY_PRINT);
    exit;
}

libxml_use_internal_errors(true);

$dom = new DOMDocument();
$dom->loadHTML($html);

$xpath = new DOMXPath($dom);

// Target table rows
$rows = $xpath->query('//table[contains(@class,"table-bordered")]/tbody/tr');

$currentMonth = date('n');
$currentTime  = strtotime(date('g:i A'));

$response = [
    'date' => date('Y-m-d H:i:s'),
    'current_status' => 'UNKNOWN',
    'peak_timing' => null
];

foreach ($rows as $row) {

    $cells = $row->getElementsByTagName('td');

    if ($cells->length < 2) continue;

    $season = trim($cells->item(0)->textContent);
    $peakTiming = trim($cells->item(1)->textContent);

    if (!seasonMatches($season, $currentMonth)) {
        continue;
    }

    $response['peak_timing'] = $peakTiming;

    list($from, $to) = array_map('trim', explode('to', $peakTiming));

    $fromTime = strtotime($from);
    $toTime   = strtotime($to);

    if ($currentTime >= $fromTime && $currentTime < $toTime) {
        $response['current_status'] = 'PEAK';
    } else {
        $response['current_status'] = 'OFF PEAK';
    }

    break;
}

echo json_encode($response, JSON_PRETTY_PRINT);


/**
 * Fetch with HTTP check + session cache fallback
 */
function fetchWithCache($url)
{
    $cacheKey = 'pitc_peak_html';

    // Return cache if exists and fresh enough (optional 6-hour TTL)
    if (isset($_SESSION[$cacheKey])) {
        return $_SESSION[$cacheKey];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Only cache if HTTP 200 and valid response
    if ($httpCode === 200 && $html) {
        $_SESSION[$cacheKey] = $html;
        return $html;
    }

    // fallback to cache if available
    return $_SESSION[$cacheKey] ?? null;
}


/**
 * Season matching logic
 */
function seasonMatches($season, $currentMonth)
{
    $months = [
        'Jan' => 1,'Feb' => 2,'Mar' => 3,'Apr' => 4,
        'May' => 5,'Jun' => 6,'Jul' => 7,'Aug' => 8,
        'Sep' => 9,'Oct' => 10,'Nov' => 11,'Dec' => 12,
    ];

    if (!preg_match('/([A-Za-z]{3})\s+to\s+([A-Za-z]{3})/i', $season, $m)) {
        return false;
    }

    $start = $months[ucfirst(strtolower($m[1]))];
    $end   = $months[ucfirst(strtolower($m[2]))];

    if ($start > $end) {
        return ($currentMonth >= $start || $currentMonth <= $end);
    }

    return ($currentMonth >= $start && $currentMonth <= $end);
}
