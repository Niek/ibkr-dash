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

function formatIbkrDate($value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Ymd', $value);
    if ($date instanceof DateTimeImmutable) {
        return $date->format('M j');
    }
    return null;
}

function extractNavSeries($performanceData): array
{
    $result = [
        'labels' => [],
        'values' => [],
        'currency' => null,
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
        $label = formatIbkrDate($dates[$i]);
        if ($label === null) {
            continue;
        }
        if (!is_numeric($navs[$i])) {
            continue;
        }
        $result['labels'][] = $label;
        $result['values'][] = round((float)$navs[$i], 2);
    }

    $currency = $data[0]['baseCurrency'] ?? null;
    if (is_string($currency) && $currency !== '') {
        $result['currency'] = $currency;
    }

    return $result;
}

function extractScalarValue($value)
{
    if (is_scalar($value)) {
        return $value;
    }

    if (!is_array($value)) {
        return null;
    }

    $scalarKeys = [
        'value',
        'amount',
        'val',
        'v',
        'number',
        'netliquidation',
        'netLiquidation',
        'nlv',
        'balance',
    ];

    foreach ($scalarKeys as $key) {
        if (array_key_exists($key, $value) && is_scalar($value[$key])) {
            return $value[$key];
        }
    }

    foreach ($scalarKeys as $key) {
        if (array_key_exists($key, $value) && is_array($value[$key])) {
            $candidate = extractScalarValue($value[$key]);
            if ($candidate !== null) {
                return $candidate;
            }
        }
    }

    foreach ($value as $item) {
        if (is_scalar($item)) {
            return $item;
        }
        if (!is_array($item)) {
            continue;
        }
        $candidate = extractScalarValue($item);
        if ($candidate !== null) {
            return $candidate;
        }
    }

    return null;
}

function extractCurrencyFromValue($value): ?string
{
    if (!is_array($value)) {
        return null;
    }

    $currencyKeys = [
        'currency',
        'curr',
        'ccy',
        'baseCurrency',
        'base_currency',
    ];

    foreach ($currencyKeys as $key) {
        if (array_key_exists($key, $value) && is_string($value[$key]) && $value[$key] !== '') {
            return $value[$key];
        }
    }

    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }
        $candidate = extractCurrencyFromValue($item);
        if ($candidate !== null) {
            return $candidate;
        }
    }

    return null;
}

function extractFxRateToBase($ledgerData, string $currency, ?string $baseCurrency): ?float
{
    if ($currency === '' || $baseCurrency === null || $baseCurrency === '') {
        return null;
    }

    if ($currency === $baseCurrency) {
        return 1.0;
    }

    if (!is_array($ledgerData)) {
        return null;
    }

    $entry = $ledgerData[$currency] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $preferredKeys = [
        'fxRateToBase',
        'fxrateToBase',
        'fxRateToBaseCurrency',
        'fxRate',
        'fxrate',
        'exchangeRate',
        'exchangerate',
    ];

    foreach ($preferredKeys as $key) {
        if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
            return (float)$entry[$key];
        }
    }

    foreach ($entry as $key => $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $lower = strtolower((string)$key);
        if (str_contains($lower, 'fxrate') || str_contains($lower, 'exchange')) {
            return (float)$value;
        }
    }

    return null;
}

function extractCashBalances($ledgerData): array
{
    if (!is_array($ledgerData)) {
        return [];
    }

    $balances = [];
    $keys = [
        'cashbalance',
        'cashBalance',
        'cash',
        'totalcashvalue',
        'totalcash',
        'availablecash',
    ];

    foreach ($ledgerData as $currency => $entry) {
        if (!is_array($entry) || !is_string($currency)) {
            continue;
        }
        $value = null;
        foreach ($keys as $key) {
            if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
                $value = (float)$entry[$key];
                break;
            }
        }
        if ($value !== null) {
            $balances[] = [
                'currency' => $currency,
                'value' => $value,
            ];
        }
    }

    return $balances;
}

function extractBaseCashBalance($ledgerData): ?float
{
    if (!is_array($ledgerData)) {
        return null;
    }

    $baseEntry = $ledgerData['BASE'] ?? null;
    if (!is_array($baseEntry)) {
        return null;
    }

    $keys = [
        'cashbalance',
        'cashBalance',
        'cash',
        'totalcashvalue',
        'totalcash',
        'availablecash',
    ];

    foreach ($keys as $key) {
        if (array_key_exists($key, $baseEntry) && is_numeric($baseEntry[$key])) {
            return (float)$baseEntry[$key];
        }
    }

    return null;
}

function extractPartitionedPnl($pnlData, string $accountId): array
{
    $result = [
        'dpl' => null,
        'upl' => null,
        'nl' => null,
        'el' => null,
        'mv' => null,
        'key' => null,
    ];

    if (!is_array($pnlData) || !isset($pnlData['upnl']) || !is_array($pnlData['upnl'])) {
        return $result;
    }

    $candidates = [];
    foreach ($pnlData['upnl'] as $key => $value) {
        if (!is_string($key) || !is_array($value)) {
            continue;
        }
        if ($accountId !== '' && str_starts_with($key, $accountId)) {
            $candidates[$key] = $value;
        }
    }

    if (empty($candidates)) {
        return $result;
    }

    $selectedKey = array_key_first($candidates);
    foreach (array_keys($candidates) as $key) {
        if (str_contains($key, '.Core')) {
            $selectedKey = $key;
            break;
        }
    }

    $data = $candidates[$selectedKey] ?? [];
    $result['key'] = $selectedKey;
    foreach (['dpl', 'upl', 'nl', 'el', 'mv'] as $field) {
        if (array_key_exists($field, $data) && is_numeric($data[$field])) {
            $result[$field] = (float)$data[$field];
        }
    }

    return $result;
}

