<?php

declare(strict_types=1);

// Periods accepted by the deployed gateway's /pa/performance endpoint; the
// OpenAPI spec also lists 3M/6M/12M but the gateway rejects them.
const PERFORMANCE_PERIODS = [
    '7D' => 'Last 7 Days',
    'MTD' => 'Month to Date',
    '1M' => 'Last 30 Days',
    'YTD' => 'Year to Date',
    '1Y' => 'Last 12 Months',
];

function selectedPerformancePeriod(): string
{
    $candidate = $_GET['period'] ?? null;
    if (is_string($candidate)) {
        $candidate = strtoupper($candidate);
        if (array_key_exists($candidate, PERFORMANCE_PERIODS)) {
            return $candidate;
        }
    }
    return '1M';
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
        if ($accountId !== '' && ($key === $accountId || str_starts_with($key, $accountId . '.'))) {
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

/**
 * Derive the numbers shown in one positions-table row. Percentages are relative to
 * the absolute cost basis so short positions keep the sign of their P&L.
 */
function computePositionRow(array $row, string $baseCurrency, $ledgerData, array $txs): array
{
    $position = is_numeric($row['position'] ?? null) ? (float)$row['position'] : null;
    $mktValue = is_numeric($row['mktValue'] ?? null) ? (float)$row['mktValue'] : null;
    $currency = (string)($row['currency'] ?? 'n/a');
    $unrealized = is_numeric($row['unrealizedPnl'] ?? null) ? (float)$row['unrealizedPnl'] : null;
    $realized = is_numeric($row['realizedPnl'] ?? null) ? (float)$row['realizedPnl'] : null;
    $avgCost = is_numeric($row['avgCost'] ?? null) ? (float)$row['avgCost'] : null;

    $costBasis = ($position !== null && $avgCost !== null) ? $position * $avgCost : null;
    $hasCostBasis = $costBasis !== null && $costBasis != 0.0;
    $unrealizedPct = ($unrealized !== null && $hasCostBasis) ? ($unrealized / abs($costBasis)) * 100 : null;
    $realizedPct = ($realized !== null && $hasCostBasis) ? ($realized / abs($costBasis)) * 100 : null;

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
        $fxRate = extractFxRateToBase($ledgerData, $currency, $baseCurrency);
        $currentValueBase = ($mktValue !== null && $fxRate !== null) ? $mktValue * $fxRate : null;
        $avgFxRate = computeWeightedFxRate($txs);
        if ($avgFxRate !== null && $costBasis !== null && $currentValueBase !== null) {
            $baseCost = $costBasis * $avgFxRate;
            if ($baseCost != 0.0) {
                $unrealizedBase = $currentValueBase - $baseCost;
                $unrealizedBasePct = ($unrealizedBase / abs($baseCost)) * 100;
            }
            if ($realized !== null) {
                $realizedBase = $realized * $avgFxRate;
                $realizedBasePct = $realizedPct;
            }
        }
    }

    return [
        'symbol' => (string)($row['contractDesc'] ?? $row['symbol'] ?? $row['name'] ?? 'n/a'),
        'position' => $position,
        'mktPrice' => is_numeric($row['mktPrice'] ?? null) ? (float)$row['mktPrice'] : null,
        'mktValue' => $mktValue,
        'currency' => $currency,
        'assetClass' => (string)($row['assetClass'] ?? 'n/a'),
        'unrealized' => $unrealized,
        'unrealizedPct' => $unrealizedPct,
        'realized' => $realized,
        'realizedPct' => $realizedPct,
        'unrealizedBase' => $unrealizedBase,
        'unrealizedBasePct' => $unrealizedBasePct,
        'realizedBase' => $realizedBase,
        'realizedBasePct' => $realizedBasePct,
    ];
}

function pnlClass(?float $value): string
{
    if ($value === null) {
        return '';
    }
    return $value < 0 ? 'has-text-danger' : 'has-text-success';
}

function pnlPercentLabel(?float $pct): string
{
    if ($pct === null) {
        return '';
    }
    return ' (' . ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%)';
}

function isNearZero(?float $value): bool
{
    return $value !== null && abs($value) < 0.000001;
}
