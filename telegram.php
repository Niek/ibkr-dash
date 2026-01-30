<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.inc.php';

loadEnv(__DIR__ . '/.env');

function stderr(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
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
        $label = formatIbkrDate($dates[$i]);
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

function extractYtdReturn($performanceData): ?float
{
    if (!is_array($performanceData)) {
        return null;
    }

    $cps = $performanceData['cps']['data'][0]['returns'] ?? null;
    if (is_array($cps) && count($cps) > 0) {
        $last = end($cps);
        if (is_numeric($last)) {
            return (float)$last * 100;
        }
    }

    $nav = $performanceData['nav']['data'][0] ?? null;
    if (is_array($nav) && isset($nav['startNAV']['val'], $nav['navs']) && is_array($nav['navs'])) {
        $start = $nav['startNAV']['val'];
        $last = end($nav['navs']);
        if (is_numeric($start) && is_numeric($last) && (float)$start != 0.0) {
            return (((float)$last - (float)$start) / (float)$start) * 100;
        }
    }

    return null;
}

function currencySymbol(string $currency): string
{
    $map = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CHF' => 'CHF ',
        'CAD' => 'C$',
        'AUD' => 'A$',
    ];

    if ($currency === '') {
        return '$';
    }

    return $map[$currency] ?? ($currency . ' ');
}

function formatMoney(float $value, string $currency, bool $signed = false): string
{
    $symbol = currencySymbol($currency);
    $formatted = number_format(abs($value), 2);
    $prefix = '';
    if ($signed) {
        $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');
    }
    return $prefix . $symbol . $formatted;
}

function formatPercent(?float $value, int $decimals = 2, bool $signed = false): string
{
    if ($value === null) {
        return 'n/a';
    }
    $prefix = '';
    if ($signed) {
        $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');
    }
    return $prefix . number_format(abs($value), $decimals) . '%';
}

function emojiForChange(?float $value): string
{
    if ($value === null) {
        return '⚪';
    }
    if ($value > 0) {
        return '🟢';
    }
    if ($value < 0) {
        return '🔴';
    }
    return '⚪';
}

function trendEmoji(?float $value): string
{
    if ($value === null) {
        return '';
    }
    if ($value > 0) {
        return '📈';
    }
    if ($value < 0) {
        return '📉';
    }
    return '➖';
}

function mdEscape(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    return preg_replace('/([_*\\[\\]()~`>#+\\-=|{}.!])/', '\\\\$1', $text);
}

function mdBold(string $text): string
{
    return '*' . mdEscape($text) . '*';
}

function mdCode(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('`', '\\`', $text);
    return '`' . $text . '`';
}

function telegramRequest(string $token, string $method, array $payload): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
            'content' => $body === false ? '{}' : $body,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $error = $raw === false ? error_get_last() : null;

    return [
        'url' => $url,
        'raw' => $raw,
        'json' => $raw ? json_decode($raw, true) : null,
        'error' => $error['message'] ?? null,
    ];
}

function resolveConidForSymbol(string $symbol, string $secType): ?int
{
    $response = apiRequest('GET', '/iserver/secdef/search?symbol=' . rawurlencode($symbol) . '&secType=' . rawurlencode($secType));
    $data = $response['json'] ?? null;
    if (!is_array($data)) {
        return null;
    }

    $candidates = [];
    foreach ($data as $item) {
        if (!is_array($item) || !isset($item['conid'])) {
            continue;
        }
        if (!is_numeric($item['conid'])) {
            continue;
        }
        $itemSymbol = $item['symbol'] ?? $item['ticker'] ?? null;
        if ($itemSymbol !== null && strcasecmp((string)$itemSymbol, $symbol) !== 0) {
            continue;
        }
        $candidates[] = (int)$item['conid'];
    }

    if (!empty($candidates)) {
        return $candidates[0];
    }

    foreach ($data as $item) {
        if (is_array($item) && isset($item['conid']) && is_numeric($item['conid'])) {
            return (int)$item['conid'];
        }
    }

    return null;
}