function extractWatchlistsSummary($watchlistsData): array
{
    if (!is_array($watchlistsData)) {
        return [];
    }

    $userLists = $watchlistsData['data']['user_lists'] ?? null;
    if (!is_array($userLists)) {
        return [];
    }

    $lists = [];
    foreach ($userLists as $list) {
        if (!is_array($list)) {
            continue;
        }
        $id = $list['id'] ?? null;
        $name = $list['name'] ?? null;
        if ($id === null || $name === null) {
            continue;
        }
        $lists[] = [
            'id' => (string)$id,
            'name' => (string)$name,
        ];
    }

    return $lists;
}

function extractWatchlistInstruments($watchlistData): array
{
    if (!is_array($watchlistData)) {
        return [];
    }
    $instruments = $watchlistData['instruments'] ?? null;
    if (!is_array($instruments)) {
        return [];
    }
    return array_values(array_filter($instruments, 'is_array'));
}

function computeHistoryPerformance($historyData): ?float
{
    if (!is_array($historyData)) {
        return null;
    }
    $bars = $historyData['data'] ?? null;
    if (!is_array($bars) || count($bars) < 2) {
        return null;
    }
    $first = null;
    $last = null;
    foreach ($bars as $bar) {
        if (!is_array($bar) || !isset($bar['c']) || !is_numeric($bar['c'])) {
            continue;
        }
        if ($first === null) {
            $first = (float)$bar['c'];
        }
        $last = (float)$bar['c'];
    }
    if ($first === null || $last === null || $first == 0.0) {
        return null;
    }
    return (($last - $first) / $first) * 100;
}

function extractTransactionsList($transactionsData): array
{
    if (!is_array($transactionsData)) {
        return [];
    }

    if (isset($transactionsData['transactions']) && is_array($transactionsData['transactions'])) {
        $transactionsData = $transactionsData['transactions'];
    }

    if (isset($transactionsData['data']) && is_array($transactionsData['data'])) {
        return $transactionsData['data'];
    }

    if (isset($transactionsData['transactions']['data']) && is_array($transactionsData['transactions']['data'])) {
        return $transactionsData['transactions']['data'];
    }

    return array_values(array_filter($transactionsData, 'is_array'));
}

function extractTransactionConid(array $tx): ?int
{
    $candidate = $tx['conid'] ?? $tx['conId'] ?? $tx['conidEx'] ?? null;
    if (is_numeric($candidate)) {
        return (int)$candidate;
    }
    return null;
}

function extractTransactionQuantity(array $tx): ?float
{
    $candidate = $tx['quantity'] ?? $tx['qty'] ?? $tx['tradeQuantity'] ?? $tx['size'] ?? $tx['units'] ?? null;
    if (is_numeric($candidate)) {
        return (float)$candidate;
    }
    $desc = $tx['desc'] ?? $tx['description'] ?? null;
    if (is_string($desc) && preg_match('/Quantity:\\s*([0-9,\\.]+)/i', $desc, $matches) === 1) {
        $value = str_replace(',', '', $matches[1]);
        if (is_numeric($value)) {
            return (float)$value;
        }
    }
    return null;
}

function extractTransactionSide(array $tx): ?string
{
    $candidate = $tx['side'] ?? $tx['action'] ?? $tx['buySell'] ?? $tx['type'] ?? null;
    if (!is_string($candidate)) {
        return null;
    }
    $normalized = strtolower($candidate);
    if (str_contains($normalized, 'buy')) {
        return 'buy';
    }
    if (str_contains($normalized, 'sell')) {
        return 'sell';
    }
    return null;
}

function extractTransactionCurrency(array $tx): ?string
{
    $candidate = $tx['currency'] ?? $tx['ccy'] ?? $tx['curr'] ?? $tx['cur'] ?? null;
    if (is_string($candidate) && $candidate !== '') {
        return $candidate;
    }
    return null;
}

function extractTransactionPrice(array $tx): ?float
{
    $candidate = $tx['pr'] ?? $tx['tradePrice'] ?? $tx['price'] ?? $tx['avgPrice'] ?? null;
    if (is_numeric($candidate)) {
        return (float)$candidate;
    }
    return null;
}

function extractTransactionFxRate(array $tx): ?float
{
    $candidate = $tx['fxRate'] ?? $tx['fxrate'] ?? $tx['fxRateToBase'] ?? $tx['exchangeRate'] ?? null;
    if (is_numeric($candidate)) {
        return (float)$candidate;
    }
    return null;
}

function extractTransactionAmountBase(array $tx, string $baseCurrency): ?float
{
    if (array_key_exists('amt', $tx) && is_numeric($tx['amt'])) {
        return abs((float)$tx['amt']);
    }

    $baseKeys = [
        'amountInBase',
        'amountBase',
        'baseAmount',
        'baseValue',
        'baseCurrencyAmount',
        'netBase',
    ];

    foreach ($baseKeys as $key) {
        if (array_key_exists($key, $tx) && is_numeric($tx[$key])) {
            return abs((float)$tx[$key]);
        }
    }

    $amountKeys = [
        'amount',
        'netAmount',
        'proceeds',
        'tradeMoney',
        'tradeAmount',
        'netCash',
        'total',
        'value',
        'cost',
    ];

    foreach ($amountKeys as $key) {
        if (array_key_exists($key, $tx) && is_numeric($tx[$key])) {
            return abs((float)$tx[$key]);
        }
    }

    $price = $tx['tradePrice'] ?? $tx['price'] ?? $tx['avgPrice'] ?? null;
    $qty = extractTransactionQuantity($tx);
    if (is_numeric($price) && $qty !== null) {
        $amount = abs((float)$price * $qty);
        $txCurrency = extractTransactionCurrency($tx);
        $fxRate = extractTransactionFxRate($tx);
        if ($txCurrency !== null && $baseCurrency !== '' && $txCurrency !== $baseCurrency && $fxRate !== null) {
            return $amount * $fxRate;
        }
        return $amount;
    }

    return null;
}

