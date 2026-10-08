<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.inc.php';
require_once __DIR__ . '/dashboard.inc.php';
require_once __DIR__ . '/notifications.inc.php';

$pageStart = microtime(true);

loadEnv(__DIR__ . '/.env');
requireBasicAuthIfConfigured();

if (isset($_GET['notifications'])) {
    handleNotificationsRequest();
}

// Load live session status and notifications together, once per page load.
$startup = apiRequestMany([
    'auth' => ['GET', '/iserver/auth/status'],
    'notifications' => ['GET', '/fyi/notifications?max=10'],
], true);
$notifications = [];
$notificationsError = null;
try {
    $notifications = parseNotifications($startup['notifications']);
} catch (RuntimeException $error) {
    $notificationsError = $error->getMessage();
}
$auth = $startup['auth'];
$authData = $auth['json'] ?? [];
$authOk = is_array($authData) && ($authData['authenticated'] ?? false) === true;
$connected = is_array($authData) && ($authData['connected'] ?? false) === true;
$serverName = $authData['serverInfo']['serverName'] ?? 'n/a';
$serverVersion = $authData['serverInfo']['serverVersion'] ?? 'n/a';
$gatewayHover = $auth['error']
    ? $auth['error']
    : 'Server: ' . $serverName . ' | Version: ' . $serverVersion;

$gatewayReady = !$auth['error'] && $authOk;

$selectedPeriod = selectedPerformancePeriod();
$periodLabel = PERFORMANCE_PERIODS[$selectedPeriod];
$labelsWithYear = $selectedPeriod === '1Y';

$accountIds = [];
$partitionedPnlData = [];
if ($gatewayReady) {
    // Skip all account calls when the session isn't usable; each would only wait for a timeout.
    $initial = apiRequestMany([
        'pnl' => ['GET', '/iserver/account/pnl/partitioned'],
        'accounts' => ['GET', '/iserver/accounts'],
    ]);
    $partitionedPnlData = $initial['pnl']['json'] ?? [];
    if (!is_array($partitionedPnlData['upnl'] ?? null) || count($partitionedPnlData['upnl']) === 0) {
        // The gateway's first pnl request only starts the subscription and returns
        // an empty payload; bypass the cache so the retry overwrites the cached miss.
        usleep(500000);
        $partitionedPnl = apiRequest('GET', '/iserver/account/pnl/partitioned', null, true);
        $partitionedPnlData = $partitionedPnl['json'] ?? [];
    }
    $accountIds = extractAccountIds($initial['accounts']['json'] ?? []);
}

$accountRequests = [];
foreach ($accountIds as $i => $accountId) {
    $accountPath = '/portfolio/' . rawurlencode($accountId);
    $accountRequests[$i . ':summary'] = ['GET', $accountPath . '/summary'];
    $accountRequests[$i . ':ledger'] = ['GET', $accountPath . '/ledger'];
    $accountRequests[$i . ':performance'] = ['POST', '/pa/performance', [
        'acctIds' => [$accountId],
        'period' => $selectedPeriod,
    ]];
    $accountRequests[$i . ':positions'] = ['GET', $accountPath . '/positions'];
}
$accountResponses = apiRequestMany($accountRequests);

