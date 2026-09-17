[{* One delta line of a KPI tile. For all KPIs shown, up is good. *}]
[{assign var="deltaChange" value=$oView->getChange($tileValue, $deltaCompare)}]
[{assign var="deltaDirection" value=$oView->getChangeDirection($deltaChange)}]
[{assign var="deltaTone" value="flat"}]
[{if $deltaDirection == 'up'}]
    [{assign var="deltaTone" value="good"}]
[{elseif $deltaDirection != 'flat'}]
    [{assign var="deltaTone" value="bad"}]
[{/if}]
<li class="f10d-delta f10d-delta--[{$deltaTone}]"
    data-tip="[{oxmultilang ident=$deltaRangeLabel}] [{$oView->formatRange($deltaRange)}]: [{if $tileFormat == 'money'}][{$oView->formatMoney($deltaCompare, 0)}][{else}][{$oView->formatNumber($deltaCompare)}][{/if}]"
    tabindex="0">
    <span class="f10d-delta-icon" aria-hidden="true">[{if $deltaDirection == 'up'}]▲[{elseif $deltaDirection == 'down'}]▼[{else}]•[{/if}]</span>
    <span class="f10d-delta-value">[{$oView->formatChange($deltaChange)}]</span>
    <span class="f10d-delta-label">[{oxmultilang ident=$deltaLabel}]</span>
</li>