function extractTransactionDateKey(array $tx): int
{
    $candidate = $tx['tradeDate'] ?? $tx['date'] ?? $tx['tradeDateTime'] ?? $tx['tdate'] ?? null;
    if (is_string($candidate)) {
        $digits = preg_replace('/\\D+/', '', $candidate);
        if ($digits !== '' && strlen($digits) >= 8) {
            return (int)substr($digits, 0, 8);
        }
    }
    return 0;
}

function groupTransactionsByConid($transactionsData, string $baseCurrency): array
{
    $list = extractTransactionsList($transactionsData);
    $grouped = [];

    foreach ($list as $tx) {
        if (!is_array($tx)) {
            continue;
        }
        $conid = extractTransactionConid($tx);
        if ($conid === null) {
            continue;
        }
        $qty = extractTransactionQuantity($tx);
        if ($qty === null) {
            continue;
        }
        $side = extractTransactionSide($tx);
        if ($side === 'sell') {
            $qty = -abs($qty);
        } elseif ($side === 'buy') {
            $qty = abs($qty);
        }
        $amountBase = extractTransactionAmountBase($tx, $baseCurrency);
        if ($amountBase === null) {
            continue;
        }
        $fxRate = extractTransactionFxRate($tx);
        $weight = abs($qty);
        $price = extractTransactionPrice($tx);
        if ($price !== null) {
            $weight = abs($qty) * $price;
        } elseif ($fxRate !== null && $fxRate > 0.0) {
            $weight = $amountBase / $fxRate;
        }
        $dateKey = extractTransactionDateKey($tx);
        $grouped[$conid][] = [
            'qty' => $qty,
            'amountBase' => $amountBase,
            'fxRate' => $fxRate,
            'weight' => $weight,
            'dateKey' => $dateKey,
        ];
    }

    foreach ($grouped as $conid => $txs) {
        usort($txs, function (array $a, array $b): int {
            return $a['dateKey'] <=> $b['dateKey'];
        });
        $grouped[$conid] = $txs;
    }

    return $grouped;
}

function computeWeightedFxRate(array $txs): ?float
{
    $totalWeight = 0.0;
    $weighted = 0.0;
    foreach ($txs as $tx) {
        $qty = (float)($tx['qty'] ?? 0.0);
        $weight = (float)($tx['weight'] ?? 0.0);
        $fxRate = $tx['fxRate'] ?? null;
        if ($qty <= 0.0 || $fxRate === null || !is_numeric($fxRate)) {
            continue;
        }
        if ($weight <= 0.0) {
            $weight = $qty;
        }
        $weighted += $weight * (float)$fxRate;
        $totalWeight += $weight;
    }
    if ($totalWeight <= 0.0) {
        return null;
    }
    return $weighted / $totalWeight;
}

function computeBasePnlFromTransactions(array $txs, ?float $currentQty, ?float $currentValueBase): array
{
    $qty = 0.0;
    $cost = 0.0;
    $realized = 0.0;
    $costSold = 0.0;

    foreach ($txs as $tx) {
        $txQty = (float)($tx['qty'] ?? 0.0);
        $amountBase = (float)($tx['amountBase'] ?? 0.0);
        if ($amountBase == 0.0) {
            continue;
        }

        if ($txQty > 0) {
            $cost += $amountBase;
            $qty += $txQty;
            continue;
        }

        $sellQty = abs($txQty);
        if ($qty <= 0.0) {
            continue;
        }
        $avgCost = $cost / $qty;
        $effectiveQty = min($sellQty, $qty);
        $costRemoved = $avgCost * $effectiveQty;
        $realized += $amountBase - $costRemoved;
        $costSold += $costRemoved;
        $cost -= $costRemoved;
        $qty -= $effectiveQty;
    }

    if ($currentQty !== null && $currentQty > 0.0) {
        if ($qty > 0.0 && abs($currentQty - $qty) > 0.0001) {
            $scale = $currentQty / $qty;
            $cost *= $scale;
        } elseif ($qty == 0.0 && $cost > 0.0) {
            $qty = $currentQty;
        }
    }

    $unrealized = null;
    $unrealizedPct = null;
    if ($currentValueBase !== null && $cost > 0.0) {
        $unrealized = $currentValueBase - $cost;
        $unrealizedPct = ($unrealized / $cost) * 100;
    }

    $realizedPct = null;
    if ($costSold > 0.0) {
        $realizedPct = ($realized / $costSold) * 100;
    }

    return [
        'costBasisBase' => $cost > 0.0 ? $cost : null,
        'unrealizedBase' => $unrealized,
        'unrealizedPct' => $unrealizedPct,
        'realizedBase' => $realized !== 0.0 ? $realized : 0.0,
        'realizedPct' => $realizedPct,
    ];
}

