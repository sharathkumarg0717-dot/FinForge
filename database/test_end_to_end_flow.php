<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Database.php';
require_once dirname(__DIR__) . '/app/Models/FinanceRepository.php';

$db = \FinSync\Core\Database::connection();
echo "--- Starting FinSync Personal Finance End-to-End Verification ---\n";

$testEmailA = 'e2e-userA-' . bin2hex(random_bytes(6)) . '@example.com';
$testEmailB = 'e2e-userB-' . bin2hex(random_bytes(6)) . '@example.com';
$passwordHash = password_hash('StrongPassword123!', PASSWORD_DEFAULT);

$createUserStmt = $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)');
$createUserStmt->execute(['name' => 'Alice Test', 'email' => $testEmailA, 'password_hash' => $passwordHash]);
$userAId = (int)$db->lastInsertId();

$createUserStmt->execute(['name' => 'Bob Test', 'email' => $testEmailB, 'password_hash' => $passwordHash]);
$userBId = (int)$db->lastInsertId();

echo "[1] Registered test users: User A (ID {$userAId}), User B (ID {$userBId})\n";

try {
    $repo = new \FinSync\Models\FinanceRepository($db);

    // Month 1: 2026-09 (Healthy surplus)
    $record1 = [
        'record_month' => '2026-09-01',
        'monthly_income' => '5000.00',
        'other_income' => '500.00',
        'monthly_expenses' => '3200.00',
        'expense_categories' => [
            ['category' => 'Rent', 'amount' => '1500.00'],
            ['category' => 'Food', 'amount' => '800.00'],
            ['category' => 'Transport', 'amount' => '300.00'],
            ['category' => 'Utilities', 'amount' => '300.00'],
            ['category' => 'Entertainment', 'amount' => '300.00'],
        ],
        'savings' => '10000.00',
        'investments' => '15000.00',
        'loans_debt' => '2500.00',
        'credit_card_balance' => '450.00',
        'financial_goals' => 'Build 6-month emergency fund',
        'notes' => 'September budget test',
    ];

    $rec1Id = $repo->savePersonalFinance($userAId, $record1);
    echo "[2] Saved record 1 for 2026-09 with ID {$rec1Id}\n";

    // Month 2: 2026-10 (Deficit / negative cash flow)
    $record2 = [
        'record_month' => '2026-10-01',
        'monthly_income' => '3000.00',
        'other_income' => '0.00',
        'monthly_expenses' => '3800.00',
        'expense_categories' => [
            ['category' => 'Rent', 'amount' => '1800.00'],
            ['category' => 'Food', 'amount' => '1200.00'],
            ['category' => 'Healthcare', 'amount' => '800.00'],
        ],
        'savings' => '8000.00',
        'investments' => '15000.00',
        'loans_debt' => '5000.00',
        'credit_card_balance' => '1200.00',
        'financial_goals' => 'Pay off credit card balance',
        'notes' => 'October deficit test',
    ];

    $rec2Id = $repo->savePersonalFinance($userAId, $record2);
    echo "[3] Saved record 2 for 2026-10 with ID {$rec2Id}\n";

    // Direct MySQL verification
    $checkDbStmt = $db->prepare('SELECT COUNT(*) FROM personal_finance_records WHERE user_id = :user_id');
    $checkDbStmt->execute(['user_id' => $userAId]);
    $countInDb = (int)$checkDbStmt->fetchColumn();
    assert($countInDb === 2, "Expected 2 records in MySQL table personal_finance_records, got {$countInDb}");
    echo "[4] Confirmed 2 records physically exist in MySQL database table personal_finance_records\n";

    // Analysis for Month 1 (Surplus)
    $res1 = $repo->personalFinance($userAId, '2026-09');
    $a1 = $res1['analysis'];
    assert($a1['total_income'] === 5500.0, "Total income should be 5500.0");
    assert($a1['total_expenses'] === 3200.0, "Total expenses should be 3200.0");
    assert($a1['cash_flow'] === 2300.0, "Cash flow should be 2300.0");
    assert($a1['savings_rate'] === 41.8, "Savings rate should be 41.8%");
    assert($a1['total_debt'] === 2950.0, "Total debt should be 2950.0");
    assert($a1['highest_spending_category']['category'] === 'Rent', "Highest category should be Rent");
    echo "[5] Month 1 analysis verified: Total Income \${$a1['total_income']}, Total Expenses \${$a1['total_expenses']}, Net Surplus +\${$a1['cash_flow']}, Savings Rate {$a1['savings_rate']}%\n";

    // Analysis for Month 2 (Deficit)
    $res2 = $repo->personalFinance($userAId, '2026-10');
    $a2 = $res2['analysis'];
    assert($a2['total_income'] === 3000.0, "Total income should be 3000.0");
    assert($a2['total_expenses'] === 3800.0, "Total expenses should be 3800.0");
    assert($a2['cash_flow'] === -800.0, "Cash flow should be -800.0");
    assert($a2['total_debt'] === 6200.0, "Total debt should be 6200.0");
    assert($a2['highest_spending_category']['category'] === 'Rent', "Highest category should be Rent");

    // Check negative cash flow warning suggestion exists
    $hasWarning = false;
    foreach ($a2['suggestions'] as $sug) {
        if (str_contains($sug, 'Warning') || str_contains($sug, 'negative cash flow') || str_contains($sug, 'higher than your income')) {
            $hasWarning = true;
            break;
        }
    }
    assert($hasWarning, "Expected negative cash flow warning suggestion for Month 2");
    echo "[6] Month 2 deficit analysis verified: Total Income \${$a2['total_income']}, Expenses \${$a2['total_expenses']}, Deficit \${$a2['cash_flow']}. Negative warning triggered correctly: TRUE\n";

    // Dashboard Overview integration verification (Requirement 3 & 4: no fake values once user entered data)
    $dash = $repo->dashboard($userAId);
    assert($dash['has_personal_records'] === true, "Dashboard should recognize real personal records");
    assert($dash['monthly_income'] === 3000.0, "Dashboard income should match user's latest record");
    assert($dash['monthly_expenses'] === 3800.0, "Dashboard expenses should match user's latest record");
    echo "[7] Dashboard overview verified using REAL user data instead of demo values\n";

    // Test Edit
    $record2['monthly_expenses'] = '2400.00';
    $updatedRows = $repo->savePersonalFinance($userAId, $record2, $rec2Id);
    assert($updatedRows === 1, "Expected 1 row updated");
    $res2Updated = $repo->personalFinance($userAId, '2026-10');
    assert($res2Updated['analysis']['cash_flow'] === 600.0, "Cash flow after edit should be +600.0");
    echo "[8] Edit record verified: Expenses updated to \$2400.00, Cash flow turned positive (+\${$res2Updated['analysis']['cash_flow']})\n";

    // Test Delete
    $deleted = $repo->deletePersonalFinance($userAId, $rec1Id);
    assert($deleted === true, "Expected record 1 to be deleted");
    $checkAfterDel = $repo->personalFinance($userAId, '2026-09');
    assert($checkAfterDel['analysis']['record_count'] === 0, "Record count for 2026-09 should now be 0");
    assert(count($checkAfterDel['records']) === 1, "Total user records should now be 1");
    echo "[9] Delete record verified: Record 1 removed, 1 record remaining\n";

    // Test User Isolation (Security: User B cannot view, edit, or delete User A's data)
    $resB = $repo->personalFinance($userBId, '2026-10');
    assert($resB['analysis']['record_count'] === 0, "User B must see 0 records");
    assert(count($resB['records']) === 0, "User B records list must be empty");

    $maliciousDelete = $repo->deletePersonalFinance($userBId, $rec2Id);
    assert($maliciousDelete === false, "User B must NOT be able to delete User A's record");

    $maliciousUpdate = $repo->savePersonalFinance($userBId, $record2, $rec2Id);
    assert($maliciousUpdate === 0, "User B must NOT be able to update User A's record");
    echo "[10] Security verified: User isolation prevents User B from viewing, editing, or deleting User A's records\n";

    echo "=== ALL END-TO-END VERIFICATION CHECKS PASSED SUCCESSFULLY! ===\n";
} finally {
    // Cleanup test users and records
    $db->prepare('DELETE FROM users WHERE id IN (:idA, :idB)')->execute(['idA' => $userAId, 'idB' => $userBId]);
    echo "[11] Cleaned up test user accounts and records from MySQL.\n";
}

