[{* KPI tile: value of $current.<tileKey> plus its change vs. the previous period and last year.
   Every include passes tileHero - and $previous is null for the year to date, so it is only
   read inside the check. Anything else is a notice on PHP 7 and a warning on PHP 8. *}]
[{assign var="tileValue" value=$current.$tileKey}]
[{assign var="tileLastYear" value=$lastYear.$tileKey}]
<div class="f10d-tile[{if $tileHero}] f10d-tile--hero[{/if}]">
    <div class="f10d-tile-label">[{oxmultilang ident=$tileLabel}]</div>
    <div class="f10d-tile-value">
        [{if $tileFormat == 'money'}]
            [{$oView->formatMoney($tileValue, 0)}]
        [{else}]
            [{$oView->formatNumber($tileValue)}]
        [{/if}]
    </div>
    <ul class="f10d-deltas">
        [{if $previous}]
            [{assign var="tilePrevious" value=$previous.$tileKey}]
            [{include file="foun10_dashboard_delta.tpl" deltaLabel="FOUN10_DASHBOARD_VS_PREVIOUS" deltaRangeLabel="FOUN10_DASHBOARD_PREVIOUS_PERIOD" deltaRange=$dashboard.ranges.previous deltaCompare=$tilePrevious}]
        [{/if}]
        [{include file="foun10_dashboard_delta.tpl" deltaLabel="FOUN10_DASHBOARD_VS_LAST_YEAR" deltaRangeLabel="FOUN10_DASHBOARD_SERIES_LAST_YEAR" deltaRange=$dashboard.ranges.lastYear deltaCompare=$tileLastYear}]
    </ul>
</div>