function extractNetLiquidation($summaryData, $ledgerData): array
{
    $keyCandidates = [
        'netliquidation',
        'netliquidationvalue',
        'net_liquidation',
        'net_liquidation_value',
        'nlv',
    ];

    if (is_array($summaryData)) {
        $lowerMap = [];
        foreach ($summaryData as $key => $value) {
            if (is_string($key)) {
                $lowerMap[strtolower($key)] = $value;
            }
        }

        foreach ($keyCandidates as $candidate) {
            if (array_key_exists($candidate, $lowerMap)) {
                $currency = null;
                if (isset($lowerMap['currency']) && is_string($lowerMap['currency'])) {
                    $currency = $lowerMap['currency'];
                }
                if (isset($lowerMap['basecurrency']) && is_string($lowerMap['basecurrency'])) {
                    $currency = $lowerMap['basecurrency'];
                }

                return [
                    'value' => $lowerMap[$candidate],
                    'currency' => $currency,
                    'source' => 'summary',
                ];
            }
        }

        foreach ($summaryData as $item) {
            if (!is_array($item)) {
                continue;
            }
            $tag = strtolower((string)($item['tag'] ?? $item['field'] ?? $item['key'] ?? ''));
            if ($tag === '' || !in_array($tag, $keyCandidates, true)) {
                continue;
            }
            return [
                'value' => $item['value'] ?? $item['amount'] ?? null,
                'currency' => $item['currency'] ?? null,
                'source' => 'summary',
            ];
        }
    }

    if (is_array($ledgerData)) {
        foreach ($ledgerData as $currency => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($entry as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (in_array(strtolower($key), $keyCandidates, true)) {
                    return [
                        'value' => $value,
                        'currency' => is_string($currency) ? $currency : null,
                        'source' => 'ledger',
                    ];
                }
            }
        }
    }

    return [
        'value' => null,
        'currency' => null,
        'source' => null,
    ];
}

loadEnv(__DIR__ . '/.env');

$auth = apiRequest('GET', '/iserver/auth/status');
$authData = $auth['json'] ?? [];
$authOk = is_array($authData) && ($authData['authenticated'] ?? false) === true;
$connected = is_array($authData) && ($authData['connected'] ?? false) === true;
$serverName = $authData['serverInfo']['serverName'] ?? 'n/a';
$serverVersion = $authData['serverInfo']['serverVersion'] ?? 'n/a';
$gatewayHover = $auth['error']
    ? $auth['error']
    : 'Server: ' . $serverName . ' | Version: ' . $serverVersion;

$partitionedPnl = apiRequest('GET', '/iserver/account/pnl/partitioned');
$partitionedPnlData = $partitionedPnl['json'] ?? [];

// Watchlists feature disabled (too slow on load).
// $watchlists = apiRequest('GET', '/iserver/watchlists');
// $watchlistsData = $watchlists['json'] ?? [];
// $watchlistSummaries = extractWatchlistsSummary($watchlistsData);
// $watchlistRows = [];
// foreach ($watchlistSummaries as $summary) {
//     $watchlist = apiRequest('GET', '/iserver/watchlist?id=' . rawurlencode($summary['id']));
//     $watchlistData = $watchlist['json'] ?? [];
//     $instruments = extractWatchlistInstruments($watchlistData);
//     foreach ($instruments as $instrument) {
//         $conid = $instrument['conid'] ?? $instrument['C'] ?? null;
//         if ($conid === null) {
//             continue;
//         }
//         $symbol = $instrument['ticker'] ?? $instrument['name'] ?? $instrument['fullName'] ?? 'n/a';
//         $assetClass = $instrument['assetClass'] ?? $instrument['ST'] ?? 'n/a';
//         $watchlistRows[] = [
//             'watchlist' => $summary['name'],
//             'conid' => (int)$conid,
//             'symbol' => (string)$symbol,
//             'assetClass' => (string)$assetClass,
//         ];
//     }
// }
//
// $watchlistCurrencies = [];
// $watchlistPerf = [];
// $watchlistConids = array_values(array_unique(array_map(function (array $row): int {
//     return (int)$row['conid'];
// }, $watchlistRows)));
//
// foreach ($watchlistConids as $conid) {
//     $contract = apiRequest('GET', '/iserver/contract/' . rawurlencode((string)$conid) . '/info');
//     $contractData = $contract['json'] ?? [];
//     if (is_array($contractData) && isset($contractData['currency']) && is_string($contractData['currency'])) {
//         $watchlistCurrencies[$conid] = $contractData['currency'];
//     }
//     $history = apiRequest('GET', '/iserver/marketdata/history?conid=' . rawurlencode((string)$conid) . '&period=1M&bar=1d');
//     $historyData = $history['json'] ?? [];
//     $perf = computeHistoryPerformance($historyData);
//     if ($perf !== null) {
//         $watchlistPerf[$conid] = $perf;
//     }
// }

$accounts = apiRequest('GET', '/iserver/accounts');
$accountData = $accounts['json'] ?? [];
$accountIds = extractAccountIds($accountData);

