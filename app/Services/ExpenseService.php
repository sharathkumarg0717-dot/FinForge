<?php
declare(strict_types=1);

namespace FinSync\Services;

use FinSync\Core\Database;
use PDO;

final class ExpenseService
{
    /**
     * List transactions for a user with flexible filtering and sorting
     */
    public function listExpenses(int $userId, array $params = []): array
    {
        $db = Database::connection();
        $conditions = ['user_id = :user_id'];
        $bindings   = ['user_id' => $userId];

        if (!empty($params['month'])) {
            $month = trim((string)$params['month']); // YYYY-MM
            $conditions[] = 'DATE_FORMAT(transaction_date, "%Y-%m") = :month';
            $bindings['month'] = $month;
        }

        if (!empty($params['type']) && in_array($params['type'], ['expense', 'income'], true)) {
            $conditions[] = 'type = :type';
            $bindings['type'] = $params['type'];
        }

        if (!empty($params['category'])) {
            $conditions[] = 'category = :category';
            $bindings['category'] = trim((string)$params['category']);
        }

        if (!empty($params['source']) && in_array($params['source'], ['manual', 'csv', 'bank'], true)) {
            $conditions[] = 'source = :source';
            $bindings['source'] = $params['source'];
        }

        if (!empty($params['search'])) {
            $search = '%' . trim((string)$params['search']) . '%';
            $conditions[] = '(category LIKE :search OR notes LIKE :search OR subcategory LIKE :search)';
            $bindings['search'] = $search;
        }

        $allowedSort = ['transaction_date', 'amount', 'created_at', 'category'];
        $sortBy = in_array($params['sort_by'] ?? '', $allowedSort, true) ? $params['sort_by'] : 'transaction_date';
        $direction = strtoupper((string)($params['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $limit = max(1, min(200, (int)($params['limit'] ?? 100)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $sql = 'SELECT id, user_id, type, amount, category, subcategory, payment_method,
                       transaction_date, notes, receipt_filename, source, is_duplicate, created_at, updated_at
                FROM expenses
                WHERE ' . implode(' AND ', $conditions) . "
                ORDER BY {$sortBy} {$direction}, id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $db->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $row['id']           = (int)$row['id'];
            $row['user_id']      = (int)$row['user_id'];
            $row['amount']       = (float)$row['amount'];
            $row['is_duplicate'] = (bool)$row['is_duplicate'];
            return $row;
        }, $rows);
    }

    /**
     * Get a single expense record
     */
    public function getExpense(int $userId, int $id): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM expenses WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['id']           = (int)$row['id'];
        $row['user_id']      = (int)$row['user_id'];
        $row['amount']       = (float)$row['amount'];
        $row['is_duplicate'] = (bool)$row['is_duplicate'];
        return $row;
    }