function fetchHistoryChange(int $conid): ?array
{
    $history = apiRequest('GET', '/iserver/marketdata/history?conid=' . rawurlencode((string)$conid) . '&period=10d&bar=1d');
    $data = $history['json'] ?? null;
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return null;
    }

    $bars = [];
    foreach ($data['data'] as $bar) {
        if (!is_array($bar) || !isset($bar['c']) || !is_numeric($bar['c'])) {
            continue;
        }
        $bars[] = (float)$bar['c'];
    }

    $count = count($bars);
    if ($count < 2) {
        return null;
    }

    $last = $bars[$count - 1];
    $prev = $bars[$count - 2];
    if ($prev == 0.0) {
        return null;
    }

    $pct = (($last - $prev) / $prev) * 100;

    return [
        'pct' => $pct,
        'price' => $last,
    ];
}

function compareLabel(?float $portfolio, ?float $market): string
{
    if ($portfolio === null || $market === null) {
        return '';
    }
    if ($portfolio > $market) {
        return ' \\(' . mdEscape('✅ You won') . '\\)';
    }
    if ($portfolio < $market) {
        return ' \\(' . mdEscape('❌ You lost') . '\\)';
    }
    return ' \\(' . mdEscape('⚪ Tie') . '\\)';
}

$token = env('TELEGRAM_TOKEN');
$chatId = env('TELEGRAM_CHAT');

if ($token === '') {
    stderr('Missing TELEGRAM_TOKEN in .env.');
    exit(1);
}

if ($chatId === '') {
    stderr('Missing TELEGRAM_CHAT in .env.');
    exit(1);
}

$accounts = apiRequest('GET', '/iserver/accounts');
$accountIds = extractAccountIds($accounts['json'] ?? null);
if (count($accountIds) === 0) {
    stderr('No IBKR accounts found.');
    exit(1);
}

$performance = apiRequest('POST', '/pa/performance', [
    'acctIds' => $accountIds,
    'period' => '7D',
]);
$navSeries = extractNavSeries($performance['json'] ?? []);
if (count($navSeries['values']) < 8) {
    $performance = apiRequest('POST', '/pa/performance', [
        'acctIds' => $accountIds,
        'period' => '1M',
    ]);
    $navSeries = extractNavSeries($performance['json'] ?? []);
}

if (count($navSeries['values']) < 2) {
    stderr('Insufficient performance data for daily report.');
    exit(1);
}

$values = $navSeries['values'];
$rawDates = $navSeries['rawDates'] ?? [];
$targetDate = new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam'));
$targetDate = $targetDate->modify('-1 day');
$targetKey = (int)$targetDate->format('Ymd');
$targetIndex = null;
foreach ($rawDates as $index => $rawDate) {
    $rawKey = (int)preg_replace('/\D+/', '', (string)$rawDate);
    if ($rawKey === 0) {
        continue;
    }
    if ($rawKey <= $targetKey) {
        $targetIndex = $index;
    }
}
if ($targetIndex === null || $targetIndex < 1) {
    stderr('Unable to find yesterday in performance data.');
    exit(1);
}

$lastIndex = $targetIndex;
$lastNav = $values[$lastIndex];
$prevNav = $values[$lastIndex - 1];
$dailyPnl = $lastNav - $prevNav;
$dailyPct = $prevNav != 0.0 ? ($dailyPnl / $prevNav) * 100 : null;
$reportDate = $navSeries['labels'][$lastIndex] ?? 'n/a';
$baseCurrency = $navSeries['currency'] ?? 'USD';

$ytd = apiRequest('POST', '/pa/performance', [
    'acctIds' => $accountIds,
    'period' => 'YTD',
]);
$ytdReturn = extractYtdReturn($ytd['json'] ?? []);