$accountsView = [];
foreach ($accountIds as $accountId) {
    $summary = apiRequest('GET', '/portfolio/' . rawurlencode($accountId) . '/summary');
    $summaryData = $summary['json'] ?? [];
    $ledger = apiRequest('GET', '/portfolio/' . rawurlencode($accountId) . '/ledger');
    $ledgerData = $ledger['json'] ?? [];
    $intradayPnl = extractPartitionedPnl($partitionedPnlData, $accountId);
    $performance = apiRequest('POST', '/pa/performance', [
        'acctIds' => [$accountId],
        'period' => '30D',
    ]);
    $performanceData = $performance['json'] ?? [];
    $navSeries = extractNavSeries($performanceData);
    if (count($navSeries['labels']) === 0) {
        $performance = apiRequest('POST', '/pa/performance', [
            'acctIds' => [$accountId],
            'period' => '1M',
        ]);
        $performanceData = $performance['json'] ?? [];
        $navSeries = extractNavSeries($performanceData);
    }
    $positions = apiRequest('GET', '/portfolio/' . rawurlencode($accountId) . '/positions');
    $positionsData = $positions['json'] ?? [];

    $netLiquidation = extractNetLiquidation($summaryData, $ledgerData);
    $cashBalances = extractCashBalances($ledgerData);
    $baseCashBalance = extractBaseCashBalance($ledgerData);
    $netLiquidationValue = $netLiquidation['value'];
    $netLiquidationCurrency = $netLiquidation['currency'];
    $netLiquidationSource = $netLiquidation['source'];
    if (is_array($netLiquidationValue)) {
        if ($netLiquidationCurrency === null) {
            $netLiquidationCurrency = extractCurrencyFromValue($netLiquidationValue);
        }
        $netLiquidationValue = extractScalarValue($netLiquidationValue);
    }
    $netLiquidationDisplay = 'n/a';
    if ($netLiquidationValue !== null) {
        if (is_numeric($netLiquidationValue)) {
            $netLiquidationDisplay = number_format((float)$netLiquidationValue, 2);
        } else {
            $netLiquidationDisplay = (string)$netLiquidationValue;
        }
        if ($netLiquidationCurrency) {
            $netLiquidationDisplay = $netLiquidationCurrency . ' ' . $netLiquidationDisplay;
        }
    }

    $chartLabels = $navSeries['labels'];
    $chartData = $navSeries['values'];
    $chartCurrency = $navSeries['currency'] ?? $netLiquidationCurrency ?? 'USD';
    $hasPerformanceData = count($chartLabels) > 0 && count($chartData) > 0;

    $positionsRows = [];
    if (is_array($positionsData)) {
        foreach ($positionsData as $row) {
            if (!is_array($row)) {
                continue;
            }
            $positionsRows[] = $row;
        }
    }

    $transactionsData = [];
    $transactionsByConid = [];
    $needsTransactions = false;
    $conids = [];
    foreach ($positionsRows as $row) {
        $rowCurrency = (string)($row['currency'] ?? '');
        if ($rowCurrency !== '' && $rowCurrency !== $chartCurrency) {
            $needsTransactions = true;
            if (isset($row['conid']) && is_numeric($row['conid'])) {
                $conids[] = (int)$row['conid'];
            }
        }
    }
    $conids = array_values(array_unique($conids));
    if ($needsTransactions && count($conids) > 0) {
        foreach ($conids as $conid) {
            $transactions = apiRequest('POST', '/pa/transactions', [
                'acctIds' => [$accountId],
                'conids' => [$conid],
                'currency' => $chartCurrency,
                'days' => (int)env('IBKR_TXN_DAYS', '3650'),
            ]);
            $transactionsData = $transactions['json'] ?? [];
            $grouped = groupTransactionsByConid($transactionsData, $chartCurrency);
            if (isset($grouped[$conid])) {
                $transactionsByConid[$conid] = $grouped[$conid];
            }
        }
    }

    usort($positionsRows, function (array $a, array $b): int {
        $aValue = is_numeric($a['mktValue'] ?? null) ? (float)$a['mktValue'] : 0.0;
        $bValue = is_numeric($b['mktValue'] ?? null) ? (float)$b['mktValue'] : 0.0;
        return $bValue <=> $aValue;
    });

    $accountsView[] = [
        'id' => $accountId,
        'summary' => $summary,
        'ledger' => $ledger,
        'ledgerData' => $ledgerData,
        'performance' => $performance,
        'netLiquidationDisplay' => $netLiquidationDisplay,
        'netLiquidationSource' => $netLiquidationSource,
        'cashBalances' => $cashBalances,
        'baseCashBalance' => $baseCashBalance,
        'intradayPnl' => $intradayPnl,
        'chartLabels' => $chartLabels,
        'chartData' => $chartData,
        'chartCurrency' => $chartCurrency,
        'hasPerformanceData' => $hasPerformanceData,
        'positions' => $positions,
        'positionsRows' => $positionsRows,
        'transactionsByConid' => $transactionsByConid,
    ];
}

$chartConfigs = [];
foreach ($accountsView as $index => $account) {
    if (!$account['hasPerformanceData']) {
        continue;
    }
    $chartConfigs[] = [
        'id' => 'pnlChart-' . $index,
        'labels' => $account['chartLabels'],
        'data' => $account['chartData'],
        'currency' => $account['chartCurrency'],
    ];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IBKR Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1/css/bulma.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --privacy-blur: 6px;
        }
        .sensitive {
            transition: filter 150ms ease;
        }
        .chart-panel {
            position: relative;
            height: 380px;
        }
        .chart-panel canvas {
            width: 100% !important;
            height: 380px !important;
        }
        body.privacy-blur .sensitive {
            filter: blur(var(--privacy-blur));
        }
        #privacyToggle {
            border: 1px solid rgba(255, 255, 255, 0.4);
        }
        #privacyToggle[data-active="true"] {
            background: rgba(255, 255, 255, 0.12);
        }
        .pnl-percent {
            margin-left: 2px;
            white-space: nowrap;
        }
    </style>
</head>
<body>
<nav class="navbar is-dark" role="navigation" aria-label="main navigation">
    <div class="navbar-brand">
        <span class="navbar-item has-text-weight-bold">IBKR Dash</span>
    </div>
    <div class="navbar-menu is-active">
        <div class="navbar-end">
            <div class="navbar-item">
                <button class="button is-dark is-inverted is-small" id="privacyToggle" type="button" aria-pressed="false" aria-label="Blur sensitive amounts" title="Blur sensitive amounts">👁️</button>
            </div>
        </div>
    </div>
</nav>