$accountsView = [];
$transactionRequests = [];
foreach ($accountIds as $i => $accountId) {
    $summaryData = $accountResponses[$i . ':summary']['json'] ?? [];
    $ledgerData = $accountResponses[$i . ':ledger']['json'] ?? [];
    $intradayPnl = extractPartitionedPnl($partitionedPnlData, $accountId);
    $performanceData = $accountResponses[$i . ':performance']['json'] ?? [];
    $navSeries = extractNavSeries($performanceData, $labelsWithYear);
    $positions = $accountResponses[$i . ':positions'];
    $positionsData = $positions['json'] ?? [];

    $netLiquidation = extractNetLiquidation($summaryData, $ledgerData);
    $cashBalances = extractCashBalances($ledgerData);
    $baseCashBalance = extractBaseCashBalance($ledgerData);
    $netLiquidationValue = $netLiquidation['value'];
    $netLiquidationCurrency = $netLiquidation['currency'];
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

    // Foreign-currency positions need their trade history for base-currency cost.
    foreach ($positionsRows as $row) {
        $rowCurrency = (string)($row['currency'] ?? '');
        if ($rowCurrency !== '' && $rowCurrency !== $chartCurrency && is_numeric($row['conid'] ?? null)) {
            $conid = (int)$row['conid'];
            $transactionRequests[$i . ':' . $conid] = ['POST', '/pa/transactions', [
                'acctIds' => [$accountId],
                'conids' => [$conid],
                'currency' => $chartCurrency,
                'days' => (int)env('IBKR_TXN_DAYS', '3650'),
            ]];
        }
    }

    usort($positionsRows, function (array $a, array $b): int {
        $aValue = is_numeric($a['mktValue'] ?? null) ? (float)$a['mktValue'] : 0.0;
        $bValue = is_numeric($b['mktValue'] ?? null) ? (float)$b['mktValue'] : 0.0;
        return $bValue <=> $aValue;
    });

    $accountsView[$i] = [
        'id' => $accountId,
        'ledgerData' => $ledgerData,
        'netLiquidationDisplay' => $netLiquidationDisplay,
        'cashBalances' => $cashBalances,
        'baseCashBalance' => $baseCashBalance,
        'intradayPnl' => $intradayPnl,
        'chartLabels' => $chartLabels,
        'chartData' => $chartData,
        'chartCurrency' => $chartCurrency,
        'hasPerformanceData' => $hasPerformanceData,
        'positions' => $positions,
        'positionsRows' => $positionsRows,
        'transactionsByConid' => [],
    ];
}

foreach (apiRequestMany($transactionRequests) as $key => $transactions) {
    [$i, $conid] = array_map('intval', explode(':', (string)$key, 2));
    $grouped = groupTransactionsByConid($transactions['json'] ?? [], $accountsView[$i]['chartCurrency']);
    if (isset($grouped[$conid])) {
        $accountsView[$i]['transactionsByConid'][$conid] = $grouped[$conid];
    }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css" integrity="sha384-DCY3M8xLkMu6c9IKcKbe+jHKMjelnwC0p+SBaxfHxoBYZWdJF2X400UdBCgATtAB" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
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
        .chart-tooltip {
            position: absolute;
            z-index: 1;
            padding: 10px;
            border: 1px solid var(--bulma-border-weak);
            border-radius: 8px;
            background: var(--bulma-scheme-main-ter);
            color: var(--bulma-text-strong);
            font-size: 0.75rem;
            line-height: 1.2;
            opacity: 0;
            pointer-events: none;
            white-space: nowrap;
        }
        .chart-tooltip-title {
            margin-bottom: 0.35rem;
            color: var(--bulma-text-weak);
            font-weight: 600;
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
        .notifications { position: relative; }
        .header-actions { gap: 0.5rem; }
        .notifications [hidden] { display: none !important; }
        .notification-unread {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--bulma-danger);
        }
        .notifications-panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(390px, calc(100vw - 24px));
            max-height: min(560px, calc(100dvh - 90px));
            overflow-y: auto;
            border: 1px solid var(--bulma-border);
            border-radius: 12px;
            background: var(--bulma-scheme-main);
            box-shadow: 0 12px 36px #0005;
        }
        .notification-message { border-top: 1px solid var(--bulma-border-weak); }
        .notification-message summary { cursor: pointer; padding: 14px 16px; }
        .notification-message summary:focus-visible { outline: 2px solid var(--bulma-link); outline-offset: -3px; }
        .notification-message:not(.is-read) summary { font-weight: 600; }
        .notification-message:not(.is-read) summary::marker { color: var(--bulma-link); }
        .notification-message time { display: block; margin-top: 4px; font-size: 0.7rem; font-weight: 400; color: var(--bulma-text-weak); }
        .notification-body { padding: 0 16px 16px; white-space: pre-wrap; overflow-wrap: anywhere; }
        .notification-message summary { overflow-wrap: anywhere; }
        @media screen and (max-width: 768px) {
            .notifications-panel { position: fixed; top: 62px; right: 12px; }
            .gateway-status { gap: 0.4rem; }
            .gateway-status .tag { font-size: 0.65rem; margin-right: 0 !important; }
            .header-actions .button { width: 36px; height: 36px; padding: 0; }
        }
    </style>
