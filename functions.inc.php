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

function requireBasicAuthIfConfigured(): void
{
    if (php_sapi_name() === 'cli') {
        return;
    }

    $username = env('USERNAME');
    $password = env('PASSWORD');
    if ($username === '' || $password === '') {
        return;
    }

    header('Cache-Control: no-cache, must-revalidate, max-age=0');

    $providedUser = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $providedPass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');

    // Evaluate both comparisons so timing doesn't reveal which field was wrong.
    $userOk = hash_equals($username, $providedUser);
    $passOk = hash_equals($password, $providedPass);
    if (!$userOk || !$passOk) {
        header('HTTP/1.1 401 Authorization Required');
        header('WWW-Authenticate: Basic realm="Access denied"');
        exit;
    }
}

function gatewayBaseUrl(): string
{
    return rtrim(env('GATEWAY_BASE_URL', 'https://localhost:5050/v1/api'), '/');
}

function apiRequest(string $method, string $path, ?array $payload = null, bool $bypassCache = false): array
{
    $baseUrl = gatewayBaseUrl();
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
    if (!$bypassCache && function_exists('apcu_fetch')) {
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

    // Only cache well-formed successes so a transient error isn't served for 5 minutes.
    $cacheable = is_array($response['json']) && !array_key_exists('error', $response['json']);
    if ($cacheable && function_exists('apcu_store')) {
        apcu_store($cacheKey, $response, 300);
    }

    return $response;
}

function extractAccountIds($accountsData): array
{
    if (!is_array($accountsData)) {
        return [];
    }

    $list = $accountsData;
    if (isset($accountsData['accounts']) && is_array($accountsData['accounts'])) {
        $list = $accountsData['accounts'];
    }

    $ids = [];
    foreach ($list as $item) {
        if (is_string($item) && $item !== '') {
            $ids[] = $item;
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        $candidate = $item['accountId'] ?? $item['id'] ?? $item['account'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            $ids[] = $candidate;
        }
    }

    return array_values(array_unique($ids));
}

function formatIbkrDate($value, bool $withYear = false): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Ymd', $value);
    if ($date instanceof DateTimeImmutable) {
        return $date->format($withYear ? "M j 'y" : 'M j');
    }
    return null;
}

function extractNavSeries($performanceData, bool $withYear = false): array
{
    $result = [
        'labels' => [],
        'values' => [],
        'currency' => null,
        'rawDates' => [],
    ];

    if (!is_array($performanceData)) {
        return $result;
    }

    $nav = $performanceData['nav'] ?? null;
    if (!is_array($nav)) {
        return $result;
    }

    $dates = $nav['dates'] ?? null;
    $data = $nav['data'] ?? null;
    if (!is_array($dates) || !is_array($data) || !isset($data[0]) || !is_array($data[0])) {
        return $result;
    }

    $navs = $data[0]['navs'] ?? null;
    if (!is_array($navs)) {
        return $result;
    }

    $count = min(count($dates), count($navs));
    for ($i = 0; $i < $count; $i++) {
        $label = formatIbkrDate($dates[$i], $withYear);
        if ($label === null) {
            continue;
        }
        if (!is_numeric($navs[$i])) {
            continue;
        }
        $result['labels'][] = $label;
        $result['values'][] = round((float)$navs[$i], 2);
        $result['rawDates'][] = (string)$dates[$i];
    }

    $currency = $data[0]['baseCurrency'] ?? null;
    if (is_string($currency) && $currency !== '') {
        $result['currency'] = $currency;
    }

    return $result;
}