<section class="hero is-info is-light is-small">
    <div class="hero-body py-4 has-text-dark">
        <div class="container">
            <div class="level">
                <div class="level-left">
                    <div>
                        <p class="title">Interactive Brokers Dashboard</p>
                        <p class="subtitle is-6 has-text-grey-dark">Gateway: <?= htmlspecialchars($auth['url']) ?></p>
                    </div>
                </div>
                <div class="level-right">
                    <div class="level-item">
                        <?php if ($auth['error']): ?>
                            <span class="tag is-danger" title="<?= htmlspecialchars($gatewayHover) ?>">Gateway Error</span>
                        <?php else: ?>
                            <div class="tags" title="<?= htmlspecialchars($gatewayHover) ?>">
                                <span class="tag <?= $authOk ? 'is-link' : 'is-warning' ?>">Authenticated</span>
                                <span class="tag <?= $connected ? 'is-link' : 'is-warning' ?>">Connected</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if (count($accountsView) === 0): ?>
            <div class="notification is-warning is-light">
                No accounts returned from the gateway. Check your session and permissions.
            </div>
        <?php else: ?>
            <?php foreach ($accountsView as $index => $account): ?>
                <?php if ($index > 0): ?>
                    <hr class="my-5">
                <?php endif; ?>
                <div class="columns is-variable is-6">
                    <div class="column is-4">
                        <div class="card">
                            <header class="card-header">
                                <p class="card-header-title is-size-6">Account Snapshot</p>
                            </header>
                            <div class="card-content">
                                <p class="has-text-grey is-size-7">Account: <span class="sensitive"><?= htmlspecialchars($account['id']) ?></span></p>
                                <p class="title is-4"><span class="sensitive"><?= htmlspecialchars($account['netLiquidationDisplay']) ?></span></p>
                                <p class="has-text-grey is-size-7">Net liquidation<?= $account['netLiquidationSource'] ? ' (' . htmlspecialchars($account['netLiquidationSource']) . ')' : '' ?></p>
                            </div>
                        </div>
                        <?php if (!empty($account['cashBalances'])): ?>
                            <div class="card mt-3">
                                <header class="card-header">
                                    <p class="card-header-title is-size-6">Cash balances</p>
                                </header>
                                <div class="card-content">
                                    <?php
                                        $baseCurrency = $account['chartCurrency'] ?? 'BASE';
                                        $cashItems = [];
                                        foreach ($account['cashBalances'] as $balance) {
                                            $currency = (string)$balance['currency'];
                                            if ($currency === 'BASE') {
                                                continue;
                                            }
                                            $cashItems[] = [
                                                'currency' => $currency,
                                                'value' => (float)$balance['value'],
                                            ];
                                        }
                                    ?>
                                    <div class="tags are-small">
                                        <?php foreach ($cashItems as $cashIndex => $balance): ?>
                                            <div class="tags has-addons mr-2 mb-2">
                                                <span class="tag is-dark"><?= htmlspecialchars($balance['currency']) ?></span>
                                                <span class="tag is-link sensitive"><?= htmlspecialchars(number_format((float)$balance['value'], 2)) ?></span>
                                            </div>
                                            <?php if ($cashIndex < count($cashItems) - 1): ?>
                                                <span class="is-size-7 mr-2 mb-2">+</span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if (!empty($cashItems)): ?>
                                            <span class="is-size-7 mr-2 mb-2">=~</span>
                                        <?php endif; ?>
                                        <?php if (isset($account['baseCashBalance']) && $account['baseCashBalance'] !== null): ?>
                                            <div class="tags has-addons mr-2 mb-2">
                                                <span class="tag is-dark"><?= htmlspecialchars($baseCurrency) ?></span>
                                                <span class="tag is-link sensitive"><?= htmlspecialchars(number_format((float)$account['baseCashBalance'], 2)) ?></span>
                                            </div>
                                            <span class="is-size-7 mb-2">total</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="card mt-3">
                            <header class="card-header">
                                <p class="card-header-title is-size-6">Intraday P&amp;L</p>
                            </header>
                            <div class="card-content">
                                <?php
                                    $pnl = $account['intradayPnl'] ?? [];
                                    $dpl = $pnl['dpl'] ?? null;
                                    $upl = $pnl['upl'] ?? null;
                                    $baseCurrency = $account['chartCurrency'] ?? 'BASE';
                                ?>
                                <?php if ($dpl === null && $upl === null): ?>
                                    <p class="has-text-grey is-size-7">No intraday P&amp;L data available.</p>
                                <?php else: ?>
                                    <div class="columns is-mobile is-multiline">
                                        <div class="column is-half">
                                            <p class="has-text-grey is-size-7">Daily P&amp;L</p>
                                            <p class="<?= $dpl !== null && $dpl < 0 ? 'has-text-danger' : 'has-text-success' ?> has-text-weight-semibold">
                                                <?php if ($dpl === null): ?>
                                                    n/a
                                                <?php else: ?>
                                                    <span class="sensitive"><?= htmlspecialchars($baseCurrency . ' ' . number_format($dpl, 2)) ?></span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="column is-half">
                                            <p class="has-text-grey is-size-7">Unrealized P&amp;L</p>
                                            <p class="<?= $upl !== null && $upl < 0 ? 'has-text-danger' : 'has-text-success' ?> has-text-weight-semibold">
                                                <?php if ($upl === null): ?>
                                                    n/a
                                                <?php else: ?>
                                                    <span class="sensitive"><?= htmlspecialchars($baseCurrency . ' ' . number_format($upl, 2)) ?></span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="column is-8">
                        <div class="card">
                            <header class="card-header">
                                <p class="card-header-title is-size-6">Net Liquidation (last 30 days)</p>
                            </header>
                            <div class="card-content p-3">
                                <?php if ($account['hasPerformanceData']): ?>
                                    <div class="chart-panel">
                                        <canvas id="pnlChart-<?= $index ?>"></canvas>
                                    </div>
                                <?php else: ?>
                                    <p class="has-text-grey">No 30-day performance data available.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="columns">
                    <div class="column">
                        <div class="card">
                            <header class="card-header">
                                <p class="card-header-title is-size-6">Positions</p>
                            </header>
                            <div class="card-content">
                                <?php if ($account['positions'] && $account['positions']['error']): ?>
                                    <div class="notification is-danger is-light">
                                        <?= htmlspecialchars($account['positions']['error']) ?>
                                    </div>
                                <?php elseif (count($account['positionsRows']) === 0): ?>
                                    <p class="has-text-grey">No positions available.</p>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table class="table is-fullwidth is-striped is-size-7">
                                            <thead>
                                                <tr>
                                                    <th class="has-text-grey-light">Symbol</th>
                                                    <th class="has-text-right has-text-grey-light">Position</th>
                                                    <th class="has-text-right has-text-grey-light">Mkt Price</th>
                                                    <th class="has-text-right has-text-grey-light">Mkt Value</th>
                                                    <th class="has-text-grey-light is-narrow">CCY</th>
                                                    <th class="has-text-right has-text-grey-light">Unrealized P&amp;L</th>
                                                    <th class="has-text-right has-text-grey-light">Realized P&amp;L</th>
                                                    <th class="has-text-right has-text-grey-light">Unrealized P&amp;L (<?= htmlspecialchars($account['chartCurrency'] ?? 'BASE') ?>)</th>
                                                    <th class="has-text-right has-text-grey-light">Realized P&amp;L (<?= htmlspecialchars($account['chartCurrency'] ?? 'BASE') ?>)</th>
                                                    <th class="has-text-grey-light is-narrow">Asset</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($account['positionsRows'] as $row): ?>
                                                    <?php
                                                        $symbol = $row['contractDesc'] ?? $row['symbol'] ?? $row['name'] ?? 'n/a';
                                                        $positionRaw = is_numeric($row['position'] ?? null) ? (float)$row['position'] : null;
                                                        $position = $positionRaw !== null ? number_format($positionRaw, 2) : 'n/a';
                                                        $mktPrice = is_numeric($row['mktPrice'] ?? null) ? number_format((float)$row['mktPrice'], 2) : 'n/a';
                                                        $mktValue = is_numeric($row['mktValue'] ?? null) ? number_format((float)$row['mktValue'], 2) : 'n/a';
                                                        $currency = $row['currency'] ?? 'n/a';
                                                        $unrealized = is_numeric($row['unrealizedPnl'] ?? null) ? (float)$row['unrealizedPnl'] : null;
                                                        $realized = is_numeric($row['realizedPnl'] ?? null) ? (float)$row['realizedPnl'] : null;
                                                        $avgCostRaw = is_numeric($row['avgCost'] ?? null) ? (float)$row['avgCost'] : null;
                                                        $costBasis = ($positionRaw !== null && $avgCostRaw !== null) ? $positionRaw * $avgCostRaw : null;
                                                        $unrealizedPct = ($unrealized !== null && $costBasis !== null && $costBasis != 0.0)
                                                            ? ($unrealized / $costBasis) * 100
                                                            : null;
                                                        $realizedPct = ($realized !== null && $costBasis !== null && $costBasis != 0.0)
                                                            ? ($realized / $costBasis) * 100
                                                            : null;
                                                        $baseCurrency = $account['chartCurrency'] ?? '';
                                                        $fxRate = extractFxRateToBase($account['ledgerData'] ?? [], (string)$currency, $baseCurrency);
                                                        $unrealizedBase = null;
                                                        $realizedBase = null;
                                                        $unrealizedBasePct = null;
                                                        $realizedBasePct = null;
                                                        if ($baseCurrency !== '' && $currency === $baseCurrency) {
                                                            $unrealizedBase = $unrealized;
                                                            $realizedBase = $realized;
                                                            $unrealizedBasePct = $unrealizedPct;
                                                            $realizedBasePct = $realizedPct;
                                                        } else {
                                                            $currentValueBase = null;
                                                            if (is_numeric($row['mktValue'] ?? null) && $fxRate !== null) {
                                                                $currentValueBase = (float)$row['mktValue'] * $fxRate;
                                                            }
                                                            $conid = is_numeric($row['conid'] ?? null) ? (int)$row['conid'] : null;
                                                            $txs = ($conid !== null && isset($account['transactionsByConid'][$conid]))
                                                                ? $account['transactionsByConid'][$conid]
                                                                : [];
                                                            $avgFxRate = computeWeightedFxRate($txs);
                                                            if ($avgFxRate !== null && $positionRaw !== null && $avgCostRaw !== null && $currentValueBase !== null) {
                                                                $baseCost = $positionRaw * $avgCostRaw * $avgFxRate;
                                                                if ($baseCost != 0.0) {
                                                                    $unrealizedBase = $currentValueBase - $baseCost;
                                                                    $unrealizedBasePct = ($unrealizedBase / $baseCost) * 100;
                                                                }
                                                                if ($realized !== null) {
                                                                    $realizedBase = $realized * $avgFxRate;
                                                                    $realizedBasePct = $realizedPct;
                                                                }
                                                            }
                                                        }
                                                        $assetClass = $row['assetClass'] ?? 'n/a';
                                                        $unrealizedClass = '';
                                                        if ($unrealized !== null) {
                                                            $unrealizedClass = $unrealized < 0 ? 'has-text-danger' : 'has-text-success';
                                                        }
                                                        $realizedClass = '';
                                                        if ($realized !== null) {
                                                            $realizedClass = $realized < 0 ? 'has-text-danger' : 'has-text-success';
                                                        }
                                                        $unrealizedBaseClass = '';
                                                        if ($unrealizedBase !== null) {
                                                            $unrealizedBaseClass = $unrealizedBase < 0 ? 'has-text-danger' : 'has-text-success';
                                                        }
                                                        $realizedBaseClass = '';
                                                        if ($realizedBase !== null) {
                                                            $realizedBaseClass = $realizedBase < 0 ? 'has-text-danger' : 'has-text-success';
                                                        }
                                                        $unrealizedPctLabel = $unrealizedPct === null ? '' : ' (' . ($unrealizedPct >= 0 ? '+' : '') . number_format($unrealizedPct, 2) . '%)';
                                                        $realizedPctLabel = $realizedPct === null ? '' : ' (' . ($realizedPct >= 0 ? '+' : '') . number_format($realizedPct, 2) . '%)';
                                                        $unrealizedBasePctLabel = $unrealizedBasePct === null ? '' : ' (' . ($unrealizedBasePct >= 0 ? '+' : '') . number_format($unrealizedBasePct, 2) . '%)';
                                                        $realizedBasePctLabel = $realizedBasePct === null ? '' : ' (' . ($realizedBasePct >= 0 ? '+' : '') . number_format($realizedBasePct, 2) . '%)';
                                                        $realizedIsZero = $realized !== null && abs($realized) < 0.000001;
                                                        $realizedBaseIsZero = $realizedBase !== null && abs($realizedBase) < 0.000001;
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string)$symbol) ?></td>
                                                        <td class="has-text-right">
                                                            <?php if ($positionRaw === null): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars($position) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right"><?= htmlspecialchars($mktPrice) ?></td>
                                                        <td class="has-text-right">
                                                            <?php if ($mktValue === 'n/a'): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars($mktValue) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars((string)$currency) ?></td>
                                                        <td class="has-text-right <?= $unrealizedClass ?>">
                                                            <?php if ($unrealized === null): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(number_format($unrealized, 2)) ?></span>
                                                                <?php if ($unrealizedPctLabel !== ''): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars($unrealizedPctLabel) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right <?= $realizedIsZero ? '' : $realizedClass ?>">
                                                            <?php if ($realized === null): ?>
                                                                n/a
                                                            <?php elseif ($realizedIsZero): ?>
                                                                —
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(number_format($realized, 2)) ?></span>
                                                                <?php if ($realizedPctLabel !== ''): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars($realizedPctLabel) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right <?= $unrealizedBaseClass ?>">
                                                            <?php if ($unrealizedBase === null): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(trim($baseCurrency . ' ' . number_format($unrealizedBase, 2))) ?></span>
                                                                <?php if ($unrealizedBasePctLabel !== ''): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars($unrealizedBasePctLabel) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right <?= $realizedBaseIsZero ? '' : $realizedBaseClass ?>">
                                                            <?php if ($realizedBase === null): ?>
                                                                n/a
                                                            <?php elseif ($realizedBaseIsZero): ?>
                                                                —
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(trim($baseCurrency . ' ' . number_format($realizedBase, 2))) ?></span>
                                                                <?php if ($realizedBasePctLabel !== ''): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars($realizedBasePctLabel) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars((string)$assetClass) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php /* Watchlists table disabled (slow to load). */ ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$connected): ?>
            <div class="notification is-light">
                Ensure the Client Portal Gateway is running on <strong>localhost:5050</strong> (or update <code>.env</code>).
                For long-lived sessions, consider running <code>ibeam</code>.
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
const chartConfigs = <?= json_encode($chartConfigs, JSON_UNESCAPED_SLASHES) ?>;
const privacyToggle = document.getElementById('privacyToggle');
const ibkrCharts = [];
const applyChartPrivacy = (chart, enabled) => {
    if (!chart?.options?.scales?.y) {
        return;
    }
    chart.options.scales.y.display = !enabled;
    chart.update('none');
};
const setPrivacyBlur = (enabled) => {
    document.body.classList.toggle('privacy-blur', enabled);
    if (privacyToggle) {
        privacyToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        privacyToggle.setAttribute('data-active', enabled ? 'true' : 'false');
    }
    ibkrCharts.forEach((chart) => applyChartPrivacy(chart, enabled));
    try {
        localStorage.setItem('privacyBlur', enabled ? '1' : '0');
    } catch (error) {
        // Ignore storage errors.
    }
};

