<?php
declare(strict_types=1);

namespace foun10\Dashboard\Core;

use DateInterval;
use DateTimeImmutable;

/**
 * A dashboard time range plus the two ranges it is compared against.
 *
 * All ranges start at midnight (or the full hour for "today") and end "now",
 * so the previous period and the same period last year cover exactly the same
 * span of the day - comparing a half-finished today against a full yesterday
 * would always look like a drop.
 */
class Period
{
    public const TODAY = 'today';
    public const DAYS_7 = '7d';
    public const DAYS_30 = '30d';
    public const DAYS_90 = '90d';
    public const YEAR_TO_DATE = 'ytd';
    public const CUSTOM = 'custom';

    /** The preset buttons; CUSTOM is chosen via the date fields instead. */
    public const KEYS = [self::TODAY, self::DAYS_7, self::DAYS_30, self::DAYS_90, self::YEAR_TO_DATE];
    public const DEFAULT_KEY = self::YEAR_TO_DATE;

    /** Earliest selectable date and longest custom range, to keep the queries sane. */
    public const CUSTOM_MIN_DATE = '2009-01-01';
    public const CUSTOM_MAX_DAYS = 366 * 5;

    /** Custom ranges up to this many days are charted per day, up to a year per week, beyond per month. */
    protected const CUSTOM_DAY_BUCKET_MAX_DAYS = 92;
    protected const CUSTOM_WEEK_BUCKET_MAX_DAYS = 366;

    public const BUCKET_HOUR = 'hour';
    public const BUCKET_DAY = 'day';
    public const BUCKET_WEEK = 'week';
    public const BUCKET_MONTH = 'month';

    /** @var string */
    protected $key;

    /** @var DateTimeImmutable */
    protected $start;

    /** @var DateTimeImmutable */
    protected $end;

    /** @var DateTimeImmutable|null null when the previous period equals last year (YTD) */
    protected $previousStart;

    /** @var DateTimeImmutable|null */
    protected $previousEnd;

    /** @var DateTimeImmutable */
    protected $lastYearStart;

    /** @var DateTimeImmutable */
    protected $lastYearEnd;

    /** @var string */
    protected $bucket;

    /** @var array|null */
    protected $buckets;

    /**
     * @param string $key one of KEYS, or CUSTOM together with $from/$to
     * @param string $from custom start date 'Y-m-d'
     * @param string $to custom end date 'Y-m-d' (inclusive)
     */
    public function __construct(string $key, DateTimeImmutable $now, string $from = '', string $to = '')
    {
        $today = $now->setTime(0, 0, 0);

        if ($key === self::CUSTOM && $this->initCustom($now, $from, $to)) {
            $this->key = self::CUSTOM;
            $this->lastYearStart = $this->start->sub(new DateInterval('P1Y'));
            $this->lastYearEnd = $this->end->sub(new DateInterval('P1Y'));

            return;
        }

        if (!in_array($key, self::KEYS, true)) {
            $key = self::DEFAULT_KEY;
        }

        $this->key = $key;
        $this->end = $now;

        switch ($key) {
            case self::TODAY:
                $this->start = $today;
                $this->bucket = self::BUCKET_HOUR;
                $this->setPrevious(new DateInterval('P1D'));
                break;

            case self::YEAR_TO_DATE:
                $this->start = $today->setDate((int) $now->format('Y'), 1, 1);
                $this->bucket = self::BUCKET_WEEK;
                break;

            default:
                $days = (int) $key;
                $this->start = $today->sub(new DateInterval('P' . ($days - 1) . 'D'));
                $this->bucket = self::BUCKET_DAY;
                $this->setPrevious(new DateInterval('P' . $days . 'D'));
        }

        $this->lastYearStart = $this->start->sub(new DateInterval('P1Y'));
        $this->lastYearEnd = $this->end->sub(new DateInterval('P1Y'));
    }

