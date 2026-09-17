[{* One bar of foun10_dashboard_bars.tpl ($barItem, $barItems, $barLabelPrefix). *}]
[{if $barLabelPrefix}]
    [{capture assign="barLabel"}][{oxmultilang ident=$barLabelPrefix|cat:$barItem.id|upper}][{/capture}]
[{elseif $barItem.label}]
    [{assign var="barLabel" value=$oView->escape($barItem.label)}]
[{else}]
    [{capture assign="barLabel"}][{oxmultilang ident="FOUN10_DASHBOARD_UNKNOWN"}][{/capture}]
[{/if}]
<li class="f10d-bar-row" tabindex="0"
    data-tip="[{$barLabel}] · [{oxmultilang ident="FOUN10_DASHBOARD_KPI_AVERAGE"}] [{$oView->formatAverage($barItem.revenue, $barItem.orders)}]">
    <div class="f10d-bar-text">
        <span class="f10d-bar-label">[{$barLabel}]</span>
        <span class="f10d-bar-value"><span class="f10d-bar-orders">[{$oView->formatNumber($barItem.orders)}] [{oxmultilang ident="FOUN10_DASHBOARD_ORDERS_ABBR"}]</span> [{$oView->formatMoney($barItem.revenue, 0)}] <span class="f10d-muted f10d-bar-share">[{$oView->formatPercent($barItem.share, 0)}]</span></span>
    </div>
    <div class="f10d-bar-track">
        <span class="f10d-bar" style="width: [{$oView->getBarWidth($barItem.revenue, $barItems.max)}]%"></span>
    </div>
</li>
