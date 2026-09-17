<?php
declare(strict_types=1);

namespace foun10\Dashboard\Controller\Admin;

use DateTimeImmutable;
use foun10\Dashboard\Core\DashboardData;
use foun10\Dashboard\Core\Formatter;
use foun10\Dashboard\Core\Period;
use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\Eshop\Core\Registry;

/**
 * Admin start page, opened in the main frame by the NavigationController
 * extension. The figures are rendered server-side; the template only adds
 * the charts and hover details on top via JS.
 */
class DashboardController extends AdminController
{
    /**
     * Session key under which the NavigationController extension hands over OXID's start-up
     * check messages. Kept here rather than on the extension: that class only exists once the
     * module chain has been built, this one can always be loaded.
     */
    public const SESSION_MESSAGES = 'foun10DashboardStartupMessages';

    protected $_sThisTemplate = 'foun10_dashboard.tpl';

    /** @var Formatter|null */
    protected $formatter;

    public function render()
    {
        parent::render();

        $period = $this->getRequestedPeriod();
        $refresh = $this->getRequestString('refresh') === '1';
        $data = $this->getDashboardData()->get($period, $refresh);
        $now = new DateTimeImmutable();
        $currency = $this->getCurrency();
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        $this->_aViewData['dashboard'] = $data;
        $this->_aViewData['dashboardMessages'] = $this->takeStartupMessages();
        $this->_aViewData['dashboardPeriod'] = $period->getKey();
        $this->_aViewData['dashboardPeriodQuery'] = $period->getQuery();
        $this->_aViewData['dashboardFrom'] = $period->getFromDate();
        $this->_aViewData['dashboardTo'] = $period->getToDate();
        $this->_aViewData['dashboardMinDate'] = Period::CUSTOM_MIN_DATE;
        $this->_aViewData['dashboardToday'] = $now->format('Y-m-d');
        $this->_aViewData['dashboardPeriods'] = Period::KEYS;
        $this->_aViewData['dashboardLocale'] = $this->getLocale();
        $this->_aViewData['dashboardChart'] = json_encode(
            [
                'bucket' => $data['trend']['bucket'],
                'points' => $data['trend']['points'],
                'currency' => (string) $currency->name,
                'locale' => $this->getLocale(),
            ],
            $jsonFlags
        );
        $this->_aViewData['dashboardYearly'] = json_encode(
            $this->getDashboardData()->getYearlyComparison($now, $refresh) + [
                'currency' => (string) $currency->name,
                'locale' => $this->getLocale(),
            ],
            $jsonFlags
        );

        return $this->_sThisTemplate;
    }

    /**
     * fnc=loadtopsellers: the next page of top sellers for the "show more"
     * button, as JSON {html, offset, hasMore}. The rows are rendered with the
     * same partial as the first page.
     */
    public function loadTopSellers()
    {
        $page = $this->getDashboardData()->getTopSellers(
            $this->getRequestedPeriod(),
            (int) $this->getRequestString('offset')
        );

        $smarty = Registry::getUtilsView()->getSmarty();
        $smarty->assign('oView', $this);
        $smarty->assign('oViewConf', $this->getViewConfig());
        $smarty->assign('topSellerPage', $page);

        $utils = Registry::getUtils();
        $utils->setHeader('Content-Type: application/json; charset=UTF-8');
        $utils->showMessageAndExit((string) json_encode([
            'html' => $smarty->fetch('foun10_dashboard_topseller_rows.tpl'),
            'offset' => $page['offset'] + count($page['items']),
            'hasMore' => $page['hasMore'],
        ]));
    }

    public function formatMoney($value, int $decimals = 2): string
    {
        return $this->getFormatter()->money($value, $decimals);
    }

    public function formatAverage($revenue, $orders): string
    {
        return $this->getFormatter()->average($revenue, $orders);
    }

    public function formatNumber($value, int $decimals = 0): string
    {
        return $this->getFormatter()->number($value, $decimals);
    }

    public function formatPercent($value, int $decimals = 1): string
    {
        return $this->getFormatter()->percent($value, $decimals);
    }

    public function getChange($current, $compare): ?float
    {
        return $this->getFormatter()->change($current, $compare);
    }

    public function formatChange(?float $change): string
    {
        return $this->getFormatter()->formatChange($change);
    }

    public function getChangeDirection(?float $change): string
    {
        return $this->getFormatter()->changeDirection($change);
    }

    public function formatRange($range): string
    {
        return $this->getFormatter()->range($range);
    }

    public function formatDateTime($value, string $format = 'd.m.Y H:i'): string
    {
        return $this->getFormatter()->dateTime($value, $format);
    }

    public function formatTimeOrDate($value): string
    {
        return $this->getFormatter()->timeOrDate($value, new DateTimeImmutable());
    }

    public function getBarWidth($value, $max): string
    {
        return $this->getFormatter()->barWidth($value, $max);
    }

    public function escape($value): string
    {
        return $this->getFormatter()->escape($value);
    }

    /**
     * Module asset URL with the file's modification time appended, so
     * browsers pick up a changed script/stylesheet right after a deploy.
     */
    public function getAssetUrl(string $path): string
    {
        $viewConfig = $this->getViewConfig();
        $file = (string) $viewConfig->getModulePath('foun10Dashboard', $path);
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return $viewConfig->getModuleUrl('foun10Dashboard', $path) . '?v=' . $version;
    }

    public function getAdminLink(string $class, string $oxid = ''): string
    {
        $link = $this->getViewConfig()->getSelfLink() . 'cl=' . $class;

        return $oxid !== '' ? $link . '&oxid=' . rawurlencode($oxid) : $link;
    }

    /**
     * The start-up check messages the NavigationController extension left
     * in the session; shown once.
     *
     * @return array<string, string>
     */
    protected function takeStartupMessages(): array
    {
        $session = Registry::getSession();
        $messages = $session->getVariable(self::SESSION_MESSAGES);
        $session->deleteVariable(self::SESSION_MESSAGES);

        return is_array($messages) ? $messages : [];
    }

    /**
     * The period from the request: a preset (period=30d) or a custom range
     * (period=custom&from=Y-m-d&to=Y-m-d); anything invalid gives the default.
     */
    protected function getRequestedPeriod(): Period
    {
        return new Period(
            $this->getRequestString('period'),
            new DateTimeImmutable(),
            $this->getRequestString('from'),
            $this->getRequestString('to')
        );
    }

    /**
     * A request parameter as string; arrays (?period[]=x) become ''.
     */
    protected function getRequestString(string $name): string
    {
        $value = Registry::getRequest()->getRequestEscapedParameter($name);

        return is_scalar($value) ? (string) $value : '';
    }

    protected function getFormatter(): Formatter
    {
        if ($this->formatter === null) {
            $currency = $this->getCurrency();
            $this->formatter = new Formatter(
                (string) $currency->dec,
                (string) $currency->thousand,
                (string) $currency->sign
            );
        }

        return $this->formatter;
    }

    /**
     * The shop's default currency - the one all figures are converted to.
     */
    protected function getCurrency()
    {
        $currencies = Registry::getConfig()->getCurrencyArray();

        return reset($currencies);
    }

    /**
     * BCP 47 locale for the chart's number/date formatting, taken from the
     * admin language file so it always matches the interface language.
     */
    protected function getLocale(): string
    {
        return (string) Registry::getLang()->translateString('FOUN10_DASHBOARD_LOCALE', null, true);
    }

    protected function getDashboardData(): DashboardData
    {
        return Registry::get(DashboardData::class);
    }
}
