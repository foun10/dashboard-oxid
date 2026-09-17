<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Unit\Core;

use DateTimeImmutable;
use foun10\Dashboard\Core\DashboardData;
use foun10\Dashboard\Core\Period;
use foun10\Dashboard\Tests\Unit\Double\TestableDashboardData;
use PHPUnit\Framework\TestCase;

final class DashboardDataTest extends TestCase
{
    private const NOW = '2026-09-17 13:05:00';

    private const TOTALS = 'SELECT COUNT(*) AS orders, COALESCE(SUM(';
    private const NEW_CUSTOMERS = 'OXREGISTER BETWEEN';
    private const BUCKETS = ' AS bucket,';
    private const CUSTOMER_TYPES = "'returning'";
    private const PAYMENTS = 'o.OXPAYMENTTYPE AS id';
    private const COUNTRIES = 'OXDELCOUNTRYID';
    private const TOP_SELLERS = 'AS productid';
    private const ARTICLES = 'OXVARSTOCK, OXVARCOUNT';
    private const VARIANTS = 'AS soldout';
    private const BASKETS = 'savedbasket';
    private const YEARLY = 'YEAR(o.OXORDERDATE)';

    /** @var string */
    private $timezone;

    /** @var TestableDashboardData */
    private $data;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->data = new TestableDashboardData();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    // ---- caching ----

    public function testGetCollectsAndCachesPerPeriodShopAndLanguage(): void
    {
        $this->data->shopId = 3;
        $this->data->languageId = 1;

        $result = $this->data->get($this->period(Period::DAYS_30));

        self::assertSame(['foun10_dashboard_v1_30d_3_1'], array_keys($this->data->cache));
        self::assertSame($result, $this->data->cache['foun10_dashboard_v1_30d_3_1']);
        self::assertNotEmpty($this->data->queries);
    }

    public function testGetServesACachedResultWithoutQuerying(): void
    {
        $this->data->cache['foun10_dashboard_v1_30d_1_0'] = ['cached' => true, 'current' => ['orders' => 3]];

        self::assertSame(
            ['cached' => true, 'current' => ['orders' => 3]],
            $this->data->get($this->period(Period::DAYS_30))
        );
        self::assertSame([], $this->data->queries);
    }

    public function testRefreshBypassesTheCacheAndReplacesTheEntry(): void
    {
        $this->data->cache['foun10_dashboard_v1_30d_1_0'] = ['cached' => true];

        $result = $this->data->get($this->period(Period::DAYS_30), true);

        self::assertArrayHasKey('current', $result);
        self::assertSame($result, $this->data->cache['foun10_dashboard_v1_30d_1_0']);
    }

    public function testCustomRangesAreCachedSeparately(): void
    {
        $this->data->get(new Period(Period::CUSTOM, new DateTimeImmutable(self::NOW), '2026-03-01', '2026-03-31'));

        self::assertSame(['foun10_dashboard_v1_custom_2026-03-01_2026-03-31_1_0'], array_keys($this->data->cache));
    }

    /**
     * @dataProvider cacheTtlProvider
     */
    public function testCacheLifetimeFallsBackToTheDefault($setting, int $expected): void
    {
        $this->data->config[DashboardData::SETTING_CACHE_TTL] = $setting;

        self::assertSame($expected, $this->data->callGetCacheTtl());
    }

    public function cacheTtlProvider(): array
    {
        return [
            'configured' => ['120', 120],
            'one second' => ['1', 1],
            'zero' => ['0', DashboardData::DEFAULT_CACHE_TTL],
            'negative' => ['-5', DashboardData::DEFAULT_CACHE_TTL],
            'empty' => ['', DashboardData::DEFAULT_CACHE_TTL],
            'not set' => [null, DashboardData::DEFAULT_CACHE_TTL],
        ];
    }

    public function testCacheEntriesAreWrittenWithTheConfiguredLifetime(): void
    {
        $this->data->config[DashboardData::SETTING_CACHE_TTL] = '90';

        $this->data->get($this->period(Period::TODAY));

        self::assertSame([90], array_values($this->data->cacheTtls));
    }

    // ---- collect ----

