# HESB Digital Center

Weekly financial flash portal for the HESB group. Each company submits weekly actuals and
forecasts. HESB Admin sets the Annual Management Plan (AMP), and everyone sees performance
dashboards against plan.

Laravel 13 · PHP 8.3 · MySQL/MariaDB · Blade + Tailwind CSS 4 · Chart.js. No SPA, no queue
worker, no external APIs.

---

## Roles

| Role | Sees | Can change |
|---|---|---|
| `company_user` | Only their own company (derived from their account, never from the URL or form) | Weekly actuals and forecasts, custom rows, Excel uploads for their company |
| `hesb_admin` | Everything | Everything: entries for any company, AMP, period locks, companies, users, exports |
| `ceo` | Every dashboard, comparison, tracker, grid and AMP | Nothing. No edit controls are rendered, and every write route returns 403 |

Access is enforced three times: route middleware (`role:`), `CompanyPolicy` / gates, and the
service layer (line ownership, locks). Tests in `tests/Feature/AccessControlTest.php`,
`EntryAccessTest.php` and `AdminTest.php` check this by direct URL, forged form fields and
JSON requests.

## How the numbers work

- **Weeks:** Wk1 = days 1-7, Wk2 = 8-14, Wk3 = 15-21, Wk4 = 22-28, Wk5 = 29 to month end.
  February has 4 weeks in non-leap years.
- **Input lines:** Revenue, Direct Materials, Direct Labor, Direct Overhead, Depre. & Amort,
  Others Fixed Overhead, Admin Exp, Selling & Distr. Cost, Other Income/Expenses, Finance Cost,
  plus any "+ Add Row" custom lines. Each custom line rolls into the subtotal you pick.
- **Derived lines:** computed, never stored. The single source of truth is
  `app/Services/Finance/FinancialCalculator.php`.
  - Total Revenue = Σ revenue lines
  - Cost of Sales = Direct Materials + Direct Labor + Direct Overhead + Depre. & Amort + Others Fixed Overhead (+ custom COS lines)
  - Gross Profit = Total Revenue − Cost of Sales
  - Profit Before Tax = Gross Profit − Admin Exp − Selling & Distr. Cost + Other Income/Expenses − Finance Cost
  - Gross Margin = Gross Profit ÷ Total Revenue
- **Signs:** costs are keyed in as positive numbers and shown in brackets. Other
  Income/Expenses is signed (+ income, − expense).
- **Precision:** all amounts are `DECIMAL(15,2)` and use `bcmath` decimal strings in PHP. The
  browser's live recalculation (`resources/js/money.js`) mirrors the PHP rules with BigInt
  cents. The server recomputes on every save.
- **Var** = Σ Actual − Σ Forecast for the month. **Var %** is relative to Σ Forecast.
- **"vs AMP" on dashboards** compares year-to-date actuals with the AMP for the weeks that have
  finished. Past months count in full, and the current month counts completed weeks ÷ weeks
  in the month.
- **Financial flash** shows the last finished week's actual and the forecasts for the next two
  weeks. A missing forecast shows a *Forecast Submission Pending* badge.
- **Period locks:** a locked month is read-only for everyone, uploads included. A lock applies
  to all companies, and a per-company setting can override it.

## Excel workflow

On the *Forecast & Actual* page:

1. **Excel template** downloads a workbook for that company and month. It's pre-filled with
   saved values, the derived rows are live Excel formulas, and a hidden sheet identifies the
   company and month.
2. Fill it in, then use **Upload Excel**. Values are *staged* into the grid and highlighted,
   so the user can check them and press **Save**. Blank cells leave existing values alone.
3. A template for another company or another month is rejected. Plain CSV or Excel sheets
   also work if their headers look like `Wk1 Act`, `Wk1 Fc`, … and rows use the line names.

AMP works the same way, with lines down the side and Jan to Dec across the top.

## Local setup

Requirements: PHP 8.3+ with `bcmath`, `intl`, `mbstring`, `pdo_mysql` (or `pdo_sqlite`),
`zip`, `gd`, `fileinfo`; Composer 2; Node 20+ (only if you change CSS/JS).

```bash
git clone <repo> hesb-digital-center && cd hesb-digital-center
composer install
cp .env.example .env
php artisan key:generate

# MySQL: create the database and user named in .env, then:
php artisan migrate --seed

# …or quick trial on SQLite:
#   set DB_CONNECTION=sqlite in .env and remove the other DB_* lines
#   touch database/database.sqlite && php artisan migrate --seed

php artisan serve          # http://localhost:8000
npm install && npm run dev # only when editing CSS/JS
```

### Demo accounts (seeded; password `password`)

