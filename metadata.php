<?php

use foun10\Dashboard\Controller\Admin\DashboardController;
use foun10\Dashboard\Extension\Application\Controller\Admin\NavigationController;

$sMetadataVersion = '2.1';

/**
 * Metadata file for module
 */
$aModule = [
    'id' => 'foun10Dashboard',
    'title' => 'foun10 - Sales Dashboard',
    'description' => [
        'de' => 'Ersetzt die Startseite des Admin-Bereichs durch ein Umsatz-Dashboard: Kennzahlen mit Vergleich zu Vorperiode und Vorjahr, Verlauf, Kundentypen, Zahlungsarten, Lieferländer, Topseller und offene Warenkörbe.',
        'en' => 'Replaces the admin start page with a sales dashboard: KPIs compared with the previous period and last year, trend, customer types, payment methods, delivery countries, top sellers and open baskets.',
    ],
    'version' => '7.0.0',
    'author' => 'foun10 GmbH',
    'email' => 'info@foun10.de',
    'extend' => [
        \OxidEsales\Eshop\Application\Controller\Admin\NavigationController::class => NavigationController::class,
    ],
    'controllers' => [
        'foun10_dashboard' => DashboardController::class,
    ],
    // Empty on purpose: OXID 7 mounts views/twig/ as a Twig namespace under the
    // module id automatically, so templates are referenced as
    // '@foun10Dashboard/admin/<name>.html.twig' instead of being registered here.
    'templates' => [],
    'settings' => [
        [
            'group' => 'foun10Dashboard',
            'name' => 'foun10DashboardCacheTTL',
            'type' => 'str',
            'value' => '600',
        ],
    ],
];
