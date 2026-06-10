<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.inc.php';

$pageStart = microtime(true);

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

    foreach (['fxRateToBase', 'fxRate', 'exchangeRate'] as $key) {
        if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
            return (float)$entry[$key];
        }
    }

    return null;
}

function extractCashBalanceFromEntry(array $entry): ?float
{
    foreach (['cashbalance', 'cashBalance', 'cash', 'totalcashvalue', 'totalcash', 'availablecash'] as $key) {
        if (array_key_exists($key, $entry) && is_numeric($entry[$key])) {
            return (float)$entry[$key];
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
    foreach ($ledgerData as $currency => $entry) {
        if (!is_array($entry) || !is_string($currency)) {
            continue;
        }
        $value = extractCashBalanceFromEntry($entry);
        if ($value !== null) {
            $balances[] = ['currency' => $currency, 'value' => $value];
        }
    }
    return $balances;
}

function extractBaseCashBalance($ledgerData): ?float
{
    $baseEntry = is_array($ledgerData) ? ($ledgerData['BASE'] ?? null) : null;
    return is_array($baseEntry) ? extractCashBalanceFromEntry($baseEntry) : null;
}

function extractPartitionedPnl($pnlData, string $accountId): array
{
    $result = ['dpl' => null, 'upl' => null];

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
    foreach (['dpl', 'upl'] as $field) {
        if (array_key_exists($field, $data) && is_numeric($data[$field])) {
            $result[$field] = (float)$data[$field];
        }
    }

    return $result;
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

    $price = extractTransactionPrice($tx);
    $qty = extractTransactionQuantity($tx);
    if ($price !== null && $qty !== null) {
        $amount = abs($price * $qty);
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
requireBasicAuthIfConfigured();

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

    $transactionsByConid = [];
    $conids = [];
    foreach ($positionsRows as $row) {
        $rowCurrency = (string)($row['currency'] ?? '');
        if ($rowCurrency !== '' && $rowCurrency !== $chartCurrency && is_numeric($row['conid'] ?? null)) {
            $conids[(int)$row['conid']] = true;
        }
    }
    foreach (array_keys($conids) as $conid) {
        $transactions = apiRequest('POST', '/pa/transactions', [
            'acctIds' => [$accountId],
            'conids' => [$conid],
            'currency' => $chartCurrency,
            'days' => (int)env('IBKR_TXN_DAYS', '3650'),
        ]);
        $grouped = groupTransactionsByConid($transactions['json'] ?? [], $chartCurrency);
        if (isset($grouped[$conid])) {
            $transactionsByConid[$conid] = $grouped[$conid];
        }
    }


    usort($positionsRows, function (array $a, array $b): int {
        $aValue = is_numeric($a['mktValue'] ?? null) ? (float)$a['mktValue'] : 0.0;
        $bValue = is_numeric($b['mktValue'] ?? null) ? (float)$b['mktValue'] : 0.0;
        return $bValue <=> $aValue;
    });

    $accountsView[] = [
        'id' => $accountId,
        'ledgerData' => $ledgerData,
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
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0a0d15">
    <title>IBKR Dashboard</title>
    <link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABwAAAAcCAMAAABF0y+mAAAAllBMVEUiIiIhISEgICAfHx8eHh4dHR0cHBwbGxsaGhoZGRkYGBgVHBwqHB0XFxcAHR1vHSA6HBwNHBxaHR+kHiMAGhkWFhYzHB3ZICdGHB3MICa6HyTkIShjHR/hICgVFRWGHiJ8HSAAGRjTICdQHR+VHiIUFBSrHiSxHyMAFRXAHyV0Gx4TExMvFhcAExMSEhKbHSHFHyUREREv0J8IAAABR0lEQVR4AV3SBRaDMBAEUOq4uzuh3vtfrrsJSyUt+t8MQSQcGxjb7Xa32+8Ph+PxdJJlWVFVVSAZoqYjKoDGl4mgadnyYgaVkjmu56MpYMFfqRZGsUbB4LdUOySpv1rwW2qmWe4IQ6SgMK9IvoKlRAZoVkVRNx9DpFLTKqL2pJBxpFKni6Ki97+Cg0SlWtgC6tqXAYpS7ZiAjf5qhPyCUwHoOk3TMAoC8lK/B4tm36nS9MwWu0jLTD0MWs2Im57x0gsimMCkibMIx3XgBrgnLKqmxnK49G1AvAOCHXnSbjpKogFyO/qAiWcwPuf+Juwb0/TBgv45V0yUAnI7ASZTOveMsRsTs0HkhphOafqsGNwi2WvFaASck/pWUikgN9kfnxyfiXVbgy+Jm9x404JtfiMjdKqH543C7TuaQPF9M9YwnCqMOwVfb9LjO/4TNVnRAAAAAElFTkSuQmCC">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1/css/bulma.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --privacy-blur: 6px;
        }
        html[data-theme="dark"] {
            --bulma-scheme-h: 224;
            --bulma-scheme-s: 24%;
            --bulma-table-cell-heading-color: var(--bulma-text-weak);
            --bulma-footer-padding: 1rem;
        }
        body {
            background-image:
                radial-gradient(1100px 500px at 85% -10%, hsla(var(--bulma-link-h), var(--bulma-link-s), 60%, 0.08), transparent 60%),
                radial-gradient(900px 450px at 5% -15%, hsla(var(--bulma-primary-h), var(--bulma-primary-s), 60%, 0.06), transparent 60%);
            background-attachment: fixed;
        }
        .navbar {
            position: sticky;
            top: 0;
            z-index: 40;
            background-color: hsla(var(--bulma-scheme-h), var(--bulma-scheme-s), var(--bulma-scheme-main-l), 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--bulma-border-weak);
        }
        .brand-mark {
            border-radius: 0.5rem;
            background: linear-gradient(135deg, var(--bulma-link), var(--bulma-primary));
        }
        .card {
            border: 1px solid var(--bulma-border-weak);
        }
        .table {
            font-variant-numeric: tabular-nums;
        }
        .dot {
            display: inline-block;
            width: 0.5em;
            height: 0.5em;
            border-radius: 9999px;
            background: currentColor;
        }
        .has-text-success-bold .dot {
            animation: pulse 2.4s ease-out infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 hsla(var(--bulma-success-h), var(--bulma-success-s), var(--bulma-success-l), 0.4); }
            70%, 100% { box-shadow: 0 0 0 0.4em transparent; }
        }
        .chart-panel {
            position: relative;
            height: 320px;
        }
        .chart-panel canvas {
            width: 100% !important;
            height: 100% !important;
        }
        .sensitive {
            transition: filter 150ms ease;
        }
        body.privacy-blur .sensitive {
            filter: blur(var(--privacy-blur));
        }
        .pnl-percent {
            margin-left: 2px;
            white-space: nowrap;
            opacity: 0.75;
        }
        #privacyToggle .eye-closed,
        #privacyToggle.is-link .eye-open {
            display: none;
        }
        #privacyToggle.is-link .eye-closed {
            display: inline;
        }
    </style>
</head>
<body>
<nav class="navbar" role="navigation" aria-label="main navigation">
    <div class="container">
        <div class="navbar-brand">
            <span class="navbar-item">
                <span class="icon brand-mark mr-2">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M3 17l5-6 4 4 6-9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="has-text-weight-bold">IBKR&nbsp;<span class="has-text-link">Dash</span></span>
            </span>
        </div>
        <div class="navbar-menu is-active">
            <div class="navbar-end">
                <div class="navbar-item" title="<?= htmlspecialchars($gatewayHover) ?>">
                    <?php if ($auth['error']): ?>
                        <span class="tag is-rounded has-background-danger-soft has-text-danger-bold"><span class="dot mr-1"></span>Gateway Error</span>
                    <?php else: ?>
                        <span class="tag is-rounded has-background-<?= $authOk ? 'success' : 'warning' ?>-soft has-text-<?= $authOk ? 'success' : 'warning' ?>-bold mr-2"><span class="dot mr-1"></span>Authenticated</span>
                        <span class="tag is-rounded has-background-<?= $connected ? 'success' : 'warning' ?>-soft has-text-<?= $connected ? 'success' : 'warning' ?>-bold"><span class="dot mr-1"></span>Connected</span>
                    <?php endif; ?>
                </div>
                <div class="navbar-item">
                    <button class="button" id="privacyToggle" type="button" aria-pressed="false" aria-label="Blur sensitive amounts" title="Blur sensitive amounts">
                        <span class="icon">
                        <svg class="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg class="eye-closed" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<section class="section py-5">
    <div class="container">
        <div class="mb-5">
            <p class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey mb-1">Portfolio Overview</p>
            <h1 class="title is-4 mb-1">Interactive Brokers Dashboard</h1>
            <p class="is-size-7 has-text-grey">Gateway: <?= htmlspecialchars($auth['url']) ?></p>
        </div>
        <?php if (count($accountsView) === 0): ?>
            <div class="notification is-warning is-light">
                No accounts returned from the gateway. Check your session and permissions.
            </div>
        <?php else: ?>
            <?php foreach ($accountsView as $index => $account): ?>
                <?php
                    $pnl = $account['intradayPnl'] ?? [];
                    $dpl = $pnl['dpl'] ?? null;
                    $upl = $pnl['upl'] ?? null;
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
                <?php if ($index > 0): ?>
                    <hr class="my-5">
                <?php endif; ?>
                <div>
                    <div class="mb-3">
                        <p class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey mb-1">Account</p>
                        <h2 class="title is-5 mb-0"><span class="sensitive"><?= htmlspecialchars($account['id']) ?></span></h2>
                    </div>

                    <div class="columns is-variable is-2 is-multiline mb-2">
                        <div class="column is-6-tablet is-3-desktop">
                            <div class="card">
                                <div class="card-content p-4">
                                    <p class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey mb-1">Net Liquidation</p>
                                    <p class="title is-4 mb-1"><span class="sensitive"><?= htmlspecialchars($account['netLiquidationDisplay']) ?></span></p>
                                    <p class="is-size-7 has-text-grey"><?= $account['netLiquidationSource'] ? 'Source: ' . htmlspecialchars($account['netLiquidationSource']) : '&nbsp;' ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6-tablet is-3-desktop">
                            <div class="card">
                                <div class="card-content p-4">
                                    <p class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey mb-1">Daily P&amp;L</p>
                                    <p class="title is-4 mb-1 <?= $dpl === null ? '' : ($dpl < 0 ? 'has-text-danger' : 'has-text-success') ?>">
                                        <?php if ($dpl === null): ?>
                                            <span class="has-text-grey">n/a</span>
                                        <?php else: ?>
                                            <span class="sensitive"><?= htmlspecialchars(($dpl >= 0 ? '+' : '-') . number_format(abs($dpl), 2)) ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="is-size-7 has-text-grey"><?= htmlspecialchars($baseCurrency) ?> &middot; intraday</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6-tablet is-3-desktop">
                            <div class="card">
                                <div class="card-content p-4">
                                    <p class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey mb-1">Unrealized P&amp;L</p>
                                    <p class="title is-4 mb-1 <?= $upl === null ? '' : ($upl < 0 ? 'has-text-danger' : 'has-text-success') ?>">
                                        <?php if ($upl === null): ?>
                                            <span class="has-text-grey">n/a</span>
                                        <?php else: ?>
                                            <span class="sensitive"><?= htmlspecialchars(($upl >= 0 ? '+' : '-') . number_format(abs($upl), 2)) ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="is-size-7 has-text-grey"><?= htmlspecialchars($baseCurrency) ?> &middot; open positions</p>
                                </div>
                            </div>
                        </div>
                        <div class="column is-6-tablet is-3-desktop">
                            <div class="card">
                                <div class="card-content p-4">
                                    <p class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey mb-1">Cash</p>
                                    <p class="title is-4 mb-1">
                                        <?php if (isset($account['baseCashBalance']) && $account['baseCashBalance'] !== null): ?>
                                            <span class="sensitive"><?= htmlspecialchars($baseCurrency . ' ' . number_format((float)$account['baseCashBalance'], 2)) ?></span>
                                        <?php else: ?>
                                            <span class="has-text-grey">n/a</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="is-size-7 has-text-grey">
                                        <?php if (!empty($cashItems)): ?>
                                            <?php
                                                $cashParts = [];
                                                foreach ($cashItems as $balance) {
                                                    $cashParts[] = htmlspecialchars($balance['currency']) . ' <span class="sensitive">' . htmlspecialchars(number_format($balance['value'], 2)) . '</span>';
                                                }
                                            ?>
                                            <?= implode(' &middot; ', $cashParts) ?>
                                        <?php else: ?>
                                            &nbsp;
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <header class="card-header">
                            <p class="card-header-title"><span class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey">Net Liquidation &middot; Last 30 Days</span></p>
                            <span class="card-header-icon"><span class="tag is-rounded has-background-link-soft has-text-link-bold"><?= htmlspecialchars($baseCurrency) ?></span></span>
                        </header>
                        <?php if ($account['hasPerformanceData']): ?>
                            <div class="chart-panel m-2">
                                <canvas id="pnlChart-<?= $index ?>"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="card-content p-4">
                                <p class="has-text-grey">No 30-day performance data available.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card">
                        <header class="card-header">
                            <p class="card-header-title"><span class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey">Positions</span></p>
                            <span class="card-header-icon"><span class="tag is-rounded has-background-link-soft has-text-link-bold"><?= count($account['positionsRows']) ?></span></span>
                        </header>
                            <div class="card-content p-4">
                                <?php if ($account['positions'] && $account['positions']['error']): ?>
                                    <div class="notification is-danger is-light">
                                        <?= htmlspecialchars($account['positions']['error']) ?>
                                    </div>
                                <?php elseif (count($account['positionsRows']) === 0): ?>
                                    <p class="has-text-grey">No positions available.</p>
                                <?php else: ?>
                                    <div class="table-container">
                                        <table class="table is-fullwidth is-striped is-hoverable is-narrow is-size-7">
                                            <thead>
                                                <tr class="is-uppercase">
                                                    <th>Symbol</th>
                                                    <th class="has-text-right">Position</th>
                                                    <th class="has-text-right">Mkt Price</th>
                                                    <th class="has-text-right">Mkt Value</th>
                                                    <th class="is-narrow">CCY</th>
                                                    <th class="has-text-right">Unrealized P&amp;L</th>
                                                    <th class="has-text-right">Realized P&amp;L</th>
                                                    <th class="has-text-right">Unrealized P&amp;L (<?= htmlspecialchars($account['chartCurrency'] ?? 'BASE') ?>)</th>
                                                    <th class="has-text-right">Realized P&amp;L (<?= htmlspecialchars($account['chartCurrency'] ?? 'BASE') ?>)</th>
                                                    <th class="is-narrow">Asset</th>
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
                                                        <td class="has-text-weight-semibold"><?= htmlspecialchars((string)$symbol) ?></td>
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
                                                        <td><span class="tag"><?= htmlspecialchars((string)$assetClass) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                    </div>
                </div>

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

<?php
    $pageEnd = microtime(true);
    $elapsedMs = ($pageEnd - $pageStart) * 1000;
    $renderedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s T');
?>
<footer class="footer">
    <div class="content has-text-centered">
        <p class="is-size-7 has-text-grey">
            Rendered <?= htmlspecialchars($renderedAt) ?> · <?= htmlspecialchars(number_format($elapsedMs, 1)) ?> ms ·
            <a href="https://github.com/Niek/ibkr-dash" target="_blank" rel="noopener noreferrer">GitHub</a>
        </p>
    </div>
</footer>

<script>
const chartConfigs = <?= json_encode($chartConfigs, JSON_UNESCAPED_SLASHES) ?>;
// Resolve Bulma CSS variables to concrete colors for Chart.js (canvas can't use var()).
const resolveColor = (name) => {
    const probe = document.createElement('span');
    probe.style.color = `var(${name})`;
    document.body.appendChild(probe);
    const color = getComputedStyle(probe).color;
    probe.remove();
    return color;
};
const withAlpha = (color, alpha) => {
    const [r, g, b] = color.match(/[\d.]+/g);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};
const accentColor = resolveColor('--bulma-link');
const borderColor = resolveColor('--bulma-border-weak');
if (window.Chart) {
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = resolveColor('--bulma-text-weak');
}
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
        privacyToggle.classList.toggle('is-link', enabled);
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
                borderColor: accentColor,
                borderWidth: 2,
                backgroundColor: (context) => {
                    const { ctx: canvasCtx, chartArea } = context.chart;
                    if (!chartArea) {
                        return withAlpha(accentColor, 0.1);
                    }
                    const gradient = canvasCtx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, withAlpha(accentColor, 0.28));
                    gradient.addColorStop(1, withAlpha(accentColor, 0));
                    return gradient;
                },
                tension: 0.3,
                fill: true,
                pointRadius: 0,
                pointHitRadius: 12,
                pointHoverRadius: 4,
                pointHoverBackgroundColor: accentColor,
                pointHoverBorderColor: resolveColor('--bulma-text-strong'),
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
                    backgroundColor: resolveColor('--bulma-scheme-main-ter'),
                    borderColor: borderColor,
                    borderWidth: 1,
                    titleColor: resolveColor('--bulma-text-weak'),
                    bodyColor: resolveColor('--bulma-text-strong'),
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: false,
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
                x: {
                    grid: { display: false },
                    border: { color: borderColor },
                    ticks: { maxTicksLimit: 10 }
                },
                y: {
                    grid: { color: withAlpha(borderColor, 0.5) },
                    border: { display: false },
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
