<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Unit\Core;

use foun10\Dashboard\Core\Formatter;
use PHPUnit\Framework\TestCase;

final class FormatterTest extends TestCase
{
    /** @var Formatter */
    private $formatter;

    protected function setUp(): void
    {
        $this->formatter = new Formatter(',', '.', '€');
    }

    public function testMoneyUsesTheCurrencySeparatorsAndSign(): void
    {
        self::assertSame('1.234,50 €', $this->formatter->money(1234.5));
        self::assertSame('1.235 €', $this->formatter->money('1234.5', 0));
        self::assertSame('0,00 €', $this->formatter->money(null));
    }

    public function testOtherCurrenciesKeepTheirOwnFormat(): void
    {
        $formatter = new Formatter('.', ',', 'CHF');

        self::assertSame('1,234.50 CHF', $formatter->money(1234.5));
    }

    public function testAverageDividesRevenueByOrdersWithoutDecimals(): void
    {
        self::assertSame('41 €', $this->formatter->average(123, 3));
        self::assertSame('42 €', $this->formatter->average('125', '3'));
    }

    public function testAverageWithoutOrdersIsADash(): void
    {
        self::assertSame('–', $this->formatter->average(100, 0));
        self::assertSame('–', $this->formatter->average(100, null));
    }

    public function testNumber(): void
    {
        self::assertSame('1.235', $this->formatter->number(1234.5));
        self::assertSame('1.234,57', $this->formatter->number('1234.567', 2));
        self::assertSame('0', $this->formatter->number(''));
    }

    public function testPercentMultipliesTheShare(): void
    {
        self::assertSame('12,5 %', $this->formatter->percent(0.125));
        self::assertSame('13 %', $this->formatter->percent(0.125, 0));
        self::assertSame('0,0 %', $this->formatter->percent(0));
    }

    public function testChangeIsRelativeToTheComparisonValue(): void
    {
        self::assertSame(0.5, $this->formatter->change(150, 100));
        self::assertSame(-0.25, $this->formatter->change('75', '100'));
        self::assertSame(0.0, $this->formatter->change(100, 100));
    }

    public function testChangeAgainstANegativeValueUsesItsMagnitude(): void
    {
        self::assertSame(2.0, $this->formatter->change(100, -100));
    }

    public function testChangeFromZeroHasNoPercentage(): void
    {
        self::assertNull($this->formatter->change(100, 0));
        self::assertNull($this->formatter->change(100, '0.0'));
        self::assertNull($this->formatter->change(100, null));
    }

    /**
     * @dataProvider formatChangeProvider
     */
    public function testFormatChange(?float $change, string $expected): void
    {
        self::assertSame($expected, $this->formatter->formatChange($change));
    }

    public function formatChangeProvider(): array
    {
        return [
            'none' => [null, '–'],
            'up' => [0.125, '+12,5 %'],
            'down' => [-0.125, '−12,5 %'],
            'flat' => [0.0, '±0,0 %'],
            'just below 100 % keeps the decimal' => [0.994, '+99,4 %'],
            'from 100 % whole percent' => [1.0, '+100 %'],
            'large drop' => [-1.5, '−150 %'],
        ];
    }

    /**
     * @dataProvider changeDirectionProvider
     */
    public function testChangeDirection(?float $change, string $expected): void
    {
        self::assertSame($expected, $this->formatter->changeDirection($change));
    }

    public function changeDirectionProvider(): array
    {
        return [
            'none' => [null, 'flat'],
            'zero' => [0.0, 'flat'],
            'below half a percent up' => [0.0049, 'flat'],
            'below half a percent down' => [-0.0049, 'flat'],
            'half a percent up' => [0.005, 'up'],
            'half a percent down' => [-0.005, 'down'],
            'up' => [0.2, 'up'],
            'down' => [-0.2, 'down'],
        ];
    }

    /**
     * @dataProvider rangeProvider
     */
    public function testRange($range, string $expected): void
    {
        self::assertSame($expected, $this->formatter->range($range));
    }

