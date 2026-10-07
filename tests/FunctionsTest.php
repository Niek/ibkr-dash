<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
    public function testExtractAccountIds(): void
    {
        $this->assertSame(
            ['U1', 'U2', 'U3'],
            extractAccountIds(['U1', 'U1', ['accountId' => 'U2'], ['id' => 'U3'], 5, ''])
        );
        $this->assertSame(['U9'], extractAccountIds(['accounts' => ['U9'], 'selectedAccount' => 'U9']));
        $this->assertSame([], extractAccountIds(null));
    }

    public function testExtractNavSeries(): void
    {
        $performance = ['nav' => [
            'dates' => ['20261001', '20261002', 'bad', '20261005'],
            'data' => [['navs' => [100.123, 200, 300, 'x'], 'baseCurrency' => 'EUR']],
        ]];

        $series = extractNavSeries($performance);

        $this->assertSame(['Oct 1', 'Oct 2'], $series['labels']);
        $this->assertSame([100.12, 200.0], $series['values']);
        $this->assertSame(['20261001', '20261002'], $series['rawDates']);
        $this->assertSame('EUR', $series['currency']);
        $this->assertSame(["Oct 1 '26", "Oct 2 '26"], extractNavSeries($performance, true)['labels']);
    }

    public function testExtractNavSeriesHandlesMissingData(): void
    {
        $this->assertSame(
            ['labels' => [], 'values' => [], 'currency' => null, 'rawDates' => []],
            extractNavSeries(['error' => 'Bad Request'])
        );
    }
}
