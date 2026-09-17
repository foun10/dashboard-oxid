<?php
declare(strict_types=1);

namespace foun10\Dashboard\Core;

use DateTimeImmutable;
use Exception;

/**
 * Number, money, change and date formatting for the dashboard, using the
 * separators and sign of the shop's default currency.
 */
class Formatter
{
    /** @var string */
    protected $decimalSeparator;

    /** @var string */
    protected $thousandsSeparator;

    /** @var string */
    protected $currencySign;

    public function __construct(string $decimalSeparator, string $thousandsSeparator, string $currencySign)
    {
        $this->decimalSeparator = $decimalSeparator;
        $this->thousandsSeparator = $thousandsSeparator;
        $this->currencySign = $currencySign;
    }

    /**
     * An amount with the currency sign, e.g. "1.234,50 €".
     */
    public function money($value, int $decimals = 2): string
    {
        return $this->number($value, $decimals) . ' ' . $this->currencySign;
    }

    /**
     * Average order value (revenue / orders) as money, "–" without orders.
     */
    public function average($revenue, $orders): string
    {
        return (int) $orders > 0 ? $this->money((float) $revenue / (int) $orders, 0) : '–';
    }

    public function number($value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, $this->decimalSeparator, $this->thousandsSeparator);
    }

    public function percent($value, int $decimals = 1): string
    {
        return $this->number((float) $value * 100, $decimals) . ' %';
    }

    /**
     * Relative change against a comparison value, or null when there is
     * nothing to compare with (a change from 0 has no meaningful percentage).
     */
    public function change($current, $compare): ?float
    {
        if ((float) $compare == 0.0) {
            return null;
        }

        return ((float) $current - (float) $compare) / abs((float) $compare);
    }

    /**
     * A change as signed percentage, "+12,5 %", "−3 %" or "±0,0 %"; whole
     * percent from 100 % on, where the decimal adds nothing.
     */
    public function formatChange(?float $change): string
    {
        if ($change === null) {
            return '–';
        }

        $sign = $change > 0 ? '+' : ($change < 0 ? '−' : '±');

        return $sign . $this->percent(abs($change), abs($change) >= 1 ? 0 : 1);
    }

    /**
     * Direction of a change for colouring: "up", "down" or "flat" (below
     * half a percent either way, or nothing to compare with).
     */
    public function changeDirection(?float $change): string
    {
        if ($change === null || abs($change) < 0.005) {
            return 'flat';
        }

        return $change > 0 ? 'up' : 'down';
    }

    /**
     * A compared range for tooltips: "17.07.–15.08.2026",
     * "29.12.2025–04.01.2026" across a year change, or
     * "15.09.2026, 00:00–13:05" for a single day.
     *
     * @param mixed $range [from, to] as 'Y-m-d H:i:s'
     */
    public function range($range): string
    {
        if (!is_array($range) || count($range) !== 2) {
            return '';
        }

        $from = $this->parseDateTime(reset($range));
        $to = $this->parseDateTime(end($range));

        if ($from === null || $to === null) {
            return '';
        }

        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $from->format('d.m.Y, H:i') . '–' . $to->format('H:i');
        }

        $fromFormat = $from->format('Y') === $to->format('Y') ? 'd.m.' : 'd.m.Y';

        return $from->format($fromFormat) . '–' . $to->format('d.m.Y');
    }

    /**
     * A database timestamp in the given date() format, '' when unreadable.
     */
    public function dateTime($value, string $format = 'd.m.Y H:i'): string
    {
        $date = $this->parseDateTime($value);

        return $date !== null ? $date->format($format) : '';
    }

    /**
     * The time alone for a timestamp from today ("13:05"), otherwise with
     * the date in front ("16.09. 22:07") - a bare time would read as today.
     */
    public function timeOrDate($value, DateTimeImmutable $now): string
    {
        $date = $this->parseDateTime($value);

        if ($date === null) {
            return '';
        }

        return $date->format($date->format('Y-m-d') === $now->format('Y-m-d') ? 'H:i' : 'd.m. H:i');
    }

    /**
     * Bar length in percent of the largest entry, as a CSS-safe number
     * (dot decimal). Non-zero values keep a sliver so they stay visible.
     */
    public function barWidth($value, $max): string
    {
        if ((float) $max <= 0) {
            return '0';
        }

        $width = min((float) $value / (float) $max * 100, 100.0);

        return number_format($width > 0 ? max($width, 0.5) : 0, 2, '.', '');
    }

    /**
     * HTML-escapes raw database text. OXID stores most input already
     * entity-encoded, so existing entities are kept instead of double-encoded
     * (Smarty 2's escape modifier cannot do that).
     */
    public function escape($value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES, 'UTF-8', false);
    }

    protected function parseDateTime($value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            return null;
        }
    }
}
