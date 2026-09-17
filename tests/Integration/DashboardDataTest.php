<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Integration;

use DateTimeImmutable;
use foun10\Dashboard\Core\DashboardData;
use foun10\Dashboard\Core\Period;
use OxidEsales\Eshop\Core\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The SQL against a real shop database. The unit suite checks what happens to query results;
 * only here does MySQL evaluate the revenue formula, the order filters, the joins on the
 * language views and the grouping of variants.
 */
class DashboardDataTest extends TestCase
{
    /** @var array|null */
    private static $data;

    public static function setUpBeforeClass(): void
    {
        Fixtures::create();
        self::$data = self::dashboardData()->get(self::period(), true);
    }

    public static function tearDownAfterClass(): void
    {
        Fixtures::remove();
    }

    public function testRevenueIsTheNetGoodsValueInTheDefaultCurrency(): void
    {
        // A 100 + B 90 (voucher) + C 100 (CHF export) + D 100 (VAT fallback) + H 200 (extra costs)
        self::assertEqualsWithDelta(590.0, self::$data['current']['revenue'], 0.001);
        self::assertSame(5, self::$data['current']['orders']);
        self::assertEqualsWithDelta(118.0, self::$data['current']['average'], 0.001);
    }

    public function testOnlyRegisteredAccountsCountAsNewCustomers(): void
    {
        self::assertSame(1, self::$data['current']['newCustomers']);
    }

    public function testComparisonRangesHoldNoFixtureOrders(): void
    {
        self::assertSame(0, self::$data['previous']['orders']);
        self::assertSame(0, self::$data['lastYear']['orders']);
    }

    public function testTrendAddsUpPerDay(): void
    {
        $points = self::$data['trend']['points'];
        $revenue = [];
        foreach ($points as $point) {
            $revenue[substr($point['start'], 0, 10)] = round((float) $point['revenue'], 2);
        }

        self::assertSame(Period::BUCKET_DAY, self::$data['trend']['bucket']);
        self::assertCount(31, $points);
        self::assertSame(100.0, $revenue['2011-03-01']);
        self::assertSame(0.0, $revenue['2011-03-02'], 'cancelled, unfinished and foreign orders stay out');
        self::assertSame(90.0, $revenue['2011-03-05']);
        self::assertSame(200.0, $revenue['2011-03-10']);
        self::assertSame(100.0, $revenue['2011-03-15']);
        self::assertSame(100.0, $revenue['2011-03-20']);
        self::assertEqualsWithDelta(590.0, array_sum($revenue), 0.001);
    }

    public function testCustomerTypesFollowTheBillingEmail(): void
    {
        self::assertSame(
            [
                'returning' => [2, 290.0],
                'new' => [2, 200.0],
                'guest' => [1, 100.0],
            ],
            $this->summarize(self::$data['customerTypes'])
        );
    }

    public function testPaymentMethodsAreLabelledFromTheShop(): void
    {
        $payments = $this->summarize(self::$data['payments']);

        self::assertSame([1, 200.0], $payments['oxidcashondel']);
        self::assertSame([2, 190.0], $payments['oxidinvoice']);
        self::assertSame([1, 100.0], $payments['oxidpayadvance']);
        self::assertSame([1, 100.0], $payments['f10dit_unknown']);

        $labels = array_column(self::$data['payments']['visible'], 'label', 'id');
        self::assertNotSame('', $labels['oxidinvoice']);
        self::assertNotSame('oxidinvoice', $labels['oxidinvoice']);
        self::assertSame('f10dit_unknown', $labels['f10dit_unknown'], 'an unknown payment shows its id');
    }

    public function testCountriesUseTheDeliveryAddressFirst(): void
    {
        $countries = $this->summarize(self::$data['countries']);

        self::assertSame([3, 390.0], $countries[Fixtures::countryId('DE')]);
        self::assertSame([1, 100.0], $countries[Fixtures::countryId('AT')]);
        self::assertSame([1, 100.0], $countries[Fixtures::countryId('CH')]);

        $labels = array_column(self::$data['countries']['visible'], 'label', 'id');
        self::assertNotSame(Fixtures::countryId('DE'), $labels[Fixtures::countryId('DE')]);
    }

    public function testTopSellersGroupVariantsAndSkipCancelledLines(): void
    {
        $items = self::$data['topSellers']['items'];

        self::assertCount(2, $items);
        self::assertFalse(self::$data['topSellers']['hasMore']);

        [$jacket, $mug] = $items;
        self::assertSame(Fixtures::id('parent'), $jacket['id']);
        self::assertSame('Fixture Jacket', $jacket['title']);
        self::assertSame('F10DIT-P', $jacket['artnum']);
        self::assertSame(4.0, $jacket['qty']);
        self::assertSame(3, $jacket['orders']);
        self::assertEqualsWithDelta(390.0, $jacket['revenue'], 0.001);
        self::assertSame(3.0, $jacket['stock']);
        self::assertSame(2, $jacket['variants']);
        self::assertSame(1, $jacket['soldOutVariants']);
        self::assertSame('warning', $jacket['stockStatus']);
        self::assertTrue($jacket['active']);
        self::assertNotSame('', $jacket['shopUrl']);

        self::assertSame(Fixtures::id('simple'), $mug['id']);
        self::assertSame(4.0, $mug['qty'], 'the cancelled line of five is not counted');
        self::assertEqualsWithDelta(200.0, $mug['revenue'], 0.001, 'CHF converted at the order rate');
        self::assertSame(0.0, $mug['stock']);
        self::assertSame('critical', $mug['stockStatus']);
    }

