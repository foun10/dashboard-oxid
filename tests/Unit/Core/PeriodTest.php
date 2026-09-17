<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Unit\Core;

use DateTimeImmutable;
use foun10\Dashboard\Core\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    private const NOW = '2026-09-17 13:05:00';

    /** @var string */
    private $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testTodayRunsFromMidnightToNowWithHourlyBuckets(): void
    {
        $period = $this->period(Period::TODAY);

        self::assertSame(Period::TODAY, $period->getKey());
        self::assertSame('2026-09-17 00:00:00', $this->format($period->getStart()));
        self::assertSame(self::NOW, $this->format($period->getEnd()));
        self::assertSame(Period::BUCKET_HOUR, $period->getBucket());
    }

    public function testTodayComparesWithTheSameSpanOfYesterdayAndLastYear(): void
    {
        $period = $this->period(Period::TODAY);

        self::assertTrue($period->hasPrevious());
        self::assertSame('2026-09-16 00:00:00', $this->format($period->getPreviousStart()));
        self::assertSame('2026-09-16 13:05:00', $this->format($period->getPreviousEnd()));
        self::assertSame('2025-09-17 00:00:00', $this->format($period->getLastYearStart()));
        self::assertSame('2025-09-17 13:05:00', $this->format($period->getLastYearEnd()));
    }

    public function testTodayChartsTheWholeDayIncludingHoursStillToCome(): void
    {
        $buckets = $this->period(Period::TODAY)->getBuckets();

        self::assertCount(24, $buckets);
        self::assertSame('2026091700', $buckets[0]['key']);
        self::assertSame('2026091723', $buckets[23]['key']);
        self::assertSame('2026-09-17 00:59:59', $this->format($buckets[0]['end']));
        self::assertSame('2025091700', $buckets[0]['lastYearKey']);
        self::assertSame('2025-09-17 00:00:00', $this->format($buckets[0]['lastYearStart']));
        self::assertSame('2025-09-17 00:59:59', $this->format($buckets[0]['lastYearEnd']));
        self::assertNull($buckets[0]['week']);
    }

    /**
     * @dataProvider dayPresetProvider
     */
    public function testDayPresetsEndNowAndCompareWithTheEquallyLongSpanBefore(
        string $key,
        string $start,
        string $previousStart,
        string $previousEnd,
        int $buckets
    ): void {
        $period = $this->period($key);

        self::assertSame($start, $this->format($period->getStart()));
        self::assertSame(self::NOW, $this->format($period->getEnd()));
        self::assertSame($previousStart, $this->format($period->getPreviousStart()));
        self::assertSame($previousEnd, $this->format($period->getPreviousEnd()));
        self::assertSame(Period::BUCKET_DAY, $period->getBucket());
        self::assertCount($buckets, $period->getBuckets());
    }

    public function dayPresetProvider(): array
    {
        return [
            '7 days' => [Period::DAYS_7, '2026-09-11 00:00:00', '2026-09-04 00:00:00', '2026-09-10 13:05:00', 7],
            '30 days' => [Period::DAYS_30, '2026-08-19 00:00:00', '2026-07-20 00:00:00', '2026-08-18 13:05:00', 30],
            '90 days' => [Period::DAYS_90, '2026-06-20 00:00:00', '2026-03-22 00:00:00', '2026-06-19 13:05:00', 90],
        ];
    }

    public function testDayBucketsAreKeyedPerDayWithTheSameDateLastYear(): void
    {
        $buckets = $this->period(Period::DAYS_7)->getBuckets();

        self::assertSame('20260911', $buckets[0]['key']);
        self::assertSame('20260917', $buckets[6]['key']);
        self::assertSame('2026-09-11 23:59:59', $this->format($buckets[0]['end']));
        self::assertSame('20250911', $buckets[0]['lastYearKey']);
        self::assertSame('2025-09-11 23:59:59', $this->format($buckets[0]['lastYearEnd']));
    }

    public function testYearToDateHasNoPreviousPeriodBecauseThatWouldBeLastYear(): void
    {
        $period = $this->period(Period::YEAR_TO_DATE);

        self::assertSame('2026-01-01 00:00:00', $this->format($period->getStart()));
        self::assertSame(self::NOW, $this->format($period->getEnd()));
        self::assertFalse($period->hasPrevious());
        self::assertNull($period->getPreviousStart());
        self::assertNull($period->getPreviousEnd());
        self::assertSame('2025-01-01 00:00:00', $this->format($period->getLastYearStart()));
        self::assertSame('2025-09-17 13:05:00', $this->format($period->getLastYearEnd()));
        self::assertSame(Period::BUCKET_WEEK, $period->getBucket());
    }

    public function testYearToDateChartsIsoWeeksStartingOnTheMondayBeforeNewYear(): void
    {
        $buckets = $this->period(Period::YEAR_TO_DATE)->getBuckets();

        self::assertCount(38, $buckets);

        // 1 January 2026 is a Thursday - week 1 starts on Monday, 29 December 2025.
        self::assertSame('202601', $buckets[0]['key']);
        self::assertSame(1, $buckets[0]['week']);
        self::assertSame('2025-12-29 00:00:00', $this->format($buckets[0]['start']));
        self::assertSame('2026-01-04 23:59:59', $this->format($buckets[0]['end']));

        // compared with week 1 of 2025, which started on 30 December 2024
        self::assertSame('202501', $buckets[0]['lastYearKey']);
        self::assertSame('2024-12-30 00:00:00', $this->format($buckets[0]['lastYearStart']));
        self::assertSame('2025-01-05 23:59:59', $this->format($buckets[0]['lastYearEnd']));

        self::assertSame('202638', $buckets[37]['key']);
        self::assertSame('2026-09-14 00:00:00', $this->format($buckets[37]['start']));
    }

    public function testWeek53WithoutCounterpartLastYearHasNoComparison(): void
    {
        $buckets = (new Period(Period::YEAR_TO_DATE, new DateTimeImmutable('2021-03-01 10:00:00')))->getBuckets();

        // 1 January 2021 is a Friday in ISO week 53 of 2020; 2019 had only 52 weeks.
        self::assertCount(10, $buckets);
        self::assertSame('202053', $buckets[0]['key']);
        self::assertSame(53, $buckets[0]['week']);
        self::assertNull($buckets[0]['lastYearKey']);
        self::assertNull($buckets[0]['lastYearStart']);
        self::assertNull($buckets[0]['lastYearEnd']);

        self::assertSame('202101', $buckets[1]['key']);
        self::assertSame('202001', $buckets[1]['lastYearKey']);
    }

    public function testWeekStartingRightNowIsCharted(): void
    {
        // Monday 14 September 2026, 00:00:00 - the current week has just begun
        $buckets = (new Period(Period::YEAR_TO_DATE, new DateTimeImmutable('2026-09-14 00:00:00')))->getBuckets();

        self::assertCount(38, $buckets);
        self::assertSame('202638', end($buckets)['key']);
    }

    /**
     * @dataProvider unknownKeyProvider
     */
    public function testUnknownKeysFallBackToYearToDate(string $key): void
    {
        self::assertSame(Period::YEAR_TO_DATE, $this->period($key)->getKey());
    }

    public function unknownKeyProvider(): array
    {
        return [
            'empty' => [''],
            'unknown' => ['14d'],
            'numeric prefix of a preset' => ['7'],
            'case differs' => ['YTD'],
        ];
    }

    public function testDefaultKeyIsYearToDate(): void
    {
        self::assertSame(Period::YEAR_TO_DATE, Period::DEFAULT_KEY);
        self::assertNotContains(Period::CUSTOM, Period::KEYS);
    }

    public function testCustomRangeInThePastEndsAtTheEndOfItsLastDay(): void
    {
        $period = $this->custom('2026-03-01', '2026-03-31');

        self::assertSame(Period::CUSTOM, $period->getKey());
        self::assertSame('2026-03-01 00:00:00', $this->format($period->getStart()));
        self::assertSame('2026-03-31 23:59:59', $this->format($period->getEnd()));
        self::assertSame('2026-03-01', $period->getFromDate());
        self::assertSame('2026-03-31', $period->getToDate());
        self::assertSame(Period::BUCKET_DAY, $period->getBucket());
        self::assertCount(31, $period->getBuckets());
    }

    public function testCustomRangeComparesWithTheEquallyLongRangeBefore(): void
    {
        $period = $this->custom('2026-03-01', '2026-03-31');

        self::assertTrue($period->hasPrevious());
        self::assertSame('2026-01-29 00:00:00', $this->format($period->getPreviousStart()));
        self::assertSame('2026-02-28 23:59:59', $this->format($period->getPreviousEnd()));
        self::assertSame('2025-03-01 00:00:00', $this->format($period->getLastYearStart()));
        self::assertSame('2025-03-31 23:59:59', $this->format($period->getLastYearEnd()));
    }

    public function testCustomRangeReproducesItselfInLinksAndCacheKeys(): void
    {
        $period = $this->custom('2026-03-01', '2026-03-31');

        self::assertSame('&period=custom&from=2026-03-01&to=2026-03-31', $period->getQuery());
        self::assertSame('custom_2026-03-01_2026-03-31', $period->getCacheId());
    }

    public function testPresetReproducesItselfInLinksAndCacheKeys(): void
    {
        $period = $this->period(Period::DAYS_30);

        self::assertSame('&period=30d', $period->getQuery());
        self::assertSame('30d', $period->getCacheId());
    }

    public function testSwappedCustomDatesArePutInOrder(): void
    {
        $period = $this->custom('2026-03-31', '2026-03-01');

        self::assertSame('2026-03-01', $period->getFromDate());
        self::assertSame('2026-03-31', $period->getToDate());
    }

    public function testCustomRangeEndingTodayEndsNowLikeThePresets(): void
    {
        $period = $this->custom('2026-09-10', '2026-09-17');

        self::assertSame(self::NOW, $this->format($period->getEnd()));
        self::assertSame('2026-09-09 13:05:00', $this->format($period->getPreviousEnd()));
    }

    public function testFutureEndIsCutToNow(): void
    {
        $period = $this->custom('2026-09-10', '2027-01-01');

        self::assertSame(self::NOW, $this->format($period->getEnd()));
        self::assertSame('2026-09-17', $period->getToDate());
    }

    public function testRangeEntirelyInTheFutureFallsBackToTheDefault(): void
    {
        self::assertSame(Period::YEAR_TO_DATE, $this->custom('2026-10-01', '2026-10-31')->getKey());
    }

    public function testSingleDayIsChartedPerHourAndComparedWithTheDayBefore(): void
    {
        $period = $this->custom('2026-03-10', '2026-03-10');

        self::assertSame(Period::BUCKET_HOUR, $period->getBucket());
        self::assertCount(24, $period->getBuckets());
        self::assertSame('2026-03-09 00:00:00', $this->format($period->getPreviousStart()));
        self::assertSame('2026-03-09 23:59:59', $this->format($period->getPreviousEnd()));
    }

    /**
     * @dataProvider customBucketProvider
     */
    public function testCustomRangeLengthDecidesTheBucket(string $from, string $to, string $bucket): void
    {
        self::assertSame($bucket, $this->custom($from, $to)->getBucket());
    }

    public function customBucketProvider(): array
    {
        return [
            '2 days' => ['2026-03-10', '2026-03-11', Period::BUCKET_DAY],
            '92 days' => ['2026-01-01', '2026-04-02', Period::BUCKET_DAY],
            '93 days' => ['2026-01-01', '2026-04-03', Period::BUCKET_WEEK],
            '366 days' => ['2024-01-01', '2024-12-31', Period::BUCKET_WEEK],
            '367 days' => ['2023-01-01', '2024-01-02', Period::BUCKET_MONTH],
        ];
    }

    public function testMonthBucketsStartOnTheFirstOfTheMonth(): void
    {
        $buckets = $this->custom('2024-01-15', '2025-03-10')->getBuckets();

        self::assertCount(15, $buckets);
        self::assertSame('202401', $buckets[0]['key']);
        self::assertSame('2024-01-01 00:00:00', $this->format($buckets[0]['start']));
        self::assertSame('202402', $buckets[1]['key']);
        self::assertSame('2024-02-29 23:59:59', $this->format($buckets[1]['end']));
        self::assertSame('202302', $buckets[1]['lastYearKey']);
        self::assertSame('2023-02-28 23:59:59', $this->format($buckets[1]['lastYearEnd']));
        self::assertSame('202503', $buckets[14]['key']);
    }

    public function testLeapDayHasNoComparisonSoLastYearsFirstOfMarchIsNotCountedTwice(): void
    {
        $buckets = $this->custom('2024-02-28', '2024-03-01')->getBuckets();

        self::assertSame(['20230228', null, '20230301'], array_column($buckets, 'lastYearKey'));
        self::assertNull($buckets[1]['lastYearStart']);
        self::assertNull($buckets[1]['lastYearEnd']);
    }

    public function testLeapDayKeepsItsComparisonWhenChartedPerHour(): void
    {
        $buckets = $this->custom('2024-02-29', '2024-02-29')->getBuckets();

        self::assertSame('2023030100', $buckets[0]['lastYearKey']);
    }

    public function testCustomStartBeforeTheEarliestDateIsRaisedToIt(): void
    {
        $period = $this->custom('2000-01-01', '2010-01-01');

        self::assertSame(Period::CUSTOM_MIN_DATE, $period->getFromDate());
        self::assertSame('2010-01-01', $period->getToDate());
    }

    public function testCustomRangeEntirelyBeforeTheEarliestDateFallsBackToTheDefault(): void
    {
        self::assertSame(Period::YEAR_TO_DATE, $this->custom('2000-01-01', '2005-01-01')->getKey());
    }

    public function testOverlongCustomRangeIsCutToTheMaximumEndingOnItsEndDate(): void
    {
        $period = $this->custom('2009-01-01', '2026-09-17');

        self::assertSame('2021-09-14', $period->getFromDate());
        self::assertSame('2026-09-17', $period->getToDate());
        self::assertSame(Period::BUCKET_MONTH, $period->getBucket());
        self::assertSame(
            Period::CUSTOM_MAX_DAYS,
            (int) $period->getStart()->diff($period->getEnd()->setTime(0, 0))->days + 1
        );
    }

    public function testCustomRangeOfExactlyTheMaximumIsKept(): void
    {
        self::assertSame('2021-09-14', $this->custom('2021-09-14', '2026-09-17')->getFromDate());
    }

    /**
     * @dataProvider invalidDateProvider
     */
    public function testInvalidCustomDatesFallBackToTheDefault(string $from, string $to): void
    {
        self::assertSame(Period::YEAR_TO_DATE, $this->custom($from, $to)->getKey());
    }

    public function invalidDateProvider(): array
    {
        return [
            'both empty' => ['', ''],
            'from missing' => ['', '2026-03-01'],
            'to missing' => ['2026-03-01', ''],
            'nonexistent day' => ['2026-02-30', '2026-03-01'],
            'german format' => ['01.03.2026', '31.03.2026'],
            'with time' => ['2026-03-01 10:00:00', '2026-03-31'],
            'trailing text' => ['2026-03-01x', '2026-03-31'],
            'no leading zeros' => ['2026-3-1', '2026-3-31'],
        ];
    }

    public function testCustomDatesAreIgnoredForPresets(): void
    {
        $period = new Period(Period::DAYS_7, new DateTimeImmutable(self::NOW), '2026-03-01', '2026-03-31');

        self::assertSame(Period::DAYS_7, $period->getKey());
        self::assertSame('2026-09-11', $period->getFromDate());
    }

    public function testBucketsAreComputedOnce(): void
    {
        $period = $this->period(Period::DAYS_7);

        self::assertSame($period->getBuckets(), $period->getBuckets());
    }

    /**
     * @dataProvider bucketSqlProvider
     */
    public function testBucketKeySqlMatchesTheBucketKeyFormat(string $key, string $from, string $to, string $sql): void
    {
        $period = $key === Period::CUSTOM ? $this->custom($from, $to) : $this->period($key);

        self::assertSame($sql, $period->getBucketKeySql('o.OXORDERDATE'));
    }

    public function bucketSqlProvider(): array
    {
        return [
            'hour' => [Period::TODAY, '', '', "DATE_FORMAT(o.OXORDERDATE, '%Y%m%d%H')"],
            'day' => [Period::DAYS_7, '', '', "DATE_FORMAT(o.OXORDERDATE, '%Y%m%d')"],
            'week' => [Period::YEAR_TO_DATE, '', '', 'YEARWEEK(o.OXORDERDATE, 3)'],
            'month' => [Period::CUSTOM, '2023-01-01', '2024-06-30', "DATE_FORMAT(o.OXORDERDATE, '%Y%m')"],
        ];
    }

    private function period(string $key): Period
    {
        return new Period($key, new DateTimeImmutable(self::NOW));
    }

    private function custom(string $from, string $to): Period
    {
        return new Period(Period::CUSTOM, new DateTimeImmutable(self::NOW), $from, $to);
    }

    private function format(?DateTimeImmutable $date): string
    {
        self::assertNotNull($date);

        return $date->format('Y-m-d H:i:s');
    }
}