    public function testCollectReturnsEverySection(): void
    {
        $result = $this->data->callCollect($this->period(Period::DAYS_7));

        self::assertSame(
            ['generatedAt', 'period', 'ranges', 'current', 'previous', 'lastYear', 'trend',
                'customerTypes', 'payments', 'countries', 'topSellers', 'openBaskets'],
            array_keys($result)
        );
        self::assertSame(self::NOW, $result['generatedAt']);
        self::assertSame(Period::DAYS_7, $result['period']);
    }

    public function testCollectReportsTheComparedRanges(): void
    {
        $result = $this->data->callCollect($this->period(Period::DAYS_7));

        self::assertSame(['2026-09-11 00:00:00', self::NOW], $result['ranges']['current']);
        self::assertSame(['2026-09-04 00:00:00', '2026-09-10 13:05:00'], $result['ranges']['previous']);
        self::assertSame(['2025-09-11 00:00:00', '2025-09-17 13:05:00'], $result['ranges']['lastYear']);
    }

    public function testCollectSkipsThePreviousPeriodForYearToDate(): void
    {
        $result = $this->data->callCollect($this->period(Period::YEAR_TO_DATE));

        self::assertNull($result['ranges']['previous']);
        self::assertNull($result['previous']);
        self::assertCount(2, $this->data->queriesContaining(self::TOTALS));
    }

    public function testCollectQueriesTotalsForAllThreeRanges(): void
    {
        $this->data->callCollect($this->period(Period::DAYS_7));

        self::assertSame(
            [
                [1, '2026-09-11 00:00:00', self::NOW],
                [1, '2026-09-04 00:00:00', '2026-09-10 13:05:00'],
                [1, '2025-09-11 00:00:00', '2025-09-17 13:05:00'],
            ],
            array_column($this->data->queriesContaining(self::TOTALS), 'params')
        );
    }

    public function testBreakdownSharesAreRelativeToTheCurrentRevenue(): void
    {
        $this->data
            ->on(self::TOTALS, ['orders' => '4', 'revenue' => '400.0'])
            ->on(self::PAYMENTS, [['id' => 'oxidpayadvance', 'label' => 'Prepayment', 'orders' => '1', 'revenue' => '100']]);

        $result = $this->data->callCollect($this->period(Period::DAYS_7));

        self::assertSame(0.25, $result['payments']['visible'][0]['share']);
    }

    // ---- order totals ----

    public function testOrderTotalsComputeTheAverageOrderValue(): void
    {
        $this->data
            ->on(self::TOTALS, ['orders' => '4', 'revenue' => '250.5'])
            ->on(self::NEW_CUSTOMERS, '2');

        self::assertSame(
            ['orders' => 4, 'revenue' => 250.5, 'average' => 62.625, 'newCustomers' => 2],
            $this->data->callGetOrderTotals(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable(self::NOW))
        );
    }

    public function testOrderTotalsWithoutOrdersHaveAZeroAverage(): void
    {
        $this->data->on(self::TOTALS, ['orders' => '0', 'revenue' => '0']);

        $totals = $this->data->callGetOrderTotals(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable(self::NOW));

        self::assertSame(0, $totals['orders']);
        self::assertSame(0.0, $totals['average']);
        self::assertSame(0, $totals['newCustomers']);
    }

    public function testOrderTotalsSurviveAnEmptyResult(): void
    {
        $totals = $this->data->callGetOrderTotals(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable(self::NOW));

        self::assertSame(['orders' => 0, 'revenue' => 0.0, 'average' => 0.0, 'newCustomers' => 0], $totals);
    }

    public function testOrderTotalsOnlyCountValidOrdersOfTheShop(): void
    {
        $this->data->shopId = 2;
        $this->data->callGetOrderTotals(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable(self::NOW));

        $totals = $this->data->queriesContaining(self::TOTALS)[0];
        self::assertStringContainsString("o.OXSHOPID = ? AND o.OXSTORNO = 0 AND o.OXTRANSSTATUS = 'OK'", $totals['sql']);
        self::assertSame([2, '2026-09-01 00:00:00', self::NOW], $totals['params']);

        $customers = $this->data->queriesContaining(self::NEW_CUSTOMERS)[0];
        self::assertStringContainsString("OXPASSWORD <> ''", $customers['sql']);
        self::assertSame([2, '2026-09-01 00:00:00', self::NOW], $customers['params']);
    }

    // ---- trend ----