</head>
<body>
<nav class="navbar" role="navigation" aria-label="main navigation">
    <div class="container">
        <div class="navbar-brand is-flex-grow-1">
            <span class="navbar-item">
                <span class="icon brand-mark">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M3 17l5-6 4 4 6-9" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <span class="has-text-weight-bold ml-2 is-hidden-mobile">IBKR&nbsp;<span class="has-text-link">Dash</span></span>
            </span>
            <div class="navbar-item gateway-status ml-auto px-2" title="<?= htmlspecialchars($gatewayHover) ?>">
                <?php if ($auth['error']): ?>
                    <span class="tag is-rounded has-background-danger-soft has-text-danger-bold"><span class="dot mr-1"></span>Gateway Error</span>
                <?php else: ?>
                    <span class="tag is-rounded has-background-<?= $authOk ? 'success' : 'warning' ?>-soft has-text-<?= $authOk ? 'success' : 'warning' ?>-bold mr-2"><span class="dot mr-1"></span><?= $authOk ? 'Authenticated' : 'Not authenticated' ?></span>
                    <span class="tag is-rounded has-background-<?= $connected ? 'success' : 'warning' ?>-soft has-text-<?= $connected ? 'success' : 'warning' ?>-bold"><span class="dot mr-1"></span><?= $connected ? 'Connected' : 'Disconnected' ?></span>
                <?php endif; ?>
            </div>
            <div class="navbar-item header-actions pl-0">
                <div class="notifications" id="notifications">
                    <button class="button" id="notificationsToggle" type="button" aria-expanded="false" aria-controls="notificationsPanel" aria-label="Notifications" title="Notifications">
                        <span class="icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>
                            </svg>
                            <span class="notification-unread" id="notificationsUnread" hidden></span>
                        </span>
                    </button>
                    <section class="notifications-panel" id="notificationsPanel" aria-label="IBKR notifications" hidden>
                        <div class="px-4 py-3">
                            <p class="has-text-weight-semibold">Notifications</p>
                            <p class="is-size-7 has-text-grey">Latest 10 IBKR FYIs</p>
                        </div>
                        <?php if (!$notifications): ?>
                            <p class="px-4 pb-3 is-size-7" role="status"><?= htmlspecialchars($notificationsError ?? 'No recent notifications.') ?></p>
                        <?php endif; ?>
                        <div class="is-size-7">
                            <?php foreach ($notifications as $message): ?>
                                <details class="notification-message <?= (int)$message['R'] === 1 ? 'is-read' : '' ?>" data-id="<?= htmlspecialchars($message['ID']) ?>">
                                    <summary>
                                        <span class="sensitive"><?= htmlspecialchars(notificationText($message['MS']) ?: 'IBKR notification') ?></span>
                                        <?php if (is_numeric($message['D'] ?? null)): ?>
                                            <time data-timestamp="<?= htmlspecialchars((string)$message['D']) ?>" hidden></time>
                                        <?php endif; ?>
                                    </summary>
                                    <div class="notification-body sensitive"><?= htmlspecialchars(notificationText($message['MD'])) ?></div>
                                    <p class="px-4 pb-3 has-text-danger" role="alert" hidden></p>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
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
</nav>