    /**
     * Sets up a custom range from two 'Y-m-d' dates. Swapped dates are put
     * in order, a future end is cut to now, and a range ending today ends
     * "now" like the presets. Returns false for anything unusable, which
     * makes the constructor fall back to the default preset.
     */
    protected function initCustom(DateTimeImmutable $now, string $from, string $to): bool
    {
        $start = $this->parseDate($from);
        $endDay = $this->parseDate($to);
        $today = $now->setTime(0, 0, 0);

        if ($start === null || $endDay === null) {
            return false;
        }

        if ($start > $endDay) {
            [$start, $endDay] = [$endDay, $start];
        }

        if ($endDay > $today) {
            $endDay = $today;
        }

        $minDate = new DateTimeImmutable(self::CUSTOM_MIN_DATE);
        if ($start < $minDate) {
            $start = $minDate;
        }

        if ($start > $endDay) {
            return false;
        }

        $days = (int) $start->diff($endDay)->days + 1;
        if ($days > self::CUSTOM_MAX_DAYS) {
            $start = $endDay->sub(new DateInterval('P' . (self::CUSTOM_MAX_DAYS - 1) . 'D'));
            $days = self::CUSTOM_MAX_DAYS;
        }

        $this->start = $start;
        $this->end = $endDay == $today ? $now : $endDay->setTime(23, 59, 59);

        if ($days === 1) {
            $this->bucket = self::BUCKET_HOUR;
        } elseif ($days <= self::CUSTOM_DAY_BUCKET_MAX_DAYS) {
            $this->bucket = self::BUCKET_DAY;
        } elseif ($days <= self::CUSTOM_WEEK_BUCKET_MAX_DAYS) {
            $this->bucket = self::BUCKET_WEEK;
        } else {
            $this->bucket = self::BUCKET_MONTH;
        }

        // the equally long range right before
        $this->setPrevious(new DateInterval('P' . $days . 'D'));

        return true;
    }

    protected function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Start date of a custom range as 'Y-m-d' (for the date fields and links).
     */
    public function getFromDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function getToDate(): string
    {
        return $this->end->format('Y-m-d');
    }

    /**
     * URL parameters that reproduce this period, e.g. "&period=30d".
     */
    public function getQuery(): string
    {
        if ($this->key === self::CUSTOM) {
            return '&period=' . self::CUSTOM . '&from=' . $this->getFromDate() . '&to=' . $this->getToDate();
        }

        return '&period=' . $this->key;
    }

    /**
     * Identifies the period in cache keys. A custom range ending today goes
     * stale like the presets do, through the cache lifetime.
     */
    public function getCacheId(): string
    {
        if ($this->key === self::CUSTOM) {
            return self::CUSTOM . '_' . $this->getFromDate() . '_' . $this->getToDate();
        }

        return $this->key;
    }

