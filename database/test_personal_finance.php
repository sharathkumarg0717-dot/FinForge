<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Models/FinanceRepository.php';

$db = \FinSync\Core\Database::connection();
$db->beginTransaction();

try {
    $createUser = $db->prepare(
        'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
    );
    $passwordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $createUser->execute([
        'name' => 'FinSync temporary verification',
        'email' => 'finance-test-' . bin2hex(random_bytes(12)) . '@example.invalid',
        'password_hash' => $passwordHash,
    ]);
    $ownerId = (int)$db->lastInsertId();
    $createUser->execute([
        'name' => 'FinSync temporary verification',
        'email' => 'finance-test-' . bin2hex(random_bytes(12)) . '@example.invalid',
        'password_hash' => $passwordHash,
    ]);
    $otherUserId = (int)$db->lastInsertId();

    $repository = new \FinSync\Models\FinanceRepository($db);
    $record = [
        'record_month' => '2026-10-01',
        'monthly_income' => '4000.00',
        'other_income' => '100.00',
        'monthly_expenses' => '3200.00',
        'expense_categories' => [
            ['category' => 'Food', 'amount' => '900.00'],
            ['category' => 'Rent', 'amount' => '1500.00'],
            ['category' => 'Transport', 'amount' => '200.00'],
        ],
        'savings' => '500.00',
        'investments' => '800.00',
        'loans_debt' => '300.00',
        'credit_card_balance' => '150.00',
        'financial_goals' => 'Build emergency savings',
        'notes' => 'Temporary rollback-only test record',
    ];
    $recordId = $repository->savePersonalFinance($ownerId, $record);
    $analysis = $repository->personalFinance($ownerId, '2026-10')['analysis'];
    if ($analysis['total_income'] !== 4100.0 || $analysis['total_expenses'] !== 3200.0
        || $analysis['cash_flow'] !== 900.0 || $analysis['savings_rate'] !== 22.0
        || $analysis['total_debt'] !== 450.0
        || $analysis['highest_spending_category']['category'] !== 'Rent') {
        throw new RuntimeException('Personal finance analysis returned unexpected totals.');
    }

    if ($repository->personalFinance($otherUserId, '2026-10')['analysis']['record_count'] !== 0
        || $repository->deletePersonalFinance($otherUserId, $recordId)) {
        throw new RuntimeException('Personal finance data was not isolated to its owner.');
    }

    $record['monthly_income'] = '2000.00';
    if ($repository->savePersonalFinance($ownerId, $record, $recordId) === 0
        || $repository->personalFinance($ownerId, '2026-10')['analysis']['cash_flow'] !== -1100.0) {
        throw new RuntimeException('Personal finance update or negative cash flow analysis failed.');
    }

    if (!$repository->deletePersonalFinance($ownerId, $recordId)
        || $repository->personalFinance($ownerId, '2026-10')['analysis']['record_count'] !== 0) {
        throw new RuntimeException('Personal finance deletion did not update the analysis.');
    }

    fwrite(STDOUT, "Personal finance database smoke test passed; temporary data will be rolled back.\n");
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