<section class="section py-5">
    <div class="container">
        <div class="mb-5">
            <p class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey mb-1">Portfolio Overview</p>
            <h1 class="title is-4 mb-1">Interactive Brokers Dashboard</h1>
            <p class="is-size-7 has-text-grey">Gateway: <?= htmlspecialchars(gatewayBaseUrl()) ?></p>
        </div>
        <?php if (!$gatewayReady): ?>
            <div class="notification is-warning is-light">
                The gateway session is not authenticated, so no account data was loaded.
            </div>
        <?php elseif (count($accountsView) === 0): ?>
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
                    $periodChangePct = null;
                    $chartValues = $account['chartData'];
                    $firstNav = $chartValues[0] ?? null;
                    $lastNav = $chartValues[count($chartValues) - 1] ?? null;
                    if (is_numeric($firstNav) && is_numeric($lastNav) && (float)$firstNav != 0.0) {
                        $periodChangePct = (((float)$lastNav - (float)$firstNav) / abs((float)$firstNav)) * 100;
                    }
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
                                    <p class="is-size-7 has-text-grey">Total account value</p>
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
                        <header class="card-header is-flex-wrap-wrap">
                            <p class="card-header-title"><span class="is-size-7 is-uppercase has-text-weight-semibold has-text-grey">Net Liquidation &middot; <?= htmlspecialchars($periodLabel) ?></span></p>
                            <div class="card-header-icon">
                                <?php if ($periodChangePct !== null): ?>
                                    <span class="tag is-rounded mr-2 has-background-<?= $periodChangePct < 0 ? 'danger' : 'success' ?>-soft has-text-<?= $periodChangePct < 0 ? 'danger' : 'success' ?>-bold"><?= htmlspecialchars(($periodChangePct >= 0 ? '+' : '') . number_format($periodChangePct, 2)) ?>%</span>
                                <?php endif; ?>
                                <span class="tag is-rounded has-background-link-soft has-text-link-bold mr-2"><?= htmlspecialchars($baseCurrency) ?></span>
                                <div class="select is-small">
                                    <select class="period-select" aria-label="Chart period">
                                        <?php foreach (PERFORMANCE_PERIODS as $value => $label): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= $value === $selectedPeriod ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </header>
                        <?php if ($account['hasPerformanceData']): ?>
                            <div class="chart-panel m-2">
                                <canvas id="pnlChart-<?= $index ?>"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="card-content p-4">
                                <p class="has-text-grey">No performance data available for this period.</p>
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
                                                        $conid = is_numeric($row['conid'] ?? null) ? (int)$row['conid'] : null;
                                                        $p = computePositionRow(
                                                            $row,
                                                            (string)($account['chartCurrency'] ?? ''),
                                                            $account['ledgerData'] ?? [],
                                                            $account['transactionsByConid'][$conid] ?? []
                                                        );
                                                        $baseCurrency = (string)($account['chartCurrency'] ?? '');
                                                    ?>
                                                    <tr>
                                                        <td class="has-text-weight-semibold"><?= htmlspecialchars($p['symbol']) ?></td>
                                                        <td class="has-text-right">
                                                            <?php if ($p['position'] === null): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(number_format($p['position'], 2)) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right"><?= $p['mktPrice'] === null ? 'n/a' : htmlspecialchars(number_format($p['mktPrice'], 2)) ?></td>
                                                        <td class="has-text-right">
                                                            <?php if ($p['mktValue'] === null): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(number_format($p['mktValue'], 2)) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($p['currency']) ?></td>
                                                        <td class="has-text-right <?= pnlClass($p['unrealized']) ?>">
                                                            <?php if ($p['unrealized'] === null): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(number_format($p['unrealized'], 2)) ?></span>
                                                                <?php if ($p['unrealizedPct'] !== null): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars(pnlPercentLabel($p['unrealizedPct'])) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right <?= isNearZero($p['realized']) ? '' : pnlClass($p['realized']) ?>">
                                                            <?php if ($p['realized'] === null): ?>
                                                                n/a
                                                            <?php elseif (isNearZero($p['realized'])): ?>
                                                                —
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(number_format($p['realized'], 2)) ?></span>
                                                                <?php if ($p['realizedPct'] !== null): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars(pnlPercentLabel($p['realizedPct'])) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right <?= pnlClass($p['unrealizedBase']) ?>">
                                                            <?php if ($p['unrealizedBase'] === null): ?>
                                                                n/a
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(trim($baseCurrency . ' ' . number_format($p['unrealizedBase'], 2))) ?></span>
                                                                <?php if ($p['unrealizedBasePct'] !== null): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars(pnlPercentLabel($p['unrealizedBasePct'])) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="has-text-right <?= isNearZero($p['realizedBase']) ? '' : pnlClass($p['realizedBase']) ?>">
                                                            <?php if ($p['realizedBase'] === null): ?>
                                                                n/a
                                                            <?php elseif (isNearZero($p['realizedBase'])): ?>
                                                                —
                                                            <?php else: ?>
                                                                <span class="sensitive"><?= htmlspecialchars(trim($baseCurrency . ' ' . number_format($p['realizedBase'], 2))) ?></span>
                                                                <?php if ($p['realizedBasePct'] !== null): ?>
                                                                    <span class="pnl-percent"><?= htmlspecialchars(pnlPercentLabel($p['realizedBasePct'])) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><span class="tag"><?= htmlspecialchars($p['assetClass']) ?></span></td>
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
                Ensure the Client Portal Gateway is running at <strong><?= htmlspecialchars(gatewayBaseUrl()) ?></strong> (or update <code>GATEWAY_BASE_URL</code> in <code>.env</code>).
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
(() => {
    const root = document.getElementById('notifications');
    const toggle = document.getElementById('notificationsToggle');
    const panel = document.getElementById('notificationsPanel');
    const unread = document.getElementById('notificationsUnread');
    const updateBadge = () => {
        const hasUnread = !!root.querySelector('.notification-message:not(.is-read)');
        unread.hidden = !hasUnread;
        toggle.setAttribute('aria-label', hasUnread ? 'Notifications — unread messages' : 'Notifications');
        toggle.title = hasUnread ? 'Unread messages among the latest 10 FYIs' : 'Notifications';
    };
    for (const time of root.querySelectorAll('time')) {
        const date = new Date(Number(time.dataset.timestamp) * 1000);
        if (!Number.isNaN(date.getTime())) {
            time.dateTime = date.toISOString();
            time.textContent = date.toLocaleString(undefined, {dateStyle: 'medium', timeStyle: 'short'});
            time.hidden = false;
        }
    }
    for (const details of root.querySelectorAll('.notification-message')) {
        const error = details.querySelector('[role="alert"]');
        let marking = false;
        details.addEventListener('toggle', async () => {
            if (!details.open || details.classList.contains('is-read') || marking) return;
            marking = true;
            error.hidden = true;
            try {
                const response = await fetch('?notifications=1', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-IBKR-Notifications': '1'},
                    body: JSON.stringify({id: details.dataset.id}),
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || 'Could not mark this message as read.');
                details.classList.add('is-read');
                updateBadge();
            } catch (failure) {
                error.textContent = failure.message;
                error.hidden = false;
            } finally {
                marking = false;
            }
        });
    }
    const close = () => {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        root.querySelectorAll('details[open]').forEach(details => { details.open = false; });
    };
    toggle.addEventListener('click', () => {
        if (!panel.hidden) return close();
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
    });
    document.addEventListener('click', event => { if (!root.contains(event.target)) close(); });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) { close(); toggle.focus(); }
    });
    updateBadge();
})();

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

