# SmartStock

**A warehouse inventory and demand forecasting system for small-to-mid operations.**

SmartStock records every stock movement as an auditable document, keeps the ledger
balanced by construction, and turns that same history into a monthly demand forecast
with published accuracy metrics.

The interface, validation messages, and domain terminology are in Indonesian
(`Admin Gudang` and `Manager Bisnis`); this README is in English.

<!-- Add screenshots here: dashboard, reorder list, accuracy report, stock entry form. -->

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-6366F1" alt="MIT License"></a>
  <img src="https://img.shields.io/badge/Laravel-12.69-FF2D20" alt="Laravel 12.69">
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4" alt="PHP 8.2">
  <img src="https://img.shields.io/badge/Vue-3.4-42B883" alt="Vue 3">
</p>

---

## Table of contents

- [What it does](#what-it-does)
- [Roles and permissions](#roles-and-permissions)
- [Tech stack](#tech-stack)
- [Installation](#installation)
- [Demo accounts](#demo-accounts)
- [Running the app](#running-the-app)
- [Scheduled jobs](#scheduled-jobs)
- [Testing](#testing)
- [Architecture notes](#architecture-notes)
- [Project layout](#project-layout)

---

## What it does

**Inventory**

- Inbound, outbound, and stock-opname entries. One submission produces **one document
  with many lines**, not one document per product, so a purchase order covering twelve
  SKUs is a single record.
- A live **stock ledger** (`Buku Besar Stok`) with filters for product, movement type,
  date range, and page size. Every row explains where the number came from.
- **Supplier traceability**: the supplier name travels with each inbound line into the
  ledger and the audit trail.
- Full master data for products, categories, and suppliers, including per-supplier
  purchase price and lead time, soft delete with restore, and search.

**Forecasting**

- A nightly ETL job condenses raw movements into daily outbound aggregates, then fits
  an **ordinary least squares linear regression** per product and projects demand for
  the target month.
- The dashboard shows trend history alongside projections, with each bar labelled by
  where its number came from — backtested history, the running month, or projection.
- An **accuracy report** scores past predictions against what actually shipped:
  WAPE, MAE, MAPE, RMSE, and bias, plus per-product error breakdown and a month-over-month
  trend.
- **Reorder monitoring** ranks products by urgency — out of stock first, then stock that
  will run out before a resupply can arrive — with a suggested order quantity and days
  of cover for each.

**Operations**

- Low-stock notifications delivered to the in-app bell, debounced so one product does
  not generate a stream of identical alerts.
- A full audit trail of every write, filterable by action.
- CSV export for both the reorder list and the accuracy report.
- Role-based access control down to individual form values, not just pages.

---

## Roles and permissions

| Action | Admin Gudang | Manager Bisnis |
|---|:---:|:---:|
| Read products, categories, suppliers | yes | yes |
| Read the stock ledger | yes | yes |
| Read the audit trail | yes | yes |
| Write master data | yes | yes |
| **Record stock movements** | **yes** | **no** |
| Open the stock entry form | yes | no |
| Analytics dashboard, reorder, accuracy, CSV export | no | yes |

A manager can open the analytics dashboard but cannot produce a stock movement. That
boundary is enforced **by value, not only by route**: the product form is writable by
both roles, so the request validator is what refuses a non-zero opening stock for a
manager, and the form does not render fields that would be rejected. A form that cannot
be saved is a trap — a user who fills it in correctly and receives a bare `403` has
nowhere to go.

---

## Tech stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12.69, PHP 8.2 |
| Frontend | Inertia.js 2 + Vue 3 (single-page, no separate API) |
| Styling | Tailwind CSS 3 |
| Build | Vite 6 |
| Auth | Laravel Breeze (Inertia stack) |
| Database | **MySQL** (the test suite runs on in-memory SQLite) |
| Charting | Hand-rolled SVG — no chart dependency |
| Icons | Inline SVG |
| Testing | PHPUnit 11 + Pest-style assertions |

---

## Installation

**Requirements:** PHP 8.2+ (with `intl` and `pdo_mysql`), Composer 2, Node 18+, and a
running MySQL server.

```bash
git clone https://github.com/<your-username>/smartstock.git
cd smartstock

composer install
npm install

cp .env.example .env
php artisan key:generate
```

**Configure the database.** `.env.example` is already set to MySQL. Adjust the
credentials if yours differ:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartstock
DB_USERNAME=root
DB_PASSWORD=
```

Create the database, then build it with demo data:

```bash
php artisan migrate:fresh --seed
```

The seeder fills 120 days of movement history, runs the ETL jobs for real, and produces
enough data to exercise every screen. It is safe to re-run.

> If you only want the schema, use `php artisan migrate` instead of
> `migrate:fresh --seed`.

---

## Demo accounts

All demo accounts use the password `password`.

| Email | Role |
|---|---|
| `admin@smartstock.test` | Admin Gudang |
| `operator@smartstock.test` | Admin Gudang |
| `manager@smartstock.test` | Manager Bisnis |
| `pending@smartstock.test` | Unverified email (to see the verification screen) |

Log in as each role in turn: the sidebar items differ, the dashboard is different, and
the product form behaves differently.

---

## Running the app

```bash
composer dev
```

That starts four processes at once — web server, queue worker, log viewer, and Vite.

Individually:

```bash
php artisan serve        # HTTP server
npm run dev              # Vite dev server with HMR
npm run build            # production assets
php artisan queue:listen # background jobs (notifications, ETL)
php artisan pail         # tail the log
```

---

## Scheduled jobs

One scheduled task, defined in `routes/console.php`:

```
smartstock:nightly-etl    daily at 00:00    withoutOverlapping() + onOneServer()
```

It chains two jobs, in this order:

1. `AggregateDailyOutboundJob` — condenses movements into daily outbound aggregates.
   Idempotent: re-running it corrects rows rather than duplicating them.
2. `CalculateMonthlyPredictionJob` — fits the regression per product and stores the
   monthly forecast.

The two are chained with `Bus::chain()` rather than scheduled as two separate events,
because ordering must be guaranteed and both are generated at the same minute.

```bash
php artisan schedule:list    # inspect
php artisan schedule:run     # trigger now
```

---

## Testing

```bash
vendor/bin/phpunit                                   # whole suite (505 tests)
vendor/bin/phpunit --filter=StockRecordingAuthorityTest   # one test
```

The suite runs against an **in-memory SQLite** database, so it never touches your
development MySQL instance. It covers authorization boundaries, ledger invariants,
date handling, the regression maths, seeder output, and the frontend source files.

Two tests are worth calling out because they guard bugs that the build cannot see:

- `VueTemplateBindingTest` and `VueScriptScopeTest` parse every `.vue` file. Vite only
  transpiles, so an unbound identifier or a missing import stays silent through
  `npm run build` and fails in the browser at runtime. These fail the build instead.
- `SourceEncodingTest` maintains an allowlist of every non-ASCII character in the
  source tree. Mojibake has repeatedly slipped in through copy-paste and machine
  translation, and a blacklist would not have caught the cases that had not happened yet.

---

## Architecture notes

A few decisions that shaped the codebase:

**Stock changes have exactly one door.** Every mutation — inbound, outbound, opname,
including opening stock from the product form — goes through `InventoryMovementService`,
inside a single transaction with the product row locked for update. The invariant
`SUM(inventory_movements.quantity) == products.current_stock` is therefore structural,
not something each call site has to remember. If you find a path that writes stock
without that service, it is a bug by definition.

**Documents are whole.** If line eight of a document cannot be fulfilled, the entire
document fails and nothing is written. A partially received purchase order is recorded
as a partial receipt, not as a failed document.

**Headers and details are separated.** `inventory_transactions` holds the document;
`inventory_movements` holds its lines. Movement type, recorder, and supplier moved to
the header. The old columns were dropped rather than emptied — a relation that
outlives its column keeps returning `null` and renders a page that looks right while
showing blanks.

**The ledger is read-only for managers, and that is deliberate.** It is the audit
trail. Without it, "stock went up by forty and there is no purchase order, why?" has no
answer. What is closed is the entry form, not the record.

**Predictions include the running month.** Forecasts are computed from history up to
the last day of the previous month and projected across the whole target month, so no
future data leaks in and re-running the same day returns the same numbers. The current
month is predicted too; skipping it left a permanent gap in the dashboard.

**Scoring rule:** only fully elapsed months are scored in the accuracy report.
Scoring a partial month makes actual demand look artificially low, so every forecast
looks like an under-prediction and the report measures the calendar instead of the
algorithm.

---

## Project layout

```
app/
  Http/Controllers/      thin; return Inertia responses only
  Http/Middleware/       role gate + shared Inertia props
  Http/Requests/         validation, including per-role rules
  Jobs/                  nightly ETL
  Notifications/         low stock alerts
  Services/              all business logic
    Support/             immutable DTOs
resources/
  js/Components/         reusable UI (SearchableSelect, LedgerTable, BrandLogo, ...)
  js/Layouts/            AppLayout (sidebar shell), GuestLayout (auth shell)
  js/Pages/              one directory per module
config/navigation.php    single source of truth for the menu
tools/                   favicon generation
tests/js/                source-level frontend guards
```

---

## License

MIT — see [LICENSE](LICENSE).
