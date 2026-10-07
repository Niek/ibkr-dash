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

// Upper bound on simultaneous gateway requests; the Client Portal Gateway
// throttles bursts and /iserver/marketdata/history allows 5 concurrent calls.
const API_MAX_CONCURRENCY = 4;

function apiRequest(string $method, string $path, ?array $payload = null, bool $bypassCache = false): array
{
    return apiRequestMany(['request' => [$method, $path, $payload]], $bypassCache)['request'];
}

/**
 * Run several gateway requests concurrently (curl_multi), returning responses keyed
 * like $requests. Each request is [method, path, payload?]. Each response has
 * url, raw, json, error and status; successful JSON responses are cached in APCu.
 */
function apiRequestMany(array $requests, bool $bypassCache = false): array
{
    $baseUrl = gatewayBaseUrl();
    $userAgent = 'IBKR-Pulse/1.0';
    $accept = 'application/json';
    $insecure = true;
    $useCache = function_exists('apcu_fetch');

    $responses = [];
    $pending = [];
    foreach ($requests as $key => $request) {
        $method = strtoupper((string)$request[0]);
        $payload = $request[2] ?? null;
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: ' . $userAgent,
        ];
        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $body = $body === false ? '{}' : $body;
        }
        $url = $baseUrl . $request[1];
        $cacheKey = 'ibkr_http_' . strtolower($method) . '_' . sha1($url . '|' . ($body ?? '') . '|' . $accept . '|' . $userAgent);

        if (!$bypassCache && $useCache) {
            $cached = apcu_fetch($cacheKey, $success);
            if ($success && is_array($cached)) {
                $responses[$key] = $cached;
                continue;
            }
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $method === 'POST' ? 15 : 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $pending[$key] = ['handle' => $handle, 'url' => $url, 'cacheKey' => $cacheKey];
    }

    $multi = curl_multi_init();
    $queue = array_keys($pending);
    $active = [];
    $start = static function () use (&$queue, &$active, $pending, $multi): void {
        while (count($active) < API_MAX_CONCURRENCY && $queue !== []) {
            $key = array_shift($queue);
            curl_multi_add_handle($multi, $pending[$key]['handle']);
            $active[spl_object_id($pending[$key]['handle'])] = $key;
        }
    };

    $start();
    while ($active !== []) {
        curl_multi_exec($multi, $running);
        if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
            usleep(1000);
        }
        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $key = $active[spl_object_id($handle)];
            unset($active[spl_object_id($handle)]);
            curl_multi_remove_handle($multi, $handle);

            $raw = curl_multi_getcontent($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = null;
            if ($info['result'] !== CURLE_OK) {
                $error = curl_error($handle) ?: curl_strerror($info['result']);
                $raw = false;
            } elseif ($status >= 400) {
                $error = 'HTTP ' . $status . ' from ' . $pending[$key]['url'];
                $raw = false;
            }

            $response = [
                'url' => $pending[$key]['url'],
                'raw' => $raw,
                'json' => is_string($raw) && $raw !== '' ? json_decode($raw, true) : null,
                'error' => $error,
                'status' => $status,
            ];
            // Only cache well-formed successes so a transient error isn't served for 5 minutes.
            $cacheable = is_array($response['json']) && !array_key_exists('error', $response['json']);
            if ($cacheable && $useCache) {
                apcu_store($pending[$key]['cacheKey'], $response, 300);
            }
            $responses[$key] = $response;
            $start();
        }
    }
    curl_multi_close($multi);

    // Preserve the caller's key order.
    return array_replace(array_intersect_key($requests, $responses), $responses);
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
