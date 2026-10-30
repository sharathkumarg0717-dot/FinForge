<?php
declare(strict_types=1);

if (php_sapi_name() === 'cli-server') {
    $requestUriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $staticFile = __DIR__ . $requestUriPath;
    if ($requestUriPath !== '/' && $requestUriPath !== '' && is_file($staticFile)) {
        return false;
    }
}

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Core/Http.php';
require_once dirname(__DIR__) . '/app/Core/Security.php';
require_once dirname(__DIR__) . '/app/Core/RateLimiter.php';
require_once dirname(__DIR__) . '/app/Models/FinanceRepository.php';
require_once dirname(__DIR__) . '/app/Services/ConsentService.php';
require_once dirname(__DIR__) . '/app/Services/MockProviderService.php';
require_once dirname(__DIR__) . '/app/Services/DemoDataService.php';
require_once dirname(__DIR__) . '/app/Services/AdvisorService.php';
require_once dirname(__DIR__) . '/app/Controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/Controllers/ApiController.php';

use FinSync\Core\Database;
use FinSync\Core\Http;
use FinSync\Core\Security;
use FinSync\Controllers\ApiController;

Security::startSession();
$configuration = Database::config();
$basePath = rtrim((string)($configuration['app']['base_path'] ?? ''), '/');
$googleClientId = (string)($configuration['google']['client_id'] ?? '');
$nonce = base64_encode(random_bytes(18));
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($basePath === '' && isset($_SERVER['SCRIPT_NAME'])) {
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($requestPath, $scriptDir)) {
        $basePath = $scriptDir;
    }
}

if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}
$requestPath = preg_replace('#/index\.php(?=/|$)#', '', $requestPath) ?: '/';
$requestPath = '/' . trim($requestPath, '/');
if ($requestPath === '//') {
    $requestPath = '/';
}

if ($requestPath === '/api/config/auth' && Http::method() === 'GET') {
    Http::json(['data' => [
        'google_client_id' => $googleClientId,
    ]]);
}

