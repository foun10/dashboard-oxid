[{* Horizontal revenue bars, scaled to the largest entry; one colour, share printed as text.
   The tail beyond the top entries sits collapsed behind an "other" summary row. *}]
[{if $barItems.visible}]
    <ul class="f10d-bars">
        [{foreach from=$barItems.visible item="barItem"}]
            [{include file="foun10_dashboard_bar_row.tpl"}]
        [{/foreach}]
    </ul>
    [{if $barItems.other}]
        <details class="f10d-bars-more">
            <summary class="f10d-bar-row"
                     data-tip="[{oxmultilang ident="FOUN10_DASHBOARD_OTHER"}] · [{oxmultilang ident="FOUN10_DASHBOARD_KPI_AVERAGE"}] [{$oView->formatAverage($barItems.other.revenue, $barItems.other.orders)}]">
                <div class="f10d-bar-text">
                    <span class="f10d-bar-label">
                        <span class="f10d-bars-more-icon" aria-hidden="true">▸</span>
                        [{oxmultilang ident="FOUN10_DASHBOARD_OTHER"}] ([{$barItems.other.count}])
                    </span>
                    <span class="f10d-bar-value"><span class="f10d-bar-orders">[{$oView->formatNumber($barItems.other.orders)}] [{oxmultilang ident="FOUN10_DASHBOARD_ORDERS_ABBR"}]</span> [{$oView->formatMoney($barItems.other.revenue, 0)}] <span class="f10d-muted f10d-bar-share">[{$oView->formatPercent($barItems.other.share, 0)}]</span></span>
                </div>
                <div class="f10d-bar-track">
                    <span class="f10d-bar f10d-bar--other" style="width: [{$oView->getBarWidth($barItems.other.revenue, $barItems.max)}]%"></span>
                </div>
            </summary>
            <ul class="f10d-bars">
                [{foreach from=$barItems.more item="barItem"}]
                    [{include file="foun10_dashboard_bar_row.tpl"}]
                [{/foreach}]
            </ul>
        </details>
    [{/if}]
[{else}]
    <p class="f10d-empty">[{oxmultilang ident="FOUN10_DASHBOARD_NO_DATA"}]</p>
[{/if}]