    public function testTrendMapsBucketRevenueOntoEveryBucket(): void
    {
        $this->data->on(self::BUCKETS, [
            ['bucket' => '20260911', 'orders' => '2', 'revenue' => '100.5'],
            ['bucket' => 20260917, 'orders' => '1', 'revenue' => '10'],
            ['bucket' => '20250912', 'orders' => '3', 'revenue' => '30'],
        ]);

        $trend = $this->data->callGetTrend($this->period(Period::DAYS_7));

        self::assertSame(Period::BUCKET_DAY, $trend['bucket']);
        self::assertCount(7, $trend['points']);
        self::assertSame([100.5, 0.0, 0.0, 0.0, 0.0, 0.0, 10.0], array_column($trend['points'], 'revenue'));
        self::assertSame([2, 0, 0, 0, 0, 0, 1], array_column($trend['points'], 'orders'));
        self::assertSame([0.0, 30.0, 0.0, 0.0, 0.0, 0.0, 0.0], array_column($trend['points'], 'lastYearRevenue'));
        self::assertSame([0, 3, 0, 0, 0, 0, 0], array_column($trend['points'], 'lastYearOrders'));
    }

    public function testTrendMarksOnlyTheBucketNowFallsIntoAsRunning(): void
    {
        $points = $this->data->callGetTrend($this->period(Period::DAYS_7))['points'];

        self::assertSame([false, false, false, false, false, false, true], array_column($points, 'running'));
    }

    public function testTrendLeavesHoursStillToComeEmpty(): void
    {
        $points = $this->data->callGetTrend($this->period(Period::TODAY))['points'];

        self::assertSame(0.0, $points[13]['revenue']);
        self::assertTrue($points[13]['running']);
        self::assertNull($points[14]['revenue']);
        self::assertNull($points[14]['orders']);
        self::assertFalse($points[14]['running']);
        self::assertSame(0.0, $points[14]['lastYearRevenue'], 'last year is complete and still shown');
    }

    public function testTrendPointsCarryTheirSpansAsIsoDates(): void
    {
        $point = $this->data->callGetTrend($this->period(Period::DAYS_7))['points'][0];

        self::assertNull($point['week']);
        self::assertSame('2026-09-11T00:00:00+00:00', $point['start']);
        self::assertSame('2026-09-11T23:59:59+00:00', $point['end']);
        self::assertSame('2025-09-11T00:00:00+00:00', $point['lastYearStart']);
        self::assertSame('2025-09-11T23:59:59+00:00', $point['lastYearEnd']);
    }

    public function testTrendQueriesTheCurrentAndLastYearSpanOnce(): void
    {
        $this->data->callGetTrend($this->period(Period::YEAR_TO_DATE));

        self::assertSame(
            [
                [1, '2025-12-29 00:00:00', self::NOW],
                [1, '2024-12-30 00:00:00', '2025-09-21 23:59:59'],
            ],
            array_column($this->data->queriesContaining(self::BUCKETS), 'params')
        );
    }

    public function testTrendWithoutAnyLastYearBucketSkipsThatQuery(): void
    {
        $period = new Period(Period::YEAR_TO_DATE, new DateTimeImmutable('2021-01-02 10:00:00'));

        $points = $this->data->callGetTrend($period)['points'];

        self::assertCount(1, $points);
        self::assertSame(53, $points[0]['week']);
        self::assertNull($points[0]['lastYearRevenue']);
        self::assertNull($points[0]['lastYearOrders']);
        self::assertNull($points[0]['lastYearStart']);
        self::assertCount(1, $this->data->queriesContaining(self::BUCKETS));
    }

    // ---- breakdowns ----

    public function testShortBreakdownIsShownCompletely(): void
    {
        $breakdown = $this->data->callToBreakdown($this->rows(7), 280.0);

        self::assertCount(7, $breakdown['visible']);
        self::assertSame([], $breakdown['more']);
        self::assertNull($breakdown['other']);
        self::assertSame(70.0, $breakdown['max']);
    }

    public function testLongBreakdownCollapsesTheTailIntoOneOtherRow(): void
    {
        $breakdown = $this->data->callToBreakdown($this->rows(9), 450.0);

        self::assertCount(6, $breakdown['visible']);
        self::assertSame(['p7', 'p8', 'p9'], array_column($breakdown['more'], 'id'));
        self::assertSame(['count' => 3, 'orders' => 3, 'revenue' => 60.0, 'share' => 60.0 / 450.0], $breakdown['other']);
    }

