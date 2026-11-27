# HESB Digital Center: notes for contributors and coding agents

Laravel 13 / PHP 8.3 / Blade + Tailwind 4 + Chart.js. See README.md for setup and the data rules.

## Rules that must not be broken

- **Money:** DECIMAL(15,2) in the DB, decimal *strings* + `App\Support\Money` (bcmath) in PHP,
  BigInt cents in JS (`resources/js/money.js`). Never use floats for amounts.
- **Derived rows** (Total Revenue, Cost of Sales, Gross Profit, PBT) are computed only by
  `App\Services\Finance\FinancialCalculator`. Never store them. If a formula changes, change it
  there and in `resources/js/money.js` (`computeDerived`) and in
  `App\Services\Excel\EntryWorkbook::derivedFormula`, then update `tests/Unit/FinancialCalculatorTest.php`.
- **Tenant isolation:** a company user's company always comes from `auth()->user()->company`
  (see `Controller::resolveCompany`). Never read `company_id` from request input for them.
  Group routes take `{company}` and are guarded by `role:` middleware *and* `CompanyPolicy`.
- **CEO is read-only:** every write route sits under `role:hesb_admin` or a policy the CEO fails.
  Add a 403 test for any new write route (see `AdminTest::adminEndpoints`).
- **Writes go through services:** weekly values via `EntryService::saveMonth`, AMP via
  `AmpService::save`. They enforce locks, line ownership and auditing.

## Commands

```bash
php artisan test            # full suite
php artisan migrate:fresh --seed
npm run build               # then commit public/build/
```
