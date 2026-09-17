<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Integration;

use foun10\Dashboard\Controller\Admin\DashboardController;
use foun10\Dashboard\Extension\Application\Controller\Admin\NavigationController;
use OxidEsales\Eshop\Core\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the module is actually wired into the running shop: the extension chain, the
 * controller key, the templates and the settings. None of this is reachable from a unit test.
 */
class ModuleWiringTest extends TestCase
{
    /**
     * Methods an extension adds on purpose. Anything else it declares must override a parent
     * method - a hook whose name no longer matches the parent is silently never called.
     */
    private const METHODS_THE_MODULE_ADDS = [];

    public function testNavigationControllerIsExtended(): void
    {
        self::assertInstanceOf(
            NavigationController::class,
            oxNew(\OxidEsales\Eshop\Application\Controller\Admin\NavigationController::class)
        );
    }

    /**
     * @dataProvider extensionClassProvider
     */
    public function testEveryExtensionMethodOverridesSomething(string $moduleClass): void
    {
        $parent = get_parent_class($moduleClass);
        self::assertNotFalse($parent, $moduleClass . ' has no parent - is the module activated?');

        $unexpected = [];
        foreach ((new \ReflectionClass($moduleClass))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $moduleClass
                || method_exists($parent, $method->getName())
                || in_array($method->getName(), self::METHODS_THE_MODULE_ADDS, true)
            ) {
                continue;
            }
            $unexpected[] = $method->getName();
        }

        self::assertSame([], $unexpected, $moduleClass . ' declares methods that override nothing in ' . $parent);
    }

    public function extensionClassProvider(): array
    {
        $cases = [];
        $root = __DIR__ . '/../../src/Extension/';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root), -4);
            $class = 'foun10\\Dashboard\\Extension\\' . str_replace('/', '\\', $relative);
            $cases[$class] = [$class];
        }

        return $cases;
    }

    public function testControllerKeyResolvesToTheDashboard(): void
    {
        $resolver = Registry::getControllerClassNameResolver();

        self::assertSame(DashboardController::class, ltrim((string) $resolver->getClassNameById('foun10_dashboard'), '\\'));
    }

    public function testCacheLifetimeSettingIsStoredWithItsDefault(): void
    {
        self::assertSame('600', (string) Registry::getConfig()->getConfigParam('foun10DashboardCacheTTL'));
    }

    public function testEveryRegisteredTemplateExists(): void
    {
        $aModule = [];
        include __DIR__ . '/../../metadata.php';

        self::assertNotEmpty($aModule['templates']);
        foreach ($aModule['templates'] as $name => $path) {
            $file = __DIR__ . '/../../' . substr($path, strlen('foun10/Dashboard/'));
            self::assertFileExists($file, $name);
        }

        $onDisk = array_map('basename', glob(__DIR__ . '/../../views/admin/tpl/*.tpl'));
        self::assertEqualsCanonicalizing($onDisk, array_keys($aModule['templates']), 'every template is registered');
    }

    public function testAssetsExist(): void
    {
        self::assertFileExists(__DIR__ . '/../../out/src/css/dashboard.css');
        self::assertFileExists(__DIR__ . '/../../out/src/js/dashboard.js');
    }

    /**
     * Both language files must define the same keys, and every key a template or the code
     * asks for must exist - a missing one renders as "ERROR: Translation for ... not found".
     */
    public function testLanguageFilesAreCompleteAndCoverEveryUsedKey(): void
    {
        $keys = [];
        foreach (['de', 'en'] as $language) {
            $aLang = [];
            include __DIR__ . '/../../views/admin/' . $language . '/foun10_dashboard_lang.php';
            $keys[$language] = array_keys($aLang);
        }
        self::assertEqualsCanonicalizing($keys['de'], $keys['en']);

        $used = [];
        $sources = array_merge(
            glob(__DIR__ . '/../../views/admin/tpl/*.tpl'),
            glob(__DIR__ . '/../../src/*/*/*.php'),
            glob(__DIR__ . '/../../src/*/*.php')
        );
        foreach ($sources as $file) {
            preg_match_all('/FOUN10_DASHBOARD_[A-Z0-9_]*[A-Z0-9]\b(?!_)/', (string) file_get_contents($file), $matches);
            $used = array_merge($used, $matches[0]);
        }

        // keys assembled from a prefix plus a value
        $dynamic = [
            'FOUN10_DASHBOARD_PERIOD_TODAY', 'FOUN10_DASHBOARD_PERIOD_7D', 'FOUN10_DASHBOARD_PERIOD_30D',
            'FOUN10_DASHBOARD_PERIOD_90D', 'FOUN10_DASHBOARD_PERIOD_YTD',
            'FOUN10_DASHBOARD_TREND_BUCKET_HOUR', 'FOUN10_DASHBOARD_TREND_BUCKET_DAY',
            'FOUN10_DASHBOARD_TREND_BUCKET_WEEK', 'FOUN10_DASHBOARD_TREND_BUCKET_MONTH',
            'FOUN10_DASHBOARD_CUSTOMER_TYPE_RETURNING', 'FOUN10_DASHBOARD_CUSTOMER_TYPE_NEW',
            'FOUN10_DASHBOARD_CUSTOMER_TYPE_GUEST',
        ];

        self::assertSame([], array_values(array_diff(array_unique(array_merge($used, $dynamic)), $keys['en'])), 'used but not defined');
        self::assertSame([], array_values(array_diff(
            array_filter($keys['en'], static function (string $key): bool {
                return strpos($key, 'FOUN10_DASHBOARD_') === 0;
            }),
            array_merge($used, $dynamic)
        )), 'defined but never used');
    }
}