    /**
     * Create a new expense or income record with duplicate detection
     */
    public function createExpense(int $userId, array $data): array
    {
        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        $type = ($data['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
        $category = trim((string)($data['category'] ?? ''));
        if ($category === '') {
            throw new \InvalidArgumentException('Category is required.');
        }

        $subcategory = !empty($data['subcategory']) ? trim((string)$data['subcategory']) : null;
        $paymentMethod = trim((string)($data['payment_method'] ?? 'cash'));
        $date = trim((string)($data['transaction_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;
        $source = in_array($data['source'] ?? '', ['manual', 'csv', 'bank'], true) ? $data['source'] : 'manual';

        $db = Database::connection();

        // Check for potential duplicate (same amount, category, type within 1 day)
        $dupStmt = $db->prepare(
            'SELECT id FROM expenses
             WHERE user_id = :user_id AND type = :type AND amount = :amount AND category = :cat
               AND ABS(DATEDIFF(transaction_date, :date)) <= 1
             LIMIT 1'
        );
        $dupStmt->execute([
            'user_id' => $userId,
            'type'    => $type,
            'amount'  => $amount,
            'cat'     => $category,
            'date'    => $date,
        ]);
        $isDuplicate = $dupStmt->fetchColumn() ? 1 : 0;

        $stmt = $db->prepare(
            'INSERT INTO expenses (user_id, type, amount, category, subcategory, payment_method,
                                  transaction_date, notes, source, is_duplicate)
             VALUES (:user_id, :type, :amount, :category, :subcategory, :payment_method,
                     :transaction_date, :notes, :source, :is_duplicate)'
        );
        $stmt->execute([
            'user_id'          => $userId,
            'type'             => $type,
            'amount'           => $amount,
            'category'         => $category,
            'subcategory'      => $subcategory,
            'payment_method'   => $paymentMethod,
            'transaction_date' => $date,
            'notes'            => $notes,
            'source'           => $source,
            'is_duplicate'     => $isDuplicate,
        ]);

        $id = (int)$db->lastInsertId();
        return $this->getExpense($userId, $id);
    }

    /**
     * Update an existing expense record
     */
    public function updateExpense(int $userId, int $id, array $data): array
    {
        $existing = $this->getExpense($userId, $id);
        if (!$existing) {
            throw new \RuntimeException('Expense record not found.');
        }

        $amount = isset($data['amount']) ? (float)$data['amount'] : $existing['amount'];
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        $type = isset($data['type']) && in_array($data['type'], ['expense', 'income'], true)
            ? $data['type'] : $existing['type'];
        $category = isset($data['category']) ? trim((string)$data['category']) : $existing['category'];
        $subcategory = array_key_exists('subcategory', $data) ? ($data['subcategory'] ? trim((string)$data['subcategory']) : null) : $existing['subcategory'];
        $paymentMethod = isset($data['payment_method']) ? trim((string)$data['payment_method']) : $existing['payment_method'];
        $date = isset($data['transaction_date']) ? trim((string)$data['transaction_date']) : $existing['transaction_date'];
        $notes = array_key_exists('notes', $data) ? ($data['notes'] ? trim((string)$data['notes']) : null) : $existing['notes'];

        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE expenses
             SET type = :type, amount = :amount, category = :category, subcategory = :subcategory,
                 payment_method = :payment_method, transaction_date = :date, notes = :notes
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'type'           => $type,
            'amount'         => $amount,
            'category'       => $category,
            'subcategory'    => $subcategory,
            'payment_method' => $paymentMethod,
            'date'           => $date,
            'notes'          => $notes,
            'id'             => $id,
            'user_id'        => $userId,
        ]);

        return $this->getExpense($userId, $id);
    }

    /**
     * Delete an expense record
     */
    public function deleteExpense(int $userId, int $id): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM expenses WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Comprehensive Monthly Report
     */
    public function getMonthlyReport(int $userId, string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $db = Database::connection();

        // Total Income and Total Expenses
        $totalsStmt = $db->prepare(
            'SELECT type, SUM(amount) AS total
             FROM expenses
             WHERE user_id = :user_id AND DATE_FORMAT(transaction_date, "%Y-%m") = :month
             GROUP BY type'
        );
        $totalsStmt->execute(['user_id' => $userId, 'month' => $month]);
        $totals = $totalsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $totalIncome   = (float)($totals['income'] ?? 0);
        $totalExpenses = (float)($totals['expense'] ?? 0);
        $netSavings    = $totalIncome - $totalExpenses;
        $savingsRate   = $totalIncome > 0 ? round(($netSavings / $totalIncome) * 100, 1) : 0.0;

        // Category-wise Breakdown (Expenses)
        $catStmt = $db->prepare(
            'SELECT category, SUM(amount) AS total, COUNT(*) AS count
             FROM expenses
             WHERE user_id = :user_id AND type = "expense"
               AND DATE_FORMAT(transaction_date, "%Y-%m") = :month
             GROUP BY category
             ORDER BY total DESC'
        );
        $catStmt->execute(['user_id' => $userId, 'month' => $month]);
        $categoryBreakdown = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $categories = array_map(function ($row) use ($totalExpenses) {
            $amount = (float)$row['total'];
            return [
                'category'   => $row['category'],
                'amount'     => $amount,
                'count'      => (int)$row['count'],
                'percentage' => $totalExpenses > 0 ? round(($amount / $totalExpenses) * 100, 1) : 0.0,
            ];
        }, $categoryBreakdown);

        // Budget vs Actual for this month
        $budgets = $this->getBudgetReport($userId, $month);

        // 6-Month Spending & Income Trend
        $trendStmt = $db->prepare(
            'SELECT DATE_FORMAT(transaction_date, "%Y-%m") AS m,
                    SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) AS income,
                    SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) AS expense
             FROM expenses
             WHERE user_id = :user_id
               AND transaction_date >= DATE_SUB(STR_TO_DATE(CONCAT(:month, "-01"), "%Y-%m-%d"), INTERVAL 5 MONTH)
               AND transaction_date <= LAST_DAY(STR_TO_DATE(CONCAT(:month, "-01"), "%Y-%m-%d"))
             GROUP BY m
             ORDER BY m ASC'
        );
        $trendStmt->execute(['user_id' => $userId, 'month' => $month]);
        $trends = array_map(function ($row) {
            return [
                'month'   => $row['m'],
                'income'  => (float)$row['income'],
                'expense' => (float)$row['expense'],
                'savings' => (float)$row['income'] - (float)$row['expense'],
            ];
        }, $trendStmt->fetchAll(PDO::FETCH_ASSOC));

        // Top 5 Largest Expenses this month
        $topStmt = $db->prepare(
            'SELECT id, amount, category, transaction_date, notes, payment_method
             FROM expenses
             WHERE user_id = :user_id AND type = "expense"
               AND DATE_FORMAT(transaction_date, "%Y-%m") = :month
             ORDER BY amount DESC LIMIT 5'
        );
        $topStmt->execute(['user_id' => $userId, 'month' => $month]);
        $topExpenses = array_map(function ($r) {
            $r['id']     = (int)$r['id'];
            $r['amount'] = (float)$r['amount'];
            return $r;
        }, $topStmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'month'              => $month,
            'total_income'       => $totalIncome,
            'total_expenses'     => $totalExpenses,
            'net_savings'        => $netSavings,
            'savings_rate'       => $savingsRate,
            'category_breakdown' => $categories,
            'budgets'            => $budgets,
            'trends'             => $trends,
            'top_expenses'       => $topExpenses,
        ];
    }

