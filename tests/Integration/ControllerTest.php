<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Integration;

use foun10\Dashboard\Controller\Admin\DashboardController;
use foun10\Dashboard\Extension\Application\Controller\Admin\NavigationController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Utils;
use PHPUnit\Framework\TestCase;

/**
 * The admin entry points: the Home redirect with the start-up messages, the dashboard page
 * and the "show more" response.
 */
class ControllerTest extends TestCase
{
    /** @var Utils */
    private $originalUtils;

    /** @var SpyUtils */
    private $utils;

    /** @var array */
    private $get;

    public static function setUpBeforeClass(): void
    {
        Fixtures::create();
    }

    public static function tearDownAfterClass(): void
    {
        Fixtures::remove();
    }

    protected function setUp(): void
    {
        $this->get = $_GET;
        $this->originalUtils = Registry::getUtils();
        $this->utils = new SpyUtils();
        Registry::set(Utils::class, $this->utils);
        Registry::getSession()->deleteVariable(DashboardController::SESSION_MESSAGES);
    }

    protected function tearDown(): void
    {
        $_GET = $this->get;
        oxNew(DashboardController::class)->setUser(null);
        Registry::set(Utils::class, $this->originalUtils);
        Registry::getSession()->deleteVariable(DashboardController::SESSION_MESSAGES);
    }

    public function testHomeFrameRedirectsToTheDashboard(): void
    {
        $_GET['item'] = 'home.tpl';

        $this->navigation([])->render();

        self::assertCount(1, $this->utils->redirects);
        self::assertStringContainsString('cl=foun10_dashboard', $this->utils->redirects[0]['url']);
        self::assertStringNotContainsString('&amp;', $this->utils->redirects[0]['url']);
        self::assertSame(302, $this->utils->redirects[0]['code']);
    }

    public function testHomeFrameHandsTheStartupMessagesToTheDashboard(): void
    {
        $_GET['item'] = 'home.tpl';

        $this->navigation(['warning' => 'Delete the setup directory', 'message' => ''])->render();

        self::assertSame(
            ['warning' => 'Delete the setup directory'],
            Registry::getSession()->getVariable(DashboardController::SESSION_MESSAGES),
            'empty entries are dropped'
        );
    }

    public function testHomeFrameWithoutMessagesClearsOldOnes(): void
    {
        $_GET['item'] = 'home.tpl';
        Registry::getSession()->setVariable(DashboardController::SESSION_MESSAGES, ['warning' => 'stale']);

        $this->navigation([])->render();

        self::assertNull(Registry::getSession()->getVariable(DashboardController::SESSION_MESSAGES));
        self::assertCount(1, $this->utils->redirects);
    }

    public function testNavigationReloadSkipsTheChecksAndStillRedirects(): void
    {
        $_GET['item'] = 'home.tpl';
        $_GET['navReload'] = '1';

        $this->navigation(['warning' => 'must not appear'])->render();

        self::assertNull(Registry::getSession()->getVariable(DashboardController::SESSION_MESSAGES));
        self::assertCount(1, $this->utils->redirects);
    }

    public function testOtherNavigationFramesAreLeftAlone(): void
    {
        $_GET['item'] = 'header.tpl';

        self::assertSame('header.tpl', $this->navigation()->render());
        self::assertSame([], $this->utils->redirects);
    }

    public function testNavigationWithoutItemIsLeftAlone(): void
    {
        unset($_GET['item']);

        self::assertSame('nav_frame.tpl', $this->navigation()->render());
        self::assertSame([], $this->utils->redirects);
    }

    public function testDashboardShowsTheStartupMessagesOnce(): void
    {
        Registry::getSession()->setVariable(DashboardController::SESSION_MESSAGES, ['warning' => 'Setup directory']);

        $first = $this->dashboard();
        $first->render();
        $second = $this->dashboard();
        $second->render();

        self::assertSame(['warning' => 'Setup directory'], $first->getViewDataElement('dashboardMessages'));
        self::assertSame([], $second->getViewDataElement('dashboardMessages'));
    }

    public function testDashboardRendersTheRequestedPeriod(): void
    {
        $_GET = ['period' => 'custom', 'from' => Fixtures::FROM, 'to' => Fixtures::TO, 'refresh' => '1'];

        $controller = $this->dashboard();

        self::assertSame('foun10_dashboard.tpl', $controller->render());
        self::assertSame('custom', $controller->getViewDataElement('dashboardPeriod'));
        self::assertSame('&period=custom&from=2011-03-01&to=2011-03-31', $controller->getViewDataElement('dashboardPeriodQuery'));
        self::assertSame(5, $controller->getViewDataElement('dashboard')['current']['orders']);

        $chart = json_decode($controller->getViewDataElement('dashboardChart'), true);
        self::assertSame('day', $chart['bucket']);
        self::assertCount(31, $chart['points']);
        self::assertNotSame('', $chart['currency']);
        self::assertMatchesRegularExpression('/^[a-z]{2}-[A-Z]{2}$/', $chart['locale']);

        $yearly = json_decode($controller->getViewDataElement('dashboardYearly'), true);
        self::assertContains(2011, array_column($yearly['years'], 'year'));
    }

