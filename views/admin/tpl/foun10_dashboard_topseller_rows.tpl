[{* Top seller table rows ($topSellerPage from DashboardData::getTopSellers) - first page and "show more" pages. *}]
[{foreach from=$topSellerPage.items item="product" name="topSellers"}]
    <tr>
        <td class="f10d-num f10d-muted">[{$topSellerPage.offset+$smarty.foreach.topSellers.iteration}]</td>
        <td class="f10d-product">
            <span class="f10d-product-title">
                [{if $product.exists}]
                    <a href="[{$oView->getAdminLink('admin_article', $product.id)}]" data-nav="admin_article">[{$oView->escape($product.title)}]</a>
                [{else}]
                    [{$oView->escape($product.title)}]
                [{/if}]
                [{if $product.shopUrl}]
                    [{capture assign="shopLinkLabel"}][{oxmultilang ident="FOUN10_DASHBOARD_OPEN_IN_SHOP"}][{/capture}]
                    <a class="f10d-shop-link" href="[{$oView->escape($product.shopUrl)}]" target="_blank" rel="noopener"
                       title="[{$shopLinkLabel}]" aria-label="[{$shopLinkLabel}]: [{$oView->escape($product.title)}]">
                        <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true" focusable="false">
                            <path d="M9 2h5v5M14 2 7.5 8.5M12 9.5V13a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3.5"
                                  fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                [{/if}]
            </span>
            <span class="f10d-muted">[{$oView->escape($product.artnum)}][{if $product.exists && !$product.active}] · [{oxmultilang ident="FOUN10_DASHBOARD_INACTIVE"}][{/if}]</span>
        </td>
        <td class="f10d-num">[{$oView->formatNumber($product.qty)}]</td>
        <td class="f10d-num">[{$oView->formatMoney($product.revenue, 0)}]</td>
        <td>
            [{if !$product.exists}]
                <span class="f10d-muted">[{oxmultilang ident="FOUN10_DASHBOARD_DELETED"}]</span>
            [{else}]
                <span class="f10d-status f10d-status--[{$product.stockStatus}]">
                    <span class="f10d-status-icon" aria-hidden="true">[{if $product.stockStatus == 'critical'}]✕[{elseif $product.stockStatus == 'warning'}]![{else}]✓[{/if}]</span>
                    [{if $product.stockStatus == 'critical'}]
                        [{oxmultilang ident="FOUN10_DASHBOARD_STOCK_SOLD_OUT"}]
                    [{else}]
                        [{$oView->formatNumber($product.stock)}] [{oxmultilang ident="FOUN10_DASHBOARD_STOCK_PIECES"}]
                    [{/if}]
                </span>
                [{if $product.soldOutVariants > 0 && $product.stockStatus != 'critical'}]
                    <span class="f10d-muted f10d-block">[{$product.soldOutVariants}]/[{$product.variants}] [{oxmultilang ident="FOUN10_DASHBOARD_VARIANTS_SOLD_OUT"}]</span>
                [{/if}]
            [{/if}]
        </td>
    </tr>
[{/foreach}]