if (str_starts_with($requestPath, '/api/')) {
    try {
        (new ApiController())->dispatch($requestPath, Http::method());
    } catch (Throwable $exception) {
        error_log('FinSync request failed: ' . $exception->getMessage());
        Http::error('The request could not be completed. Confirm the local database setup and try again.', 'server_error', 500);
    }
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://accounts.google.com/gsi/client; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://accounts.google.com/gsi/style; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: https://*.googleusercontent.com https://accounts.google.com; frame-src 'self' https://accounts.google.com/; connect-src 'self' https://accounts.google.com/ https://oauth2.googleapis.com/; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#101b33">
  <title>FinSync — Family finances, in focus</title>
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link rel="preconnect" href="https://cdnjs.cloudflare.com">
  <link rel="preconnect" href="https://accounts.google.com">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="<?= Security::escape($basePath) ?>/assets/app.css?v=<?= @filemtime(__DIR__ . '/assets/app.css') ?: '2' ?>">
  <script nonce="<?= Security::escape($nonce) ?>">
    window.FINSYNC_BASE = <?= json_encode($basePath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.FINSYNC_GOOGLE_CLIENT_ID = <?= json_encode($googleClientId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>
  <div id="auth-view" class="auth-shell" hidden>
    <section class="auth-brand" aria-label="FinSync introduction">
      <a class="brand brand-light" href="#" aria-label="FinSync home"><span class="brand-mark"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span><span>Fin<span>Sync</span></span></a>
      <div class="auth-intro">
        <p class="eyebrow">A clearer view, together</p>
        <h1>Family finances,<br><em>in focus.</em></h1>
        <p>One calm place to understand your household’s demo accounts, cash flow, and upcoming plans.</p>
        <div class="auth-points"><span><i class="fa-solid fa-shield-halved"></i> Private local demo</span><span><i class="fa-solid fa-flask"></i> Synthetic data only</span></div>
      </div>
      <small>FinSync is an informational demo. No real accounts or payments are connected.</small>
    </section>
    <section class="auth-form-wrap">
      <div class="auth-card">
        <div class="mobile-brand"><a class="brand" href="#"><span class="brand-mark"><i class="fa-solid fa-layer-group"></i></span><span>Fin<span>Sync</span></span></a></div>
        <div class="auth-heading"><p class="eyebrow">Welcome to FinSync</p><h2 id="auth-title">Sign in to your space</h2><p id="auth-subtitle">A household overview, designed around clarity.</p></div>
        <div class="auth-switch" role="tablist" aria-label="Choose sign in or register">
          <button type="button" class="auth-tab active" data-auth-mode="login" role="tab" aria-selected="true">Sign in</button>
          <button type="button" class="auth-tab" data-auth-mode="register" role="tab" aria-selected="false">Create account</button>
        </div>
        <form id="auth-form" novalidate>
          <label id="name-field" hidden>Your name<input name="name" autocomplete="name" maxlength="120" placeholder="e.g. Alex Morgan"></label>
          <label>Email address<input name="email" type="email" autocomplete="email" maxlength="190" required placeholder="you@example.com"></label>
          <label>Password<input name="password" type="password" autocomplete="current-password" minlength="10" maxlength="200" required placeholder="At least 10 characters"></label>
          <label id="consent-field" class="check-line" hidden><input name="consent_demo" type="checkbox"><span>I agree to use only FinSync’s synthetic demo family-finance records. I understand this does not connect real accounts.</span></label>
          <button id="auth-submit" class="primary-button full-button" type="submit"><span>Sign in</span><i class="fa-solid fa-arrow-right"></i></button>
          <p id="auth-message" class="form-message" role="status" aria-live="polite"></p>
        </form>
        <div class="auth-divider"><span>or continue with</span></div>
        <div id="google-auth-container" class="google-auth-box">
          <div id="google-signin-btn"></div>
          <button type="button" id="google-signin-custom" class="google-button" aria-label="Sign in with Google">
            <svg class="google-icon" viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span id="google-btn-text">Sign in with Google</span>
          </button>
        </div>
        <div class="auth-safety"><i class="fa-solid fa-lock" aria-hidden="true"></i><span>Your password is hashed. Never enter real bank credentials here.</span></div>
      </div>
    </section>
  </div>

  <div id="app-view" class="app-shell" hidden>
    <aside class="sidebar" id="sidebar">
      <a class="brand sidebar-brand" href="#" data-nav="personal-finance" aria-label="FinSync personal finance dashboard"><span class="brand-mark"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span><span>Fin<span>Sync</span></span></a>
      <div class="workspace-label">WORKSPACE</div>
      <nav class="side-nav" aria-label="Main navigation">
        <button class="nav-link active" data-nav="personal-finance"><i class="fa-solid fa-chart-pie"></i><span>My finances</span></button>
        <button class="nav-link" data-nav="overview"><i class="fa-solid fa-grid-2"></i><span>Demo overview</span></button>
        <button class="nav-link" data-nav="family"><i class="fa-solid fa-people-roof"></i><span>Family</span><span class="nav-count" id="family-count">—</span></button>
        <button class="nav-link" data-nav="accounts"><i class="fa-solid fa-wallet"></i><span>Accounts</span></button>
        <button class="nav-link" data-nav="transactions"><i class="fa-solid fa-arrow-right-arrow-left"></i><span>Transactions</span></button>
        <button class="nav-link" data-nav="investments"><i class="fa-solid fa-chart-line"></i><span>Investments</span></button>
      </nav>
      <div class="workspace-label nav-spacer">PLAN &amp; PROTECT</div>
      <nav class="side-nav" aria-label="Planning navigation">
        <button class="nav-link" data-nav="reminders"><i class="fa-regular fa-calendar-check"></i><span>Reminders</span><span class="nav-count" id="reminder-count">—</span></button>
        <button class="nav-link" data-nav="advisor"><i class="fa-solid fa-sparkles"></i><span>AI insights</span><span class="beta-badge">DEMO</span></button>
        <button class="nav-link" data-nav="payments"><i class="fa-solid fa-receipt"></i><span>Demo receipts</span></button>
        <button class="nav-link" data-nav="consent"><i class="fa-solid fa-shield-halved"></i><span>Privacy &amp; consent</span></button>
      </nav>
      <div class="sidebar-bottom">
        <div class="demo-sidebar"><span class="demo-dot"></span><span><b>Private local workspace</b><small>Personal + demo data are separate</small></span></div>
        <div class="profile-row"><span class="avatar" id="profile-avatar">F</span><span class="profile-meta"><b id="profile-name">FinSync user</b><small id="profile-email"></small></span><button id="logout-button" class="icon-button" aria-label="Sign out" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button></div>
      </div>
    </aside>
    <div class="main-column">
      <header class="topbar">
        <button id="mobile-menu" class="icon-button menu-button" aria-label="Open navigation"><i class="fa-solid fa-bars"></i></button>
        <div class="breadcrumbs"><span>FinSync</span><i class="fa-solid fa-chevron-right"></i><b id="page-crumb">Overview</b></div>
        <div class="topbar-actions"><span class="demo-pill"><span class="demo-dot"></span> <span id="workspace-mode-label">PERSONAL DATA</span></span><button class="icon-button notification-button" data-nav="reminders" aria-label="Open reminders"><i class="fa-regular fa-bell"></i><span id="notification-dot" class="notification-dot" hidden></span></button><span class="top-date" id="today-label"></span></div>
      </header>
      <main class="content" id="main-content">
        <div id="notice-region" aria-live="polite"></div>
        <section id="consent-gate" class="consent-gate" hidden>
          <div class="gate-icon"><i class="fa-solid fa-shield-halved"></i></div><div><p class="eyebrow">Your choice matters</p><h2>Financial data is paused</h2><p>Grant consent to view your synthetic family-finance demo records. You can revoke access at any time.</p><button class="primary-button" data-nav="consent">Review consent settings <i class="fa-solid fa-arrow-right"></i></button></div>
        </section>

        <section class="screen" id="screen-personal-finance">
          <div class="page-heading">
            <div>
              <p class="eyebrow">YOUR PERSONAL FINANCES <span class="live-dot"></span> PRIVATE TO YOUR ACCOUNT</p>
              <h1>Your finance dashboard</h1>
              <p class="subtitle">Enter and manage your monthly figures. Analysis is computed dynamically from your saved MySQL records.</p>
            </div>
            <div class="finance-heading-actions">
              <label class="month-filter">Analysis month
                <input id="finance-month" type="month" required>
              </label>
              <button class="primary-button" id="finance-open-form" type="button"><i class="fa-solid fa-plus"></i> Add record</button>
            </div>
          </div>

          <div id="finance-recorded-months" class="recorded-months-bar" hidden>
            <span class="recorded-months-label"><i class="fa-regular fa-calendar"></i> Saved months:</span>
            <div id="finance-month-chips" class="month-chips"></div>
          </div>

          <div class="stat-grid finance-stat-grid">
            <article class="stat-card">
              <div class="stat-top"><span class="stat-label">Total income</span><span class="stat-icon green-icon"><i class="fa-solid fa-arrow-down-long"></i></span></div>
              <div class="stat-value" id="finance-income">—</div>
              <div class="stat-foot"><span class="tiny-note">Monthly + other income</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top"><span class="stat-label">Total expenses</span><span class="stat-icon amber-icon"><i class="fa-solid fa-arrow-up-long"></i></span></div>
              <div class="stat-value" id="finance-expenses">—</div>
              <div class="stat-foot"><span class="tiny-note">From your saved records</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top"><span class="stat-label">Net savings / cash flow</span><span class="stat-icon blue-icon"><i class="fa-solid fa-scale-balanced"></i></span></div>
              <div class="stat-value" id="finance-net">—</div>
              <div class="stat-foot"><span class="tiny-note">Income minus expenses</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top"><span class="stat-label">Savings rate</span><span class="stat-icon green-icon"><i class="fa-solid fa-piggy-bank"></i></span></div>
              <div class="stat-value" id="finance-savings-rate">—</div>
              <div class="stat-foot"><span class="tiny-note">Net savings ÷ total income</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top"><span class="stat-label">Total debt</span><span class="stat-icon amber-icon"><i class="fa-solid fa-landmark"></i></span></div>
              <div class="stat-value" id="finance-debt">—</div>
              <div class="stat-foot"><span class="tiny-note">Loans + credit card balance</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top"><span class="stat-label">Savings &amp; investments</span><span class="stat-icon violet-icon"><i class="fa-solid fa-chart-line"></i></span></div>
              <div class="stat-value" id="finance-assets">—</div>
              <div class="stat-foot"><span class="tiny-note">Balances you entered</span></div>
            </article>
          </div>

          <article class="panel form-panel finance-form-panel" id="finance-form-panel" hidden>
            <div class="panel-heading">
              <div>
                <h2 id="finance-form-title">Enter financial information</h2>
                <p>Saved securely to your MySQL database. All fields use dark, high-contrast text.</p>
              </div>
              <button class="icon-button" id="finance-form-close" type="button" aria-label="Close form"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="finance-form" class="finance-form" novalidate>
              <input type="hidden" name="id">
              <label>Record month <span class="required-star">*</span>
                <input name="month" type="month" required>
              </label>
              <label>Monthly income (₹) <span class="required-star">*</span>
                <input name="monthly_income" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" required placeholder="0.00">
              </label>
              <label>Other income (₹)
                <input name="other_income" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" value="0.00" placeholder="0.00">
              </label>
              <label>Monthly expenses (₹) <span class="required-star">*</span>
                <input name="monthly_expenses" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" required placeholder="0.00">
              </label>
              
              <fieldset class="finance-categories">
                <legend><i class="fa-solid fa-tags"></i> Expense categories breakdown</legend>
                <div class="category-preset-group">
                  <span class="preset-label">Quick add category:</span>
                  <button type="button" class="category-preset" data-cat="Food"><i class="fa-solid fa-utensils"></i> Food</button>
                  <button type="button" class="category-preset" data-cat="Rent"><i class="fa-solid fa-house"></i> Rent</button>
                  <button type="button" class="category-preset" data-cat="Transport"><i class="fa-solid fa-car"></i> Transport</button>
                  <button type="button" class="category-preset" data-cat="Utilities"><i class="fa-solid fa-bolt"></i> Utilities</button>
                  <button type="button" class="category-preset" data-cat="Shopping"><i class="fa-solid fa-bag-shopping"></i> Shopping</button>
                  <button type="button" class="category-preset" data-cat="Entertainment"><i class="fa-solid fa-film"></i> Entertainment</button>
                  <button type="button" class="category-preset" data-cat="Healthcare"><i class="fa-solid fa-notes-medical"></i> Healthcare</button>
                  <button type="button" class="category-preset" data-cat="Education"><i class="fa-solid fa-graduation-cap"></i> Education</button>
                  <button type="button" class="category-preset" data-cat="Insurance"><i class="fa-solid fa-shield"></i> Insurance</button>
                  <button type="button" class="category-preset" data-cat="Other"><i class="fa-solid fa-tag"></i> Other</button>
                </div>
                <datalist id="finance-category-options">
                  <option value="Food">
                  <option value="Rent">
                  <option value="Transport">
                  <option value="Utilities">
                  <option value="Shopping">
                  <option value="Entertainment">
                  <option value="Healthcare">
                  <option value="Education">
                  <option value="Personal">
                  <option value="Insurance">
                  <option value="Other">
                </datalist>
                <div id="finance-category-fields"></div>
                <div class="category-actions-bar">
                  <button class="outline-button small-button" id="add-finance-category" type="button"><i class="fa-solid fa-plus"></i> Add category row</button>
                  <div class="category-calc-row">
                    <span>Category total: <strong id="category-sum-display">₹0.00</strong></span>
                    <button type="button" id="sync-category-expense" class="text-button"><i class="fa-solid fa-copy"></i> Set as Monthly expenses</button>
                  </div>
                </div>
              </fieldset>

              <label>Savings balance (₹)
                <input name="savings" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" value="0.00" placeholder="0.00">
              </label>
              <label>Investments balance (₹)
                <input name="investments" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" value="0.00" placeholder="0.00">
              </label>
              <label>Loans / debt (₹)
                <input name="loans_debt" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" value="0.00" placeholder="0.00">
              </label>
              <label>Credit card balance (₹)
                <input name="credit_card_balance" type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" value="0.00" placeholder="0.00">
              </label>
              <label class="finance-wide">Financial goals
                <textarea name="financial_goals" rows="2" maxlength="2000" placeholder="e.g. Save ₹1,00,000 for emergency fund, pay off credit card debt"></textarea>
              </label>
              <label class="finance-wide">Optional notes
                <textarea name="notes" rows="2" maxlength="2000" placeholder="Add any details, breakdown notes, or reminders for this month"></textarea>
              </label>
              <p id="finance-form-message" class="form-message finance-wide" role="status" aria-live="polite"></p>
              <div class="finance-form-actions finance-wide">
                <button class="primary-button" id="finance-save-button" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save financial record</button>
                <button class="outline-button" id="finance-cancel-edit" type="button" hidden>Cancel edit</button>
              </div>
            </form>
          </article>

          <div id="finance-empty" class="empty-state finance-empty" hidden>
            <i class="fa-solid fa-chart-line"></i>
            <p>No personal records saved for this month yet. Click <strong>Add record</strong> above to enter your numbers and generate analysis.</p>
          </div>

          <div class="overview-grid finance-analysis-grid">
            <article class="panel">
              <div class="panel-heading">
                <div>
                  <h2>Income vs expenses</h2>
                  <p id="finance-cashflow-caption">Saved amounts for the selected month</p>
                </div>
                <span class="period-tag" id="finance-month-label"></span>
              </div>
              <div class="chart-frame">
                <canvas id="finance-cashflow-chart" role="img" aria-label="Chart comparing your saved income and expenses"></canvas>
                <div class="chart-fallback finance-chart-fallback" id="finance-cashflow-fallback"></div>
              </div>
            </article>
            <article class="panel health-panel">
              <div class="panel-heading">
                <div>
                  <h2>Financial health indicators</h2>
                  <p>Calculated directly from your saved records</p>
                </div>
                <span class="stat-icon blue-icon"><i class="fa-solid fa-heart-pulse"></i></span>
              </div>
              <div id="finance-indicators" class="finance-indicators"></div>
              <div class="health-note"><i class="fa-solid fa-circle-info"></i> Calculated from your stored financial records.</div>
            </article>
          </div>

          <div class="overview-grid finance-analysis-grid">
            <article class="panel category-panel">
              <div class="panel-heading">
                <div>
                  <h2>Spending by category</h2>
                  <p>Highest spending categories listed first</p>
                </div>
              </div>
              <div class="category-chart-frame finance-category-chart">
                <canvas id="finance-category-chart" role="img" aria-label="Chart of your saved spending by category"></canvas>
                <div id="finance-category-empty" class="category-empty" hidden>No category amounts recorded for this month.</div>
              </div>
              <div id="finance-category-summary" class="category-summary"></div>
            </article>
            <article class="panel">
              <div class="panel-heading">
                <div>
                  <h2>Personalized suggestions</h2>
                  <p>Actionable advice based ONLY on your stored data</p>
                </div>
                <span class="stat-icon blue-icon"><i class="fa-solid fa-lightbulb"></i></span>
              </div>
              <div id="finance-suggestions" class="finance-suggestions"></div>
            </article>
          </div>

          <div class="overview-grid finance-analysis-grid">
            <article class="panel">
              <div class="panel-heading">
                <div>
                  <h2>Financial goals</h2>
                  <p>Saved targets for your finances</p>
                </div>
                <span class="stat-icon green-icon"><i class="fa-solid fa-bullseye"></i></span>
              </div>
              <div id="finance-goals" class="finance-goals"></div>
            </article>
            <article class="panel">
              <div class="panel-heading">
                <div>
                  <h2>Notes &amp; context</h2>
                  <p>Notes saved with your records</p>
                </div>
                <span class="stat-icon blue-icon"><i class="fa-solid fa-note-sticky"></i></span>
              </div>
              <div id="finance-notes" class="finance-notes"></div>
            </article>
          </div>

          <article class="panel finance-records-panel">
            <div class="panel-heading">
              <div>
                <h2>Your saved financial records</h2>
                <p id="finance-record-count">All stored records in MySQL</p>
              </div>
              <button class="primary-button small-button" id="add-finance-record"><i class="fa-solid fa-plus"></i> Add new record</button>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>MONTH</th>
                    <th>INCOME</th>
                    <th>EXPENSES</th>
                    <th>NET SAVINGS</th>
                    <th>SAVINGS &amp; INVEST</th>
                    <th>TOTAL DEBT</th>
                    <th>GOALS / NOTES</th>
                    <th class="align-right">ACTIONS</th>
                  </tr>
                </thead>
                <tbody id="finance-records-table"></tbody>
              </table>
            </div>
            <div id="finance-records-empty" class="empty-state" hidden>
              <p>You have not saved any financial records yet. Use the "Add record" button to enter your first month.</p>
            </div>
          </article>
        </section>

        <section class="screen" id="screen-overview">
          <div class="page-heading"><div><p class="eyebrow">HOUSEHOLD SNAPSHOT <span class="live-dot"></span> DEMO</p><h1>Good to see you, <span id="greeting-name">there</span></h1><p class="subtitle">Here’s a thoughtful snapshot of your household finances.</p></div><button class="outline-button" id="refresh-button"><i class="fa-solid fa-arrows-rotate"></i> Refresh view</button></div>
          <div class="stat-grid">
            <article class="stat-card feature-stat"><div class="stat-top"><span class="stat-label">Total net worth <i class="fa-regular fa-circle-question" title="Synthetic assets less demo liabilities"></i></span><span class="stat-icon blue-icon"><i class="fa-solid fa-scale-balanced"></i></span></div><div class="stat-value" id="net-worth">₹—</div><div class="stat-foot"><span class="status-chip neutral-chip"><i class="fa-solid fa-flask"></i> Demo snapshot</span><span class="muted">Across your household</span></div><div class="stat-glow"></div></article>
            <article class="stat-card"><div class="stat-top"><span class="stat-label">Monthly income</span><span class="stat-icon green-icon"><i class="fa-solid fa-arrow-down-long"></i></span></div><div class="stat-value" id="monthly-income">₹—</div><div class="stat-foot"><span class="tiny-note">Synthetic entries this month</span></div></article>
            <article class="stat-card"><div class="stat-top"><span class="stat-label">Monthly expenses</span><span class="stat-icon amber-icon"><i class="fa-solid fa-arrow-up-long"></i></span></div><div class="stat-value" id="monthly-expenses">₹—</div><div class="stat-foot"><span class="tiny-note">Synthetic entries this month</span></div></article>
            <article class="stat-card"><div class="stat-top"><span class="stat-label">Investment value</span><span class="stat-icon violet-icon"><i class="fa-solid fa-chart-pie"></i></span></div><div class="stat-value" id="investment-value">₹—</div><div class="stat-foot"><span class="tiny-note"><span id="account-count">0</span> connected demo accounts</span></div></article>
          </div>
          <div class="overview-grid">
            <article class="panel cashflow-panel"><div class="panel-heading"><div><h2>Monthly cash flow</h2><p>Demo income compared with expenses</p></div><span class="period-tag">THIS MONTH</span></div><div class="chart-frame"><canvas id="cashflow-chart" role="img" aria-label="Bar chart comparing synthetic income and expenses this month"></canvas><div class="chart-fallback" id="cashflow-fallback"></div></div><div class="chart-legend"><span><i class="legend-dot income-dot"></i>Income</span><span><i class="legend-dot expense-dot"></i>Expenses</span><b id="monthly-net">Net ₹—</b></div></article>
            <article class="panel health-panel"><div class="panel-heading"><div><h2>Financial wellness</h2><p>Simple demo indicator</p></div><span class="stat-icon blue-icon"><i class="fa-solid fa-heart-pulse"></i></span></div><div class="health-score"><div class="score-ring"><strong id="health-score">—</strong><small>of 100</small></div><div class="score-copy"><b id="health-label">Demo snapshot</b><p id="health-explanation">An educational summary based on synthetic records.</p></div></div><div class="health-note"><i class="fa-solid fa-circle-info"></i> Not a credit score or professional advice.</div></article>
          </div>
          <div class="overview-grid lower-grid">
            <article class="panel"><div class="panel-heading"><div><h2>Recent activity</h2><p>Your latest synthetic transactions</p></div><button class="text-button" data-nav="transactions">All transactions <i class="fa-solid fa-arrow-right"></i></button></div><div id="recent-transactions" class="transaction-list"></div></article>
            <article class="panel"><div class="panel-heading"><div><h2>Coming up</h2><p>Recurring demo reminders</p></div><button class="text-button" data-nav="reminders">View all <i class="fa-solid fa-arrow-right"></i></button></div><div id="upcoming-list" class="upcoming-list"></div></article>
            <article class="panel category-panel"><div class="panel-heading"><div><h2>Spending by category</h2><p>Synthetic expenses this month</p></div><span class="period-tag">THIS MONTH</span></div><div class="category-chart-frame"><canvas id="category-chart" role="img" aria-label="Donut chart showing this month’s synthetic expense categories"></canvas><div id="category-empty" class="category-empty" hidden>No category expenses recorded this month.</div></div><div id="category-summary" class="category-summary" aria-label="Text summary of spending by category"></div></article>
          </div>
          <p class="disclaimer-line"><i class="fa-solid fa-flask"></i> Demo data only. Values are synthetic and do not represent real accounts or personalized financial guidance.</p>
        </section>

        <section class="screen" id="screen-family" hidden>
          <div class="page-heading">
            <div>
              <p class="eyebrow">YOUR HOUSEHOLD FINANCIALS <span class="live-dot"></span> LIVE CALCULATIONS</p>
              <h1>Family finance &amp; members</h1>
              <p class="subtitle">View total household savings, spend, debt, credit, and click any family member to open their individual dashboard.</p>
            </div>
            <div class="page-heading-actions">
              <button class="primary-button" id="add-family-button"><i class="fa-solid fa-user-plus"></i> Add member</button>
            </div>
          </div>

          <!-- ENTIRE FAMILY CALCULATIONS (HOUSEHOLD TOTALS) -->
          <div class="family-calculations-section">
            <div class="stat-grid family-stat-grid">
              <article class="stat-card">
                <div class="stat-top">
                  <span class="stat-label">Family Total Savings</span>
                  <span class="stat-icon green-icon"><i class="fa-solid fa-piggy-bank"></i></span>
                </div>
                <div class="stat-value" id="family-calc-savings">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Combined bank &amp; liquid savings</span></div>
              </article>
              <article class="stat-card">
                <div class="stat-top">
                  <span class="stat-label">Family Total Spend</span>
                  <span class="stat-icon orange-icon"><i class="fa-solid fa-arrow-trend-down"></i></span>
                </div>
                <div class="stat-value" id="family-calc-spend">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Combined household expenses</span></div>
              </article>
              <article class="stat-card">
                <div class="stat-top">
                  <span class="stat-label">Family Total Debt</span>
                  <span class="stat-icon red-icon"><i class="fa-solid fa-hand-holding-dollar"></i></span>
                </div>
                <div class="stat-value" id="family-calc-debt">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Loans &amp; outstanding balances</span></div>
              </article>
              <article class="stat-card">
                <div class="stat-top">
                  <span class="stat-label">Family Total Credit</span>
                  <span class="stat-icon purple-icon"><i class="fa-solid fa-credit-card"></i></span>
                </div>
                <div class="stat-value" id="family-calc-credit">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Credit card balances / usage</span></div>
              </article>
              <article class="stat-card feature-stat">
                <div class="stat-top">
                  <span class="stat-label">Family Net Worth</span>
                  <span class="stat-icon blue-icon"><i class="fa-solid fa-scale-balanced"></i></span>
                </div>
                <div class="stat-value" id="family-calc-networth">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Assets (Savings + Invest) − Debt</span></div>
              </article>
            </div>
          </div>

          <!-- ADD FAMILY MEMBER FORM PANEL -->
          <article id="family-form-panel" class="panel form-panel" hidden>
            <div class="panel-heading">
              <div>
                <h2>Add a family profile</h2>
                <p>Create a household member profile. You can link demo accounts to them anytime.</p>
              </div>
              <button class="icon-button" id="family-form-close" aria-label="Close form"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="family-form" class="inline-form">
              <label>Member name<input name="name" maxlength="120" placeholder="e.g. Priya Kumar" required></label>
              <label>Relationship
                <select name="relationship" required>
                  <option value="">Choose one</option>
                  <option>Partner</option>
                  <option>Child</option>
                  <option>Parent</option>
                  <option>Sibling</option>
                  <option>Other</option>
                </select>
              </label>
              <button class="primary-button" type="submit"><i class="fa-solid fa-check"></i> Add profile</button>
            </form>
          </article>

          <!-- HOUSEHOLD PROFILES / MEMBERS LIST PANEL -->
          <div id="family-main-view">
            <article class="panel">
              <div class="panel-heading">
                <div>
                  <h2>Household members &amp; dashboards</h2>
                  <p>Click on any family member avatar or card to open their dedicated personal dashboard.</p>
                </div>
                <span id="family-total" class="period-tag"></span>
              </div>
              
              <!-- Family Member Cards Grid -->
              <div id="family-cards-grid" class="family-members-grid"></div>

              <!-- Family Members Table View -->
              <div class="table-wrap" style="margin-top: 18px;">
                <table>
                  <thead>
                    <tr>
                      <th>MEMBER</th>
                      <th>RELATIONSHIP</th>
                      <th>SAVINGS</th>
                      <th>SPEND</th>
                      <th>DEBT / CREDIT</th>
                      <th>ACCOUNTS</th>
                      <th>STATUS</th>
                      <th class="align-right">ACTIONS</th>
                    </tr>
                  </thead>
                  <tbody id="family-table"></tbody>
                </table>
              </div>
              <div id="family-empty" class="empty-state" hidden>
                <i class="fa-solid fa-people-roof"></i>
                <p>No active household profiles yet. Click "Add member" to get started.</p>
              </div>
            </article>
          </div>

          <!-- INDIVIDUAL MEMBER DASHBOARD VIEW (Shown when a member is clicked) -->
          <div id="family-member-view" hidden>
            <div class="member-dashboard-topbar">
              <button class="outline-button" id="member-back-button" type="button"><i class="fa-solid fa-arrow-left"></i> Back to family overview</button>
              <div class="member-quick-switcher">
                <span>Switch member:</span>
                <select id="member-select-switcher" aria-label="Switch family member"></select>
              </div>
            </div>

            <!-- Member Header Card -->
            <article class="panel member-header-panel">
              <div class="member-header-wrap">
                <div class="member-large-avatar" id="member-dash-avatar">M</div>
                <div class="member-header-info">
                  <div class="member-title-row">
                    <h2 id="member-dash-name">Member Name</h2>
                    <span class="status-chip success-chip" id="member-dash-status">Active</span>
                  </div>
                  <p class="member-sub-info">
                    <span class="type-chip" id="member-dash-relation">Relationship</span>
                    <span id="member-dash-accounts-count">0 accounts linked</span>
                  </p>
                </div>
                <div class="member-header-actions">
                  <button class="primary-button" id="member-link-account-btn" type="button"><i class="fa-solid fa-plus"></i> Link demo account</button>
                </div>
              </div>
            </article>

            <!-- Member Specific Financial Calculations -->
            <div class="stat-grid compact-stats" style="margin-bottom: 18px;">
              <article class="stat-card">
                <div class="stat-top"><span class="stat-label">Member Savings</span><span class="stat-icon green-icon"><i class="fa-solid fa-piggy-bank"></i></span></div>
                <div class="stat-value" id="member-dash-savings">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Checking &amp; savings</span></div>
              </article>
              <article class="stat-card">
                <div class="stat-top"><span class="stat-label">Member Spending</span><span class="stat-icon orange-icon"><i class="fa-solid fa-arrow-trend-down"></i></span></div>
                <div class="stat-value" id="member-dash-spend">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Expenses &amp; purchases</span></div>
              </article>
              <article class="stat-card">
                <div class="stat-top"><span class="stat-label">Member Debt</span><span class="stat-icon red-icon"><i class="fa-solid fa-hand-holding-dollar"></i></span></div>
                <div class="stat-value" id="member-dash-debt">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Loans &amp; borrowings</span></div>
              </article>
              <article class="stat-card">
                <div class="stat-top"><span class="stat-label">Member Credit</span><span class="stat-icon purple-icon"><i class="fa-solid fa-credit-card"></i></span></div>
                <div class="stat-value" id="member-dash-credit">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Credit card usage</span></div>
              </article>
              <article class="stat-card">
                <div class="stat-top"><span class="stat-label">Member Investments</span><span class="stat-icon blue-icon"><i class="fa-solid fa-chart-line"></i></span></div>
                <div class="stat-value" id="member-dash-investments">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Holdings &amp; assets</span></div>
              </article>
              <article class="stat-card feature-stat">
                <div class="stat-top"><span class="stat-label">Net Contribution</span><span class="stat-icon blue-icon"><i class="fa-solid fa-wallet"></i></span></div>
                <div class="stat-value" id="member-dash-net">₹0.00</div>
                <div class="stat-foot"><span class="tiny-note">Assets − Liabilities</span></div>
              </article>
            </div>

            <!-- Member Accounts Panel -->
            <article class="panel" style="margin-bottom: 18px;">
              <div class="panel-heading">
                <div>
                  <h2>Connected accounts</h2>
                  <p>Bank, savings, credit, loan, and wallet accounts for this member.</p>
                </div>
                <button class="outline-button small-button" id="member-add-account-sub-btn" type="button"><i class="fa-solid fa-plus"></i> Add account</button>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>INSTITUTION</th>
                      <th>TYPE</th>
                      <th>ACCOUNT NUMBER</th>
                      <th class="align-right">BALANCE</th>
                    </tr>
                  </thead>
                  <tbody id="member-accounts-table"></tbody>
                </table>
              </div>
              <div id="member-accounts-empty" class="empty-state" hidden>
                <i class="fa-solid fa-wallet"></i>
                <p>No accounts connected for this member yet. Click "Link demo account" above to add one.</p>
              </div>
            </article>

            <!-- Member Transactions Panel -->
            <article class="panel">
              <div class="panel-heading">
                <div>
                  <h2>Recent activity</h2>
                  <p>Transactions associated with this member's accounts.</p>
                </div>
                <span id="member-tx-count" class="period-tag">0 entries</span>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>DESCRIPTION</th>
                      <th>CATEGORY</th>
                      <th>ACCOUNT</th>
                      <th>DATE</th>
                      <th class="align-right">AMOUNT</th>
                    </tr>
                  </thead>
                  <tbody id="member-tx-table"></tbody>
                </table>
              </div>
              <div id="member-tx-empty" class="empty-state" hidden>
                <i class="fa-solid fa-receipt"></i>
                <p>No transactions recorded for this member's accounts yet.</p>
              </div>
            </article>
          </div>
        </section>

        <section class="screen" id="screen-accounts" hidden>
          <div class="page-heading"><div><p class="eyebrow">SYNTHETIC PROVIDER CONNECTIONS</p><h1>Accounts</h1><p class="subtitle">A normalized view of mock bank, investment, credit, loan, and wallet data.</p></div><span class="status-chip info-chip"><i class="fa-solid fa-flask"></i> Mock adapters only</span></div>
          <div class="stat-grid compact-stats" id="account-summary"></div><article class="panel"><div class="panel-heading"><div><h2>Household accounts</h2><p>Account references are masked; no provider credentials are stored.</p></div><span class="period-tag">LOCAL DEMO</span></div><div class="table-wrap"><table><thead><tr><th>INSTITUTION</th><th>MEMBER</th><th>TYPE</th><th>ACCOUNT</th><th class="align-right">BALANCE</th></tr></thead><tbody id="accounts-table"></tbody></table></div></article>
        </section>

        <section class="screen" id="screen-transactions" hidden>
          <div class="page-heading"><div><p class="eyebrow">HOUSEHOLD ACTIVITY</p><h1>Transactions</h1><p class="subtitle">Review and filter synthetic entries from the mock provider adapters.</p></div><label class="filter-label">Category<select id="transaction-filter"><option value="">All categories</option></select></label></div>
          <article class="panel"><div class="panel-heading"><div><h2>Transaction history</h2><p id="transaction-count">Demo entries</p></div><span class="period-tag">SYNTHETIC</span></div><div class="table-wrap"><table><thead><tr><th>DESCRIPTION</th><th>CATEGORY</th><th>ACCOUNT</th><th>DATE</th><th class="align-right">AMOUNT</th></tr></thead><tbody id="transactions-table"></tbody></table></div><div id="transactions-empty" class="empty-state" hidden><i class="fa-solid fa-magnifying-glass"></i><p>No transactions match that category.</p></div></article>
        </section>

        <section class="screen" id="screen-investments" hidden>
          <div class="page-heading">
            <div>
              <p class="eyebrow">EQUITY, MUTUAL FUNDS &amp; DEMAT <span class="live-dot"></span> LIVE PORTFOLIO</p>
              <h1>Investments &amp; Demat</h1>
              <p class="subtitle">Track your Zerodha Demat account, live stock holdings, mutual funds, and total portfolio performance in Indian Rupees (₹).</p>
            </div>
            <div class="page-heading-actions">
              <button class="primary-button zerodha-btn" id="connect-zerodha-btn" type="button"><i class="fa-solid fa-bolt"></i> Connect Zerodha Demat</button>
              <button class="outline-button" id="add-holding-btn" type="button"><i class="fa-solid fa-plus"></i> Add Holding</button>
            </div>
          </div>

          <!-- ZERODHA DEMAT ACCOUNT STATUS CARD -->
          <article class="panel zerodha-card" id="zerodha-banner-panel">
            <div class="zerodha-card-body">
              <div class="zerodha-brand-badge">
                <span class="zerodha-kite-icon">K</span>
                <div>
                  <h3 id="zerodha-card-title">Zerodha Demat Account</h3>
                  <p id="zerodha-card-sub">Not connected yet. Link your Zerodha Kite account to track real equity &amp; ETF holdings.</p>
                </div>
              </div>
              <div class="zerodha-actions-wrap" id="zerodha-actions-container">
                <button class="primary-button zerodha-btn open-zerodha-dialog" type="button"><i class="fa-solid fa-bolt"></i> Connect Zerodha</button>
              </div>
            </div>
            <div id="zerodha-connected-details" class="zerodha-details-row" hidden>
              <div class="zerodha-detail-pill"><span>Broker:</span> <b>Zerodha Broking Ltd</b></div>
              <div class="zerodha-detail-pill"><span>Client ID:</span> <b id="zerodha-client-display">—</b></div>
              <div class="zerodha-detail-pill"><span>Depository:</span> <b>CDSL</b></div>
              <div class="zerodha-detail-pill"><span>Status:</span> <span class="status-chip success-chip">Active Demat</span></div>
              <div class="zerodha-detail-pill"><span>Last Synced:</span> <span id="zerodha-synced-at">Just now</span></div>
            </div>
          </article>

          <!-- PORTFOLIO PERFORMANCE METRICS -->
          <div class="stat-grid" style="margin-bottom: 20px;">
            <article class="stat-card feature-stat">
              <div class="stat-top">
                <span class="stat-label">Total Portfolio Value</span>
                <span class="stat-icon blue-icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
              </div>
              <div class="stat-value" id="holdings-total">₹0.00</div>
              <div class="stat-foot"><span class="tiny-note" id="holdings-count-note">0 instruments</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top">
                <span class="stat-label">Total Invested Cost</span>
                <span class="stat-icon gray-icon"><i class="fa-solid fa-money-bill-wave"></i></span>
              </div>
              <div class="stat-value" id="portfolio-invested">₹0.00</div>
              <div class="stat-foot"><span class="tiny-note">Total buy capital</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top">
                <span class="stat-label">Total Returns (P&amp;L)</span>
                <span class="stat-icon green-icon" id="pnl-icon"><i class="fa-solid fa-chart-line"></i></span>
              </div>
              <div class="stat-value" id="portfolio-pnl">₹0.00</div>
              <div class="stat-foot"><span id="portfolio-pnl-pct" class="status-chip success-chip">+0.0%</span></div>
            </article>
            <article class="stat-card">
              <div class="stat-top">
                <span class="stat-label">Zerodha Holdings</span>
                <span class="stat-icon orange-icon"><i class="fa-solid fa-bolt"></i></span>
              </div>
              <div class="stat-value" id="zerodha-holdings-val">₹0.00</div>
              <div class="stat-foot"><span class="tiny-note" id="zerodha-holdings-count">0 stocks</span></div>
            </article>
          </div>

          <!-- PORTFOLIO HOLDINGS TABLE -->
          <article class="panel">
            <div class="panel-heading">
              <div>
                <h2>Portfolio holdings</h2>
                <p>Equities, ETFs, and mutual funds across your Demat and investment accounts.</p>
              </div>
              <div style="display:flex; gap:8px; align-items:center;">
                <button class="outline-button small-button" id="refresh-investments-btn" type="button"><i class="fa-solid fa-rotate"></i> Refresh</button>
              </div>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>INSTRUMENT</th>
                    <th>SYMBOL</th>
                    <th>DEMAT / BROKER</th>
                    <th class="align-right">QTY</th>
                    <th class="align-right">AVG BUY (₹)</th>
                    <th class="align-right">LTP (₹)</th>
                    <th class="align-right">MARKET VALUE</th>
                    <th class="align-right">P&amp;L (₹ / %)</th>
                    <th class="align-right">ACTION</th>
                  </tr>
                </thead>
                <tbody id="investments-table"></tbody>
              </table>
            </div>
            <div id="investments-empty" class="empty-state" hidden>
              <i class="fa-solid fa-chart-pie"></i>
              <p>No investment holdings found. Click "Connect Zerodha Demat" or "Add Holding" to track your portfolio.</p>
            </div>
          </article>
        </section>

        <section class="screen" id="screen-reminders" hidden>
          <div class="page-heading"><div><p class="eyebrow">STAY A LITTLE AHEAD</p><h1>Reminders</h1><p class="subtitle">Recurring payment schedules and account notices—demo only.</p></div><span class="status-chip warning-chip"><i class="fa-regular fa-calendar"></i> No automatic payments</span></div>
          <article class="panel"><div class="panel-heading"><div><h2>Upcoming recurring items</h2><p>Use “Simulate” to create a demo-only receipt for an item.</p></div><span class="period-tag">SCHEDULES</span></div><div class="table-wrap"><table><thead><tr><th>ITEM</th><th>CADENCE</th><th>NEXT DUE</th><th class="align-right">AMOUNT</th><th></th></tr></thead><tbody id="recurring-table"></tbody></table></div></article>
          <article class="panel"><div class="panel-heading"><div><h2>Notifications</h2><p>Local demo reminders for this workspace.</p></div><span class="period-tag" id="unread-label"></span></div><div class="notification-list" id="notifications-list"></div></article>
        </section>

        <section class="screen" id="screen-advisor" hidden>
          <div class="page-heading"><div><p class="eyebrow">SAFE, INFORMATIVE, GROUNDED IN YOUR DEMO</p><h1>AI insights</h1><p class="subtitle">Ask about what’s visible in your consent-authorized FinSync workspace.</p></div><span class="status-chip info-chip"><i class="fa-solid fa-wand-magic-sparkles"></i> Local fallback</span></div>
          <div class="advisor-layout"><article class="panel advisor-intro"><div class="advisor-orb"><i class="fa-solid fa-sparkles"></i></div><p class="eyebrow">FINANCIAL WELLNESS, WITHOUT THE NOISE</p><h2>A little more context.<br>A little less guesswork.</h2><p>FinSync’s demo advisor summarizes only the synthetic records available in your workspace. It does not make trades or take actions.</p><div class="advisor-boundaries"><span><i class="fa-solid fa-check"></i> Uses authorized demo data</span><span><i class="fa-solid fa-check"></i> Explains where figures come from</span><span><i class="fa-solid fa-ban"></i> Never moves money</span></div></article><article class="panel chat-panel"><div class="panel-heading"><div><h2>Ask FinSync</h2><p>Safe deterministic fallback is ready</p></div><span class="online-indicator"><i></i> AVAILABLE</span></div><div id="chat-messages" class="chat-messages" aria-live="polite"><div class="chat-bubble assistant-bubble"><span class="chat-avatar"><i class="fa-solid fa-sparkles"></i></span><div><b>FinSync insight</b><p>Hi <span id="advisor-name">there</span>! I can summarize your synthetic cash flow or net-worth snapshot. What would you like to understand?</p></div></div></div><form id="advisor-form" class="chat-form"><label class="visually-hidden" for="advisor-question">Ask a question about your demo records</label><input id="advisor-question" name="question" maxlength="500" required placeholder="Try “Summarize my spending this month”"><button class="primary-button" type="submit" aria-label="Send question"><i class="fa-solid fa-arrow-up"></i></button></form><p class="advisor-disclaimer"><i class="fa-solid fa-circle-info"></i> Informational demo only—not financial, tax, investment, or legal advice. Verify important decisions with a qualified professional.</p></article></div>
        </section>

        <section class="screen" id="screen-payments" hidden>
          <div class="page-heading"><div><p class="eyebrow">NOTHING LEAVES THIS DEMO</p><h1>Demo receipts</h1><p class="subtitle">Review simulated renewal, EMI, or one-time demo receipts.</p></div><span class="status-chip danger-chip"><i class="fa-solid fa-ban"></i> No real payments</span></div>
          <article class="warning-banner"><span class="warning-icon"><i class="fa-solid fa-shield-halved"></i></span><div><b>Simulation only</b><p>Creating a receipt records a demo state in this local database. No card, bank, or payment processor is contacted.</p></div></article>
          <article class="panel"><div class="panel-heading"><div><h2>Receipts</h2><p>Demo receipts are scoped to your account.</p></div><span class="period-tag">LOCAL RECORDS</span></div><div id="payments-list" class="receipt-list"></div><div id="payments-empty" class="empty-state" hidden><i class="fa-solid fa-receipt"></i><p>No demo receipts yet. Simulate a recurring item from Reminders.</p></div></article>
        </section>

        <section class="screen" id="screen-consent" hidden>
          <div class="page-heading"><div><p class="eyebrow">YOUR DATA, YOUR DECISION</p><h1>Privacy &amp; consent</h1><p class="subtitle">Control whether FinSync can show the synthetic family-finance records in this workspace.</p></div><span id="consent-status-badge" class="status-chip neutral-chip">Checking status</span></div>
          <article class="consent-card"><div class="consent-card-icon"><i class="fa-solid fa-shield-heart"></i></div><div class="consent-card-copy"><p class="eyebrow">FINANCIAL DATA ACCESS</p><h2>View synthetic household records</h2><p>When enabled, FinSync displays demo accounts, transaction history, holdings, recurring items, and summaries created for this workspace. The demo does not connect to a bank, broker, or real person’s financial account.</p><ul><li>Consent is checked by the server before synthetic financial data is returned.</li><li>Revoking access pauses synthetic dashboard, advisor, and demo-finance views, but never changes your personal finance records.</li><li>Changing this setting never authorizes a real provider connection or payment.</li></ul></div><div class="consent-control"><span id="consent-toggle-label">Consent is off</span><button id="consent-toggle" class="toggle-button" role="switch" aria-checked="false" aria-label="Grant or revoke demo financial-data consent"><span></span></button></div></article>
          <article class="panel privacy-details"><div class="panel-heading"><div><h2>Data boundaries</h2><p>What this local prototype does—and does not—do.</p></div></div><div class="boundary-grid"><div><i class="fa-solid fa-database"></i><b>Local MySQL</b><p>Profile and synthetic app records are stored in the XAMPP database you configure.</p></div><div><i class="fa-solid fa-plug-circle-xmark"></i><b>No live providers</b><p>Mock adapters normalize fixture data only. FinSync never asks for bank credentials.</p></div><div><i class="fa-solid fa-credit-card"></i><b>No real money</b><p>Payment and renewal flows only create demo receipts in your local database.</p></div></div></article>
        </section>
      </main>
      <footer class="app-footer"><span>FinSync <span id="footer-year"></span></span><span><i class="fa-solid fa-flask"></i> Local informational demo · Synthetic financial records only</span></footer>
    </div>
  </div>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
  <dialog id="receipt-dialog"><article><header><button aria-label="Close" rel="prev" id="receipt-close"></button><h2>Demo receipt created</h2></header><div id="receipt-content"></div><footer><button class="primary-button" id="receipt-ok">Done</button></footer></article></dialog>
  <dialog id="google-modal" class="google-modal"><article><header><button aria-label="Close" rel="prev" id="google-modal-close"></button><h2><i class="fa-brands fa-google"></i> Sign in with Google</h2></header><form id="google-modal-form"><p class="modal-sub">Enter your Google account details to sign in or create your personal account.</p><label>Your Name<input type="text" name="google_name" placeholder="e.g. Alex Morgan" required autocomplete="name"></label><label>Google Email address<input type="email" name="google_email" placeholder="you@gmail.com" required autocomplete="email"></label><p id="google-modal-error" class="form-message error-text" role="status" aria-live="polite"></p><footer><button type="submit" id="google-modal-submit" class="primary-button full-button"><i class="fa-brands fa-google"></i> Continue with Google</button></footer></form></article></dialog>
  <dialog id="family-account-dialog" class="family-account-modal">
    <article>
      <header>
        <button aria-label="Close" rel="prev" id="family-account-dialog-close"></button>
        <h2><i class="fa-solid fa-wallet"></i> Link Demo Account</h2>
      </header>
      <form id="family-account-form">
        <p class="modal-sub">Add a synthetic bank, credit, loan, or investment account for <b id="family-account-member-name">Member</b>.</p>
        <input type="hidden" name="family_member_id" id="family-account-member-id" required>
        <label>Financial Institution
          <input type="text" name="institution" placeholder="e.g. State Bank of India, HDFC Bank, ICICI Bank" required>
        </label>
        <label>Account Type
          <select name="account_type" required>
            <option value="savings" selected>Savings Account</option>
            <option value="checking">Checking / Salary Account</option>
            <option value="credit">Credit Card</option>
            <option value="loan">Personal / Home Loan</option>
            <option value="investment">Investment Portfolio</option>
            <option value="wallet">Digital Wallet</option>
          </select>
        </label>
        <label>Account / Card Number
          <input type="text" name="masked_number" placeholder="•••• 5521" maxlength="24">
        </label>
        <label>Current Balance (₹)
          <input type="number" step="0.01" name="balance" placeholder="25000" required>
        </label>
        <p id="family-account-error" class="form-message error-text" role="status" aria-live="polite"></p>
        <footer>
          <button type="submit" id="family-account-submit" class="primary-button full-button"><i class="fa-solid fa-check"></i> Save &amp; Connect Account</button>
        </footer>
      </form>
    </article>
  </dialog>

  <!-- ZERODHA DEMAT ACCOUNT MODAL -->
  <dialog id="zerodha-modal" class="zerodha-modal">
    <article class="zerodha-dialog-content">
      <header>
        <button aria-label="Close" rel="prev" id="zerodha-modal-close"></button>
        <div class="zerodha-modal-header">
          <span class="zerodha-kite-icon">K</span>
          <div>
            <h2>Connect Zerodha Demat</h2>
            <p>Link your actual Zerodha Kite account and track your Indian equity holdings.</p>
          </div>
        </div>
      </header>

      <!-- TAB BUTTONS -->
      <div class="zerodha-tabs">
        <button type="button" class="zerodha-tab-btn active" data-tab="tab-manual"><i class="fa-solid fa-id-card"></i> Client ID &amp; Stocks</button>
        <button type="button" class="zerodha-tab-btn" data-tab="tab-csv"><i class="fa-solid fa-file-csv"></i> Console CSV Import</button>
        <button type="button" class="zerodha-tab-btn" data-tab="tab-api"><i class="fa-solid fa-bolt"></i> Kite Connect API</button>
      </div>

      <!-- TAB 1: CLIENT ID & STOCKS -->
      <div id="tab-manual" class="zerodha-tab-content">
        <form id="zerodha-manual-form">
          <div class="form-row-2">
            <label>Zerodha Client ID
              <input type="text" name="client_id" placeholder="e.g. SK0717 or AB1234" value="SK0717" required>
            </label>
            <label>16-digit Demat BOID (Optional)
              <input type="text" name="demat_boid" placeholder="e.g. 1208160012345678" maxlength="16">
            </label>
          </div>

          <div class="zerodha-stocks-section">
            <div class="section-title-row">
              <b>Zerodha Stock &amp; ETF Holdings</b>
              <span class="tiny-note">Add your actual shares</span>
            </div>

            <!-- Quick presets -->
            <div class="stock-preset-chips">
              <span style="font-size:11px; color:#64748b; font-weight:600;">Quick add:</span>
              <button type="button" class="stock-chip-add" data-sym="RELIANCE" data-name="Reliance Industries" data-buy="2840.50" data-ltp="2980.20">+ RELIANCE</button>
              <button type="button" class="stock-chip-add" data-sym="TCS" data-name="Tata Consultancy Services" data-buy="3780.00" data-ltp="4120.00">+ TCS</button>
              <button type="button" class="stock-chip-add" data-sym="INFY" data-name="Infosys Ltd" data-buy="1450.00" data-ltp="1860.00">+ INFY</button>
              <button type="button" class="stock-chip-add" data-sym="HDFCBANK" data-name="HDFC Bank Ltd" data-buy="1510.00" data-ltp="1690.00">+ HDFCBANK</button>
              <button type="button" class="stock-chip-add" data-sym="TATAMOTORS" data-name="Tata Motors Ltd" data-buy="820.00" data-ltp="965.50">+ TATAMOTORS</button>
              <button type="button" class="stock-chip-add" data-sym="NIFTYBEES" data-name="Nippon Nifty 50 ETF" data-buy="230.00" data-ltp="262.50">+ NIFTYBEES</button>
            </div>

            <div id="zerodha-holdings-rows" class="stock-input-rows">
              <div class="stock-row-header">
                <span>SYMBOL</span>
                <span>QTY</span>
                <span>BUY PRICE (₹)</span>
                <span>CURRENT LTP (₹)</span>
                <span></span>
              </div>
              <div class="stock-row-item">
                <input type="text" class="h-sym" placeholder="e.g. RELIANCE" value="RELIANCE" required>
                <input type="number" class="h-qty" placeholder="Qty" value="25" min="1" step="any" required>
                <input type="number" class="h-buy" placeholder="Buy Price" value="2840.50" step="0.01" required>
                <input type="number" class="h-ltp" placeholder="Current Price" value="2980.20" step="0.01" required>
                <button type="button" class="icon-button remove-stock-row" title="Remove"><i class="fa-solid fa-trash"></i></button>
              </div>
            </div>

            <button type="button" class="outline-button small-button" id="add-another-stock-btn" style="margin-top:10px;">
              <i class="fa-solid fa-plus"></i> Add another stock
            </button>
          </div>

          <p id="zerodha-manual-error" class="form-message error-text" role="status" aria-live="polite"></p>
          <footer style="margin-top:20px;">
            <button type="submit" id="zerodha-manual-submit" class="primary-button full-button zerodha-btn">
              <i class="fa-solid fa-check"></i> Connect Zerodha Demat &amp; Save Holdings
            </button>
          </footer>
        </form>
      </div>

      <!-- TAB 2: CONSOLE CSV IMPORT -->
      <div id="tab-csv" class="zerodha-tab-content" hidden>
        <form id="zerodha-csv-form">
          <p class="modal-sub">Upload the holdings CSV export from <b>Zerodha Console</b> (Console → Portfolio → Holdings → Download CSV) or paste its contents below.</p>
          
          <label class="file-drop-area" id="zerodha-file-drop">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size:28px; color:#ff5722; margin-bottom:8px;"></i>
            <b>Choose Zerodha CSV file or drag &amp; drop</b>
            <small style="color:#64748b;">holdings.csv from Zerodha Console</small>
            <input type="file" id="zerodha-csv-file" accept=".csv" style="display:none;">
          </label>

          <label style="margin-top:14px;">Or paste CSV content directly:
            <textarea id="zerodha-csv-text" rows="5" placeholder="Instrument,Qty.,Avg. cost,LTP,Cur. val,P&amp;L&#10;RELIANCE,25,2840.50,2980.20,74505.00,3492.50&#10;INFY,50,1420.50,1850.75,92537.50,21512.50"></textarea>
          </label>

          <p id="zerodha-csv-error" class="form-message error-text" role="status" aria-live="polite"></p>
          <footer>
            <button type="submit" id="zerodha-csv-submit" class="primary-button full-button zerodha-btn">
              <i class="fa-solid fa-file-import"></i> Parse &amp; Import Zerodha Holdings
            </button>
          </footer>
        </form>
      </div>

      <!-- TAB 3: KITE CONNECT API -->
      <div id="tab-api" class="zerodha-tab-content" hidden>
        <form id="zerodha-api-form">
          <p class="modal-sub">Direct sync via official <b>Zerodha Kite Connect API</b> (<a href="https://kite.trade" target="_blank" rel="noreferrer" style="color:#ff5722;">kite.trade</a>).</p>
          <label>Kite API Key
            <input type="text" name="api_key" placeholder="e.g. abcdef1234567890" required>
          </label>
          <label>Kite Access Token
            <input type="password" name="access_token" placeholder="Generated daily access token" required>
          </label>
          <div class="auth-safety" style="margin-top:10px;">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Your token is used directly with <code>api.kite.trade/portfolio/holdings</code> to pull your real portfolio into local MySQL.</span>
          </div>

          <p id="zerodha-api-error" class="form-message error-text" role="status" aria-live="polite"></p>
          <footer>
            <button type="submit" id="zerodha-api-submit" class="primary-button full-button zerodha-btn">
              <i class="fa-solid fa-rotate"></i> Sync Live Holdings from Kite API
            </button>
          </footer>
        </form>
      </div>
    </article>
  </dialog>

  <!-- ADD INDIVIDUAL HOLDING MODAL -->
  <dialog id="add-holding-modal" class="family-account-modal">
    <article>
      <header>
        <button aria-label="Close" rel="prev" id="add-holding-close"></button>
        <h2><i class="fa-solid fa-chart-line"></i> Add Stock Holding</h2>
      </header>
      <form id="add-holding-form">
        <p class="modal-sub">Add an Indian equity, ETF, or mutual fund holding to your portfolio.</p>
        <label>Stock / Symbol
          <input type="text" name="symbol" placeholder="e.g. RELIANCE, TCS, INFY, TATAMOTORS, NIFTYBEES" required>
        </label>
        <label>Company / Instrument Name
          <input type="text" name="instrument" placeholder="e.g. Reliance Industries Ltd (Optional)">
        </label>
        <div class="form-row-2">
          <label>Quantity (Shares/Units)
            <input type="number" step="any" name="units" placeholder="e.g. 25" required>
          </label>
          <label>Average Buy Price (₹)
            <input type="number" step="0.01" name="buy_price" placeholder="e.g. 2840.50" required>
          </label>
        </div>
        <label>Current Market Price / LTP (₹)
          <input type="number" step="0.01" name="price" placeholder="e.g. 2980.20" required>
        </label>
        <label>Demat Account / Broker
          <select name="broker_select" id="holding-broker-select">
            <option value="zerodha">Zerodha Demat (Kite)</option>
            <option value="other">Default Portfolio</option>
          </select>
        </label>
        <p id="add-holding-error" class="form-message error-text" role="status" aria-live="polite"></p>
        <footer>
          <button type="submit" class="primary-button full-button"><i class="fa-solid fa-plus"></i> Add to Portfolio</button>
        </footer>
      </form>
    </article>
  </dialog>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <script src="<?= Security::escape($basePath) ?>/assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: '2' ?>" defer></script>
</body>
</html>
