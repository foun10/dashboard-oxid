# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

Each OXID line has its own release series: `7.x` on the `b-7.x` branch for OXID 7, `6.x` on the
`b-6.x` branch for OXID 6 - the major version tracks the OXID line it targets, not a generation
of the module. The two are developed in parallel, so a fix usually appears in both.

## [6.0.0] - 2026-09-17

First public release for OXID 6. The module existed internally before this; the public history
and the version numbering start here.

### Added

- The admin start page - after login and behind the *Home* link in the admin header - shows a
  sales dashboard instead of the menu overview. OXID's start-up checks still run, and their
  warnings appear at the top of the dashboard.
- Net revenue, orders, average order value and new customer accounts for today, the last 7, 30
  or 90 days, the year to date or a custom date range, each compared with the previous period and
  the same period last year.
- A revenue and order trend per hour, day, ISO calendar week or month with last year underneath,
  optionally cumulative and viewable as a table.
- Breakdowns by customer type (returning, new with account, guest - by billing e-mail), payment
  method and delivery country, with the long tail collapsed into one row.
- Top sellers by quantity with variants combined, current stock, sold-out variant warnings and
  links to the product in the admin and in the shop, paged with a "show more" button.
- Open baskets of logged-in customers from the last 24 hours with their value.
- A yearly comparison of all years, month by month.
- A configurable cache lifetime for the figures and a refresh button that bypasses it.

### Known limitations

- Guest baskets are kept in the session by OXID and cannot be listed.
- The customer-type breakdown and the yearly comparison read all completed orders in one grouped
  query each. Both are cached, but on very large order tables the first load after the cache
  expires is noticeable.
- Content other modules add to the admin home page (`home.tpl`) is no longer shown.
