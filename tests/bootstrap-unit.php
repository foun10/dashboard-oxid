<?php

declare(strict_types=1);

/**
 * Bootstrap for the unit suite: only the module's autoloader. The classes under test reach
 * the shop exclusively through protected seams, which the test doubles override.
 */
require __DIR__ . '/../vendor/autoload.php';
