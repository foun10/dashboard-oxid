[{assign var="current" value=$dashboard.current}]
[{assign var="previous" value=$dashboard.previous}]
[{assign var="lastYear" value=$dashboard.lastYear}]
[{assign var="baskets" value=$dashboard.openBaskets}]
<!DOCTYPE html>
<html lang="[{$dashboardLocale|truncate:2:""}]">
<head>
    <meta charset="[{$charset|default:"UTF-8"}]">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>[{oxmultilang ident="FOUN10_DASHBOARD_TITLE"}]</title>
    <link rel="stylesheet" href="[{$oView->getAssetUrl('out/src/css/dashboard.css')}]">
</head>
<body class="f10d">
<script>
    try {
        parent.sShopTitle = "[{$actshop|oxaddslashes}]";
        parent.setTitle();
    } catch (e) {}
</script>

<main class="f10d-page">
    <header class="f10d-head">
        <div>
            <h1 class="f10d-title">[{oxmultilang ident="FOUN10_DASHBOARD_TITLE"}]</h1>
            <p class="f10d-sub">
                [{oxmultilang ident="FOUN10_DASHBOARD_REVENUE_NOTE"}]
                · [{oxmultilang ident="FOUN10_DASHBOARD_GENERATED_AT"}] [{$oView->formatDateTime($dashboard.generatedAt)}]
            </p>
        </div>
    </header>

    [{if $dashboardMessages}]
        [{* OXID's start-up checks (setup directory, config permissions, updates), as on the standard home page. *}]
        <section class="f10d-messages" role="status">
            [{foreach from=$dashboardMessages item="message" key="messageClass"}]
                <p class="f10d-message f10d-message--[{$messageClass}]">[{$message}]</p>
            [{/foreach}]
        </section>
    [{/if}]

    <nav class="f10d-filters" aria-label="[{oxmultilang ident="FOUN10_DASHBOARD_PERIOD"}]">
        <div class="f10d-segmented">
            [{foreach from=$dashboardPeriods item="periodKey"}]
                <a class="f10d-segment[{if $periodKey == $dashboardPeriod}] is-active[{/if}]"
                   href="[{$oView->getAdminLink('foun10_dashboard')}]&period=[{$periodKey}]"
                   [{if $periodKey == $dashboardPeriod}]aria-current="true"[{/if}]>
                    [{oxmultilang ident="FOUN10_DASHBOARD_PERIOD_"|cat:$periodKey|upper}]
                </a>
            [{/foreach}]
        </div>
        <form class="f10d-range[{if $dashboardPeriod == 'custom'}] is-active[{/if}]" id="f10d-range"
              data-url="[{$oView->getAdminLink('foun10_dashboard')}]&period=custom">
            <label for="f10d-range-from">[{oxmultilang ident="FOUN10_DASHBOARD_PERIOD_CUSTOM"}]</label>
            <input type="date" id="f10d-range-from" name="from" required
                   value="[{$dashboardFrom}]" min="[{$dashboardMinDate}]" max="[{$dashboardToday}]"
                   aria-label="[{oxmultilang ident="FOUN10_DASHBOARD_RANGE_FROM"}]">
            <span aria-hidden="true">–</span>
            <input type="date" id="f10d-range-to" name="to" required
                   value="[{$dashboardTo}]" min="[{$dashboardMinDate}]" max="[{$dashboardToday}]"
                   aria-label="[{oxmultilang ident="FOUN10_DASHBOARD_RANGE_TO"}]">
            <button type="submit">[{oxmultilang ident="FOUN10_DASHBOARD_RANGE_APPLY"}]</button>
        </form>
        <a class="f10d-refresh" href="[{$oView->getAdminLink('foun10_dashboard')}][{$dashboardPeriodQuery}]&refresh=1">
            <span aria-hidden="true">↻</span> [{oxmultilang ident="FOUN10_DASHBOARD_REFRESH"}]
        </a>
    </nav>

    [{* ---- KPIs ---- *}]
    <section class="f10d-kpis">
        <div class="f10d-card f10d-hero">
            [{include file="foun10_dashboard_tile.tpl" tileLabel="FOUN10_DASHBOARD_KPI_REVENUE" tileFormat="money" tileKey="revenue" tileHero=true}]
        </div>
        <div class="f10d-card f10d-tiles">
            [{include file="foun10_dashboard_tile.tpl" tileLabel="FOUN10_DASHBOARD_KPI_ORDERS" tileFormat="number" tileKey="orders" tileHero=false}]
            [{include file="foun10_dashboard_tile.tpl" tileLabel="FOUN10_DASHBOARD_KPI_AVERAGE" tileFormat="money" tileKey="average" tileHero=false}]
            [{include file="foun10_dashboard_tile.tpl" tileLabel="FOUN10_DASHBOARD_KPI_NEW_CUSTOMERS" tileFormat="number" tileKey="newCustomers" tileHero=false}]
        </div>
    </section>

    [{* ---- Trend ---- *}]
    <section class="f10d-card">
        <div class="f10d-card-head">
            <div>
                <h2 class="f10d-h2" id="f10d-trend-title"
                    data-title-revenue="[{oxmultilang ident="FOUN10_DASHBOARD_TREND_TITLE"}]"
                    data-title-orders="[{oxmultilang ident="FOUN10_DASHBOARD_TREND_TITLE_ORDERS"}]">[{oxmultilang ident="FOUN10_DASHBOARD_TREND_TITLE"}]</h2>
                <p class="f10d-hint">[{oxmultilang ident="FOUN10_DASHBOARD_TREND_BUCKET_"|cat:$dashboard.trend.bucket|upper}]</p>
            </div>
            <div class="f10d-trend-controls">
                <div class="f10d-trend-switches">
                    <label class="f10d-check">
                        <input type="checkbox" id="f10d-trend-cumulative">
                        [{oxmultilang ident="FOUN10_DASHBOARD_CUMULATIVE"}]
                    </label>
                    <div class="f10d-segmented f10d-segmented--small" role="group" aria-label="[{oxmultilang ident="FOUN10_DASHBOARD_TREND_METRIC"}]">
                        <button type="button" class="f10d-segment is-active" data-metric="revenue" aria-pressed="true">[{oxmultilang ident="FOUN10_DASHBOARD_METRIC_REVENUE"}]</button>
                        <button type="button" class="f10d-segment" data-metric="orders" aria-pressed="false">[{oxmultilang ident="FOUN10_DASHBOARD_KPI_ORDERS"}]</button>
                    </div>
                </div>
                <ul class="f10d-legend">
                    <li><span class="f10d-key f10d-key--current" aria-hidden="true"></span>[{oxmultilang ident="FOUN10_DASHBOARD_SERIES_CURRENT"}]</li>
                    <li><span class="f10d-key f10d-key--last-year" aria-hidden="true"></span>[{oxmultilang ident="FOUN10_DASHBOARD_SERIES_LAST_YEAR"}]</li>
                </ul>
            </div>
        </div>
        <div class="f10d-chart" id="f10d-trend"
             data-label-current="[{oxmultilang ident="FOUN10_DASHBOARD_SERIES_CURRENT"}]"
             data-label-last-year="[{oxmultilang ident="FOUN10_DASHBOARD_SERIES_LAST_YEAR"}]"
             data-label-orders="[{oxmultilang ident="FOUN10_DASHBOARD_ORDERS_SHORT"}]"
             data-label-cumulative="[{oxmultilang ident="FOUN10_DASHBOARD_CUMULATIVE_UNTIL"}]"
             data-label-week="[{oxmultilang ident="FOUN10_DASHBOARD_WEEK_SHORT"}]"
             data-label-date="[{oxmultilang ident="FOUN10_DASHBOARD_TABLE_DATE"}]"
             data-label-revenue="[{oxmultilang ident="FOUN10_DASHBOARD_KPI_REVENUE"}]"></div>
        <details class="f10d-table-toggle">
            <summary>[{oxmultilang ident="FOUN10_DASHBOARD_SHOW_TABLE"}]</summary>
            <div class="f10d-table-scroll" id="f10d-trend-table"></div>
        </details>
        <script type="application/json" id="f10d-trend-data">[{$dashboardChart}]</script>
    </section>

    [{* ---- Breakdowns ---- *}]
    <section class="f10d-grid f10d-grid--3">
        <div class="f10d-card">
            <h2 class="f10d-h2">[{oxmultilang ident="FOUN10_DASHBOARD_CUSTOMER_TYPES"}]</h2>
            <p class="f10d-hint">[{oxmultilang ident="FOUN10_DASHBOARD_CUSTOMER_TYPES_HINT"}]</p>
            [{include file="foun10_dashboard_bars.tpl" barItems=$dashboard.customerTypes barLabelPrefix="FOUN10_DASHBOARD_CUSTOMER_TYPE_"}]
        </div>
        <div class="f10d-card">
            <h2 class="f10d-h2">[{oxmultilang ident="FOUN10_DASHBOARD_PAYMENTS"}]</h2>
            [{include file="foun10_dashboard_bars.tpl" barItems=$dashboard.payments barLabelPrefix=""}]
        </div>
        <div class="f10d-card">
            <h2 class="f10d-h2">[{oxmultilang ident="FOUN10_DASHBOARD_COUNTRIES"}]</h2>
            <p class="f10d-hint">[{oxmultilang ident="FOUN10_DASHBOARD_COUNTRIES_HINT"}]</p>
            [{include file="foun10_dashboard_bars.tpl" barItems=$dashboard.countries barLabelPrefix=""}]
        </div>
    </section>

    <section class="f10d-grid f10d-grid--wide">
        [{* ---- Top sellers ---- *}]
        <div class="f10d-card">
            <h2 class="f10d-h2">[{oxmultilang ident="FOUN10_DASHBOARD_TOP_SELLERS"}]</h2>
            <p class="f10d-hint">[{oxmultilang ident="FOUN10_DASHBOARD_TOP_SELLERS_HINT"}]</p>
            [{if $dashboard.topSellers.items}]
                <div class="f10d-table-scroll">
                    <table class="f10d-table">
                        <thead>
                        <tr>
                            <th class="f10d-num">#</th>
                            <th>[{oxmultilang ident="FOUN10_DASHBOARD_COL_PRODUCT"}]</th>
                            <th class="f10d-num">[{oxmultilang ident="FOUN10_DASHBOARD_COL_QTY"}]</th>
                            <th class="f10d-num">[{oxmultilang ident="FOUN10_DASHBOARD_KPI_REVENUE"}]</th>
                            <th>[{oxmultilang ident="FOUN10_DASHBOARD_COL_STOCK"}]</th>
                        </tr>
                        </thead>
                        <tbody id="f10d-topsellers">
                        [{include file="foun10_dashboard_topseller_rows.tpl" topSellerPage=$dashboard.topSellers}]
                        </tbody>
                    </table>
                </div>
                [{if $dashboard.topSellers.hasMore}]
                    <button type="button" class="f10d-more" id="f10d-topsellers-more"
                            data-url="[{$oView->getAdminLink('foun10_dashboard')}]&fnc=loadtopsellers[{$dashboardPeriodQuery}]"
                            data-offset="[{$dashboard.topSellers.items|@count}]"
                            data-label-loading="[{oxmultilang ident="FOUN10_DASHBOARD_LOADING"}]"
                            data-label-error="[{oxmultilang ident="FOUN10_DASHBOARD_LOAD_ERROR"}]">[{oxmultilang ident="FOUN10_DASHBOARD_SHOW_MORE"}]</button>
                [{/if}]
            [{else}]
                <p class="f10d-empty">[{oxmultilang ident="FOUN10_DASHBOARD_NO_DATA"}]</p>
            [{/if}]
        </div>

        [{* ---- Open baskets (always last 24h, independent of the period filter) ---- *}]
        <div class="f10d-card">
            <div class="f10d-card-head">
                <div>
                    <h2 class="f10d-h2">[{oxmultilang ident="FOUN10_DASHBOARD_BASKETS"}]</h2>
                    <p class="f10d-hint">[{oxmultilang ident="FOUN10_DASHBOARD_BASKETS_HINT"}]</p>
                </div>
            </div>

            [{if !$baskets.available}]
                <p class="f10d-empty">[{oxmultilang ident="FOUN10_DASHBOARD_BASKETS_UNAVAILABLE"}]</p>
            [{else}]
                <div class="f10d-mini-stats">
                    <div>
                        <div class="f10d-mini-label">[{oxmultilang ident="FOUN10_DASHBOARD_BASKETS_OPEN"}]</div>
                        <div class="f10d-mini-value">[{$oView->formatNumber($baskets.open)}]</div>
                        <div class="f10d-muted">[{$oView->formatNumber($baskets.openQty)}] [{oxmultilang ident="FOUN10_DASHBOARD_ITEMS"}]</div>
                    </div>
                    <div>
                        <div class="f10d-mini-label">
                            [{oxmultilang ident="FOUN10_DASHBOARD_BASKETS_VALUE"}]
                            ([{if $baskets.netPrices}][{oxmultilang ident="FOUN10_DASHBOARD_NET"}][{else}][{oxmultilang ident="FOUN10_DASHBOARD_GROSS"}][{/if}])
                        </div>
                        <div class="f10d-mini-value">[{$oView->formatMoney($baskets.openValue, 0)}]</div>
                    </div>
                </div>

                [{if $baskets.list}]
                    <div class="f10d-table-scroll">
                        <table class="f10d-table">
                            <thead>
                            <tr>
                                <th>[{oxmultilang ident="FOUN10_DASHBOARD_COL_CUSTOMER"}]</th>
                                <th class="f10d-num">[{oxmultilang ident="FOUN10_DASHBOARD_COL_QTY"}]</th>
                                <th class="f10d-num">[{oxmultilang ident="FOUN10_DASHBOARD_COL_VALUE"}]</th>
                                <th class="f10d-num">[{oxmultilang ident="FOUN10_DASHBOARD_COL_CHANGED"}]</th>
                            </tr>
                            </thead>
                            <tbody>
                            [{foreach from=$baskets.list item="basket"}]
                                <tr>
                                    <td class="f10d-product">
                                        <a href="[{$oView->getAdminLink('admin_user', $basket.userId)}]" data-nav="admin_user">[{if $basket.name}][{$oView->escape($basket.name)}][{else}][{$oView->escape($basket.email)}][{/if}]</a>
                                        [{if $basket.name}]<span class="f10d-muted">[{$oView->escape($basket.email)}]</span>[{/if}]
                                    </td>
                                    <td class="f10d-num">[{$oView->formatNumber($basket.qty)}]</td>
                                    <td class="f10d-num">[{$oView->formatMoney($basket.value, 0)}]</td>
                                    <td class="f10d-num f10d-muted">[{$oView->formatTimeOrDate($basket.changed)}]</td>
                                </tr>
                            [{/foreach}]
                            </tbody>
                        </table>
                    </div>
                [{else}]
                    <p class="f10d-empty">[{oxmultilang ident="FOUN10_DASHBOARD_BASKETS_NONE"}]</p>
                [{/if}]
            [{/if}]
        </div>
    </section>

    [{* ---- Yearly comparison (all years, independent of the period filter) ---- *}]
    <section class="f10d-card" id="f10d-yearly">
        <div class="f10d-card-head">
            <div>
                <h2 class="f10d-h2">[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_TITLE"}]</h2>
                <p class="f10d-hint">[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_HINT"}]</p>
            </div>
            <div class="f10d-trend-switches">
                <label class="f10d-check">
                    <input type="checkbox" id="f10d-yearly-cumulative">
                    [{oxmultilang ident="FOUN10_DASHBOARD_CUMULATIVE"}]
                </label>
                <div class="f10d-segmented f10d-segmented--small" role="group" aria-label="[{oxmultilang ident="FOUN10_DASHBOARD_TREND_METRIC"}]">
                    <button type="button" class="f10d-segment is-active" data-yearly-metric="revenue" aria-pressed="true">[{oxmultilang ident="FOUN10_DASHBOARD_METRIC_REVENUE"}]</button>
                    <button type="button" class="f10d-segment" data-yearly-metric="orders" aria-pressed="false">[{oxmultilang ident="FOUN10_DASHBOARD_KPI_ORDERS"}]</button>
                </div>
            </div>
        </div>
        <div class="f10d-year-chips" id="f10d-year-chips" role="group"
             aria-label="[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_SELECT"}]"
             data-label-max="[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_MAX"}]"></div>
        <div class="f10d-chart" id="f10d-yearly-chart"
             data-label-orders="[{oxmultilang ident="FOUN10_DASHBOARD_ORDERS_SHORT"}]"
             data-label-to-date="[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_TO_DATE"}]"
             data-label-title="[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_TITLE"}]"
             data-label-year="[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_YEAR"}]"
             data-label-total="[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_TOTAL"}]"
             data-label-best="[{oxmultilang ident="FOUN10_DASHBOARD_YEARLY_BEST"}]"
             data-label-previous-year="[{oxmultilang ident="FOUN10_DASHBOARD_SERIES_LAST_YEAR"}]"
             data-label-cumulative="[{oxmultilang ident="FOUN10_DASHBOARD_CUMULATIVE_UNTIL"}]"></div>
        <details class="f10d-table-toggle">
            <summary>[{oxmultilang ident="FOUN10_DASHBOARD_SHOW_TABLE"}]</summary>
            <div class="f10d-table-scroll" id="f10d-yearly-table"></div>
        </details>
        <script type="application/json" id="f10d-yearly-data">[{$dashboardYearly}]</script>
    </section>
</main>

<div class="f10d-tooltip" id="f10d-tooltip" role="status" hidden></div>
<script src="[{$oView->getAssetUrl('out/src/js/dashboard.js')}]"></script>
</body>
</html>