$last7Returns = [];
$returnStart = max(1, $lastIndex - 6);
for ($i = $returnStart; $i <= $lastIndex; $i++) {
    $prev = $values[$i - 1] ?? null;
    $curr = $values[$i] ?? null;
    if (!is_numeric($prev) || !is_numeric($curr) || (float)$prev == 0.0) {
        continue;
    }
    $pct = (((float)$curr - (float)$prev) / (float)$prev) * 100;
    $rawDate = $rawDates[$i] ?? '';
    $dateKey = (string)preg_replace('/\D+/', '', (string)$rawDate);
    $dateLabel = $rawDate !== '' ? (string)$rawDate : 'n/a';
    if ($dateKey !== '' && strlen($dateKey) >= 8) {
        $dateObj = DateTimeImmutable::createFromFormat('Ymd', substr($dateKey, 0, 8));
        if ($dateObj instanceof DateTimeImmutable) {
            $dateLabel = $dateObj->format('Y-m-d');
        }
    }
    $last7Returns[] = $dateLabel . ': ' . formatPercent($pct, 1, true) . ' ' . emojiForChange($pct);
}

$positionsByConid = [];
foreach ($accountIds as $accountId) {
    $positions = apiRequest('GET', '/portfolio/' . rawurlencode($accountId) . '/positions');
    $positionsData = $positions['json'] ?? [];
    if (!is_array($positionsData)) {
        continue;
    }
    foreach ($positionsData as $row) {
        if (!is_array($row)) {
            continue;
        }
        $conid = $row['conid'] ?? null;
        if (!is_numeric($conid)) {
            continue;
        }
        $conid = (int)$conid;
        $symbol = $row['contractDesc'] ?? $row['symbol'] ?? $row['name'] ?? 'n/a';
        $currency = is_string($row['currency'] ?? null) ? (string)$row['currency'] : $baseCurrency;
        $mktValue = is_numeric($row['mktValue'] ?? null) ? (float)$row['mktValue'] : 0.0;
        if (!isset($positionsByConid[$conid])) {
            $positionsByConid[$conid] = [
                'conid' => $conid,
                'symbol' => (string)$symbol,
                'currency' => $currency,
                'mktValue' => 0.0,
            ];
        }
        $positionsByConid[$conid]['mktValue'] += $mktValue;
    }
}

$positionsList = array_values($positionsByConid);
usort($positionsList, function (array $a, array $b): int {
    return abs($b['mktValue']) <=> abs($a['mktValue']);
});

$positionsList = array_slice($positionsList, 0, 12);
$movers = [];
foreach ($positionsList as $position) {
    $change = fetchHistoryChange((int)$position['conid']);
    if ($change === null || !isset($change['pct'], $change['price'])) {
        continue;
    }
    $movers[] = [
        'symbol' => $position['symbol'],
        'currency' => $position['currency'],
        'pct' => (float)$change['pct'],
        'price' => (float)$change['price'],
    ];
}

$topMovers = $movers;
$worstMovers = $movers;
usort($topMovers, function (array $a, array $b): int {
    return $b['pct'] <=> $a['pct'];
});
usort($worstMovers, function (array $a, array $b): int {
    return $a['pct'] <=> $b['pct'];
});
$topMovers = array_slice($topMovers, 0, 2);
$worstMovers = array_slice($worstMovers, 0, 2);

$spConid = resolveConidForSymbol('SPX', 'IND');
if ($spConid === null) {
    $spConid = resolveConidForSymbol('SPY', 'STK');
}

$nasdaqConid = resolveConidForSymbol('NDX', 'IND');
if ($nasdaqConid === null) {
    $nasdaqConid = resolveConidForSymbol('QQQ', 'STK');
}

$spReturn = $spConid !== null ? fetchHistoryChange($spConid) : null;
$nasdaqReturn = $nasdaqConid !== null ? fetchHistoryChange($nasdaqConid) : null;

