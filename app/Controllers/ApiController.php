<?php
declare(strict_types=1);

namespace FinSync\Controllers;

use FinSync\Core\Database;
use FinSync\Core\Http;
use FinSync\Core\RateLimiter;
use FinSync\Core\Security;
use FinSync\Models\FinanceRepository;
use FinSync\Services\AdvisorService;
use FinSync\Services\ConsentService;
use PDO;
use Throwable;

final class ApiController
{
    private PDO $db;
    private ConsentService $consent;
    private FinanceRepository $finance;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->consent = new ConsentService($this->db);
        $this->finance = new FinanceRepository($this->db);
    }

    public function dispatch(string $path, string $method): void
    {
        if ($path === '/api/health' && $method === 'GET') {
            $this->db->query('SELECT 1');
            Http::json(['data' => ['status' => 'ok', 'mode' => 'local-demo']]);
        }
        if ($path === '/api/session' && $method === 'GET') {
            $id = Security::currentUserId();
            $user = null;
            $granted = false;
            if ($id !== null) {
                $statement = $this->db->prepare('SELECT id, name, email, avatar_url, auth_provider FROM users WHERE id = :id AND status = "active" LIMIT 1');
                $statement->execute(['id' => $id]);
                $user = $statement->fetch() ?: null;
                if ($user === null) {
                    unset($_SESSION['user_id']);
                } else {
                    $granted = $this->consent->granted($id);
                }
            }
            Http::json(['data' => [
                'authenticated' => $user !== null,
                'user' => $user ? [
                    'id' => (int)$user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'avatar' => $user['avatar_url'] ?? null,
                    'auth_provider' => $user['auth_provider'] ?? 'local',
                ] : null,
                'consent_granted' => $granted,
                'csrf_token' => Security::csrfToken(),
            ]]);
        }

        $auth = new AuthController();
        if ($path === '/api/register' && $method === 'POST') {
            Http::requireJson();
            Security::requireCsrf();
            $auth->register(Http::body());
        }
        if ($path === '/api/login' && $method === 'POST') {
            Http::requireJson();
            Security::requireCsrf();
            $auth->login(Http::body());
        }
        if ($path === '/api/auth/google' && $method === 'POST') {
            Http::requireJson();
            Security::requireCsrf();
            $auth->google(Http::body());
        }

        $userId = Security::requireUser();
        if ($method !== 'GET') {
            Http::requireJson();
            Security::requireCsrf();
        }

        if ($path === '/api/logout' && $method === 'POST') {
            $auth->logout($userId);
        }
        if ($path === '/api/consent' && $method === 'GET') {
            Http::json(['data' => ['financial_data' => ['granted' => $this->consent->granted($userId), 'description' => 'Access to this account’s synthetic family-finance records within FinSync.']]]);
        }
        if ($path === '/api/consent' && $method === 'PUT') {
            $body = Http::body();
            if (!array_key_exists('granted', $body) || !is_bool($body['granted'])) {
                Http::error('Choose whether demo financial-data access should be granted.');
            }
            $this->consent->set($userId, $body['granted']);
            $this->audit($userId, $body['granted'] ? 'consent.granted' : 'consent.revoked', 'consent', null);
            Http::json(['data' => ['granted' => $body['granted']]]);
        }
        if ($path === '/api/personal-finance' && $method === 'GET') {
            $monthInput = $_GET['month'] ?? date('Y-m');
            if (!is_string($monthInput)) {
                Http::error('Choose a valid month for the financial analysis.');
            }
            $month = trim($monthInput);
            if (!$this->validMonth($month)) {
                Http::error('Choose a valid month for the financial analysis.');
            }
            Http::json(['data' => $this->finance->personalFinance($userId, $month)]);
        }
        if ($path === '/api/personal-finance' && $method === 'POST') {
            $record = $this->validatePersonalFinance(Http::body());
            $id = $this->finance->savePersonalFinance($userId, $record);
            $this->audit($userId, 'personal_finance.created', 'personal_finance_record', $id);
            Http::json(['data' => ['id' => $id]], 201);
        }
        if (preg_match('#^/api/personal-finance/([1-9]\d*)$#', $path, $match) && $method === 'PUT') {
            $id = (int)$match[1];
            $record = $this->validatePersonalFinance(Http::body());
            if ($this->finance->savePersonalFinance($userId, $record, $id) === 0) {
                Http::error('Financial record not found.', 'not_found', 404);
            }
            $this->audit($userId, 'personal_finance.updated', 'personal_finance_record', $id);
            Http::json(['data' => ['id' => $id, 'updated' => true]]);
        }
        if (preg_match('#^/api/personal-finance/([1-9]\d*)$#', $path, $match) && $method === 'DELETE') {
            $id = (int)$match[1];
            if (!$this->finance->deletePersonalFinance($userId, $id)) {
                Http::error('Financial record not found.', 'not_found', 404);
            }
            $this->audit($userId, 'personal_finance.deleted', 'personal_finance_record', $id);
            Http::json(['data' => ['deleted' => true]]);
        }
        $this->requireConsent($userId);
        if ($path === '/api/dashboard' && $method === 'GET') {
            Http::json(['data' => $this->finance->dashboard($userId)]);
        }
        if ($path === '/api/accounts' && $method === 'GET') {
            Http::json(['data' => $this->finance->accountList($userId)]);
        }
        if ($path === '/api/accounts' && $method === 'POST') {
            $body = Http::body();
            $memberId = (int)($body['family_member_id'] ?? 0);
            if ($memberId <= 0) {
                Http::error('Choose a valid family member for this account.');
            }
            try {
                $accountId = $this->finance->createAccount($userId, $body);
                $this->audit($userId, 'account.created', 'financial_account', $accountId);
                Http::json(['data' => ['id' => $accountId, 'success' => true]], 201);
            } catch (\InvalidArgumentException $e) {
                Http::error($e->getMessage(), 'invalid_input', 400);
            }
        }
        if ($path === '/api/transactions' && $method === 'GET') {
            $category = trim((string)($_GET['category'] ?? ''));
            if (strlen($category) > 240) {
                Http::error('The selected category is invalid.');
            }
            Http::json(['data' => $this->finance->transactions($userId, 200, $category ?: null)]);
        }
        if ($path === '/api/investments' && $method === 'GET') {
            Http::json(['data' => $this->finance->holdings($userId)]);
        }
        if ($path === '/api/zerodha' && $method === 'GET') {
            Http::json(['data' => $this->finance->zerodhaAccount($userId)]);
        }
        if ($path === '/api/zerodha/connect' && $method === 'POST') {
            $body = Http::body();
            $clientId = trim((string)($body['client_id'] ?? ''));
            $dematBoid = trim((string)($body['demat_boid'] ?? ''));
            if ($clientId === '' && $dematBoid === '') {
                Http::error('Enter your Zerodha Client ID or Demat BO ID.');
            }
            $accountId = $this->finance->connectZerodhaDemat($userId, $clientId ?: $dematBoid, $dematBoid ?: null);

            if (!empty($body['holdings']) && is_array($body['holdings'])) {
                $this->finance->importZerodhaHoldings($userId, $accountId, $body['holdings'], false);
            }

            $this->audit($userId, 'zerodha.connected', 'financial_account', $accountId);
            Http::json(['data' => $this->finance->zerodhaAccount($userId)], 201);
        }
        if ($path === '/api/zerodha/import-csv' && $method === 'POST') {
            $body = Http::body();
            $csv = (string)($body['csv'] ?? '');
            $accountId = (int)($body['account_id'] ?? 0);
            if ($accountId <= 0) {
                $zerodha = $this->finance->zerodhaAccount($userId);
                $accountId = $zerodha ? (int)$zerodha['account']['id'] : $this->finance->connectZerodhaDemat($userId, 'Kite User');
            }
            if (trim($csv) === '') {
                Http::error('No CSV content provided for import.');
            }
            $parsed = $this->finance->parseZerodhaCsv($csv);
            if (empty($parsed)) {
                Http::error('No valid stock holdings found in CSV. Confirm column headers (Instrument/Symbol, Qty, Price).');
            }
            $count = $this->finance->importZerodhaHoldings($userId, $accountId, $parsed, true);
            $this->audit($userId, 'zerodha.csv_imported', 'financial_account', $accountId);
            Http::json(['data' => ['imported_count' => $count, 'zerodha' => $this->finance->zerodhaAccount($userId)]]);
        }
        if ($path === '/api/zerodha/sync-kite' && $method === 'POST') {
            $body = Http::body();
            $apiKey = trim((string)($body['api_key'] ?? ''));
            $accessToken = trim((string)($body['access_token'] ?? ''));
            $accountId = (int)($body['account_id'] ?? 0);
            if ($apiKey === '' || $accessToken === '') {
                Http::error('Enter both Zerodha Kite API Key and Access Token.');
            }
            if ($accountId <= 0) {
                $zerodha = $this->finance->zerodhaAccount($userId);
                $accountId = $zerodha ? (int)$zerodha['account']['id'] : $this->finance->connectZerodhaDemat($userId, 'Kite User');
            }
            try {
                $res = $this->finance->syncZerodhaKiteApi($userId, $accountId, $apiKey, $accessToken);
                $this->audit($userId, 'zerodha.kite_synced', 'financial_account', $accountId);
                Http::json(['data' => ['imported_count' => $res['imported_count'], 'zerodha' => $this->finance->zerodhaAccount($userId)]]);
            } catch (\Throwable $e) {
                Http::error($e->getMessage(), 'kite_api_error', 400);
            }
        }
        if ($path === '/api/zerodha/holding' && $method === 'POST') {
            $body = Http::body();
            $accountId = (int)($body['account_id'] ?? 0);
            if ($accountId <= 0) {
                $zerodha = $this->finance->zerodhaAccount($userId);
                $accountId = $zerodha ? (int)$zerodha['account']['id'] : $this->finance->connectZerodhaDemat($userId, 'Kite User');
            }
            $symbol = strtoupper(trim((string)($body['symbol'] ?? '')));
            $instrument = trim((string)($body['instrument'] ?? $symbol));
            $units = (float)($body['units'] ?? 0);
            $buyPrice = (float)($body['buy_price'] ?? 0);
            $price = (float)($body['price'] ?? $buyPrice);
            if ($symbol === '' || $units <= 0) {
                Http::error('Enter a valid stock symbol and quantity.');
            }
            $holding = [
                'symbol' => $symbol,
                'instrument' => $instrument ?: $symbol,
                'units' => $units,
                'buy_price' => $buyPrice,
                'price' => $price,
            ];
            $this->finance->importZerodhaHoldings($userId, $accountId, [$holding], false);
            Http::json(['data' => $this->finance->zerodhaAccount($userId)], 201);
        }
        if (preg_match('#^/api/holdings/(\d+)$#', $path, $match) && $method === 'DELETE') {
            $deleted = $this->finance->deleteHolding($userId, (int)$match[1]);
            if (!$deleted) {
                Http::error('Holding not found.', 'not_found', 404);
            }
            Http::json(['data' => ['deleted' => true, 'holdings' => $this->finance->holdings($userId)]]);
        }
        if ($path === '/api/family-overview' && $method === 'GET') {
            Http::json(['data' => $this->finance->familyOverview($userId)]);
        }
        if ($path === '/api/family' && $method === 'GET') {
            Http::json(['data' => $this->finance->family($userId)]);
        }
        if (preg_match('#^/api/family/(\d+)/dashboard$#', $path, $match) && $method === 'GET') {
            $dashboard = $this->finance->familyMemberDashboard($userId, (int)$match[1]);
            if ($dashboard === null) {
                Http::error('Family member not found.', 'not_found', 404);
            }
            Http::json(['data' => $dashboard]);
        }
        if ($path === '/api/family' && $method === 'POST') {
            $body = Http::body();
            $name = trim((string)($body['name'] ?? ''));
            $relationship = trim((string)($body['relationship'] ?? ''));
            $nameLength = preg_match_all('/./us', $name);
            if ($nameLength === false || $nameLength < 2 || $nameLength > 120 || preg_match('/[\x00-\x1F<>]/u', $name)) {
                Http::error('Enter a family member name between 2 and 120 characters.');
            }
            if (!in_array($relationship, ['Partner', 'Child', 'Parent', 'Sibling', 'Other'], true)) {
                Http::error('Choose a listed family relationship.');
            }
            $statement = $this->db->prepare('INSERT INTO family_members (user_id, name, relationship) VALUES (:user_id, :name, :relationship)');
            $statement->execute(['user_id' => $userId, 'name' => $name, 'relationship' => $relationship]);
            $id = (int)$this->db->lastInsertId();
            $this->audit($userId, 'family.created', 'family_member', $id);
            Http::json(['data' => ['id' => $id, 'name' => $name, 'relationship' => $relationship, 'status' => 'active']], 201);
        }
        if (preg_match('#^/api/family/(\d+)$#', $path, $match) && $method === 'PATCH') {
            $body = Http::body();
            if (($body['status'] ?? null) !== 'inactive') {
                Http::error('Only deactivation is supported for this demo profile.');
            }
            $statement = $this->db->prepare('UPDATE family_members SET status = "inactive" WHERE id = :id AND user_id = :user_id');
            $statement->execute(['id' => (int)$match[1], 'user_id' => $userId]);
            if ($statement->rowCount() === 0) {
                Http::error('Family member not found.', 'not_found', 404);
            }
            $this->audit($userId, 'family.deactivated', 'family_member', (int)$match[1]);
            Http::json(['data' => ['deactivated' => true]]);
        }
        if ($path === '/api/recurring' && $method === 'GET') {
            Http::json(['data' => $this->finance->recurring($userId)]);
        }
        if ($path === '/api/notifications' && $method === 'GET') {
            Http::json(['data' => $this->finance->notifications($userId)]);
        }
        if (preg_match('#^/api/notifications/(\d+)/read$#', $path, $match) && $method === 'POST') {
            $statement = $this->db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
            $statement->execute(['id' => (int)$match[1], 'user_id' => $userId]);
            if ($statement->rowCount() === 0) {
                Http::error('Notification not found.', 'not_found', 404);
            }
            Http::json(['data' => ['read' => true]]);
        }
        if ($path === '/api/payments' && $method === 'GET') {
            Http::json(['data' => $this->finance->payments($userId)]);
        }
        if ($path === '/api/payments' && $method === 'POST') {
            $this->createSimulatedPayment($userId, Http::body());
        }
        if ($path === '/api/advisor' && $method === 'POST') {
            if (!RateLimiter::allow('advisor', 20, 3600)) {
                Http::error('Advisor demo limit reached. Try again later.', 'rate_limited', 429);
            }
            $body = Http::body();
            $question = trim((string)($body['question'] ?? ''));
            $questionLength = preg_match_all('/./us', $question);
            if ($questionLength === false || $questionLength < 3 || $questionLength > 500) {
                Http::error('Enter a question between 3 and 500 characters.');
            }
            $result = (new AdvisorService())->respond($question, $this->finance->dashboard($userId));
            $this->audit($userId, 'advisor.fallback_used', 'advisor', null);
            Http::json(['data' => $result]);
        }

        Http::error('Route not found.', 'not_found', 404);
    }

    private function createSimulatedPayment(int $userId, array $body): void
    {
        $recurringId = filter_var($body['recurring_id'] ?? null, FILTER_VALIDATE_INT);
        if ($recurringId !== false && $recurringId !== null) {
            $statement = $this->db->prepare(
                'SELECT id, name, amount FROM recurring_payments WHERE id = :id AND user_id = :user_id AND status = "active"'
            );
            $statement->execute(['id' => (int)$recurringId, 'user_id' => $userId]);
            $recurring = $statement->fetch();
            if (!$recurring) {
                Http::error('Recurring demo item not found.', 'not_found', 404);
            }
            $description = (string)$recurring['name'];
            $amount = (float)$recurring['amount'];
            $referenceId = (int)$recurring['id'];
        } else {
            $description = trim((string)($body['description'] ?? 'Demo payment'));
            $amount = filter_var($body['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $descriptionLength = preg_match_all('/./us', $description);
            if ($descriptionLength === false || $descriptionLength < 3 || $descriptionLength > 140 || $amount === false || $amount <= 0 || $amount > 10000) {
                Http::error('Provide a description and a demo amount above zero and no more than 10,000.');
            }
            $referenceId = null;
        }
        $receipt = 'FS-DEMO-' . strtoupper(bin2hex(random_bytes(6)));
        $statement = $this->db->prepare(
            'INSERT INTO simulated_payments (user_id, recurring_payment_id, description, amount, state, receipt_reference) '
            . 'VALUES (:user_id, :recurring_id, :description, :amount, "demo_completed", :receipt)'
        );
        $statement->execute([
            'user_id' => $userId, 'recurring_id' => $referenceId,
            'description' => $description, 'amount' => $amount, 'receipt' => $receipt,
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->audit($userId, 'payment.simulated', 'simulated_payment', $id);
        Http::json(['data' => [
            'id' => $id, 'description' => $description, 'amount' => round((float)$amount, 2),
            'state' => 'demo_completed', 'receipt_reference' => $receipt,
            'notice' => 'This is a demo receipt only. No payment processor or real account was contacted.',
        ]], 201);
    }

    private function requireConsent(int $userId): void
    {
        if (!$this->consent->granted($userId)) {
            Http::error('Grant demo financial-data consent before viewing these records.', 'consent_required', 403);
        }
    }

    private function validatePersonalFinance(array $body): array
    {
        $monthInput = $body['month'] ?? '';
        if (!is_string($monthInput)) {
            Http::error('Choose a valid month for this financial record.');
        }
        $month = trim($monthInput);
        if (!$this->validMonth($month)) {
            Http::error('Choose a valid month for this financial record.');
        }

        $amounts = [];
        foreach ([
            'monthly_income' => 'Monthly income',
            'other_income' => 'Other income',
            'monthly_expenses' => 'Monthly expenses',
            'savings' => 'Savings',
            'investments' => 'Investments',
            'loans_debt' => 'Loans/debt',
            'credit_card_balance' => 'Credit card balance',
        ] as $field => $label) {
            $value = $body[$field] ?? ($field === 'monthly_income' || $field === 'monthly_expenses' ? null : '0');
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                Http::error("Enter a valid non-negative amount for {$label}.");
            }
            $text = trim((string)$value);
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $text)) {
                Http::error("Enter a valid non-negative amount for {$label} (up to two decimal places).");
            }
            $amounts[$field] = number_format((float)$text, 2, '.', '');
        }

        $categoriesInput = $body['expense_categories'] ?? [];
        if (!is_array($categoriesInput) || count($categoriesInput) > 20) {
            Http::error('Add no more than 20 expense categories.');
        }
        $categories = [];
        $seen = [];
        foreach ($categoriesInput as $item) {
            if (!is_array($item)) {
                Http::error('Enter a category and amount for each expense category.');
            }
            $rawName = $item['category'] ?? '';
            if (!is_string($rawName)) {
                Http::error('Enter a valid expense category name.');
            }
            $name = trim($rawName);
            $rawAmount = $item['amount'] ?? '';
            $categoryLength = preg_match_all('/./us', $name);
            if ($name === '' && ($rawAmount === '' || $rawAmount === null)) {
                continue;
            }
            if ($categoryLength === false || $categoryLength < 1 || $categoryLength > 40
                || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s&\'-]*$/u', $name)) {
                Http::error('Use a valid expense category name of up to 40 characters.');
            }
            $key = strtolower($name);
            if (isset($seen[$key])) {
                Http::error('Use each expense category only once per record.');
            }
            $seen[$key] = true;
            if (!is_string($rawAmount) && !is_int($rawAmount) && !is_float($rawAmount)) {
                Http::error("Enter a valid amount for the {$name} category.");
            }
            $amountText = trim((string)$rawAmount);
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amountText) || (float)$amountText <= 0) {
                Http::error("Enter a category amount above zero for {$name}.");
            }
            $categories[] = ['category' => $name, 'amount' => number_format((float)$amountText, 2, '.', '')];
        }

        $goals = $this->validatedOptionalText($body['financial_goals'] ?? '', 'Financial goals', 2000);
        $notes = $this->validatedOptionalText($body['notes'] ?? '', 'Notes', 2000);
        return [
            'record_month' => $month . '-01',
            'monthly_income' => $amounts['monthly_income'],
            'other_income' => $amounts['other_income'],
            'monthly_expenses' => $amounts['monthly_expenses'],
            'expense_categories' => $categories,
            'savings' => $amounts['savings'],
            'investments' => $amounts['investments'],
            'loans_debt' => $amounts['loans_debt'],
            'credit_card_balance' => $amounts['credit_card_balance'],
            'financial_goals' => $goals,
            'notes' => $notes,
        ];
    }

    private function validatedOptionalText(mixed $value, string $label, int $maxLength): string
    {
        if (!is_string($value)) {
            Http::error("Enter valid text for {$label}.");
        }
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            Http::error("{$label} must be no more than {$maxLength} characters and cannot contain control characters.");
        }
        return trim($value);
    }

    private function validMonth(string $month): bool
    {
        return preg_match('/^(\d{4})-(\d{2})$/D', $month, $matches) === 1
            && checkdate((int)$matches[2], 1, (int)$matches[1]);
    }

    private function audit(int $userId, string $action, string $type, ?int $resourceId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, resource_type, resource_id) VALUES (:actor, :action, :type, :resource)'
        );
        $statement->execute(['actor' => $userId, 'action' => $action, 'type' => $type, 'resource' => $resourceId]);
    }
}
