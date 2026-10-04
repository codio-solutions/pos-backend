# POS Backend — Complete Setup (Phases 1–4)

Covers: POS core, website order webhook, employee shifts + accounts, and
visit logging with product ordering. Every file below is meant to be
**copied over whole** — nothing in this guide asks you to hand-edit a
generated file and insert a snippet, which is what caused the config drift
in earlier rounds of this build.

## 1. Create the project

```bash
composer create-project laravel/laravel pos-backend
cd pos-backend
composer require laravel/sanctum
php artisan install:api
composer show laravel/sanctum      # must NOT say "not found" — stop and fix if it does
```

## 2. Copy these files over, replacing whatever Laravel generated

| From this zip | Goes to | Notes |
|---|---|---|
| `bootstrap/app.php` | `bootstrap/app.php` | **Replace whole file.** Registers the `role` middleware alias. |
| `config/services.php` | `config/services.php` | **Replace whole file.** Has the website webhook secret + frontend URL blocks already in it. |
| `config/cors.php` | `config/cors.php` | **New file** (Laravel 11+ doesn't generate this by default) — just copy it in. |
| `app/Models/*` | `app/Models/` | Replace all. `User.php` here has `HasApiTokens` + `role`/`phone` in `$fillable` — this was the source of two earlier bugs. |
| `app/Http/Middleware/*` | `app/Http/Middleware/` | New folder — `EnsureUserHasRole.php`. |
| `app/Http/Controllers/Api/*` | `app/Http/Controllers/Api/` | Create this folder, copy all 8 controllers in. |
| `app/Mail/*` | `app/Mail/` | New folder — `EmployeeWelcomeMail.php`. |
| `resources/views/emails/*` | `resources/views/emails/` | New folder — the welcome email template. |
| `database/migrations/*` | `database/migrations/` | All 9 migrations. |
| `database/seeders/*` | `database/seeders/` | `AdminUserSeeder.php`, `EmployeeUserSeeder.php`. |
| `routes/api.php` | `routes/api.php` | **Replace whole file.** |

## 3. Environment

Add to `.env`:
```env
DB_CONNECTION=sqlite

WEBSITE_WEBHOOK_SECRET=change-this-to-something-random
FRONTEND_URL=http://localhost:3000

MAIL_MAILER=log
```

`MAIL_MAILER=log` writes emails to `storage/logs/laravel.log` instead of
sending — fine for now, see "Mail" below for a real inbox.

```bash
touch database/database.sqlite
```

## 4. Migrate and seed

```bash
composer dump-autoload
php artisan config:clear
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
php artisan db:seed --class=EmployeeUserSeeder
```

## 5. Run it

```bash
php artisan storage:link
php artisan serve
```

`storage:link` is required now — visit photo proof (Phase 4 extension)
is saved to `storage/app/public/visits` and served via a symlink at
`public/storage`. Without this, uploaded photos save fine but their URLs
404 when the frontend tries to display them.

Backend is at `http://localhost:8000`. Pair it with the `pos-dashboard`
frontend running on `http://localhost:3000` (`npm run dev`).

## Login

```
Admin:    admin@khanenterprises.test    / change-this-password
Employee: rep1@khanenterprises.test     / change-this-password
```

## If something still 500s

```bash
tail -50 storage/logs/laravel.log
```

This is the fastest way to see the *actual* exception — the browser's
"Request failed (500)" message deliberately hides the real reason. Common
causes, in order of likelihood:
1. `bootstrap/app.php` wasn't replaced → `role` middleware not registered → "Target class [role] does not exist"
2. `User.php` wasn't replaced → `Call to undefined method createToken()`, or a `MassAssignmentException` on `role`/`phone`
3. `config/services.php` wasn't replaced → `frontend.url` or `website.webhook_secret` resolve to `null`

## API reference

| Method | Path | Role | Purpose |
|---|---|---|---|
| POST | `/login` | — | Get a token |
| POST | `/logout` | any | Revoke current token |
| GET | `/me` | any | Current user |
| GET | `/products` | any | Product list (read-only for employees — the visit form needs it) |
| POST/PUT/DELETE | `/products...` | admin | Manage products |
| GET/POST | `/purchases`, `/purchase-returns` | admin | Purchasing |
| GET/POST | `/orders`, `/sale-returns` | admin | POS selling, unified order view |
| POST | `/orders/webhook` | signature | Website → POS integration |
| GET | `/reports/sales-and-stock` | admin | Stock report |
| POST | `/shifts/check-in`, `/shifts/check-out` | any | Employee shift timer |
| GET | `/shifts/current` | any | Own open shift |
| GET | `/shifts/mine` | any | Own shift history + stats (My Hours) |
| GET | `/shifts`, PATCH `/shifts/{id}/close` | admin | Working hours log |
| GET/POST | `/employees` | admin | Create field rep accounts (emails credentials) |
| POST | `/employees/{id}/reset-password` | admin | Generate + email a new password |
| POST | `/visits` | any | Employee submits a visit — multipart/form-data, requires doctor_photo + building_photo |
| GET | `/visits/mine` | any | Own visit history (My Visits) |
| GET | `/visits` | admin | Cross-employee visit log |

## Mail (employee welcome emails)

For a real test inbox instead of the log file, [Mailtrap](https://mailtrap.io)
(free tier) is the easiest option:
```env
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=<from mailtrap>
MAIL_PASSWORD=<from mailtrap>
```
If sending fails for any reason, employee creation still succeeds and the
API returns the plaintext password once for the admin to share manually —
see `EmployeeController::store`.

## Design notes

- **Stock is never a stored number** — always derived by summing
  `stock_movements`. Sign convention: `purchase`/`sale_return` add (+),
  `sale`/`purchase_return` subtract (-).
- **`Order` is the single entry point for every sale** regardless of
  source (`pos`, `website`, `visit`) — `Order::complete()` writes the
  stock movements and is idempotent.
- **Visit → Order**: when a visit includes `items`, `VisitController`
  creates both a `visit_order_items` row (the visit's own record) and an
  `orders` row (source: `visit`) through the same completion pipeline as
  every other sale — so it shows up in the POS Orders page and the stock
  report exactly like a website or in-store sale would.
- **Role enforcement is server-side** (`role:admin` middleware) — the
  frontend hiding nav items is a UX nicety, not the actual security
  boundary.