    public function testBarsScaleToTheOtherRowWhenItIsTheLargest(): void
    {
        $rows = $this->rows(8);
        $rows[6]['revenue'] = '80';
        $rows[7]['revenue'] = '75';

        $breakdown = $this->data->callToBreakdown($rows, 1000.0);

        self::assertSame(155.0, $breakdown['max']);
    }

    public function testBreakdownItemsAreTypedAndShared(): void
    {
        $breakdown = $this->data->callToBreakdown(
            [['id' => 'a7c40f631fc920687.20179984', 'label' => null, 'orders' => '3', 'revenue' => '25.5']],
            100.0
        );

        self::assertSame(
            ['id' => 'a7c40f631fc920687.20179984', 'label' => '', 'orders' => 3, 'revenue' => 25.5, 'share' => 0.255],
            $breakdown['visible'][0]
        );
    }

    public function testBreakdownWithoutRevenueHasZeroShares(): void
    {
        $breakdown = $this->data->callToBreakdown($this->rows(9), 0.0);

        self::assertSame(0.0, $breakdown['visible'][0]['share']);
        self::assertSame(0.0, $breakdown['other']['share']);
    }

    public function testEmptyBreakdown(): void
    {
        self::assertSame(
            ['visible' => [], 'more' => [], 'other' => null, 'max' => 0.0],
            $this->data->callToBreakdown([], 0.0)
        );
    }

    public function testCustomerTypesAreDecidedByBillingEmailAcrossAllValidOrders(): void
    {
        $this->data->shopId = 5;
        $this->data->on(self::CUSTOMER_TYPES, [['id' => 'returning', 'orders' => '2', 'revenue' => '50']]);

        $breakdown = $this->data->callGetCustomerTypes($this->period(Period::DAYS_7), 100.0);

        self::assertSame('returning', $breakdown['visible'][0]['id']);
        $query = $this->data->queriesContaining(self::CUSTOMER_TYPES)[0];
        self::assertStringContainsString('GROUP BY OXBILLEMAIL', $query['sql']);
        self::assertSame([5, 5, '2026-09-11 00:00:00', self::NOW], $query['params']);
    }

    public function testPaymentsAndCountriesUseTheLanguageViews(): void
    {
        $this->data->languageId = 1;

        $this->data->callGetPayments($this->period(Period::DAYS_7), 0.0);
        $this->data->callGetCountries($this->period(Period::DAYS_7), 0.0);

        self::assertStringContainsString('LEFT JOIN oxv_oxpayments_1 p', $this->data->queries[0]['sql']);
        self::assertStringContainsString('LEFT JOIN oxv_oxcountry_1 c', $this->data->queries[1]['sql']);
    }

    public function testCountriesFallBackToTheBillingCountry(): void
    {
        $this->data->callGetCountries($this->period(Period::DAYS_7), 0.0);

        self::assertStringContainsString(
            "COALESCE(NULLIF(o.OXDELCOUNTRYID, ''), o.OXBILLCOUNTRYID) AS id",
            $this->data->queries[0]['sql']
        );
    }

    // ---- top sellers ----

    public function testTopSellersCombineOrderAndArticleData(): void
    {
        $this->data
            ->on(self::TOP_SELLERS, [
                ['productid' => 'parent1', 'qty' => '12', 'revenue' => '240.5', 'orders' => '9', 'ordertitle' => 'Old title', 'orderartnum' => 'A-1-S'],
            ])
            ->on(self::ARTICLES, [
                ['OXID' => 'parent1', 'OXARTNUM' => 'A-1', 'OXTITLE' => 'Jacket', 'OXSTOCK' => '0', 'OXVARSTOCK' => '14', 'OXVARCOUNT' => '3', 'OXACTIVE' => '1'],
            ])
            ->on(self::VARIANTS, [
                ['OXPARENTID' => 'parent1', 'variants' => '3', 'soldout' => '1'],
            ]);

        $page = $this->data->getTopSellers($this->period(Period::DAYS_7));

        self::assertSame(0, $page['offset']);
        self::assertFalse($page['hasMore']);
        self::assertSame([[
            'id' => 'parent1',
            'exists' => true,
            'active' => true,
            'shopUrl' => 'https://shop.test/parent1.html',
            'artnum' => 'A-1',
            'title' => 'Jacket',
            'qty' => 12.0,
            'orders' => 9,
            'revenue' => 240.5,
            'stock' => 14.0,
            'variants' => 3,
            'soldOutVariants' => 1,
            'stockStatus' => 'warning',
        ]], $page['items']);
    }