const renderChartTooltip = ({ chart, tooltip }, formatMoney) => {
    let element = chart.canvas.parentNode.querySelector('.chart-tooltip');
    if (!element) {
        element = document.createElement('div');
        element.className = 'chart-tooltip';
        element.innerHTML = '<div class="chart-tooltip-title"></div><div><span class="sensitive"></span><span class="chart-tooltip-percent"></span></div>';
        chart.canvas.parentNode.appendChild(element);
    }

    if (tooltip.opacity === 0 || !tooltip.dataPoints.length) {
        element.style.opacity = 0;
        return;
    }

    const point = tooltip.dataPoints[0];
    const value = point.parsed.y;
    const index = point.dataIndex;
    const previous = point.dataset.data[index - 1];
    let percentage = '';
    if (index > 0 && typeof previous === 'number' && previous !== 0) {
        const change = ((value - previous) / previous) * 100;
        percentage = ` (${change >= 0 ? '+' : ''}${change.toFixed(2)}%)`;
    }

    element.querySelector('.chart-tooltip-title').textContent = tooltip.title?.[0] ?? '';
    element.querySelector('.sensitive').textContent = formatMoney(value);
    element.querySelector('.chart-tooltip-percent').textContent = percentage;
    element.style.opacity = 1;

    const halfWidth = element.offsetWidth / 2;
    const positionX = chart.canvas.offsetLeft + tooltip.caretX;
    const positionY = chart.canvas.offsetTop + tooltip.caretY;
    element.style.left = `${Math.max(halfWidth, Math.min(positionX, chart.canvas.offsetWidth - halfWidth))}px`;
    element.style.top = `${positionY}px`;
    element.style.transform = tooltip.caretY < element.offsetHeight + 8
        ? 'translate(-50%, 8px)'
        : 'translate(-50%, calc(-100% - 8px))';
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

document.querySelectorAll('.period-select').forEach((select) => {
    select.addEventListener('change', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('period', select.value);
        window.location.assign(url);
    });
});

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
                    enabled: false,
                    external: (context) => renderChartTooltip(context, formatMoney)
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { color: borderColor },
                    ticks: { maxTicksLimit: 10, maxRotation: 0 }
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