    public function testArrayParametersFallBackToTheDefaultPeriod(): void
    {
        $_GET = ['period' => ['custom'], 'from' => ['x'], 'to' => ['y']];

        $controller = $this->dashboard();
        $controller->render();

        self::assertSame('ytd', $controller->getViewDataElement('dashboardPeriod'));
    }

    /**
     * Renders the whole page and collects every notice and warning on the way instead of
     * stopping at the first. Each case drives a different branch of the templates - a variable
     * a partial does not receive is a notice on PHP 7 and a warning on PHP 8, so it shows up in
     * the log on every page view.
     *
     * @dataProvider pageProvider
     */
    public function testTheWholePageRendersWithoutNotices(array $query, bool $saveBaskets, array $expected): void
    {
        $_GET = $query + ['refresh' => '1'];
        $config = Registry::getConfig();
        $previous = $config->getConfigParam('blPerfNoBasketSaving');
        $config->setConfigParam('blPerfNoBasketSaving', !$saveBaskets);

        $notices = [];
        // Only what the module and its templates raise counts. Deprecations are left out
        // entirely - the framework raises plenty while the container is built - and so is shop
        // code: OXID 6 itself trips over "2M" * 1024 on every admin page under PHP 7.
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$notices): bool {
            if (strpos($file, '/oxid-esales/') === false) {
                $notices[] = $message . ' in ' . basename($file) . ':' . $line;
            }

            return true;
        }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            $html = $this->renderPage();
        } finally {
            restore_error_handler();
            $config->setConfigParam('blPerfNoBasketSaving', $previous);
        }

        self::assertSame([], $notices);
        self::assertStringNotContainsString('ERROR', $html);
        self::assertStringNotContainsString('Translation for', $html);
        foreach ($expected as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    public function pageProvider(): array
    {
        $fixtures = ['period' => 'custom', 'from' => Fixtures::FROM, 'to' => Fixtures::TO];

        return [
            'custom range with data' => [$fixtures, true, ['id="f10d-trend-data"', 'Fixture Jacket', 'fixture-a@example.com', 'f10d-mini-stats']],
            'year to date, no previous period' => [['period' => 'ytd'], true, ['id="f10d-yearly-data"']],
            'today, hourly' => [['period' => 'today'], true, ['id="f10d-trend-data"']],
            'range without orders' => [['period' => 'custom', 'from' => '2009-01-01', 'to' => '2009-01-31'], true, ['f10d-empty']],
            'baskets not saved' => [$fixtures, false, ['f10d-empty']],
        ];
    }

    public function testLoadTopSellersRespondsWithTheNextPage(): void
    {
        $_GET = ['period' => 'custom', 'from' => Fixtures::FROM, 'to' => Fixtures::TO, 'offset' => '1'];

        $this->dashboard()->loadTopSellers();

        self::assertSame(['Content-Type: application/json; charset=UTF-8'], $this->utils->headers);
        self::assertCount(1, $this->utils->messages);
        $response = json_decode($this->utils->messages[0], true);
        self::assertSame(2, $response['offset']);
        self::assertFalse($response['hasMore']);
        self::assertStringContainsString('Fixture Mug', $response['html']);
        self::assertStringNotContainsString('Fixture Jacket', $response['html']);
        self::assertStringContainsString('<td class="f10d-num f10d-muted">2</td>', $response['html'], 'numbering continues');
    }

    /**
     * The navigation controller with the shop admin logged in - the menu tree it builds checks
     * the user's rights. With $startupMessages, OXID's own start-up checks are replaced by a
     * fixed result: what they report depends on the environment (file permissions, system
     * requirements), and on some combinations the core method itself emits a PHP warning.
     */
    private function renderPage(): string
    {
        $controller = $this->dashboard();
        $template = $controller->render();
        // what ShopControl does before rendering - it provides e.g. the charset
        $controller->addGlobalParams();
        $smarty = Registry::getUtilsView()->getSmarty();
        foreach ($controller->getViewData() as $key => $value) {
            $smarty->assign($key, $value);
        }
        $smarty->assign('oView', $controller);
        $smarty->assign('oViewConf', $controller->getViewConfig());

        return $smarty->fetch($template);
    }

    private function navigation(?array $startupMessages = null): NavigationController
    {
        $navigation = oxNew(\OxidEsales\Eshop\Application\Controller\Admin\NavigationController::class);

        if ($startupMessages !== null) {
            $navigation = new class ($startupMessages) extends NavigationController {
                /** @var array */
                private $startupMessages;

                public function __construct(array $startupMessages)
                {
                    parent::__construct();
                    $this->startupMessages = $startupMessages;
                }

                protected function _doStartUpChecks() // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
                {
                    return $this->startupMessages;
                }
            };
        }

        $admin = oxNew(\OxidEsales\Eshop\Application\Model\User::class);
        self::assertTrue($admin->load(Fixtures::id('admin')));
        $navigation->setUser($admin);

        return $navigation;
    }

    private function dashboard(): DashboardController
    {
        return oxNew(DashboardController::class);
    }
}
