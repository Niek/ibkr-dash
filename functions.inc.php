<?php

declare(strict_types=1);

function loadEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $vars = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($vars)) {
        return [];
    }

    foreach ($vars as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $value = is_scalar($value) ? (string)$value : '';
        $vars[$key] = $value;
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }

    return $vars;
}

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function apiRequest(string $method, string $path, ?array $payload = null): array
{
    $baseUrl = rtrim(env('GATEWAY_BASE_URL', 'https://localhost:5050/v1/api'), '/');
    $userAgent = 'IBKR-Pulse/1.0';
    $accept = 'application/json';
    $insecure = true;
    $method = strtoupper($method);
    $timeout = $method === 'POST' ? 15 : 10;

    $headers = [
        'Accept: ' . $accept,
        'User-Agent: ' . $userAgent,
    ];

    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $http = [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'timeout' => $timeout,
    ];
    if ($body !== null) {
        $http['content'] = $body === false ? '{}' : $body;
    }

    $context = stream_context_create([
        'http' => $http,
        'ssl' => [
            'verify_peer' => !$insecure,
            'verify_peer_name' => !$insecure,
        ],
    ]);

    $url = $baseUrl . $path;
    $cacheKey = 'ibkr_http_' . strtolower($method) . '_' . sha1($url . '|' . ($body ?? '') . '|' . $accept . '|' . $userAgent);
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey, $success);
        if ($success && is_array($cached)) {
            return $cached;
        }
    }
    $raw = @file_get_contents($url, false, $context);
    $error = $raw === false ? error_get_last() : null;

    $response = [
        'url' => $url,
        'raw' => $raw,
        'json' => $raw ? json_decode($raw, true) : null,
        'error' => $error['message'] ?? null,
    ];

    if ($raw !== false && function_exists('apcu_store')) {
        apcu_store($cacheKey, $response, 300);
    }

    return $response;
}
