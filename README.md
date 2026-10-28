# FinSync

FinSync is a local family-finance dashboard demo built with native PHP 8+, MySQL/PDO, semantic HTML, CSS, vanilla JavaScript, and Chart.js. It runs with XAMPP and does not require Azure, cloud provisioning, Composer, a framework, or a live financial provider.

> **Synthetic demo only:** Never enter bank credentials. Provider adapters use fixtures only. Payment receipts are simulations and cannot move real money. Advisor responses are informational summaries, not professional financial advice.

## Local setup (XAMPP on Windows)

1. Install XAMPP with PHP 8+ and the `pdo_mysql` PHP extension. Start **Apache** and **MySQL** in the XAMPP Control Panel. Enable Apache `mod_rewrite` and allow `.htaccess` overrides (`AllowOverride All`) if your XAMPP configuration has them disabled.
2. Put this folder in `C:\xampp\htdocs\FinSync` (or use your existing XAMPP `htdocs` directory).
3. Open `http://localhost/phpmyadmin`, create an empty database named `finsync` with `utf8mb4_unicode_ci`, select it, then import `database/schema.sql`.
   - The schema script drops and recreates the FinSync tables. Import only into an empty/local FinSync demo database; do not point it at another application’s database.
   - For an existing FinSync database with user accounts you need to keep, run the **Apply FinSync Personal Finance Migration** VS Code task or import `database/personal_finance_migration.sql` in phpMyAdmin instead. It adds only the personal finance table and does not drop existing data.
   - Run the **Test FinSync Personal Finance** VS Code task to smoke-test database create/read/update/delete, analysis totals, and user isolation. The test data is rolled back.
4. Copy `config.local.example.php` to `config.local.php` in the project root. For a standard XAMPP install, the defaults (`127.0.0.1:3306`, database `finsync`, user `root`, blank password) usually work. Set `app.base_path` to `/FinSync/public` when browsing the default XAMPP folder location.
5. Visit `http://localhost/FinSync/public/`, choose **Create account**, and register with a password of at least 10 characters. Registration asks for explicit consent to synthetic FinSync records, creates a password hash, and creates a per-user set of labeled demo fixtures. **My finances** is the default dashboard for entering your own records; it works independently of consent for the separate synthetic demo pages.

If you configure Apache’s document root directly to `FinSync/public`, leave `app.base_path` empty and open `http://localhost/`. Keep `config.local.php` private; it is ignored by Git. Do not copy real financial data or credentials into the workspace.

The web server must be able to write to `storage/rate-limits` for request throttling. `storage/` and `public/uploads/` deny direct web access; the prototype intentionally has no upload feature.

## Local dependencies

- PHP 8+ and MySQL with PDO MySQL are required.
- Pico.css, Font Awesome, and Chart.js are loaded from their public CDNs by the browser for the planned component library, icons, and charts. The PHP API and data remain local; internet access is needed for those CDN assets. If an offline installation is required, vendor reviewed copies locally and update the CSP in `public/index.php`.
- No AI key is required. Advisor answers use the deterministic local fallback and only read the signed-in user’s consent-authorized demo summaries.

## Included capabilities

- Registration, login, logout, hashed passwords, regenerated session IDs, session/CSRF protections, basic per-IP throttling, and user-scoped PDO queries.
- Consent grant/revoke enforced by the PHP API before financial data reads.
- Family profiles are deactivated rather than deleted, preserving linked consent records.
- Synthetic family profiles, masked checking/savings/credit/investment/loan/wallet accounts, holdings, transactions, recurring items, notifications, and account summaries.
- User-entered monthly income, expenses and categories, savings, investments, debt, credit-card balance, goals, and notes stored in the `personal_finance_records` MySQL table and scoped to the signed-in user.
- Personal finance dashboard with month-by-month analysis, cash flow, savings rate, debt totals, category spending, indicators, suggestions, and personal-record add/edit/delete controls. It never uses synthetic values in this analysis.
- Responsive overview, accessible tables, monthly cash-flow chart with a text summary, family management, category filtering, investment summary, reminder list, advisor fallback, privacy controls, and simulated payment receipts.
- Audit events for authentication, consent, family-profile changes, advisor requests, and simulated receipts.
- Mock-provider normalization boundary with no provider credentials, network connections, payment processor, or automatic money movement.

## API route inventory

All routes are same-origin under `/api`; state-changing requests require the session CSRF token in `X-CSRF-Token`.

Personal finance routes require authentication and CSRF verification on writes; they are independent of consent for the separate synthetic demo records. Every query is scoped to the current session’s user.

| Method | Path | Access / purpose |
|---|---|---|
| GET | `/api/health` | Local service health |
| GET | `/api/session` | Current session, user, consent, and CSRF token |
| POST | `/api/register` | Create user, explicit demo consent, and synthetic fixtures |
| POST | `/api/login` | Authenticate and regenerate session ID |
| POST | `/api/logout` | End the current session |
| GET | `/api/personal-finance?month=YYYY-MM` | Read the user’s records and selected-month analysis |
| POST | `/api/personal-finance` | Create an owner-scoped personal finance record |
| PUT / DELETE | `/api/personal-finance/{id}` | Update or delete an owner-scoped personal finance record |
| GET / PUT | `/api/consent` | Read or change synthetic-data consent |
| GET | `/api/dashboard` | Account totals, cash flow, score explanation, recent activity |
| GET | `/api/family` | List household profiles |
| POST | `/api/family` | Add a household profile without connecting accounts |
| PATCH | `/api/family/{id}` | Deactivate an owner-scoped profile |
| GET | `/api/accounts` | List masked accounts |
| GET | `/api/transactions?category={name}` | List/filter transactions |
| GET | `/api/investments` | List synthetic holdings |
| GET | `/api/recurring` | List demo recurring schedules |
| GET | `/api/notifications` | List reminders/notices |
| POST | `/api/notifications/{id}/read` | Mark an owner-scoped notice read |
| GET / POST | `/api/payments` | List receipts / create a demo-only receipt |
| POST | `/api/advisor` | Ask a question answered by local safe fallback |

Financial API routes require an active `financial_data` consent record and an authenticated session. Every query is scoped to the user in the current session.

## Validation notes

The browser UI requires Apache/PHP/MySQL to be running with the imported schema and local configuration. Useful local checks:

- `php -v`
- `php -m` (confirm `PDO` and `pdo_mysql`)
- `php -l public/index.php`
- `php -l app/Controllers/ApiController.php`
- Open `http://localhost/FinSync/public/api/health` after configuration.