    /**
     * Budget comparison report
     */
    public function getBudgetReport(int $userId, string $month): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT b.category, b.monthly_limit,
                    COALESCE(SUM(e.amount), 0) AS actual_spent
             FROM budgets b
             LEFT JOIN expenses e ON e.user_id = b.user_id
                                 AND e.category = b.category
                                 AND e.type = "expense"
                                 AND DATE_FORMAT(e.transaction_date, "%Y-%m") = :month
             WHERE b.user_id = :user_id AND b.month = :b_month
             GROUP BY b.category, b.monthly_limit
             ORDER BY b.monthly_limit DESC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'month'   => $month,
            'b_month' => $month,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $limit  = (float)$row['monthly_limit'];
            $actual = (float)$row['actual_spent'];
            $pct    = $limit > 0 ? round(($actual / $limit) * 100, 1) : 0.0;
            return [
                'category'      => $row['category'],
                'monthly_limit' => $limit,
                'actual_spent'  => $actual,
                'remaining'     => max(0.0, $limit - $actual),
                'percentage'    => $pct,
                'is_warning'    => ($pct >= 85.0 && $pct <= 100.0),
                'is_exceeded'   => ($pct > 100.0),
            ];
        }, $rows);
    }

    /**
     * Set or update a monthly budget limit
     */
    public function saveBudget(int $userId, string $category, float $monthlyLimit, string $month): array
    {
        $category = trim($category);
        if ($category === '' || $monthlyLimit < 0) {
            throw new \InvalidArgumentException('Valid category and non-negative limit required.');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO budgets (user_id, category, monthly_limit, month)
             VALUES (:user_id, :cat, :lim, :month)
             ON DUPLICATE KEY UPDATE monthly_limit = :lim_update'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'cat'        => $category,
            'lim'        => $monthlyLimit,
            'month'      => $month,
            'lim_update' => $monthlyLimit,
        ]);

        return $this->getBudgetReport($userId, $month);
    }

    /**
     * Import transactions from CSV with duplicate detection
     */
    public function importCsv(int $userId, string $csvContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('CSV must contain a header row and at least one data row.');
        }

        $header = str_getcsv(array_shift($lines));
        $headerMap = [];
        foreach ($header as $idx => $colName) {
            $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', (string)$colName)));
            $headerMap[$normalized] = $idx;
        }

        // Helper to locate column index
        $findCol = function (array $aliases) use ($headerMap): ?int {
            foreach ($aliases as $alias) {
                if (isset($headerMap[$alias])) {
                    return $headerMap[$alias];
                }
            }
            return null;
        };

        $dateCol   = $findCol(['date', 'txndate', 'transactiondate', 'valuedate']);
        $amtCol    = $findCol(['amount', 'txnoutput', 'debit', 'withdrawal', 'credit', 'inr']);
        $catCol    = $findCol(['category', 'categoryname', 'tag', 'type']);
        $noteCol   = $findCol(['description', 'narration', 'notes', 'particulars', 'remarks']);
        $typeCol   = $findCol(['type', 'drcr', 'crdr', 'transactiontype']);

        $imported   = 0;
        $duplicates = 0;
        $errors     = [];

        $db = Database::connection();
        $dupCheckStmt = $db->prepare(
            'SELECT id FROM expenses
             WHERE user_id = :uid AND amount = :amt AND transaction_date = :dt AND category = :cat
             LIMIT 1'
        );

        $insertStmt = $db->prepare(
            'INSERT INTO expenses (user_id, type, amount, category, transaction_date, notes, source, is_duplicate)
             VALUES (:user_id, :type, :amount, :category, :transaction_date, :notes, "csv", :is_duplicate)'
        );

        foreach ($lines as $lineNum => $line) {
            if (trim($line) === '') continue;
            $row = str_getcsv($line);
            if (count($row) === 0) continue;

            $dateRaw = $dateCol !== null ? trim($row[$dateCol] ?? '') : '';
            $amtRaw  = $amtCol !== null ? trim($row[$amtCol] ?? '') : '';
            $cat     = $catCol !== null && !empty($row[$catCol]) ? trim($row[$catCol]) : 'Other';
            $notes   = $noteCol !== null ? trim($row[$noteCol] ?? '') : '';
            $typeRaw = $typeCol !== null ? strtolower(trim($row[$typeCol] ?? '')) : 'expense';

            // Clean amount
            $cleanAmt = (float)preg_replace('/[^0-9.]/', '', $amtRaw);
            if ($cleanAmt <= 0) {
                continue;
            }

            // Clean date
            $timestamp = strtotime($dateRaw);
            $date = $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');

            // Determine type
            $type = (str_contains($typeRaw, 'cr') || str_contains($typeRaw, 'income') || str_contains($typeRaw, 'credit'))
                ? 'income' : 'expense';

            // Duplicate check
            $dupCheckStmt->execute([
                'uid' => $userId,
                'amt' => $cleanAmt,
                'dt'  => $date,
                'cat' => $cat,
            ]);
            $isDuplicate = $dupCheckStmt->fetchColumn() ? 1 : 0;
            if ($isDuplicate) {
                $duplicates++;
            }

            try {
                $insertStmt->execute([
                    'user_id'          => $userId,
                    'type'             => $type,
                    'amount'           => $cleanAmt,
                    'category'         => $cat,
                    'transaction_date' => $date,
                    'notes'            => $notes ?: null,
                    'is_duplicate'     => $isDuplicate,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$lineNum}: " . $e->getMessage();
            }
        }

        return [
            'imported_count'  => $imported,
            'duplicate_count' => $duplicates,
            'errors'          => $errors,
        ];
    }

    /**
     * Get available expense and income categories
     */
    public function listCategories(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, name, type, icon, color, is_default
             FROM expense_categories
             WHERE user_id IS NULL OR user_id = :user_id
             ORDER BY type ASC, is_default DESC, name ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

