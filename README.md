# Sales Dashboard

[![CI b-7.x](https://img.shields.io/github/actions/workflow/status/foun10/dashboard-oxid/ci.yml?branch=b-7.x&label=CI%20b-7.x)](https://github.com/foun10/dashboard-oxid/actions/workflows/ci.yml?query=branch%3Ab-7.x)
[![CI b-6.x](https://img.shields.io/github/actions/workflow/status/foun10/dashboard-oxid/ci.yml?branch=b-6.x&label=CI%20b-6.x)](https://github.com/foun10/dashboard-oxid/actions/workflows/ci.yml?query=branch%3Ab-6.x)
[![Latest Release](https://img.shields.io/github/v/release/foun10/dashboard-oxid?sort=semver)](https://github.com/foun10/dashboard-oxid/releases)
[![PHP](https://img.shields.io/badge/PHP-%5E8.0-777BB4?logo=php&logoColor=white)](#compatibility)
[![OXID eShop](https://img.shields.io/badge/OXID%20eShop-7.0%20%E2%80%93%207.5-e30613)](#compatibility)
[![License](https://img.shields.io/badge/license-GPL--3.0--only-blue)](LICENSE)

> Replaces the OXID eShop admin start page with a sales dashboard: revenue, orders and new
> customers compared with the previous period and the same period last year, a revenue trend,
> where the revenue comes from, what sells and which baskets are still open.

---

## Compatibility

| Module version | Branch | OXID eShop | Template engine |
|---|---|---|---|
| 7.x | [`b-7.x`](https://github.com/foun10/dashboard-oxid/tree/b-7.x) | 7.0 – 7.5 | Twig |
| 6.x | [`b-6.x`](https://github.com/foun10/dashboard-oxid/tree/b-6.x) | 6.2 – 6.5 | Smarty |

Composer resolves the right line for your shop automatically.

### Tested combinations

Every row below is installed from scratch and exercised by the full test suite on every push.
This is not a statement of intent — if a combination is listed here, CI proves it.

<!-- ci-matrix:start -->

| OXID eShop | PHP |
|---|---|
| 7.0 | 8.0, 8.1 |
| 7.1 | 8.1, 8.2 |
| 7.2 | 8.2, 8.3 |
| 7.3 | 8.2, 8.3, 8.4 |
| 7.4 | 8.2, 8.3, 8.4 |
| 7.5 | 8.3, 8.4, 8.5 |

<!-- ci-matrix:end -->

## Features

- **The admin opens on your numbers.** Right after login, and whenever you click *Home* in the
  admin header, you see the dashboard instead of the menu overview. OXID's own start-up warnings
  (setup directory still present, writable `config.inc.php`, system health, update notice) are
  still shown — at the top of the dashboard.
- **Every figure compared, not just reported.** Net revenue, orders, average order value and new
  customer accounts for today, the last 7, 30 or 90 days, the year to date or any date range —
  each against the equally long period before and the same period last year. The comparison
  always covers the same span of the day, so a half-finished today is not compared with a full
  yesterday.
- **Revenue trend with last year underneath**, per hour, day, calendar week or month depending on
  the range, switchable to order counts, optionally cumulative, and available as a table.
- **Where the revenue comes from:** returning customers vs. new customers with an account vs.
  guests, payment methods and delivery countries.
- **What sells, and whether you can keep selling it.** Top sellers by quantity with variants
  combined, their current stock, and a warning when single variants are already sold out even
  though the total looks healthy. Links go straight to the product in the admin and in the shop.
- **Open baskets of the last 24 hours** from logged-in customers, with their value, and a yearly
  comparison of all years month by month.

### What "revenue" means here

The dashboard shows the **net goods value**: after discounts and vouchers, without shipping,
payment, wrapping and gift card costs, and without VAT — VAT-free export orders included
correctly. Orders in other currencies are converted into the shop's default currency with the
exchange rate stored on the order.

Only **completed** orders count: cancelled orders are excluded, and so are orders whose
transaction never finished (`OXTRANSSTATUS` other than `OK` — for example orders abandoned at a
payment provider or failed payments). Cancelled single items are left out of the top sellers.

A customer counts as **returning** when the same billing e-mail address has an earlier completed
order — so guests who come back count as returning too.

## Installation

```bash
composer require foun10/dashboard
```

Then activate the module:

```bash
vendor/bin/oe-console oe:module:activate foun10Dashboard
```

That is all. The next time you open the admin, it starts on the dashboard.

## Configuration

*Extensions → Modules → foun10 - Sales Dashboard → Settings*

| Setting | Default | Description |
|---|---|---|
| Cache lifetime of the figures in seconds | `600` | The figures are cached per period, shop and language, because the start page is opened often and order tables grow large. *Refresh* on the dashboard bypasses the cache at any time. |

The open baskets depend on the shop setting *Master Settings → Core Settings → Perform. →
Don't save Shopping Carts of registered Users*. With it switched on there are no saved baskets to
show, and the dashboard says so.

## Good to know

- **Guest baskets are not visible.** OXID keeps them in the session only. The open-baskets list
  shows saved baskets of logged-in customers. OXID deletes a saved basket when it is ordered, so
  everything listed is still open. Its value is based on the products' current base price.
- **Large shops:** the customer-type breakdown and the yearly comparison each read all completed
  orders in one grouped query. Both are cached, but the first load after the cache expires does
  the work. If that is too slow for your order volume, raise the cache lifetime.
- **Multiple shops (EE):** all figures are for the shop selected in the admin. Open baskets are
  assigned through their customer's shop — unless customer accounts are shared between shops
  (`blMallUsers`), where OXID uses one saved basket everywhere and the list shows all of them.
  CI covers the Community Edition only.
- **Other modules that add content to the admin home page** (`home.html.twig`) are not shown
  anymore, because the dashboard replaces that page.

## Development & Testing

```bash
# Unit tests (no shop required)
composer tests-unit

# Integration tests (require an installed OXID eShop with the module activated)
composer tests-integration

# Mutation testing (Infection, PHP 8.2+)
composer tests-mutation
```

The CI runs these same commands against every supported OXID/PHP combination –
see [.github/workflows/ci.yml](.github/workflows/ci.yml).

The integration tests write their own orders, customers, articles and baskets (dated March 2011,
IDs prefixed `f10dit`) into the shop database and remove them again afterwards. Run them against
a test shop, not a live one.

## Honest opinion

We built this module for our own shops. Here is our honest take on when it is the right choice —
and when it is not:

| :white_check_mark: Use it when... | :x: Look elsewhere when... |
|-----------------------------------|----------------------------|
| You don't find the standard OXID admin home page useful | Your figures already live elsewhere — analytics, ERP, BI — and that is where you look |
| You want a quick overview of your orders every time you open the admin | You would rather not have sales figures this prominent in the backend, for example because many people with admin access should not see them first thing |
| You want today's numbers next to the historical ones, without exports | You need gross revenue, per-channel attribution or marketing metrics — this module deliberately reports net goods revenue from the order table only |

## Deutsche Kurzbeschreibung

Das Modul ersetzt die Startseite des OXID-Admin-Bereichs durch ein Umsatz-Dashboard: Netto-Umsatz,
Bestellungen, durchschnittlicher Bestellwert und neue Kundenkonten im Vergleich zur Vorperiode und
zum Vorjahr, Umsatzverlauf, Neu- und Stammkunden, Zahlungsarten, Lieferländer, Topseller mit
Lagerbestand und offene Warenkörbe. Version 6.x ist für OXID 6.2 – 6.5, Version 7.x für OXID 7.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[GPL-3.0-only](LICENSE). You may use this module commercially, including in customer projects.
If you redistribute it, or a derivative of it, that has to happen under the GPL as well.

## Like this module?

If this module saves you time, a ⭐ on this repository genuinely makes our day — and helps other
OXID developers find it.

Found a bug or missing a feature? Open an
[issue](https://github.com/foun10/dashboard-oxid/issues) — we read them.

And if you need a hand with this module or are wrestling with other OXID eShop challenges, feel
free to reach out at [foun10.de](https://www.foun10.de).