| Email | Role |
|---|---|
| `admin@hesb.test` | HESB Admin |
| `ceo@hesb.test` | CEO (read-only) |
| `hauto@hesb.test`, `hdc@hesb.test`, `phn@hesb.test`, `hts@hesb.test`, `heng@hesb.test` | One user per company |

The seed loads deterministic sample data for 2026: AMP for every month, actuals up to the last
finished week, and forecasts ahead. HICOM Engineering has deliberately not submitted its
forecasts, so the pending badges and advisory have something to show. **Change or remove
these accounts before going live.**

### Tests

```bash
php artisan test
```

Unit tests cover the calculator, money parsing and formatting, and weeks. Feature tests cover
authentication and rate limiting, tenant isolation, CEO read-only, the grid, Excel round
trips, dashboards, admin screens and exports.

## Deployment

### cPanel (shared hosting)

1. Create a MySQL database and user in cPanel, with all privileges on that database.
2. Upload the project (including `vendor/` if the host has no SSH/Composer, and the committed
   `public/build/`) to a folder **outside** `public_html`, e.g. `~/hesb`.
3. Point the domain or subdomain's document root to `~/hesb/public`. If that isn't possible,
   copy the contents of `public/` into `public_html` and change the two `require` paths in
   `public_html/index.php` to `../hesb/vendor/autoload.php` and `../hesb/bootstrap/app.php`.
4. In *Select PHP Version*, choose PHP 8.3 and enable `bcmath`, `intl`, `mbstring`,
   `pdo_mysql`, `zip`, `gd`, `fileinfo`.
5. Create `.env` from `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, your
   `APP_URL` (https), DB credentials, SMTP settings, `SESSION_SECURE_COOKIE=true`.
6. Using cPanel Terminal or SSH:
   ```bash
   php artisan key:generate --force
   php artisan migrate --force
   php artisan db:seed --class=Database\\Seeders\\FinancialLineSeeder --force   # required lines only
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   Then create the first admin:
   ```bash
   php artisan tinker --execute="App\Models\User::create(['name'=>'HESB Admin','email'=>'you@hesb.com.my','password'=>'ChangeMe-2026','role'=>'hesb_admin']);"
   ```
   Then add the companies and users from the Admin screens.
7. Make sure `storage/` and `bootstrap/cache/` are writable by PHP (755 folders / 644 files is
   usual on cPanel).
8. Turn on *Force HTTPS Redirect* for the domain.

There's no cron or queue requirement. Password-reset mail is sent synchronously.

### VPS (Ubuntu + Nginx + PHP-FPM)

```bash
sudo apt install nginx mysql-server php8.3-fpm php8.3-{bcmath,intl,mbstring,mysql,zip,gd,xml,curl}
cd /var/www && git clone <repo> hesb && cd hesb
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env      # production values as above
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\FinancialLineSeeder --force
php artisan config:cache route:cache view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

Nginx server block (then add TLS with `certbot --nginx`):

```nginx
server {
    server_name hesb.example.com;
    root /var/www/hesb/public;
    index index.php;
    client_max_body_size 8M;            # Excel uploads (app limit is 5 MB)

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

### Updating

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache route:cache view:cache
```

If you change CSS/JS, run `npm run build` and commit `public/build/`.

## Security notes

- Passwords are hashed with bcrypt. Login is rate-limited (5 per minute per email+IP, 20 per
  minute per IP), and password reset never reveals whether an email exists.
- CSRF protection on every form. Sessions are stored in the database and encrypted, with
  secure cookies in production.
- Deactivating a user or company ends any live session on the next request.
- No raw SQL with user input. Uploads are type- and size-checked, stored outside the web
  root, and parsed as data (no macros, no formulas trusted).
- CSV/XLSX exports guard against spreadsheet formula injection.
- Every data change, upload, lock, login and export is written to the audit log with the old
  and new value and the IP address.
- Security headers: `X-Frame-Options: DENY`, `nosniff`, a strict referrer policy, and
  `no-store` caching for signed-in pages.

## Project map

```
app/Services/Finance/FinancialCalculator.php  derived rows (single source of truth)
app/Services/Finance/EntryGrid.php            monthly grid (weeks, totals, AMP, Var)
app/Services/Finance/EntryService.php         the only writer of weekly entries
app/Services/Finance/AmpService.php           AMP grid and writer
app/Services/Finance/PerformanceService.php   dashboards, flash, consolidation
app/Services/Finance/FinanceGuru.php          rule-based advisory
app/Services/Excel/*                          templates, upload parsing, exports
app/Policies/CompanyPolicy.php                who may view / enter / plan / export
resources/js/money.js                         browser mirror of the calculator
resources/css/app.css                         design tokens (Purity UI, light + dark)
```
