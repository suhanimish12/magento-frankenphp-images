<?php
/**
 * Health Check Endpoint for Magento FrankenPHP
 * 
 * Simple health check that verifies the Docker image components.
 * Does NOT check application-level services (database, Redis, etc.)
 * 
 * Usage: curl http://localhost/health.php
 * 
 * Response codes:
 *   200 - All checks passed
 *   503 - One or more checks failed
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$status = 'healthy';
$httpCode = 200;
$checks = [];

// Check 1: PHP Version
$checks['php'] = [
    'status' => 'healthy',
    'version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
];

// Check 2: Required PHP Extensions
$requiredExtensions = [
    'bcmath', 'gd', 'intl', 'mbstring', 'opcache', 
    'pdo_mysql', 'soap', 'xsl', 'zip', 'sockets', 
    'redis', 'apcu'
];

$missingExtensions = array_filter($requiredExtensions, function($ext) {
    return !extension_loaded($ext);
});

$checks['extensions'] = [
    'status' => empty($missingExtensions) ? 'healthy' : 'unhealthy',
    'required' => count($requiredExtensions),
    'loaded' => count($requiredExtensions) - count($missingExtensions),
    'missing' => array_values($missingExtensions),
];

if (!empty($missingExtensions)) {
    $status = 'unhealthy';
    $httpCode = 503;
}

// Check 3: OPcache Status
if (function_exists('opcache_get_status')) {
    $opcacheStatus = opcache_get_status(false);
    $checks['opcache'] = [
        'status' => $opcacheStatus !== false ? 'healthy' : 'unhealthy',
        'enabled' => $opcacheStatus !== false,
        'jit_enabled' => isset($opcacheStatus['jit']) && $opcacheStatus['jit']['enabled'],
    ];
    
    if ($opcacheStatus === false) {
        $status = 'degraded';
    }
} else {
    $checks['opcache'] = [
        'status' => 'unhealthy',
        'enabled' => false,
    ];
    $status = 'degraded';
}

// Check 4: Disk Space
$diskFree = disk_free_space('/var/www/html');
$diskTotal = disk_total_space('/var/www/html');
$diskUsedPercent = round((1 - ($diskFree / $diskTotal)) * 100, 2);

$checks['disk'] = [
    'status' => $diskUsedPercent < 90 ? 'healthy' : 'degraded',
    'used_percent' => $diskUsedPercent,
    'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
];

if ($diskUsedPercent >= 95) {
    $status = 'unhealthy';
    $httpCode = 503;
} elseif ($diskUsedPercent >= 90) {
    $status = 'degraded';
}

// Response
$response = [
    'status' => $status,
    'timestamp' => date('c'),
    'checks' => $checks,
];

http_response_code($httpCode);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
