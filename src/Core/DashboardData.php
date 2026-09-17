<?php
declare(strict_types=1);

namespace foun10\Dashboard\Core;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\TableViewNameGenerator;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;

/**
 * Collects all dashboard figures for one period.
 *
 * Revenue is the net goods value in the shop's default currency (see
 * REVENUE_SQL). Only orders that are not cancelled and whose transaction
 * finished (OXTRANSSTATUS = OK) count - OXID writes NOT_FINISHED while the
 * order is being placed and only sets OK once it went through, so orders
 * abandoned at a payment provider or failed payments stay out.
 *
 * Results are cached in the OXID file cache because the admin start page is
 * opened often and the order tables can be large.
 */
class DashboardData
{
    public const MODULE_ID = 'foun10Dashboard';
    public const SETTING_CACHE_TTL = 'foun10DashboardCacheTTL';
    public const DEFAULT_CACHE_TTL = 600;

    protected const CACHE_PREFIX = 'foun10_dashboard_v1_';
    protected const DATE_FORMAT = 'Y-m-d H:i:s';
    protected const TOP_SELLER_LIMIT = 10;
    protected const BREAKDOWN_LIMIT = 7;
    protected const OPEN_BASKET_LIMIT = 10;
    protected const LOW_STOCK_THRESHOLD = 5;

    /**
     * Net goods value after discounts, without shipping/payment/wrapping costs,
     * converted to the default currency (rate 1) with the rate stored on the
     * order.
     *
     * OXTOTALNETSUM cannot be used directly: depending on OXISNETTOMODE it is
     * taken before or after vouchers. Instead: gross total minus the (gross)
     * cost columns gives the discounted goods gross, minus the stored article
     * VAT gives net - which is also right for VAT-free export orders. Orders
     * with an implausible stored VAT amount (negative, or above the goods
     * value) fall back to the first VAT rate.
     */
    protected const GOODS_GROSS_SQL = '(o.OXTOTALORDERSUM - o.OXDELCOST - o.OXPAYCOST - o.OXWRAPCOST - o.OXGIFTCARDCOST)';
    protected const GOODS_VAT_SQL = '(o.OXARTVATPRICE1 + o.OXARTVATPRICE2)';
    protected const REVENUE_SQL = 'IF(' . self::GOODS_VAT_SQL . ' BETWEEN 0 AND ' . self::GOODS_GROSS_SQL . ', '
        . self::GOODS_GROSS_SQL . ' - ' . self::GOODS_VAT_SQL . ', '
        . self::GOODS_GROSS_SQL . ' / (1 + o.OXARTVAT1 / 100))'
        . ' / IF(o.OXCURRATE > 0, o.OXCURRATE, 1)';
    protected const VALID_ORDER_SQL = "o.OXSHOPID = ? AND o.OXSTORNO = 0 AND o.OXTRANSSTATUS = 'OK' AND o.OXORDERDATE BETWEEN ? AND ?";