    public function testTopSellerWithoutVariantsUsesItsOwnStock(): void
    {
        $this->data
            ->on(self::TOP_SELLERS, [$this->topSellerRow('single')])
            ->on(self::ARTICLES, [
                ['OXID' => 'single', 'OXARTNUM' => 'S-1', 'OXTITLE' => 'Mug', 'OXSTOCK' => '40', 'OXVARSTOCK' => '0', 'OXVARCOUNT' => '0', 'OXACTIVE' => '1'],
            ]);

        $item = $this->data->getTopSellers($this->period(Period::DAYS_7))['items'][0];

        self::assertSame(40.0, $item['stock']);
        self::assertSame(0, $item['variants']);
        self::assertSame(0, $item['soldOutVariants']);
        self::assertSame('good', $item['stockStatus']);
    }

    public function testDeletedTopSellerKeepsTheDataFromTheOrder(): void
    {
        $this->data->on(self::TOP_SELLERS, [$this->topSellerRow('gone')]);

        $item = $this->data->getTopSellers($this->period(Period::DAYS_7))['items'][0];

        self::assertFalse($item['exists']);
        self::assertFalse($item['active']);
        self::assertSame('', $item['shopUrl']);
        self::assertSame('Ordered title', $item['title']);
        self::assertSame('ORD-1', $item['artnum']);
        self::assertNull($item['stock']);
        self::assertSame('good', $item['stockStatus']);
    }

    public function testInactiveTopSellerHasNoShopLink(): void
    {
        $this->data
            ->on(self::TOP_SELLERS, [$this->topSellerRow('off')])
            ->on(self::ARTICLES, [
                ['OXID' => 'off', 'OXARTNUM' => 'O-1', 'OXTITLE' => '', 'OXSTOCK' => '10', 'OXVARSTOCK' => '0', 'OXVARCOUNT' => '0', 'OXACTIVE' => '0'],
            ]);

        $item = $this->data->getTopSellers($this->period(Period::DAYS_7))['items'][0];

        self::assertTrue($item['exists']);
        self::assertFalse($item['active']);
        self::assertSame('', $item['shopUrl']);
        self::assertSame('Ordered title', $item['title'], 'an empty article title falls back to the order');
        self::assertSame('O-1', $item['artnum']);
    }

    public function testTopSellersFetchOneExtraRowToKnowAboutMorePages(): void
    {
        $rows = [];
        for ($i = 1; $i <= 11; $i++) {
            $rows[] = $this->topSellerRow('p' . $i);
        }
        $this->data->on(self::TOP_SELLERS, $rows);

        $page = $this->data->getTopSellers($this->period(Period::DAYS_7), 20);

        self::assertTrue($page['hasMore']);
        self::assertSame(20, $page['offset']);
        self::assertCount(10, $page['items']);
        self::assertStringContainsString('LIMIT 20, 11', $this->data->queriesContaining(self::TOP_SELLERS)[0]['sql']);

        $articles = $this->data->queriesContaining(self::ARTICLES)[0];
        self::assertCount(10, $articles['params'], 'details only for the rows shown');
        self::assertStringContainsString('FROM oxv_oxarticles_0 WHERE OXID IN (?,?,?,?,?,?,?,?,?,?)', $articles['sql']);
    }

    public function testTopSellerPageOfExactlyTenHasNoMore(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = $this->topSellerRow('p' . $i);
        }
        $this->data->on(self::TOP_SELLERS, $rows);