    protected function setPrevious(DateInterval $shift): void
    {
        $this->previousStart = $this->start->sub($shift);
        $this->previousEnd = $this->end->sub($shift);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getStart(): DateTimeImmutable
    {
        return $this->start;
    }

    public function getEnd(): DateTimeImmutable
    {
        return $this->end;
    }

    public function hasPrevious(): bool
    {
        return $this->previousStart !== null;
    }

    public function getPreviousStart(): ?DateTimeImmutable
    {
        return $this->previousStart;
    }

    public function getPreviousEnd(): ?DateTimeImmutable
    {
        return $this->previousEnd;
    }

    public function getLastYearStart(): DateTimeImmutable
    {
        return $this->lastYearStart;
    }

    public function getLastYearEnd(): DateTimeImmutable
    {
        return $this->lastYearEnd;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * The trend chart's points, oldest first. Each has a key matching
     * getBucketKeySql(), its own span and the span it is compared with.
     *
     * Hours, days and months compare with the same dates last year. Weeks are
     * ISO calendar weeks (Mon-Sun) compared with the same week number last
     * year, so weekdays line up. The first week (month) starts on the Monday
     * (1st) on or before the range start, so it can include a few days before
     * it - the KPI tiles still count from the exact start. A week 53 without
     * counterpart has no last-year span (null).
     *
     * @return array<int, array{key: string, week: int|null, start: DateTimeImmutable, end: DateTimeImmutable,
     *     lastYearKey: string|null, lastYearStart: DateTimeImmutable|null, lastYearEnd: DateTimeImmutable|null}>
     */
    public function getBuckets(): array
    {
        if ($this->buckets !== null) {
            return $this->buckets;
        }

        $this->buckets = [];

        if ($this->bucket === self::BUCKET_WEEK) {
            $weekday = (int) $this->start->format('N');
            $monday = $this->start->sub(new DateInterval('P' . ($weekday - 1) . 'D'));

            for ($start = $monday; $start <= $this->end; $start = $start->add(new DateInterval('P7D'))) {
                $isoYear = (int) $start->format('o');
                $week = (int) $start->format('W');
                $lastYearStart = $start->setISODate($isoYear - 1, $week, 1);
                $hasLastYear = (int) $lastYearStart->format('W') === $week;

                $this->buckets[] = [
                    'key' => $start->format('oW'),
                    'week' => $week,
                    'start' => $start,
                    'end' => $this->getBucketEnd($start),
                    'lastYearKey' => $hasLastYear ? $lastYearStart->format('oW') : null,
                    'lastYearStart' => $hasLastYear ? $lastYearStart : null,
                    'lastYearEnd' => $hasLastYear ? $this->getBucketEnd($lastYearStart) : null,
                ];
            }

            return $this->buckets;
        }

        $format = $this->getBucketKeyFormat();

        switch ($this->bucket) {
            case self::BUCKET_HOUR:
                // always the whole day, the hours still to come stay empty
                $step = new DateInterval('PT1H');
                $start = $this->start;
                $last = $this->start->setTime(23, 0, 0);
                break;
            case self::BUCKET_MONTH:
                $step = new DateInterval('P1M');
                $start = $this->start->modify('first day of this month');
                $last = $this->end;
                break;
            default:
                $step = new DateInterval('P1D');
                $start = $this->start;
                $last = $this->end;
        }

        for (; $start <= $last; $start = $start->add($step)) {
            $lastYearStart = $start->sub(new DateInterval('P1Y'));
            // 29 February would land on 1 March last year, which the next
            // day already compares with - so, like week 53, it has none.
            $hasLastYear = $this->bucket !== self::BUCKET_DAY || $start->format('m-d') !== '02-29';

            $this->buckets[] = [
                'key' => $start->format($format),
                'week' => null,
                'start' => $start,
                'end' => $this->getBucketEnd($start),
                'lastYearKey' => $hasLastYear ? $lastYearStart->format($format) : null,
                'lastYearStart' => $hasLastYear ? $lastYearStart : null,
                'lastYearEnd' => $hasLastYear ? $this->getBucketEnd($lastYearStart) : null,
            ];
        }

        return $this->buckets;
    }

    /**
     * SQL expression producing the same bucket key as getBuckets() for a
     * date column (YEARWEEK mode 3 = ISO weeks, e.g. 202637).
     */
    public function getBucketKeySql(string $column): string
    {
        switch ($this->bucket) {
            case self::BUCKET_HOUR:
                return "DATE_FORMAT($column, '%Y%m%d%H')";
            case self::BUCKET_WEEK:
                return "YEARWEEK($column, 3)";
            case self::BUCKET_MONTH:
                return "DATE_FORMAT($column, '%Y%m')";
            default:
                return "DATE_FORMAT($column, '%Y%m%d')";
        }
    }

    protected function getBucketKeyFormat(): string
    {
        switch ($this->bucket) {
            case self::BUCKET_HOUR:
                return 'YmdH';
            case self::BUCKET_WEEK:
                return 'oW';
            case self::BUCKET_MONTH:
                return 'Ym';
            default:
                return 'Ymd';
        }
    }

    protected function getBucketEnd(DateTimeImmutable $start): DateTimeImmutable
    {
        switch ($this->bucket) {
            case self::BUCKET_HOUR:
                $length = 'PT1H';
                break;
            case self::BUCKET_WEEK:
                $length = 'P7D';
                break;
            case self::BUCKET_MONTH:
                $length = 'P1M';
                break;
            default:
                $length = 'P1D';
        }

        return $start->add(new DateInterval($length))->sub(new DateInterval('PT1S'));
    }
}
