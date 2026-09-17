<?php

declare(strict_types=1);

/**
 * Bootstrap for the integration suite.
 *
 * Unlike the unit suite these tests need a running shop: the module has to be installed and
 * activated so that the *_parent chain exists, and the database has to hold the test orders.
 * PHPUnit has already loaded the module's own autoloader by the time this file runs, so all
 * that is left is to pull in the shop.
 *
 * The path differs per environment, hence the environment variable. The default matches the
 * Docker test shops in this repository.
 */
$shopBootstrap = getenv('OXID_SHOP_BOOTSTRAP') ?: '/var/www/html/source/bootstrap.php';

if (!is_file($shopBootstrap)) {
    fwrite(
        STDERR,
        "Shop bootstrap not found at: $shopBootstrap\n" .
        "Set OXID_SHOP_BOOTSTRAP to the shop's source/bootstrap.php.\n"
    );
    exit(1);
}

$moduleAutoloader = require __DIR__ . '/../vendor/autoload.php';

require_once $shopBootstrap;

// The admin controllers would start the shop session lazily - by then PHPUnit has printed its
// header, and session_start()/session_regenerate_id() fail with "headers already sent".
// Started here, nothing has been printed yet.
\OxidEsales\Eshop\Core\Registry::getSession()->start();

/**
 * Take the module's autoloader out of the chain completely. PHPUnit's binary registered it
 * before the shop's, so it wins by registration order alone - and PHPUnit's dependencies
 * overlap with the shop's own. Whichever copy of a shared package wins has to be the shop's,
 * because that is what the module runs against in production.
 */
$moduleAutoloader->unregister();

$testOnlyPrefixes = [
    'foun10\\Dashboard\\Tests\\',
    'PHPUnit\\',
    'SebastianBergmann\\',
    'PharIo\\',
    'DeepCopy\\',
    'Prophecy\\',
    'TheSeer\\',
    'PhpParser\\',
    'Doctrine\\Instantiator\\',
];

// Put it back only for the packages the test run itself owns. The module's own classes are
// not in this list on purpose: the shop resolves them through the path repository, which is
// exactly the code path production uses.
spl_autoload_register(
    static function (string $class) use ($moduleAutoloader, $testOnlyPrefixes): void {
        foreach ($testOnlyPrefixes as $prefix) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $moduleAutoloader->loadClass($class);

                return;
            }
        }
    },
    true,
    true
);