    public function rangeProvider(): array
    {
        return [
            'same year' => [['2026-07-17 00:00:00', '2026-08-15 23:59:59'], '17.07.–15.08.2026'],
            'across new year' => [['2025-12-29 00:00:00', '2026-01-04 23:59:59'], '29.12.2025–04.01.2026'],
            'single day' => [['2026-09-15 00:00:00', '2026-09-15 13:05:00'], '15.09.2026, 00:00–13:05'],
            'keyed array' => [['from' => '2026-07-17 00:00:00', 'to' => '2026-08-15 23:59:59'], '17.07.–15.08.2026'],
            'null' => [null, ''],
            'one value' => [['2026-07-17 00:00:00'], ''],
            'three values' => [['2026-07-17', '2026-07-18', '2026-07-19'], ''],
            'unreadable' => [['yesterday-ish', '2026-07-18 00:00:00'], ''],
            'not a string' => [[20260717, '2026-07-18 00:00:00'], ''],
            'empty string' => [['', '2026-07-18 00:00:00'], ''],
        ];
    }

    public function testDateTime(): void
    {
        self::assertSame('17.09.2026 13:05', $this->formatter->dateTime('2026-09-17 13:05:42'));
        self::assertSame('13:05', $this->formatter->dateTime('2026-09-17 13:05:42', 'H:i'));
        self::assertSame('', $this->formatter->dateTime('not a date'));
        self::assertSame('', $this->formatter->dateTime(null));
        self::assertSame('', $this->formatter->dateTime('   '));
    }

    public function testTimeOrDateShowsTheDateOnlyForOtherDays(): void
    {
        $now = new \DateTimeImmutable('2026-09-17 13:05:00');

        self::assertSame('00:00', $this->formatter->timeOrDate('2026-09-17 00:00:00', $now));
        self::assertSame('23:59', $this->formatter->timeOrDate('2026-09-17 23:59:59', $now));
        self::assertSame('16.09. 23:59', $this->formatter->timeOrDate('2026-09-16 23:59:59', $now));
        self::assertSame('17.09. 08:00', $this->formatter->timeOrDate('2025-09-17 08:00:00', $now));
        self::assertSame('', $this->formatter->timeOrDate('garbage', $now));
        self::assertSame('', $this->formatter->timeOrDate(null, $now));
    }

    /**
     * @dataProvider barWidthProvider
     */
    public function testBarWidth($value, $max, string $expected): void
    {
        self::assertSame($expected, $this->formatter->barWidth($value, $max));
    }

    public function barWidthProvider(): array
    {
        return [
            'largest' => [200, 200, '100.00'],
            'half' => [100, 200, '50.00'],
            'dot decimal regardless of currency' => [1, 3, '33.33'],
            'tiny values keep a sliver' => [1, 1000, '0.50'],
            'exactly the sliver' => [1, 200, '0.50'],
            'just above the sliver' => [1.02, 200, '0.51'],
            'zero stays empty' => [0, 200, '0.00'],
            'negative stays empty' => [-5, 200, '0.00'],
            'no maximum' => [10, 0, '0'],
            'negative maximum' => [10, -1, '0'],
            'capped at full width' => [300, 200, '100.00'],
        ];
    }

    public function testEscapeKeepsExistingEntities(): void
    {
        self::assertSame('Tom &amp; Jerry', $this->formatter->escape('Tom &amp; Jerry'));
        self::assertSame('Tom &amp; Jerry', $this->formatter->escape('Tom & Jerry'));
        self::assertSame('&lt;b&gt;&quot;x&quot; &#039;y&#039;&lt;/b&gt;', $this->formatter->escape('<b>"x" \'y\'</b>'));
    }

    public function testEscapeCastsScalarsAndDropsEverythingElse(): void
    {
        self::assertSame('42', $this->formatter->escape(42));
        self::assertSame('', $this->formatter->escape(null));
        self::assertSame('', $this->formatter->escape(['x']));
    }
}