        self::assertFalse($this->data->getTopSellers($this->period(Period::DAYS_7))['hasMore']);
    }

    public function testNegativeTopSellerOffsetStartsAtTheBeginning(): void
    {
        $page = $this->data->getTopSellers($this->period(Period::DAYS_7), -10);

        self::assertSame(['items' => [], 'offset' => 0, 'hasMore' => false], $page);
        self::assertStringContainsString('LIMIT 0, 11', $this->data->queries[0]['sql']);
        self::assertCount(1, $this->data->queries, 'no detail queries without rows');
    }

    public function testTopSellersGroupVariantsUnderTheirParent(): void
    {
        $this->data->getTopSellers($this->period(Period::DAYS_7));

        $sql = $this->data->queries[0]['sql'];
        self::assertStringContainsString("COALESCE(NULLIF(a.OXPARENTID, ''), oa.OXARTID) AS productid", $sql);
        self::assertStringContainsString('GROUP BY productid', $sql);
        self::assertStringContainsString('AND oa.OXSTORNO = 0', $sql);
    }

    /**
     * @dataProvider stockStatusProvider
     */
    public function testStockStatus(?float $stock, int $soldOutVariants, string $expected): void
    {
        self::assertSame($expected, $this->data->callGetStockStatus($stock, $soldOutVariants));
    }

    public function stockStatusProvider(): array
    {
        return [
            'plenty' => [6.0, 0, 'good'],
            'at the threshold' => [5.0, 0, 'warning'],
            'one left' => [1.0, 0, 'warning'],
            'none left' => [0.0, 0, 'critical'],
            'oversold' => [-2.0, 0, 'critical'],
            'none left beats sold-out variants' => [0.0, 2, 'critical'],
            'plenty but a variant is gone' => [50.0, 1, 'warning'],
            'unknown stock' => [null, 0, 'good'],
            'unknown stock with sold-out variants' => [null, 1, 'warning'],
        ];
    }

    // ---- open baskets ----

    public function testOpenBasketsAreSortedByValueAndLimitedToTen(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = [
                'id' => 'b' . $i, 'userid' => 'u' . $i, 'changed' => '2026-09-17 12:00:00',
                'fname' => 'First' . $i, 'lname' => 'Last', 'email' => 'c' . $i . '@example.com',
                'positions' => '1', 'qty' => '2', 'value' => (string) ($i * 10),
            ];
        }
        $this->data->on(self::BASKETS, $rows);

        $baskets = $this->data->callGetOpenBaskets();

        self::assertTrue($baskets['available']);
        self::assertSame(12, $baskets['open']);
        self::assertSame(780.0, $baskets['openValue']);
        self::assertSame(24.0, $baskets['openQty']);
        self::assertCount(10, $baskets['list']);
        self::assertSame(['b12', 'b11', 'b10'], array_slice(array_column($baskets['list'], 'id'), 0, 3));
        self::assertSame([
            'id' => 'b12',
            'userId' => 'u12',
            'name' => 'First12 Last',
            'email' => 'c12@example.com',
            'changed' => '2026-09-17 12:00:00',
            'positions' => 1,
            'qty' => 2.0,
            'value' => 120.0,
        ], $baskets['list'][0]);
    }

    public function testOpenBasketOfAnUnknownUserHasNoName(): void
    {
        $this->data->on(self::BASKETS, [[
            'id' => 'b1', 'userid' => 'gone', 'changed' => '2026-09-17 12:00:00',
            'fname' => null, 'lname' => null, 'email' => null,
            'positions' => '1', 'qty' => '1', 'value' => '5',
        ]]);

        $basket = $this->data->callGetOpenBaskets()['list'][0];

        self::assertSame('', $basket['name']);
        self::assertSame('', $basket['email']);
    }

    public function testOpenBasketsReportThePriceMode(): void
    {
        self::assertFalse($this->data->callGetOpenBaskets()['netPrices']);

        $this->data->config['blEnterNetPrice'] = '1';
        self::assertTrue($this->data->callGetOpenBaskets()['netPrices']);
    }

    public function testNoOpenBaskets(): void
    {
        self::assertSame(
            ['available' => true, 'netPrices' => false, 'open' => 0, 'openValue' => 0.0, 'openQty' => 0.0, 'list' => []],
            $this->data->callGetOpenBaskets()
        );
        self::assertStringContainsString('NOW() - INTERVAL 24 HOUR', $this->data->queries[0]['sql']);
    }

    public function testOpenBasketsBelongToTheCustomersOfTheShop(): void
    {
        $this->data->shopId = 3;

        $this->data->callGetOpenBaskets();

        $query = $this->data->queriesContaining(self::BASKETS)[0];
        self::assertStringContainsString('AND u.OXSHOPID = ?', $query['sql']);
        self::assertSame([3], $query['params']);
    }

    public function testOpenBasketsOfSharedCustomerAccountsAreNotNarrowedToOneShop(): void
    {
        $this->data->config['blMallUsers'] = true;

        $this->data->callGetOpenBaskets();

        $query = $this->data->queriesContaining(self::BASKETS)[0];
        self::assertStringNotContainsString('OXSHOPID', $query['sql']);
        self::assertSame([], $query['params']);
    }

    public function testOpenBasketsAreUnavailableWithoutBasketSaving(): void
    {
        $this->data->config['blPerfNoBasketSaving'] = true;

        self::assertSame(['available' => false], $this->data->callGetOpenBaskets());
        self::assertSame([], $this->data->queries);
    }

    // ---- yearly comparison ----

    public function testYearlyComparisonGroupsMonthsPerYearNewestFirst(): void
    {
        $this->data->on(self::YEARLY, [
            ['year' => '2025', 'month' => '1', 'orders' => '3', 'revenue' => '30.5'],
            ['year' => '2025', 'month' => '12', 'orders' => '1', 'revenue' => '10'],
            ['year' => '2026', 'month' => '9', 'orders' => '2', 'revenue' => '20'],
        ]);

        $result = $this->data->getYearlyComparison(new DateTimeImmutable(self::NOW));

        self::assertSame(2026, $result['currentYear']);
        self::assertSame(9, $result['currentMonth']);
        self::assertSame([2026, 2025], array_column($result['years'], 'year'));

        [$current, $previous] = $result['years'];
        self::assertSame(2, $current['orders']);
        self::assertSame(20.0, $current['revenue']);
        self::assertCount(12, $current['months']);
        self::assertSame(['orders' => 0, 'revenue' => 0.0], $current['months'][0]);
        self::assertSame(['orders' => 2, 'revenue' => 20.0], $current['months'][8]);
        self::assertNull($current['months'][9], 'months still to come have no bar');
        self::assertNull($current['months'][11]);

        self::assertSame(4, $previous['orders']);
        self::assertSame(40.5, $previous['revenue']);
        self::assertSame(['orders' => 3, 'revenue' => 30.5], $previous['months'][0]);
        self::assertSame(['orders' => 1, 'revenue' => 10.0], $previous['months'][11]);
        self::assertNotContains(null, $previous['months']);
    }

    public function testYearlyComparisonCoversEverythingSinceTheEarliestDate(): void
    {
        $this->data->shopId = 4;

        $result = $this->data->getYearlyComparison(new DateTimeImmutable(self::NOW));

        self::assertSame([], $result['years']);
        self::assertSame(
            [4, Period::CUSTOM_MIN_DATE . ' 00:00:00', self::NOW],
            $this->data->queriesContaining(self::YEARLY)[0]['params']
        );
    }

    public function testYearlyComparisonIsCachedPerDayAndShop(): void
    {
        $this->data->cache['foun10_dashboard_v1_yearly_20260917_1'] = ['cached' => true, 'years' => []];

        self::assertSame(
            ['cached' => true, 'years' => []],
            $this->data->getYearlyComparison(new DateTimeImmutable(self::NOW))
        );
        self::assertSame([], $this->data->queries);

        $fresh = $this->data->getYearlyComparison(new DateTimeImmutable(self::NOW), true);
        self::assertSame($fresh, $this->data->cache['foun10_dashboard_v1_yearly_20260917_1']);
    }

    private function period(string $key): Period
    {
        return new Period($key, new DateTimeImmutable(self::NOW));
    }

    /**
     * $count breakdown rows with descending revenue 70, 60, ... (10 per step below 70: 20 for
     * all rows from the seventh on).
     */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => 'p' . $i,
                'label' => 'Entry ' . $i,
                'orders' => '1',
                'revenue' => (string) max(80 - $i * 10, 20),
            ];
        }

        return $rows;
    }

    private function topSellerRow(string $id): array
    {
        return [
            'productid' => $id,
            'qty' => '1',
            'revenue' => '10',
            'orders' => '1',
            'ordertitle' => 'Ordered title',
            'orderartnum' => 'ORD-1',
        ];
    }
}