    /**
     * Revenue and orders per month for every year with orders - the yearly
     * comparison at the bottom. Independent of the selected period, so it
     * has its own cache entry; one grouped pass over all valid orders.
     *
     * @return array{currentYear: int, currentMonth: int, years: array<int, array>}
     */
    public function getYearlyComparison(DateTimeImmutable $now, bool $forceRefresh = false): array
    {
        $cacheKey = self::CACHE_PREFIX . 'yearly_' . $now->format('Ymd') . '_' . $this->getShopId();

        if (!$forceRefresh) {
            $cached = $this->readCache($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $rows = $this->fetchAll(
            "SELECT YEAR(o.OXORDERDATE) AS year, MONTH(o.OXORDERDATE) AS month,
                COUNT(*) AS orders, SUM(" . self::REVENUE_SQL . ") AS revenue
            FROM oxorder o
            WHERE " . self::VALID_ORDER_SQL . '
            GROUP BY year, month',
            [$this->getShopId(), Period::CUSTOM_MIN_DATE . ' 00:00:00', $now->format(self::DATE_FORMAT)]
        );

        $data = $this->buildYearlyComparison($rows, $now);
        $this->writeCache($cacheKey, $data);

        return $data;
    }

    public function get(Period $period, bool $forceRefresh = false): array
    {
        $cacheKey = self::CACHE_PREFIX . $period->getCacheId() . '_' . $this->getShopId() . '_' . $this->getLanguageId();

        if (!$forceRefresh) {
            $cached = $this->readCache($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = $this->collect($period);
        $this->writeCache($cacheKey, $data);

        return $data;
    }

    /**
     * @param array<int, array{year: mixed, month: mixed, orders: mixed, revenue: mixed}> $rows
     */
    protected function buildYearlyComparison(array $rows, DateTimeImmutable $now): array
    {
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('n');
        $years = [];

        foreach ($rows as $row) {
            $year = (int) $row['year'];
            if (!isset($years[$year])) {
                // months still to come in the current year stay null (no bar)
                $months = [];
                for ($month = 1; $month <= 12; $month++) {
                    $months[$month] = $year === $currentYear && $month > $currentMonth
                        ? null
                        : ['orders' => 0, 'revenue' => 0.0];
                }
                $years[$year] = ['year' => $year, 'months' => $months, 'orders' => 0, 'revenue' => 0.0];
            }

            $month = (int) $row['month'];
            $years[$year]['months'][$month] = [
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
            ];
            $years[$year]['orders'] += (int) $row['orders'];
            $years[$year]['revenue'] += (float) $row['revenue'];
        }

        krsort($years);

        return [
            'currentYear' => $currentYear,
            'currentMonth' => $currentMonth,
            'years' => array_values(array_map(static function (array $year): array {
                $year['months'] = array_values($year['months']);

                return $year;
            }, $years)),
        ];
    }

    protected function collect(Period $period): array
    {
        $current = $this->getOrderTotals($period->getStart(), $period->getEnd());

        return [
            'generatedAt' => $this->now()->format(self::DATE_FORMAT),
            'period' => $period->getKey(),
            // Shown in the delta tooltips so "vs. previous period" is concrete.
            'ranges' => [
                'current' => [
                    $period->getStart()->format(self::DATE_FORMAT),
                    $period->getEnd()->format(self::DATE_FORMAT),
                ],
                'previous' => $period->hasPrevious() ? [
                    $period->getPreviousStart()->format(self::DATE_FORMAT),
                    $period->getPreviousEnd()->format(self::DATE_FORMAT),
                ] : null,
                'lastYear' => [
                    $period->getLastYearStart()->format(self::DATE_FORMAT),
                    $period->getLastYearEnd()->format(self::DATE_FORMAT),
                ],
            ],
            'current' => $current,
            'previous' => $period->hasPrevious()
                ? $this->getOrderTotals($period->getPreviousStart(), $period->getPreviousEnd())
                : null,
            'lastYear' => $this->getOrderTotals($period->getLastYearStart(), $period->getLastYearEnd()),
            'trend' => $this->getTrend($period),
            'customerTypes' => $this->getCustomerTypes($period, $current['revenue']),
            'payments' => $this->getPayments($period, $current['revenue']),
            'countries' => $this->getCountries($period, $current['revenue']),
            'topSellers' => $this->getTopSellers($period),
            'openBaskets' => $this->getOpenBaskets(),
        ];
    }

    protected function getOrderTotals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $range = [$this->getShopId(), $from->format(self::DATE_FORMAT), $to->format(self::DATE_FORMAT)];

        $orders = $this->fetchRow(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(' . self::REVENUE_SQL . '), 0) AS revenue
            FROM oxorder o
            WHERE ' . self::VALID_ORDER_SQL,
            $range
        );

        // Guests are stored without password - only real customer accounts count.
        $newCustomers = (int) $this->fetchOne(
            "SELECT COUNT(*) FROM oxuser
            WHERE OXSHOPID = ? AND OXPASSWORD <> '' AND OXREGISTER BETWEEN ? AND ?",
            $range
        );

        $count = (int) ($orders['orders'] ?? 0);
        $revenue = (float) ($orders['revenue'] ?? 0);

        return [
            'orders' => $count,
            'revenue' => $revenue,
            'average' => $count > 0 ? $revenue / $count : 0.0,
            'newCustomers' => $newCustomers,
        ];
    }

    protected function getTrend(Period $period): array
    {
        $buckets = $period->getBuckets();
        $first = reset($buckets);
        $end = $period->getEnd();

        $current = $this->getBucketRevenue($period, $first['start'], $end);

        $lastYearStarts = array_filter(array_column($buckets, 'lastYearStart'));
        $lastYearEnds = array_filter(array_column($buckets, 'lastYearEnd'));
        $lastYear = $lastYearStarts
            ? $this->getBucketRevenue($period, min($lastYearStarts), max($lastYearEnds))
            : [];

        $points = [];
        foreach ($buckets as $bucket) {
            $lastYearKey = $bucket['lastYearKey'];
            // Buckets still in the future stay empty instead of dropping to zero.
            $started = $bucket['start'] <= $end;

            $points[] = [
                'week' => $bucket['week'],
                'start' => $bucket['start']->format('c'),
                'end' => $bucket['end']->format('c'),
                'lastYearStart' => $lastYearKey !== null ? $bucket['lastYearStart']->format('c') : null,
                'lastYearEnd' => $lastYearKey !== null ? $bucket['lastYearEnd']->format('c') : null,
                'revenue' => $started ? ($current[$bucket['key']]['revenue'] ?? 0.0) : null,
                'orders' => $started ? ($current[$bucket['key']]['orders'] ?? 0) : null,
                'lastYearRevenue' => $lastYearKey !== null ? ($lastYear[$lastYearKey]['revenue'] ?? 0.0) : null,
                'lastYearOrders' => $lastYearKey !== null ? ($lastYear[$lastYearKey]['orders'] ?? 0) : null,
                // the bucket "now" falls into - only partly over, so not compared
                'running' => $started && $bucket['end'] > $end,
            ];
        }

        return [
            'bucket' => $period->getBucket(),
            'points' => $points,
        ];
    }

    /**
     * @return array<string, array{orders: int, revenue: float}> keyed like Period::getBuckets()
     */
    protected function getBucketRevenue(Period $period, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->fetchAll(
            'SELECT ' . $period->getBucketKeySql('o.OXORDERDATE') . ' AS bucket,
                COUNT(*) AS orders,
                SUM(' . self::REVENUE_SQL . ') AS revenue
            FROM oxorder o
            WHERE ' . self::VALID_ORDER_SQL . '
            GROUP BY bucket',
            [
                $this->getShopId(),
                $from->format(self::DATE_FORMAT),
                $to->format(self::DATE_FORMAT),
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['bucket']] = [
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
            ];
        }

        return $result;
    }

    /**
     * Revenue by customer type, identified by billing e-mail so guests who
     * come back count as returning too:
     * - returning: the same e-mail has an earlier valid order
     * - new: first order, placed with a customer account
     * - guest: first order, placed as guest (user without password)
     *
     * The first order per e-mail comes from one grouped pass over all valid
     * orders instead of a lookup per order - oxorder has no index on
     * OXBILLEMAIL, and the result is cached.
     */
    protected function getCustomerTypes(Period $period, float $totalRevenue): array
    {
        $rows = $this->fetchAll(
            "SELECT CASE
                    WHEN f.first_order < o.OXORDERDATE THEN 'returning'
                    WHEN IFNULL(u.OXPASSWORD, '') = '' THEN 'guest'
                    ELSE 'new'
                END AS id,
                COUNT(*) AS orders,
                SUM(" . self::REVENUE_SQL . ") AS revenue
            FROM oxorder o
            JOIN (
                SELECT OXBILLEMAIL AS email, MIN(OXORDERDATE) AS first_order
                FROM oxorder
                WHERE OXSHOPID = ? AND OXSTORNO = 0 AND OXTRANSSTATUS = 'OK'
                GROUP BY OXBILLEMAIL
            ) f ON f.email = o.OXBILLEMAIL
            LEFT JOIN oxuser u ON u.OXID = o.OXUSERID
            WHERE " . self::VALID_ORDER_SQL . '
            GROUP BY id
            ORDER BY revenue DESC',
            array_merge([$this->getShopId()], $this->getRangeParams($period))
        );

        return $this->toBreakdown($rows, $totalRevenue);
    }

    protected function getPayments(Period $period, float $totalRevenue): array
    {
        $paymentView = $this->getViewName('oxpayments');

        $rows = $this->fetchAll(
            'SELECT o.OXPAYMENTTYPE AS id, COALESCE(MAX(p.OXDESC), o.OXPAYMENTTYPE) AS label,
                COUNT(*) AS orders, SUM(' . self::REVENUE_SQL . ") AS revenue
            FROM oxorder o
            LEFT JOIN $paymentView p ON p.OXID = o.OXPAYMENTTYPE
            WHERE " . self::VALID_ORDER_SQL . '
            GROUP BY o.OXPAYMENTTYPE
            ORDER BY revenue DESC',
            $this->getRangeParams($period)
        );

        return $this->toBreakdown($rows, $totalRevenue);
    }

    /**
     * Revenue per delivery country. OXDELCOUNTRYID is only filled when the
     * customer entered a separate delivery address, otherwise the goods go
     * to the billing address - so the billing country is the fallback.
     */
    protected function getCountries(Period $period, float $totalRevenue): array
    {
        $countryView = $this->getViewName('oxcountry');
        $countrySql = "COALESCE(NULLIF(o.OXDELCOUNTRYID, ''), o.OXBILLCOUNTRYID)";

        $rows = $this->fetchAll(
            "SELECT $countrySql AS id, COALESCE(MAX(c.OXTITLE), $countrySql) AS label,
                COUNT(*) AS orders, SUM(" . self::REVENUE_SQL . ") AS revenue
            FROM oxorder o
            LEFT JOIN $countryView c ON c.OXID = $countrySql
            WHERE " . self::VALID_ORDER_SQL . '
            GROUP BY id
            ORDER BY revenue DESC',
            $this->getRangeParams($period)
        );

        return $this->toBreakdown($rows, $totalRevenue);
    }

    /**
     * Splits a revenue-sorted list into the largest entries ("visible") and
     * the tail ("more"), which the template shows collapsed behind a single
     * "other" summary row so the bar lists stay short. A tail of one entry is
     * shown directly - collapsing a single row saves nothing.
     *
     * @return array{visible: array, more: array, other: array|null, max: float}
     */
    protected function toBreakdown(array $rows, float $totalRevenue): array
    {
        $items = [];
        foreach ($rows as $row) {
            $revenue = (float) $row['revenue'];
            $items[] = [
                'id' => (string) $row['id'],
                'label' => (string) ($row['label'] ?? ''),
                'orders' => (int) $row['orders'],
                'revenue' => $revenue,
                'share' => $totalRevenue > 0 ? $revenue / $totalRevenue : 0.0,
            ];
        }

        $visibleCount = count($items) > self::BREAKDOWN_LIMIT ? self::BREAKDOWN_LIMIT - 1 : count($items);
        $more = array_slice($items, $visibleCount);
        $other = null;

        if ($more) {
            $revenue = array_sum(array_column($more, 'revenue'));
            $other = [
                'count' => count($more),
                'orders' => array_sum(array_column($more, 'orders')),
                'revenue' => $revenue,
                'share' => $totalRevenue > 0 ? $revenue / $totalRevenue : 0.0,
            ];
        }

        return [
            'visible' => array_slice($items, 0, $visibleCount),
            'more' => $more,
            'other' => $other,
            // Bars scale to the largest single entry, the other row included.
            'max' => (float) max(array_merge([0.0], array_column($items, 'revenue'), $other ? [$other['revenue']] : [])),
        ];
    }

    /**
     * Best sellers by quantity, grouped by parent product so all variants of
     * one product count together. Stock is the parent's variant stock sum;
     * the number of sold-out variants is reported separately because a
     * healthy sum can hide that the popular variants are gone.
     *
     * Paged for the "show more" button: one extra row is fetched to know
     * whether another page exists. Not cached - only the first page is part
     * of the cached dashboard data.
     *
     * @return array{items: array, offset: int, hasMore: bool}
     */
    public function getTopSellers(Period $period, int $offset = 0): array
    {
        $offset = max(0, $offset);

        $rows = $this->fetchAll(
            "SELECT COALESCE(NULLIF(a.OXPARENTID, ''), oa.OXARTID) AS productid,
                SUM(oa.OXAMOUNT) AS qty,
                SUM(oa.OXNETPRICE / IF(o.OXCURRATE > 0, o.OXCURRATE, 1)) AS revenue,
                COUNT(DISTINCT o.OXID) AS orders,
                MAX(oa.OXTITLE) AS ordertitle,
                MAX(oa.OXARTNUM) AS orderartnum
            FROM oxorder o
            JOIN oxorderarticles oa ON oa.OXORDERID = o.OXID
            LEFT JOIN oxarticles a ON a.OXID = oa.OXARTID
            WHERE " . self::VALID_ORDER_SQL . '
                AND oa.OXSTORNO = 0
            GROUP BY productid
            ORDER BY qty DESC, revenue DESC, productid
            LIMIT ' . $offset . ', ' . (self::TOP_SELLER_LIMIT + 1),
            $this->getRangeParams($period)
        );

        $hasMore = count($rows) > self::TOP_SELLER_LIMIT;
        $rows = array_slice($rows, 0, self::TOP_SELLER_LIMIT);

        if (!$rows) {
            return ['items' => [], 'offset' => $offset, 'hasMore' => false];
        }

        $ids = array_map('strval', array_column($rows, 'productid'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $articleView = $this->getViewName('oxarticles');

        $articles = [];
        foreach ($this->fetchAll(
            "SELECT OXID, OXARTNUM, OXTITLE, OXSTOCK, OXVARSTOCK, OXVARCOUNT, OXACTIVE
            FROM $articleView WHERE OXID IN ($placeholders)",
            $ids
        ) as $article) {
            $articles[(string) $article['OXID']] = $article;
        }

        $variantStock = [];
        foreach ($this->fetchAll(
            "SELECT OXPARENTID, COUNT(*) AS variants, SUM(OXSTOCK <= 0) AS soldout
            FROM oxarticles WHERE OXPARENTID IN ($placeholders) AND OXACTIVE = 1
            GROUP BY OXPARENTID",
            $ids
        ) as $variant) {
            $variantStock[(string) $variant['OXPARENTID']] = $variant;
        }

        $result = [];
        foreach ($rows as $row) {
            $id = (string) $row['productid'];
            $article = $articles[$id] ?? null;
            $variants = $variantStock[$id] ?? null;
            $stock = null;

            if ($article !== null) {
                $stock = (int) $article['OXVARCOUNT'] > 0 ? (float) $article['OXVARSTOCK'] : (float) $article['OXSTOCK'];
            }

            $soldOut = $variants ? (int) $variants['soldout'] : 0;
            $active = $article !== null && (int) $article['OXACTIVE'] === 1;
            $title = (string) ($article['OXTITLE'] ?? '');

            $result[] = [
                'id' => $id,
                'exists' => $article !== null,
                'active' => $active,
                'shopUrl' => $active ? $this->getShopUrl($id) : '',
                'artnum' => (string) ($article['OXARTNUM'] ?? $row['orderartnum']),
                'title' => $title !== '' ? $title : (string) $row['ordertitle'],
                'qty' => (float) $row['qty'],
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
                'stock' => $stock,
                'variants' => $variants ? (int) $variants['variants'] : 0,
                'soldOutVariants' => $soldOut,
                'stockStatus' => $this->getStockStatus($stock, $soldOut),
            ];
        }

        return ['items' => $result, 'offset' => $offset, 'hasMore' => $hasMore];
    }

    /**
     * "critical" when nothing is left, "warning" when stock is low or single
     * variants are sold out, otherwise "good". Unknown stock (deleted
     * product) only warns about its variants.
     */
    protected function getStockStatus(?float $stock, int $soldOutVariants): string
    {
        if ($stock !== null && $stock <= 0) {
            return 'critical';
        }

        if ($soldOutVariants > 0 || ($stock !== null && $stock <= self::LOW_STOCK_THRESHOLD)) {
            return 'warning';
        }

        return 'good';
    }

    /**
     * Saved baskets of logged-in customers touched in the last 24 hours.
     * Guest baskets live in the session only and are not visible here. OXID
     * deletes a saved basket once it was ordered, so every basket left is
     * still open. Without basket saving (blPerfNoBasketSaving) there is
     * nothing to show.
     *
     * Baskets carry no shop id, their customers do. With customer accounts
     * shared between shops (blMallUsers) OXID uses the same saved basket in
     * every shop, so the list is then not narrowed down to one.
     */
    protected function getOpenBaskets(): array
    {
        if ($this->getConfigBool('blPerfNoBasketSaving')) {
            return ['available' => false];
        }

        $sharedUsers = $this->getConfigBool('blMallUsers');

        // OXTIMESTAMP is written by MySQL, so the window is computed there too.
        $rows = $this->fetchAll(
            "SELECT b.OXID AS id, b.OXUSERID AS userid, MAX(b.OXTIMESTAMP) AS changed,
                MAX(u.OXFNAME) AS fname, MAX(u.OXLNAME) AS lname, MAX(u.OXUSERNAME) AS email,
                COUNT(i.OXID) AS positions,
                COALESCE(SUM(i.OXAMOUNT), 0) AS qty,
                COALESCE(SUM(i.OXAMOUNT * IF(a.OXPRICE > 0, a.OXPRICE, IFNULL(p.OXPRICE, 0))), 0) AS value
            FROM oxuserbaskets b
            JOIN oxuserbasketitems i ON i.OXBASKETID = b.OXID
            LEFT JOIN oxarticles a ON a.OXID = i.OXARTID
            LEFT JOIN oxarticles p ON p.OXID = a.OXPARENTID
            LEFT JOIN oxuser u ON u.OXID = b.OXUSERID
            WHERE b.OXTITLE = 'savedbasket'
                AND b.OXTIMESTAMP >= NOW() - INTERVAL 24 HOUR"
                . ($sharedUsers ? '' : ' AND u.OXSHOPID = ?') . '
            GROUP BY b.OXID, b.OXUSERID',
            $sharedUsers ? [] : [$this->getShopId()]
        );

        return $this->buildOpenBaskets($rows);
    }

    protected function buildOpenBaskets(array $rows): array
    {
        $open = [];
        $openValue = 0.0;
        $openQty = 0.0;

        foreach ($rows as $row) {
            $openValue += (float) $row['value'];
            $openQty += (float) $row['qty'];
            $open[] = [
                'id' => (string) $row['id'],
                'userId' => (string) $row['userid'],
                'name' => trim($row['fname'] . ' ' . $row['lname']),
                'email' => (string) $row['email'],
                'changed' => (string) $row['changed'],
                'positions' => (int) $row['positions'],
                'qty' => (float) $row['qty'],
                'value' => (float) $row['value'],
            ];
        }

        usort($open, static function (array $a, array $b): int {
            return $b['value'] <=> $a['value'];
        });

        return [
            'available' => true,
            'netPrices' => $this->getConfigBool('blEnterNetPrice'),
            'open' => count($open),
            'openValue' => $openValue,
            'openQty' => $openQty,
            'list' => array_slice($open, 0, self::OPEN_BASKET_LIMIT),
        ];
    }

    protected function getRangeParams(Period $period): array
    {
        return [
            $this->getShopId(),
            $period->getStart()->format(self::DATE_FORMAT),
            $period->getEnd()->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Storefront URL of a product in the shop's default language (not the
     * admin's). getMainLink() is the SEO URL in the main category and,
     * unlike getStdLink(), carries no admin session parameters.
     */
    protected function getShopUrl(string $articleId): string
    {
        $article = oxNew(Article::class);
        if (!$article->load($articleId)) {
            return '';
        }

        return (string) $article->getMainLink((int) Registry::getConfig()->getConfigParam('sDefaultLang'));
    }

    protected function getCacheTtl(): int
    {
        $ttl = (int) $this->getModuleSettingString(self::SETTING_CACHE_TTL);

        return $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
    }

    /**
     * @return mixed
     */
    protected function readCache(string $key)
    {
        return Registry::getUtils()->fromFileCache($key);
    }

    protected function writeCache(string $key, array $data): void
    {
        Registry::getUtils()->toFileCache($key, $data, $this->getCacheTtl());
    }

    protected function getConfigBool(string $name): bool
    {
        return (bool) Registry::getConfig()->getConfigParam($name);
    }

    /**
     * A module setting. OXID 7 keeps them in var/configuration, where Config::getConfigParam()
     * returns null without any error - they have to come from the module setting service. The
     * 6.x line reads the same seam from oxconfig.
     */
    protected function getModuleSettingString(string $name): string
    {
        return (string) ContainerFactory::getInstance()
            ->getContainer()
            ->get(ModuleSettingServiceInterface::class)
            ->getString($name, self::MODULE_ID);
    }

    protected function getViewName(string $table): string
    {
        return (string) Registry::get(TableViewNameGenerator::class)->getViewName($table, $this->getLanguageId());
    }

    protected function getLanguageId(): int
    {
        return (int) Registry::getLang()->getBaseLanguage();
    }

    protected function getShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        return $this->getDb()->getAll($sql, $params);
    }

    protected function fetchRow(string $sql, array $params = []): array
    {
        $row = $this->getDb()->getRow($sql, $params);

        return is_array($row) ? $row : [];
    }

    /**
     * @return mixed
     */
    protected function fetchOne(string $sql, array $params = [])
    {
        return $this->getDb()->getOne($sql, $params);
    }

    protected function getDb()
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
    }
}
