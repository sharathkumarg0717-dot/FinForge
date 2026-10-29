(() => {
  'use strict';

  const base = window.FINSYNC_BASE || '';
  const state = { csrf: '', user: null, consent: false, dashboard: null, personalFinance: null, accounts: [], family: [], transactions: [], investments: [], recurring: [], notifications: [], payments: [], cashChart: null, categoryChart: null, personalCashChart: null, personalCategoryChart: null };
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const money = (value) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number(value) || 0);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const dateLabel = (value) => {
    if (!value) return '—';
    const date = new Date(`${value}T12:00:00`);
    return Number.isNaN(date.getTime()) ? esc(value) : new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
  };
  const currentMonth = () => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  };
  const icons = {
    checking: 'fa-building-columns', savings: 'fa-piggy-bank', credit: 'fa-credit-card',
    investment: 'fa-chart-pie', loan: 'fa-house', wallet: 'fa-wallet',
  };

  async function api(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET' && state.csrf) headers['X-CSRF-Token'] = state.csrf;
    const response = await fetch(`${base}${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const result = await response.json().catch(() => ({}));
    if (result.data?.csrf_token) state.csrf = result.data.csrf_token;
    if (!response.ok) {
      const error = new Error(result.error?.message || `Request failed (${response.status}).`);
      error.code = result.error?.code || 'request_failed';
      error.status = response.status;
      throw error;
    }
    return result.data;
  }

  function toast(message, error = false) {
    const element = $('#toast');
    element.textContent = message;
    element.classList.toggle('toast-error', error);
    element.classList.add('toast-visible');
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => element.classList.remove('toast-visible'), 3600);
  }

  function showNotice(message, kind = 'info') {
    const region = $('#notice-region');
    if (!message) {
      region.innerHTML = '';
      return;
    }
    const icon = kind === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info';
    region.innerHTML = `<div class="inline-notice ${kind === 'error' ? 'notice-error' : ''}"><i class="fa-solid ${icon}"></i><span>${esc(message)}</span><button class="icon-button" aria-label="Dismiss notice"><i class="fa-solid fa-xmark"></i></button></div>`;
    $('.inline-notice button', region).addEventListener('click', () => { region.innerHTML = ''; });
  }

  function showAuth(mode = 'login') {
    $('#app-view').hidden = true;
    $('#auth-view').hidden = false;
    setAuthMode(mode);
  }

  function showApp() {
    $('#auth-view').hidden = true;
    $('#app-view').hidden = false;
    $('#today-label').textContent = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date());
    $('#footer-year').textContent = new Date().getFullYear();
  }

  function setAuthMode(mode) {
    const registering = mode === 'register';
    $$('.auth-tab').forEach((button) => {
      const active = button.dataset.authMode === mode;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', String(active));
    });
    $('#name-field').hidden = !registering;
    $('#consent-field').hidden = !registering;
    $('#auth-title').textContent = registering ? 'Create your demo space' : 'Sign in to your space';
    $('#auth-subtitle').textContent = registering ? 'Set up a private local workspace with synthetic data.' : 'A household overview, designed around clarity.';
    $('#auth-submit span').textContent = registering ? 'Create account' : 'Sign in';
    const password = $('#auth-form [name="password"]');
    password.autocomplete = registering ? 'new-password' : 'current-password';
    $('#auth-message').textContent = '';
    $('#auth-form').dataset.mode = mode;
  }

  function goTo(screen) {
    const valid = ['personal-finance', 'overview', 'family', 'accounts', 'transactions', 'investments', 'reminders', 'advisor', 'payments', 'consent'];
    if (!valid.includes(screen)) screen = 'overview';
    if (!state.consent && screen !== 'consent' && screen !== 'personal-finance') {
      screen = 'overview';
      $('#consent-gate').hidden = false;
      $$('.screen').forEach((section) => { section.hidden = true; });
    } else {
      $('#consent-gate').hidden = true;
      $$('.screen').forEach((section) => { section.hidden = section.id !== `screen-${screen}`; });
    }
    $$('.nav-link').forEach((item) => item.classList.toggle('active', item.dataset.nav === screen));
    $('#page-crumb').textContent = screen[0].toUpperCase() + screen.slice(1).replace('-', ' ');
    $('#workspace-mode-label').textContent = screen === 'personal-finance' ? 'PERSONAL DATA' : 'DEMO DATA';
    $('#sidebar').classList.remove('sidebar-open');
    if (screen === 'consent') refreshConsent();
    if (state.consent || screen === 'personal-finance') {
      const loaders = {
        'personal-finance': () => loadPersonalFinance($('#finance-month').value || currentMonth()),
        overview: loadDashboard, family: loadFamily, accounts: loadAccounts,
        transactions: () => loadTransactions($('#transaction-filter').value),
        investments: loadInvestments, reminders: loadReminders,
        payments: loadPayments,
      };
      if (loaders[screen]) loaders[screen]().catch(handleError);
    }
  }

  function handleError(error) {
    if (error.code === 'consent_required') {
      state.consent = false;
      updateConsentControls();
      goTo('consent');
      showNotice(error.message, 'error');
    } else {
      showNotice(error.message || 'Something went wrong. Please try again.', 'error');
    }
  }

  function updateConsentControls() {
    const button = $('#consent-toggle');
    button.setAttribute('aria-checked', String(state.consent));
    button.classList.toggle('toggle-on', state.consent);
    $('#consent-toggle-label').textContent = state.consent ? 'Consent is on' : 'Consent is off';
    const badge = $('#consent-status-badge');
    badge.textContent = state.consent ? 'Active' : 'Revoked';
    badge.className = `status-chip ${state.consent ? 'success-chip' : 'warning-chip'}`;
    if (!state.consent) {
      $('#consent-gate').hidden = $('#screen-consent').hidden;
    }
  }

  async function refreshConsent() {
    try {
      const data = await api('/api/consent');
      state.consent = Boolean(data.financial_data.granted);
      updateConsentControls();
    } catch (error) { handleError(error); }
  }

  async function initializeSession() {
    try {
      const result = await api('/api/session');
      state.csrf = result.csrf_token || '';
      state.user = result.user;
      state.consent = Boolean(result.consent_granted);
      if (!result.authenticated || !result.user) {
        showAuth();
        return;
      }
      showApp();
      showUser(result.user);
      updateConsentControls();
      goTo('personal-finance');
    } catch (error) {
      showAuth();
      $('#auth-message').textContent = error.message || 'Could not reach the local FinSync service.';
    }
  }

  function showUser(user) {
    const first = (user.name || 'there').trim().split(/\s+/)[0];
    $('#profile-name').textContent = user.name;
    $('#profile-email').textContent = user.email;
    $('#profile-avatar').textContent = first.charAt(0).toUpperCase();
    $('#greeting-name').textContent = first;
    $('#advisor-name').textContent = first;
  }

  async function loadDashboard() {
    const [data, allTransactions] = await Promise.all([api('/api/dashboard'), api('/api/transactions')]);
    state.dashboard = data;
    $('#net-worth').textContent = money(data.net_worth);
    $('#monthly-income').textContent = money(data.monthly_income);
    $('#monthly-expenses').textContent = money(data.monthly_expenses);
    $('#investment-value').textContent = money(data.investment_value);
    $('#account-count').textContent = data.account_count;
    $('#monthly-net').textContent = `Net ${money(data.monthly_net)}`;
    $('#health-score').textContent = data.health_score;
    $('#health-label').textContent = data.health_score >= 75 ? 'On a steady track' : 'Room to explore';
    $('#health-explanation').textContent = data.health_explanation;
    $('#family-count').textContent = '—';
    $('#reminder-count').textContent = data.unread_notifications ? String(data.unread_notifications) : '';
    $('#notification-dot').hidden = !data.unread_notifications;
    renderRecent(data.transactions || []);
    renderUpcoming(data.upcoming || []);
    renderCashflow(data.monthly_income, data.monthly_expenses);
    renderCategories(allTransactions);
    showNotice('');
  }

  async function loadPersonalFinance(month = currentMonth(), isUserAction = false) {
    const data = await api(`/api/personal-finance?month=${encodeURIComponent(month)}`);
    state.personalFinance = data;

    // If the selected month has no records, but the user has records in other months,
    // and this wasn't an explicit user month-picker change, auto-switch to their latest recorded month!
    if (!isUserAction && data.analysis.record_count === 0 && data.latest_month && data.latest_month !== month) {
      return loadPersonalFinance(data.latest_month, false);
    }

    const analysis = data.analysis;
    const hasRecords = analysis.record_count > 0;
    const amount = (value) => hasRecords ? money(value) : '—';
    $('#finance-month').value = data.month;
    $('#finance-empty').hidden = hasRecords;
    $('#finance-income').textContent = amount(analysis.total_income);
    $('#finance-expenses').textContent = amount(analysis.total_expenses);
    $('#finance-net').textContent = amount(analysis.net_savings);
    $('#finance-net').classList.toggle('amount-expense', hasRecords && analysis.net_savings < 0);
    $('#finance-savings-rate').textContent = hasRecords && analysis.savings_rate !== null ? `${analysis.savings_rate}%` : '—';
    $('#finance-debt').textContent = amount(analysis.total_debt);
    $('#finance-assets').textContent = amount(analysis.savings_balance + analysis.investments);
    $('#finance-month-label').textContent = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' })
      .format(new Date(`${data.month}-01T12:00:00`)).toUpperCase();
    $('#finance-cashflow-caption').textContent = hasRecords
      ? `${money(analysis.total_income)} total income · ${money(analysis.total_expenses)} expenses`
      : 'Add a record to see your monthly cash flow.';

    // Update recorded months quick switch chips
    const monthsBar = $('#finance-recorded-months');
    const chipsContainer = $('#finance-month-chips');
    if (data.available_months && data.available_months.length > 0) {
      monthsBar.hidden = false;
      chipsContainer.innerHTML = data.available_months.map((m) => {
        const label = new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric' })
          .format(new Date(`${m}-01T12:00:00`));
        return `<button type="button" class="month-chip ${m === data.month ? 'active' : ''}" data-month="${esc(m)}">${esc(label)}</button>`;
      }).join('');
    } else {
      monthsBar.hidden = true;
      chipsContainer.innerHTML = '';
    }

    renderPersonalCashflow(analysis);
    renderPersonalCategories(analysis.spending_by_category);

    // Render Indicators
    $('#finance-indicators').innerHTML = hasRecords && analysis.indicators.length
      ? analysis.indicators.map((item) => `<div class="finance-indicator ${esc(item.status)}"><i class="fa-solid ${esc(item.icon || 'fa-circle-info')}"></i><span>${esc(item.label)}</span></div>`).join('')
      : '<div class="empty-state"><p>Indicators will appear once you save your monthly figures.</p></div>';

    // Render Suggestions
    $('#finance-suggestions').innerHTML = analysis.suggestions.length
      ? analysis.suggestions.map((item) => `<p class="finance-suggestion"><i class="fa-solid fa-lightbulb" style="color:#2563eb; margin-right:6px;"></i>${esc(item)}</p>`).join('')
      : '<div class="empty-state"><p>Personalized suggestions will be generated dynamically from your stored financial data.</p></div>';

    // Render Goals
    $('#finance-goals').innerHTML = analysis.goals.length
      ? analysis.goals.map((goal) => `<p class="finance-goal"><small><i class="fa-solid fa-bullseye" style="color:#16a34a; margin-right:4px;"></i>${esc(goal.month)}</small>${esc(goal.text).replace(/\n/g, '<br>')}</p>`).join('')
      : '<div class="empty-state"><p>No goals saved for this month yet. Enter a goal when saving your record.</p></div>';

    // Render Notes
    const notesContainer = $('#finance-notes');
    if (notesContainer) {
      notesContainer.innerHTML = (analysis.notes && analysis.notes.length)
        ? analysis.notes.map((n) => `<p class="finance-note-item"><small><i class="fa-solid fa-note-sticky" style="color:#6366f1; margin-right:4px;"></i>${esc(n.month)}</small>${esc(n.text).replace(/\n/g, '<br>')}</p>`).join('')
        : '<div class="empty-state"><p>No notes saved for this month.</p></div>';
    }

    // Render Records Table
    $('#finance-record-count').textContent = `${data.records.length} saved ${data.records.length === 1 ? 'record' : 'records'}`;
    $('#finance-records-empty').hidden = data.records.length > 0;
    $('#finance-records-table').innerHTML = data.records.map((record) => {
      const monthLabel = new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric' })
        .format(new Date(`${record.record_month}T12:00:00`));
      const totalIncome = Number(record.monthly_income) + Number(record.other_income);
      const totalExpenses = Number(record.monthly_expenses);
      const netSavings = totalIncome - totalExpenses;
      const savingsAssets = Number(record.savings) + Number(record.investments);
      const debt = Number(record.loans_debt) + Number(record.credit_card_balance);
      const snippet = (record.financial_goals || record.notes || '—').trim();
      const truncated = snippet.length > 35 ? snippet.slice(0, 32) + '…' : snippet;
      const netClass = netSavings >= 0 ? 'amount-income' : 'amount-expense';

      return `<tr>
        <td><strong>${esc(monthLabel)}</strong></td>
        <td>${money(totalIncome)}</td>
        <td>${money(totalExpenses)}</td>
        <td class="${netClass}"><strong>${netSavings >= 0 ? '+' : ''}${money(netSavings)}</strong></td>
        <td>${money(savingsAssets)}</td>
        <td>${money(debt)}</td>
        <td title="${esc(snippet)}">${esc(truncated)}</td>
        <td class="align-right">
          <div class="finance-record-actions">
            <button class="outline-button small-button finance-edit" type="button" data-id="${Number(record.id)}"><i class="fa-solid fa-pen-to-square"></i> Edit</button>
            <button class="outline-button small-button finance-delete" type="button" data-id="${Number(record.id)}"><i class="fa-solid fa-trash"></i> Delete</button>
          </div>
        </td>
      </tr>`;
    }).join('');

    // If user has zero records at all, open the form so they don't have to search for it
    if (data.records.length === 0) {
      $('#finance-form-panel').hidden = false;
    }
  }

  function renderPersonalCashflow(analysis) {
    const fallback = $('#finance-cashflow-fallback');
    fallback.innerHTML = analysis.record_count
      ? `<span>Income: <b>${money(analysis.total_income)}</b></span><span>Expenses: <b>${money(analysis.total_expenses)}</b></span><b>Cash flow: ${money(analysis.cash_flow)}</b>`
      : '<span>No saved financial figures for this month.</span>';
    if (typeof Chart === 'undefined') return;
    if (state.personalCashChart) state.personalCashChart.destroy();
    state.personalCashChart = null;
    if (!analysis.record_count) return;
    state.personalCashChart = new Chart($('#finance-cashflow-chart'), {
      type: 'bar',
      data: {
        labels: ['Selected month'], datasets: [
          { label: 'Total Income', data: [analysis.record_count ? Number(analysis.total_income) : 0], backgroundColor: '#16a34a', borderRadius: 8, maxBarThickness: 48 },
          { label: 'Total Expenses', data: [analysis.record_count ? Number(analysis.total_expenses) : 0], backgroundColor: '#d97706', borderRadius: 8, maxBarThickness: 48 },
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${money(context.raw)}` } } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#0f172a', font: { weight: '600', size: 12 } } },
          y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#0f172a', font: { weight: '550' }, callback: (value) => money(value) } }
        },
      },
    });
  }

  function renderPersonalCategories(categories) {
    const colors = ['#2563eb', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#dc2626', '#db2777', '#4b5563'];
    const empty = categories.length === 0;
    $('#finance-category-empty').hidden = !empty;
    $('#finance-category-summary').innerHTML = categories.map((item, index) =>
      `<div class="category-summary-row">
        <span><i style="--category-color:${colors[index % colors.length]}"></i>${esc(item.category)} <span class="category-pct">(${item.percentage || 0}%)</span></span>
        <b>${money(item.amount)}</b>
      </div>`
    ).join('');
    if (typeof Chart === 'undefined') return;
    if (state.personalCategoryChart) state.personalCategoryChart.destroy();
    state.personalCategoryChart = null;
    if (empty) return;
    state.personalCategoryChart = new Chart($('#finance-category-chart'), {
      type: 'doughnut',
      data: {
        labels: categories.map((item) => item.category), datasets: [{
          data: categories.map((item) => Number(item.amount)),
          backgroundColor: categories.map((_, index) => colors[index % colors.length]),
          borderWidth: 0, hoverOffset: 4,
        }]
      },
      options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${context.label}: ${money(context.raw)}` } } } },
    });
  }

  function updateCategorySum() {
    const rows = $$('.finance-category-row', $('#finance-category-fields'));
    let sum = 0;
    rows.forEach((row) => {
      const val = parseFloat($('[name="amount"]', row)?.value) || 0;
      sum += val;
    });
    const display = $('#category-sum-display');
    if (display) display.textContent = money(sum);
    return sum;
  }

  function addFinanceCategoryRow(category = '', amount = '') {
    const container = $('#finance-category-fields');
    const row = document.createElement('div');
    row.className = 'finance-category-row';
    row.innerHTML = `<label>Category name
      <input type="text" name="category" maxlength="40" list="finance-category-options" value="${esc(category)}" placeholder="e.g. Food, Rent, Utilities">
    </label>
    <label>Amount ($)
      <input type="number" name="amount" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" value="${esc(amount)}" placeholder="0.00">
    </label>
    <button class="icon-button" type="button" data-remove-category aria-label="Remove category" title="Remove category"><i class="fa-solid fa-trash"></i></button>`;
    container.append(row);
    updateCategorySum();
    return row;
  }

  function resetFinanceForm(month = $('#finance-month').value || currentMonth()) {
    const form = $('#finance-form');
    form.reset();
    form.elements.id.value = '';
    form.elements.month.value = month;
    ['other_income', 'savings', 'investments', 'loans_debt', 'credit_card_balance'].forEach((field) => {
      if (form.elements[field]) form.elements[field].value = '0.00';
    });
    $('#finance-category-fields').replaceChildren();
    updateCategorySum();
    $('#finance-form-title').textContent = 'Enter financial information';
    $('#finance-save-button').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save financial record';
    $('#finance-cancel-edit').hidden = true;
    $('#finance-form-message').textContent = '';
    $('#finance-form-message').classList.remove('error-text');
  }

  function clearPersonalFinanceView() {
    state.personalFinance = null;
    if (state.personalCashChart) state.personalCashChart.destroy();
    if (state.personalCategoryChart) state.personalCategoryChart.destroy();
    state.personalCashChart = null;
    state.personalCategoryChart = null;
    ['finance-income', 'finance-expenses', 'finance-net', 'finance-savings-rate', 'finance-debt', 'finance-assets']
      .forEach((selector) => { $(`#${selector}`).textContent = '—'; });
    $('#finance-net').classList.remove('amount-expense');
    $('#finance-records-table').replaceChildren();
    $('#finance-category-summary').replaceChildren();
    $('#finance-indicators').replaceChildren();
    $('#finance-suggestions').replaceChildren();
    $('#finance-goals').replaceChildren();
    const notesEl = $('#finance-notes');
    if (notesEl) notesEl.replaceChildren();
    $('#finance-record-count').textContent = 'All stored records in MySQL';
    $('#finance-form-panel').hidden = true;
    resetFinanceForm(currentMonth());
  }

  function editPersonalFinance(id) {
    const record = state.personalFinance?.records.find((item) => Number(item.id) === Number(id));
    if (!record) {
      toast('That financial record is no longer available. Refresh and try again.', true);
      return;
    }
    const form = $('#finance-form');
    const recordMonth = String(record.record_month).slice(0, 7);
    $('#finance-month').value = recordMonth;
    loadPersonalFinance(recordMonth, true).catch(handleError);
    form.elements.id.value = record.id;
    form.elements.month.value = recordMonth;
    ['monthly_income', 'other_income', 'monthly_expenses', 'savings', 'investments', 'loans_debt', 'credit_card_balance']
      .forEach((field) => { if (form.elements[field]) form.elements[field].value = record[field]; });
    form.elements.financial_goals.value = record.financial_goals || '';
    form.elements.notes.value = record.notes || '';
    $('#finance-category-fields').replaceChildren();
    (record.expense_categories || []).forEach((item) => addFinanceCategoryRow(item.category, item.amount));
    updateCategorySum();
    $('#finance-form-title').textContent = `Edit financial record (${recordMonth})`;
    $('#finance-save-button').innerHTML = '<i class="fa-solid fa-check"></i> Save changes';
    $('#finance-cancel-edit').hidden = false;
    $('#finance-form-message').textContent = '';
    $('#finance-form-message').classList.remove('error-text');
    $('#finance-form-panel').hidden = false;
    $('#finance-form-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    form.elements.monthly_income.focus();
  }

  function renderCashflow(income, expenses) {
    const fallback = $('#cashflow-fallback');
    fallback.innerHTML = `<span>Income: <b>${money(income)}</b></span><span>Expenses: <b>${money(expenses)}</b></span>`;
    if (typeof Chart === 'undefined') return;
    if (state.cashChart) state.cashChart.destroy();
    state.cashChart = new Chart($('#cashflow-chart'), {
      type: 'bar',
      data: {
        labels: ['This month'], datasets: [
          { label: 'Income', data: [Number(income)], backgroundColor: '#39b98a', borderRadius: 8, maxBarThickness: 44 },
          { label: 'Expenses', data: [Number(expenses)], backgroundColor: '#f2a94a', borderRadius: 8, maxBarThickness: 44 },
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${money(context.raw)}` } } },
        scales: { x: { grid: { display: false }, ticks: { color: '#75819a' } }, y: { beginAtZero: true, grid: { color: '#edf0f5' }, ticks: { color: '#75819a', callback: (value) => money(value) } } },
      },
    });
  }

  function renderCategories(rows) {
    const now = new Date();
    const month = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    const totals = new Map();
    rows.filter((item) => item.direction === 'expense' && String(item.transaction_date).startsWith(month))
      .forEach((item) => totals.set(item.category, (totals.get(item.category) || 0) + Number(item.amount)));
    const sorted = [...totals.entries()].sort((a, b) => b[1] - a[1]);
    const colors = ['#6685e8', '#41b88c', '#f0a94f', '#9a82df', '#65b8cf', '#eb7f86'];
    const empty = sorted.length === 0;
    $('#category-empty').hidden = !empty;
    $('#category-summary').innerHTML = empty ? '' : sorted.map(([category, amount], index) => `<div class="category-summary-row"><span><i style="--category-color:${colors[index % colors.length]}"></i>${esc(category)}</span><b>${money(amount)}</b></div>`).join('');
    if (typeof Chart === 'undefined' || empty) {
      if (state.categoryChart) state.categoryChart.destroy();
      state.categoryChart = null;
      return;
    }
    if (state.categoryChart) state.categoryChart.destroy();
    state.categoryChart = new Chart($('#category-chart'), {
      type: 'doughnut',
      data: { labels: sorted.map(([category]) => category), datasets: [{ data: sorted.map(([, amount]) => amount), backgroundColor: sorted.map((_, index) => colors[index % colors.length]), borderWidth: 0, hoverOffset: 4 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '69%', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${context.label}: ${money(context.raw)}` } } } },
    });
  }

  function renderRecent(rows) {
    const target = $('#recent-transactions');
    if (!rows.length) {
      target.innerHTML = '<div class="empty-state"><p>No demo activity yet.</p></div>';
      return;
    }
    target.innerHTML = rows.slice(0, 5).map((item) => {
      const glyph = item.direction === 'income' ? 'fa-arrow-down' : 'fa-arrow-up';
      const amountClass = item.direction === 'income' ? 'amount-income' : 'amount-expense';
      return `<div class="transaction-row"><span class="transaction-glyph ${amountClass}"><i class="fa-solid ${glyph}"></i></span><span class="transaction-detail"><b>${esc(item.description)}</b><small>${esc(item.category)} · ${dateLabel(item.transaction_date)}</small></span><strong class="${amountClass}">${item.direction === 'income' ? '+' : '−'}${money(item.amount)}</strong></div>`;
    }).join('');
  }

  function renderUpcoming(rows) {
    const target = $('#upcoming-list');
    target.innerHTML = rows.length ? rows.map((item) => {
      const due = new Date(`${item.next_due_date}T12:00:00`);
      const days = Math.ceil((due - new Date(new Date().toDateString())) / 86400000);
      const dueLabel = days <= 0 ? 'Due today' : days === 1 ? 'Due tomorrow' : `In ${days} days`;
      return `<div class="upcoming-row"><span class="calendar-date"><b>${esc(item.next_due_date.slice(8, 10))}</b><small>${esc(new Intl.DateTimeFormat(undefined, { month: 'short' }).format(due))}</small></span><span class="upcoming-detail"><b>${esc(item.name)}</b><small>${dueLabel} · ${esc(item.cadence)}</small></span><strong>${money(item.amount)}</strong></div>`;
    }).join('') : '<div class="empty-state"><p>No upcoming demo items.</p></div>';
  }

  async function loadFamily() {
    state.family = await api('/api/family');
    $('#family-count').textContent = state.family.filter((member) => member.status === 'active').length || '';
    $('#family-total').textContent = `${state.family.length} PROFILES`;
    const active = state.family.filter((member) => member.status === 'active');
    $('#family-empty').hidden = active.length > 0;
    $('#family-table').innerHTML = active.map((member) => `<tr><td><span class="member-cell"><span class="member-avatar">${esc(member.name.charAt(0).toUpperCase())}</span><b>${esc(member.name)}</b></span></td><td>${esc(member.relationship)}</td><td>${Number(member.account_count)} demo accounts</td><td><span class="status-chip success-chip">Active</span></td><td class="align-right"><button class="text-button deactivate-member" data-id="${Number(member.id)}">Deactivate</button></td></tr>`).join('');
  }

  async function loadAccounts() {
    state.accounts = await api('/api/accounts');
    const totals = {};
    state.accounts.forEach((account) => { totals[account.account_type] = (totals[account.account_type] || 0) + 1; });
    $('#account-summary').innerHTML = Object.entries(totals).map(([type, count]) => `<article class="stat-card mini-stat"><div class="stat-top"><span class="stat-label">${esc(type[0].toUpperCase() + type.slice(1))} accounts</span><span class="stat-icon blue-icon"><i class="fa-solid ${icons[type] || 'fa-wallet'}"></i></span></div><div class="stat-value">${count}</div><div class="stat-foot"><span class="tiny-note">Mock adapter</span></div></article>`).join('');
    $('#accounts-table').innerHTML = state.accounts.map((account) => `<tr><td><span class="institution-cell"><span class="account-icon"><i class="fa-solid ${icons[account.account_type] || 'fa-wallet'}"></i></span><span><b>${esc(account.institution)}</b><small>Mock provider</small></span></span></td><td>${esc(account.member_name)}</td><td><span class="type-chip">${esc(account.account_type)}</span></td><td class="masked">${esc(account.masked_number)}</td><td class="align-right"><b class="${Number(account.balance) < 0 ? 'amount-expense' : ''}">${money(account.balance)}</b></td></tr>`).join('');
  }

  async function loadTransactions(category = '') {
    const query = category ? `?category=${encodeURIComponent(category)}` : '';
    state.transactions = await api(`/api/transactions${query}`);
    const select = $('#transaction-filter');
    const selected = select.value;
    const categories = [...new Set(state.transactions.map((transaction) => transaction.category))].sort();
    if (!category) {
      select.innerHTML = '<option value="">All categories</option>' + categories.map((item) => `<option value="${esc(item)}">${esc(item)}</option>`).join('');
      select.value = selected;
    }
    $('#transaction-count').textContent = `${state.transactions.length} synthetic entries`;
    $('#transactions-empty').hidden = state.transactions.length > 0;
    $('#transactions-table').innerHTML = state.transactions.map((item) => `<tr><td><span class="transaction-cell"><span class="transaction-glyph ${item.direction === 'income' ? 'amount-income' : 'amount-expense'}"><i class="fa-solid ${item.direction === 'income' ? 'fa-arrow-down' : 'fa-arrow-up'}"></i></span><b>${esc(item.description)}</b></span></td><td><span class="type-chip">${esc(item.category)}</span></td><td><span>${esc(item.institution)}<small class="table-sub">${esc(item.masked_number)}</small></span></td><td>${dateLabel(item.transaction_date)}</td><td class="align-right"><b class="${item.direction === 'income' ? 'amount-income' : ''}">${item.direction === 'income' ? '+' : '−'}${money(item.amount)}</b></td></tr>`).join('');
  }

  async function loadInvestments() {
    state.investments = await api('/api/investments');
    const total = state.investments.reduce((sum, item) => sum + Number(item.market_value), 0);
    $('#holdings-total').textContent = money(total);
    $('#investments-table').innerHTML = state.investments.map((item) => `<tr><td><b>${esc(item.instrument)}</b><small class="table-sub">${esc(item.institution)}</small></td><td><span class="symbol-chip">${esc(item.symbol)}</span></td><td>${Number(item.units).toFixed(3)}</td><td>${money(item.price)}</td><td class="align-right"><b>${money(item.market_value)}</b></td></tr>`).join('');
  }

  async function loadReminders() {
    const [recurring, notifications] = await Promise.all([api('/api/recurring'), api('/api/notifications')]);
    state.recurring = recurring;
    state.notifications = notifications;
    const unread = notifications.filter((item) => !Number(item.is_read)).length;
    $('#unread-label').textContent = `${unread} UNREAD`;
    $('#reminder-count').textContent = unread ? String(unread) : '';
    $('#notification-dot').hidden = unread === 0;
    $('#recurring-table').innerHTML = recurring.map((item) => `<tr><td><b>${esc(item.name)}</b><small class="table-sub">Synthetic schedule</small></td><td><span class="type-chip">${esc(item.cadence)}</span></td><td>${dateLabel(item.next_due_date)}</td><td class="align-right"><b>${money(item.amount)}</b></td><td class="align-right"><button class="outline-button small-button simulate-payment" data-id="${Number(item.id)}"><i class="fa-solid fa-flask"></i> Simulate</button></td></tr>`).join('');
    $('#notifications-list').innerHTML = notifications.length ? notifications.map((item) => `<div class="notification-row ${Number(item.is_read) ? 'notification-read' : ''}"><span class="notification-icon"><i class="fa-regular ${item.type === 'reminder' ? 'fa-calendar' : 'fa-circle-info'}"></i></span><span class="notification-copy"><b>${esc(item.message)}</b><small>${item.due_date ? `Due ${dateLabel(item.due_date)}` : 'FinSync demo notice'}</small></span>${Number(item.is_read) ? '<span class="read-label">Read</span>' : `<button class="text-button mark-read" data-id="${Number(item.id)}">Mark read</button>`}</div>`).join('') : '<div class="empty-state"><p>No notifications.</p></div>';
  }

  async function loadPayments() {
    state.payments = await api('/api/payments');
    $('#payments-empty').hidden = state.payments.length > 0;
    $('#payments-list').innerHTML = state.payments.map((item) => `<div class="receipt-row"><span class="receipt-icon"><i class="fa-solid fa-receipt"></i></span><span class="receipt-copy"><b>${esc(item.description)}</b><small>${dateLabel(item.created_at.slice(0, 10))} · Ref ${esc(item.receipt_reference)}</small></span><span class="receipt-amount"><b>${money(item.amount)}</b><small class="status-chip info-chip">DEMO ONLY</small></span></div>`).join('');
  }

  async function createPayment(recurringId) {
    try {
      const receipt = await api('/api/payments', { method: 'POST', body: { recurring_id: Number(recurringId) } });
      $('#receipt-content').innerHTML = `<div class="receipt-success"><i class="fa-solid fa-circle-check"></i><b>Demo receipt recorded</b><p>${esc(receipt.description)} · ${money(receipt.amount)}</p><span>${esc(receipt.receipt_reference)}</span><small>${esc(receipt.notice)}</small></div>`;
      $('#receipt-dialog').showModal();
      await loadPayments();
      toast('Demo receipt created. No real payment was made.');
    } catch (error) { handleError(error); }
  }

  async function submitAdvisor(event) {
    event.preventDefault();
    const input = $('#advisor-question');
    const form = $('#advisor-form');
    const submitBtn = form ? $('button[type="submit"]', form) : null;
    const question = input.value.trim();
    if (!question) return;
    if (submitBtn) submitBtn.disabled = true;
    const messages = $('#chat-messages');
    messages.insertAdjacentHTML('beforeend', `<div class="chat-bubble user-bubble"><span class="chat-avatar"><i class="fa-regular fa-user"></i></span><div><b>You</b><p>${esc(question)}</p></div></div>`);
    input.value = '';
    const pending = document.createElement('div');
    pending.className = 'chat-bubble assistant-bubble pending-bubble';
    pending.innerHTML = '<span class="chat-avatar"><i class="fa-solid fa-sparkles"></i></span><div><b>FinSync insight</b><p><i class="fa-solid fa-circle-notch fa-spin"></i> Reviewing your demo snapshot…</p></div>';
    messages.append(pending);
    messages.scrollTop = messages.scrollHeight;
    try {
      const result = await api('/api/advisor', { method: 'POST', body: { question } });
      pending.classList.remove('pending-bubble');
      pending.innerHTML = `<span class="chat-avatar"><i class="fa-solid fa-sparkles"></i></span><div><b>FinSync insight · Local fallback</b><p>${esc(result.answer)}</p><small>${esc(result.disclaimer)}</small></div>`;
    } catch (error) {
      pending.classList.remove('pending-bubble');
      pending.classList.add('error-bubble');
      pending.innerHTML = `<span class="chat-avatar error-avatar"><i class="fa-solid fa-circle-exclamation"></i></span><div><b>FinSync insight</b><p class="chat-error-text">${esc(error.message || 'Unable to generate insight at this time. Please try again.')}</p></div>`;
      handleError(error);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
      input.focus();
    }
    messages.scrollTop = messages.scrollHeight;
  }

  $('#auth-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const mode = form.dataset.mode;
    const data = Object.fromEntries(new FormData(form).entries());
    data.consent_demo = Boolean(form.elements.consent_demo?.checked);
    const message = $('#auth-message');
    message.textContent = '';
    const button = $('#auth-submit');
    button.disabled = true;
    try {
      const result = await api(mode === 'register' ? '/api/register' : '/api/login', { method: 'POST', body: data });
      state.user = result.user;
      state.csrf = result.csrf_token;
      const consent = await api('/api/consent');
      state.consent = Boolean(consent.financial_data.granted);
      clearPersonalFinanceView();
      showApp();
      showUser(result.user);
      updateConsentControls();
      goTo('personal-finance');
      toast(mode === 'register' ? 'Your local demo workspace is ready.' : 'Welcome back.');
    } catch (error) {
      message.textContent = error.message;
      message.classList.add('error-text');
    } finally { button.disabled = false; }
  });

  $$('.auth-tab').forEach((button) => button.addEventListener('click', () => setAuthMode(button.dataset.authMode)));
  document.addEventListener('click', async (event) => {
    const nav = event.target.closest('[data-nav]');
    if (nav) {
      event.preventDefault();
      goTo(nav.dataset.nav);
      return;
    }
    const removeCategory = event.target.closest('[data-remove-category]');
    if (removeCategory) {
      removeCategory.closest('.finance-category-row').remove();
      updateCategorySum();
      return;
    }
    const preset = event.target.closest('.category-preset');
    if (preset) {
      const cat = preset.dataset.cat;
      const existing = $$('.finance-category-row', $('#finance-category-fields')).find((row) => {
        return $('[name="category"]', row)?.value.trim().toLowerCase() === cat.toLowerCase();
      });
      if (existing) {
        $('[name="amount"]', existing)?.focus();
      } else {
        const row = addFinanceCategoryRow(cat, '');
        $('[name="amount"]', row)?.focus();
      }
      return;
    }
    const monthChip = event.target.closest('.month-chip');
    if (monthChip) {
      const targetMonth = monthChip.dataset.month;
      if (targetMonth) {
        $('#finance-month').value = targetMonth;
        loadPersonalFinance(targetMonth, true).catch(handleError);
      }
      return;
    }
    const editFinance = event.target.closest('.finance-edit');
    if (editFinance) {
      editPersonalFinance(editFinance.dataset.id);
      return;
    }
    const deleteFinance = event.target.closest('.finance-delete');
    if (deleteFinance) {
      if (!window.confirm('Delete this saved financial record? This cannot be undone.')) return;
      try {
        await api(`/api/personal-finance/${Number(deleteFinance.dataset.id)}`, { method: 'DELETE', body: {} });
        await loadPersonalFinance($('#finance-month').value || currentMonth());
        toast('Financial record deleted.');
      } catch (error) { handleError(error); }
      return;
    }
    const simulate = event.target.closest('.simulate-payment');
    if (simulate) { await createPayment(simulate.dataset.id); return; }
    const markRead = event.target.closest('.mark-read');
    if (markRead) {
      try { await api(`/api/notifications/${Number(markRead.dataset.id)}/read`, { method: 'POST', body: {} }); await loadReminders(); }
      catch (error) { handleError(error); }
      return;
    }
    const deactivate = event.target.closest('.deactivate-member');
    if (deactivate && window.confirm('Deactivate this profile in the household demo?')) {
      try { await api(`/api/family/${Number(deactivate.dataset.id)}`, { method: 'PATCH', body: { status: 'inactive' } }); await loadFamily(); toast('Family profile deactivated.'); }
      catch (error) { handleError(error); }
    }
  });

  $('#logout-button').addEventListener('click', async () => {
    try { await api('/api/logout', { method: 'POST', body: {} }); }
    catch (error) { handleError(error); }
    state.user = null;
    state.consent = false;
    state.csrf = '';
    clearPersonalFinanceView();
    try {
      const anonymousSession = await api('/api/session');
      state.csrf = anonymousSession.csrf_token || '';
    } catch (error) {
      showNotice(error.message || 'Refresh the page before signing in again.', 'error');
    }
    showAuth();
    toast('You have signed out.');
  });
  $('#refresh-button').addEventListener('click', () => loadDashboard().then(() => toast('Overview refreshed.')).catch(handleError));
  $('#finance-month').value = currentMonth();
  $('#finance-month').addEventListener('change', (event) => {
    if (event.target.value) loadPersonalFinance(event.target.value, true).catch(handleError);
  });
  const openFinanceForm = () => {
    resetFinanceForm($('#finance-month').value || currentMonth());
    $('#finance-form-panel').hidden = false;
    $('#finance-form-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('#finance-form [name="monthly_income"]').focus();
  };
  $('#finance-open-form').addEventListener('click', openFinanceForm);
  $('#add-finance-record').addEventListener('click', openFinanceForm);
  $('#finance-form-close').addEventListener('click', () => { $('#finance-form-panel').hidden = true; });
  $('#add-finance-category').addEventListener('click', () => addFinanceCategoryRow());
  $('#finance-cancel-edit').addEventListener('click', () => resetFinanceForm());

  const syncBtn = $('#sync-category-expense');
  if (syncBtn) {
    syncBtn.addEventListener('click', () => {
      const sum = updateCategorySum();
      const expensesInput = $('#finance-form [name="monthly_expenses"]');
      if (expensesInput) {
        expensesInput.value = sum.toFixed(2);
        expensesInput.focus();
        toast(`Monthly expenses set to ${money(sum)}`);
      }
    });
  }

  const catFields = $('#finance-category-fields');
  if (catFields) {
    catFields.addEventListener('input', () => updateCategorySum());
  }

  $('#finance-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    const message = $('#finance-form-message');
    const button = $('#finance-save-button');
    message.textContent = '';
    message.classList.remove('error-text');
    const body = Object.fromEntries(new FormData(form).entries());
    const id = body.id;
    delete body.id;
    delete body.category;
    delete body.amount;

    // Validate non-negative amounts
    for (const f of ['monthly_income', 'other_income', 'monthly_expenses', 'savings', 'investments', 'loans_debt', 'credit_card_balance']) {
      if (body[f] !== undefined && body[f] !== '') {
        const num = parseFloat(body[f]);
        if (isNaN(num) || num < 0) {
          message.textContent = 'Please enter a valid non-negative number for all monetary fields.';
          message.classList.add('error-text');
          return;
        }
      }
    }

    body.expense_categories = $$('.finance-category-row', form).map((row) => ({
      category: $('[name="category"]', row).value.trim(),
      amount: $('[name="amount"]', row).value.trim(),
    })).filter((cat) => cat.category !== '' || cat.amount !== '');

    for (const cat of body.expense_categories) {
      if (!cat.category) {
        message.textContent = 'Please enter a category name for each category row.';
        message.classList.add('error-text');
        return;
      }
      const amt = parseFloat(cat.amount);
      if (isNaN(amt) || amt <= 0) {
        message.textContent = `Please enter an amount greater than 0 for "${cat.category}".`;
        message.classList.add('error-text');
        return;
      }
    }

    button.disabled = true;
    const origBtnHtml = button.innerHTML;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving to MySQL...';

    try {
      await api(id ? `/api/personal-finance/${Number(id)}` : '/api/personal-finance', {
        method: id ? 'PUT' : 'POST',
        body,
      });
      $('#finance-form-panel').hidden = true;
      $('#finance-month').value = body.month;
      resetFinanceForm(body.month);
      await loadPersonalFinance(body.month, true);
      toast(id ? 'Financial record updated in MySQL database.' : 'Financial record saved in MySQL database.');
    } catch (error) {
      message.textContent = error.message || 'The financial record could not be saved.';
      message.classList.add('error-text');
    } finally {
      button.disabled = false;
      button.innerHTML = origBtnHtml;
    }
  });
  $('#transaction-filter').addEventListener('change', (event) => loadTransactions(event.target.value).catch(handleError));
  $('#add-family-button').addEventListener('click', () => { $('#family-form-panel').hidden = false; $('#family-form [name="name"]').focus(); });
  $('#family-form-close').addEventListener('click', () => { $('#family-form-panel').hidden = true; });
  $('#family-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget || event.target || $('#family-form');
    if (!form) return;
    const submitBtn = form.querySelector('button[type="submit"]');
    const body = Object.fromEntries(new FormData(form).entries());
    if (submitBtn) submitBtn.disabled = true;
    try {
      await api('/api/family', { method: 'POST', body });
      if (typeof form.reset === 'function') {
        form.reset();
      }
      const panel = $('#family-form-panel');
      if (panel) panel.hidden = true;
      await loadFamily();
      toast('Family profile added. No account was connected.');
    } catch (error) {
      handleError(error);
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  });
  $('#consent-toggle').addEventListener('click', async () => {
    const next = !state.consent;
    if (!next && !window.confirm('Revoke access to the synthetic family-finance records? Finance and advisor views will be paused.')) return;
    try {
      await api('/api/consent', { method: 'PUT', body: { granted: next } });
      state.consent = next;
      updateConsentControls();
      showNotice(next ? 'Consent granted. Synthetic demo data is available.' : 'Consent revoked. Financial data access is paused.');
      if (next) goTo('overview');
      else goTo('consent');
    } catch (error) { handleError(error); }
  });
  $('#advisor-form').addEventListener('submit', submitAdvisor);
  $('#mobile-menu').addEventListener('click', () => $('#sidebar').classList.toggle('sidebar-open'));
  $('#receipt-close').addEventListener('click', () => $('#receipt-dialog').close());
  $('#receipt-ok').addEventListener('click', () => $('#receipt-dialog').close());
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') $('#sidebar').classList.remove('sidebar-open');
  });

  initializeSession();
})();