    public function testTopSellerPagesContinueAtTheOffset(): void
    {
        $page = self::dashboardData()->getTopSellers(self::period(), 1);

        self::assertSame(1, $page['offset']);
        self::assertSame([Fixtures::id('simple')], array_column($page['items'], 'id'));
    }

    public function testOpenBasketsShowFreshSavedBasketsOnly(): void
    {
        $baskets = self::$data['openBaskets'];
        $ids = array_column($baskets['list'], 'id');

        self::assertTrue($baskets['available']);
        self::assertContains(Fixtures::id('basketfresh'), $ids);
        self::assertNotContains(Fixtures::id('basketold'), $ids);
        self::assertNotContains(Fixtures::id('basketnotice'), $ids);
        self::assertNotContains(Fixtures::id('basketother'), $ids, 'the customer belongs to another shop');

        $fresh = $baskets['list'][array_search(Fixtures::id('basketfresh'), $ids, true)];
        self::assertSame(Fixtures::id('userA'), $fresh['userId']);
        self::assertSame('fixture-a@example.com', $fresh['email']);
        self::assertSame(20.0, $fresh['qty']);
        self::assertEqualsWithDelta(1190.0, $fresh['value'], 0.001, 'a variant without own price uses the parent price');
    }

    public function testSharedCustomerAccountsShowBasketsOfAllShops(): void
    {
        $config = Registry::getConfig();
        $previous = $config->getConfigParam('blMallUsers');
        $config->setConfigParam('blMallUsers', true);

        try {
            $data = self::dashboardData()->get(self::period(), true);
        } finally {
            $config->setConfigParam('blMallUsers', $previous);
        }

        self::assertContains(Fixtures::id('basketother'), array_column($data['openBaskets']['list'], 'id'));
    }

    public function testOpenBasketsAreUnavailableWhenTheShopDoesNotSaveThem(): void
    {
        $config = Registry::getConfig();
        $previous = $config->getConfigParam('blPerfNoBasketSaving');
        $config->setConfigParam('blPerfNoBasketSaving', true);

        try {
            $data = self::dashboardData()->get(self::period(), true);
        } finally {
            $config->setConfigParam('blPerfNoBasketSaving', $previous);
        }

        self::assertSame(['available' => false], $data['openBaskets']);
    }

    public function testYearlyComparisonContainsTheFixtureYear(): void
    {
        $yearly = self::dashboardData()->getYearlyComparison(new DateTimeImmutable(), true);
        $years = array_column($yearly['years'], null, 'year');

        self::assertArrayHasKey(2011, $years);
        self::assertSame(5, $years[2011]['orders']);
        self::assertEqualsWithDelta(590.0, $years[2011]['revenue'], 0.001);
        self::assertSame(0, $years[2011]['months'][0]['orders'], 'the cancelled January order is not counted');
        self::assertSame(5, $years[2011]['months'][2]['orders']);
    }

    public function testResultIsCachedUntilRefreshed(): void
    {
        $dashboard = self::dashboardData();
        $first = $dashboard->get(self::period());

        Fixtures::remove();
        try {
            self::assertSame($first, $dashboard->get(self::period()), 'served from the cache');
            self::assertSame(0, $dashboard->get(self::period(), true)['current']['orders'], 'refreshed');
        } finally {
            Fixtures::create();
            $dashboard->get(self::period(), true);
        }
    }

    public function testEmptyRangeProducesACompleteStructure(): void
    {
        $period = new Period(Period::CUSTOM, new DateTimeImmutable(), '2009-01-01', '2009-01-31');
        $data = self::dashboardData()->get($period, true);

        self::assertSame(0, $data['current']['orders']);
        self::assertSame([], $data['topSellers']['items']);
        self::assertSame([], $data['payments']['visible']);
        self::assertCount(31, $data['trend']['points']);
    }

    /**
     * @return array<string, array{0: int, 1: float}>
     */
    private function summarize(array $breakdown): array
    {
        $result = [];
        foreach (array_merge($breakdown['visible'], $breakdown['more']) as $item) {
            $result[$item['id']] = [$item['orders'], round($item['revenue'], 2)];
        }

        return $result;
    }

    private static function period(): Period
    {
        return new Period(Period::CUSTOM, new DateTimeImmutable(), Fixtures::FROM, Fixtures::TO);
    }

    private static function dashboardData(): DashboardData
    {
        return oxNew(DashboardData::class);
    }
}