if (privacyToggle) {
    let initial = false;
    try {
        initial = localStorage.getItem('privacyBlur') === '1';
    } catch (error) {
        initial = false;
    }
    setPrivacyBlur(initial);
    privacyToggle.addEventListener('click', () => {
        setPrivacyBlur(!document.body.classList.contains('privacy-blur'));
    });
}

chartConfigs.forEach((config) => {
    const ctx = document.getElementById(config.id);
    if (!ctx || !config.labels || !config.labels.length || !config.data || !config.data.length) {
        return;
    }

    let moneyFormatter = null;
    try {
        moneyFormatter = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: config.currency || 'USD',
            maximumFractionDigits: 2
        });
    } catch (error) {
        moneyFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
    }

    const formatMoney = (value) => moneyFormatter.format(value);
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: config.labels,
            datasets: [{
                label: 'Net liquidation',
                data: config.data,
                borderColor: '#3273dc',
                backgroundColor: 'rgba(50, 115, 220, 0.15)',
                tension: 0.25,
                fill: true,
                pointRadius: 3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            hover: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: (context) => {
                            const value = context.parsed.y;
                            const labelValue = formatMoney(value);
                            const idx = context.dataIndex;
                            const series = context.dataset.data;
                            if (idx > 0 && typeof series[idx - 1] === 'number' && series[idx - 1] !== 0) {
                                const pct = ((value - series[idx - 1]) / series[idx - 1]) * 100;
                                const pctLabel = `${pct >= 0 ? '+' : ''}${pct.toFixed(2)}%`;
                                return `${labelValue} (${pctLabel})`;
                            }
                            return labelValue;
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: (value) => formatMoney(value)
                    }
                }
            }
        }
    });
    ibkrCharts.push(chart);
    if (document.body.classList.contains('privacy-blur')) {
        applyChartPrivacy(chart, true);
    }
});
</script>
</body>
</html>
