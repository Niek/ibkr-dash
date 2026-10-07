<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    public function testLongPositionInBaseCurrency(): void
    {
        $row = computePositionRow([
            'contractDesc' => 'ASML',
            'position' => 10,
            'avgCost' => 100,
            'mktPrice' => 120,
            'mktValue' => 1200,
            'currency' => 'EUR',
            'unrealizedPnl' => 200,
            'realizedPnl' => 0,
            'assetClass' => 'STK',
        ], 'EUR', [], []);

        $this->assertSame('ASML', $row['symbol']);
        $this->assertEqualsWithDelta(20.0, $row['unrealizedPct'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $row['realizedPct'], 1e-9);
        $this->assertSame($row['unrealized'], $row['unrealizedBase']);
        $this->assertSame($row['unrealizedPct'], $row['unrealizedBasePct']);
    }

    public function testProfitableShortHasPositivePercentage(): void
    {
        $row = computePositionRow([
            'position' => -10,
            'avgCost' => 100,
            'mktValue' => -900,
            'currency' => 'EUR',
            'unrealizedPnl' => 100,
        ], 'EUR', [], []);

        $this->assertEqualsWithDelta(10.0, $row['unrealizedPct'], 1e-9);
    }

    public function testLosingShortHasNegativePercentage(): void
    {
        $row = computePositionRow([
            'position' => -10,
            'avgCost' => 100,
            'mktValue' => -1050,
            'currency' => 'EUR',
            'unrealizedPnl' => -50,
        ], 'EUR', [], []);

        $this->assertEqualsWithDelta(-5.0, $row['unrealizedPct'], 1e-9);
    }

    public function testForeignPositionUsesHistoricalFxForBaseCost(): void
    {
        $ledger = ['USD' => ['fxRateToBase' => 0.9]];
        $txs = [['qty' => 10.0, 'weight' => 1000.0, 'fxRate' => 0.8]];

        $row = computePositionRow([
            'position' => 10,
            'avgCost' => 100,
            'mktValue' => 1100,
            'currency' => 'USD',
            'unrealizedPnl' => 100,
            'realizedPnl' => 20,
        ], 'EUR', $ledger, $txs);

        // Cost 1000 USD @ 0.8 = 800 EUR; value 1100 USD @ 0.9 = 990 EUR.
        $this->assertEqualsWithDelta(190.0, $row['unrealizedBase'], 1e-9);
        $this->assertEqualsWithDelta(23.75, $row['unrealizedBasePct'], 1e-9);
        $this->assertEqualsWithDelta(16.0, $row['realizedBase'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $row['realizedBasePct'], 1e-9);
    }

    public function testForeignPositionWithoutTransactionsHasNoBasePnl(): void
    {
        $row = computePositionRow([
            'position' => 10,
            'avgCost' => 100,
            'mktValue' => 1100,
            'currency' => 'USD',
            'unrealizedPnl' => 100,
        ], 'EUR', ['USD' => ['fxRateToBase' => 0.9]], []);

        $this->assertNull($row['unrealizedBase']);
        $this->assertNull($row['realizedBase']);
        $this->assertEqualsWithDelta(10.0, $row['unrealizedPct'], 1e-9);
    }

    public function testMissingFieldsBecomeNull(): void
    {
        $row = computePositionRow(['symbol' => 'X'], 'EUR', [], []);

        $this->assertSame('X', $row['symbol']);
        $this->assertSame('n/a', $row['currency']);
        $this->assertSame('n/a', $row['assetClass']);
        foreach (['position', 'mktPrice', 'mktValue', 'unrealized', 'unrealizedPct', 'realized', 'realizedPct'] as $key) {
            $this->assertNull($row[$key], $key);
        }
    }

    public function testPartitionedPnlMatchesExactAccount(): void
    {
        $pnl = ['upnl' => [
            'U1234.Core' => ['dpl' => 5, 'upl' => 6],
            'U123.Core' => ['dpl' => 1, 'upl' => 2],
        ]];

        $this->assertSame(['dpl' => 1.0, 'upl' => 2.0], extractPartitionedPnl($pnl, 'U123'));
        $this->assertSame(['dpl' => 5.0, 'upl' => 6.0], extractPartitionedPnl($pnl, 'U1234'));
        $this->assertSame(['dpl' => null, 'upl' => null], extractPartitionedPnl($pnl, 'U12'));
    }

    public function testGroupTransactionsAndWeightedFxRate(): void
    {
        $data = ['transactions' => [
            ['conid' => 42, 'type' => 'Buy', 'qty' => 10, 'pr' => 100, 'cur' => 'USD', 'fxRate' => 0.8, 'amt' => 1000, 'date' => '20250102'],
            ['conid' => 42, 'type' => 'Sell', 'qty' => 4, 'pr' => 120, 'cur' => 'USD', 'fxRate' => 0.9, 'amt' => 480, 'date' => '20250101'],
            ['conid' => 42, 'type' => 'Buy', 'qty' => 10, 'pr' => 200, 'cur' => 'USD', 'fxRate' => 1.0, 'amt' => 2000, 'date' => '20250103'],
            ['conid' => 7, 'type' => 'Buy', 'qty' => 1, 'pr' => 1, 'cur' => 'USD', 'fxRate' => 1.0, 'date' => '20250101'],
        ]];

        $grouped = groupTransactionsByConid($data, 'EUR');

        $this->assertSame([42, 7], array_keys($grouped));
        $this->assertSame([20250101, 20250102, 20250103], array_column($grouped[42], 'dateKey'));
        $this->assertSame([-4.0, 10.0, 10.0], array_column($grouped[42], 'qty'));
        // Only buys count, weighted by traded value: (1000*0.8 + 2000*1.0) / 3000.
        $this->assertEqualsWithDelta(2800 / 3000, computeWeightedFxRate($grouped[42]), 1e-9);
        $this->assertNull(computeWeightedFxRate([]));
    }

    public function testFxRateToBase(): void
    {
        $ledger = ['USD' => ['fxRateToBase' => 0.9], 'GBP' => ['other' => 1]];

        $this->assertSame(1.0, extractFxRateToBase($ledger, 'EUR', 'EUR'));
        $this->assertSame(0.9, extractFxRateToBase($ledger, 'USD', 'EUR'));
        $this->assertNull(extractFxRateToBase($ledger, 'GBP', 'EUR'));
        $this->assertNull(extractFxRateToBase($ledger, 'USD', null));
    }

    public function testNetLiquidationFromSummaryObject(): void
    {
        $result = extractNetLiquidation(['netliquidation' => ['amount' => 1234.5, 'currency' => 'EUR']], []);

        $this->assertSame('summary', $result['source']);
        $this->assertSame(1234.5, extractScalarValue($result['value']));
        $this->assertSame('EUR', extractCurrencyFromValue($result['value']));
    }

    public function testNetLiquidationFallsBackToLedger(): void
    {
        $result = extractNetLiquidation([], ['BASE' => ['netliquidationvalue' => 99]]);

        $this->assertSame(['value' => 99, 'currency' => 'BASE', 'source' => 'ledger'], $result);
    }

    public function testCashBalances(): void
    {
        $ledger = [
            'BASE' => ['cashbalance' => 10],
            'USD' => ['cashbalance' => 5],
            'EUR' => ['other' => 1],
        ];

        $this->assertSame([
            ['currency' => 'BASE', 'value' => 10.0],
            ['currency' => 'USD', 'value' => 5.0],
        ], extractCashBalances($ledger));
        $this->assertSame(10.0, extractBaseCashBalance($ledger));
        $this->assertNull(extractBaseCashBalance(null));
    }

    public function testPnlFormattingHelpers(): void
    {
        $this->assertSame(' (+1.23%)', pnlPercentLabel(1.234));
        $this->assertSame(' (-2.00%)', pnlPercentLabel(-2.0));
        $this->assertSame('', pnlPercentLabel(null));
        $this->assertSame('has-text-success', pnlClass(0.0));
        $this->assertSame('has-text-danger', pnlClass(-0.01));
        $this->assertSame('', pnlClass(null));
        $this->assertTrue(isNearZero(0.0000001));
        $this->assertFalse(isNearZero(null));
    }
}
