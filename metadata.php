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
    'version' => '6.0.0',
    'author' => 'foun10 GmbH',
    'email' => 'info@foun10.de',
    'extend' => [
        \OxidEsales\Eshop\Application\Controller\Admin\NavigationController::class => NavigationController::class,
    ],
    'controllers' => [
        'foun10_dashboard' => DashboardController::class,
    ],
    'templates' => [
        'foun10_dashboard.tpl' => 'foun10/Dashboard/views/admin/tpl/foun10_dashboard.tpl',
        'foun10_dashboard_tile.tpl' => 'foun10/Dashboard/views/admin/tpl/foun10_dashboard_tile.tpl',
        'foun10_dashboard_delta.tpl' => 'foun10/Dashboard/views/admin/tpl/foun10_dashboard_delta.tpl',
        'foun10_dashboard_bars.tpl' => 'foun10/Dashboard/views/admin/tpl/foun10_dashboard_bars.tpl',
        'foun10_dashboard_bar_row.tpl' => 'foun10/Dashboard/views/admin/tpl/foun10_dashboard_bar_row.tpl',
        'foun10_dashboard_topseller_rows.tpl' => 'foun10/Dashboard/views/admin/tpl/foun10_dashboard_topseller_rows.tpl',
    ],
    'settings' => [
        [
            'group' => 'foun10Dashboard',
            'name' => 'foun10DashboardCacheTTL',
            'type' => 'str',
            'value' => '600',
        ],
    ],
];