$lines = [];
$lines[] = mdBold('📉 Portfolio Report: ' . $reportDate);
$lines[] = '';
$lines[] = mdBold('Overview');
$lines[] = '• Daily P&L: ' . mdCode(formatMoney($dailyPnl, $baseCurrency, true)) . ' \\(' . emojiForChange($dailyPct) . ' ' . mdCode(formatPercent($dailyPct, 2, true)) . '\\)';
$lines[] = '• Total NAV: ' . mdCode(formatMoney($lastNav, $baseCurrency, false));
$ytdEmoji = trendEmoji($ytdReturn);
$ytdLabel = $ytdReturn === null ? mdEscape('n/a') : mdCode(formatPercent($ytdReturn, 1, true));
$lines[] = '• YTD Return: ' . $ytdLabel . ($ytdEmoji !== '' ? ' ' . $ytdEmoji : '');
$lines[] = '';
$lines[] = mdBold('vs. Market');
if ($spReturn !== null && isset($spReturn['pct'])) {
    $lines[] = '• 🏛️ ' . mdEscape('S&P 500') . ': ' . mdCode(formatPercent($spReturn['pct'], 1, true)) . compareLabel($dailyPct, (float)$spReturn['pct']);
} else {
    $lines[] = '• 🏛️ ' . mdEscape('S&P 500') . ': ' . mdEscape('n/a');
}
if ($nasdaqReturn !== null && isset($nasdaqReturn['pct'])) {
    $lines[] = '• 💻 ' . mdEscape('Nasdaq') . ': ' . mdCode(formatPercent($nasdaqReturn['pct'], 1, true)) . compareLabel($dailyPct, (float)$nasdaqReturn['pct']);
} else {
    $lines[] = '• 💻 ' . mdEscape('Nasdaq') . ': ' . mdEscape('n/a');
}
$lines[] = '';
$lines[] = mdBold('Top Movers');
if (count($topMovers) === 0) {
    $lines[] = '• ' . mdEscape('n/a');
} else {
    foreach ($topMovers as $mover) {
        $lines[] = '• ' . emojiForChange($mover['pct']) . ' ' . mdEscape($mover['symbol']) . ': ' . mdCode(formatMoney($mover['price'], $mover['currency'], false)) . ' \\(' . mdCode(formatPercent($mover['pct'], 1, true)) . '\\)';
    }
}
$lines[] = '';
$lines[] = mdBold('Worst Movers');
if (count($worstMovers) === 0) {
    $lines[] = '• ' . mdEscape('n/a');
} else {
    foreach ($worstMovers as $mover) {
        $lines[] = '• ' . emojiForChange($mover['pct']) . ' ' . mdEscape($mover['symbol']) . ': ' . mdCode(formatMoney($mover['price'], $mover['currency'], false)) . ' \\(' . mdCode(formatPercent($mover['pct'], 1, true)) . '\\)';
    }
}
$lines[] = '';
$lines[] = mdBold('Last 7 Days');
if (count($last7Returns) === 0) {
    $lines[] = '• ' . mdEscape('n/a');
} else {
    foreach ($last7Returns as $line) {
        $parts = explode(': ', $line, 2);
        if (count($parts) === 2) {
            $lines[] = '• ' . mdCode($parts[0]) . ': ' . mdCode($parts[1]);
        } else {
            $lines[] = '• ' . mdEscape($line);
        }
    }
}

$message = implode("\n", $lines);

$telegram = telegramRequest($token, 'sendMessage', [
    'chat_id' => $chatId,
    'text' => $message,
    'disable_web_page_preview' => true,
    'parse_mode' => 'MarkdownV2',
]);

if ($telegram['error'] !== null) {
    stderr('Telegram request failed: ' . $telegram['error']);
    exit(1);
}

$telegramJson = $telegram['json'] ?? null;
if (!is_array($telegramJson) || ($telegramJson['ok'] ?? false) !== true) {
    stderr('Telegram request returned an error.');
    exit(1);
}

echo $message . PHP_EOL;
