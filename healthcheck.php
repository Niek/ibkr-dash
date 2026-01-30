<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.inc.php';

loadEnv(__DIR__ . '/.env');

$response = apiRequest('GET', '/iserver/auth/status');
$data = $response['json'] ?? null;

if (!is_array($data)) {
    exit(1);
}

$authenticated = $data['authenticated'] ?? null;
if ($authenticated === true) {
    exit(0);
}

exit(1);
