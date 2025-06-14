<?php
date_default_timezone_set('Asia/Kolkata');

// Configuration
$serversFile = __DIR__ . '/data/servers.json';
$healthFile = __DIR__ . '/data/health.json';
$checkInterval = 5; // seconds
$maxHistory = 10000000; // Max history entries to keep per server

// Initialize health data structure
$healthData = file_exists($healthFile) ? json_decode(file_get_contents($healthFile), true) : [
    'last_checked' => null,
    'next_check' => null,
    'servers' => []
];

// Read servers configuration
$servers = json_decode(file_get_contents($serversFile), true) ?: [];

// Update metadata
$healthData['last_checked'] = date('Y-m-d H:i:s');
$healthData['next_check'] = date('Y-m-d H:i:s', time() + $checkInterval);

foreach ($servers as $server) {
    if (!$server['enabled']) {
        continue;
    }

    $url = $server['url'];
    $start = microtime(true);

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $latency = round((microtime(true) - $start) * 1000);
    $ip = gethostbyname(parse_url($url, PHP_URL_HOST));
    $headersSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $headersSize);
    curl_close($ch);

    // Determine health status
    $healthStatus = 'unknown';
    if (isStatusCodeInRange($statusCode, $server['success'])) {
        $healthStatus = 'up';
    } elseif (isStatusCodeInRange($statusCode, $server['errors'])) {
        $healthStatus = 'down';
    }

    // Create check record
    $checkRecord = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => $healthStatus,
        'status_code' => $statusCode,
        'latency_ms' => $latency,
        'ip_address' => $ip,
        'response_sample' => substr(strip_tags($body), 0, 200)
    ];

    // Initialize server entry if not exists
    if (!isset($healthData['servers'][$url])) {
        $healthData['servers'][$url] = [
            'name' => $server['name'] ?? '',
            'icon' => $server['icon'] ?? '',
            'current_status' => null,
            'health_history' => []
        ];
    }

    // Update current status
    $healthData['servers'][$url]['current_status'] = $checkRecord;
    
    // Add to history (limit to maxHistory entries)
    array_unshift($healthData['servers'][$url]['health_history'], $checkRecord);
    $healthData['servers'][$url]['health_history'] = array_slice(
        $healthData['servers'][$url]['health_history'], 
        0, 
        $maxHistory
    );

    // Update check count
    $healthData['servers'][$url]['total_checks'] = 
        ($healthData['servers'][$url]['total_checks'] ?? 0) + 1;
}

// Save health data
file_put_contents($healthFile, json_encode($healthData, JSON_PRETTY_PRINT));

// Helper function
function isStatusCodeInRange($code, $ranges) {
    foreach ($ranges as $range) {
        if (is_numeric($range)) {
            if ($code == $range) return true;
        } elseif (strpos($range, '-') !== false) {
            list($start, $end) = explode('-', $range);
            if ($code >= $start && $code <= $end) return true;
        }
    }
    return false;
}

echo "Health check completed at " . date('Y-m-d H:i:s');