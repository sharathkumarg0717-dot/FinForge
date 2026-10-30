<?php
declare(strict_types=1);

namespace FinSync\Models;

use PDO;

final class FinanceRepository
{
    public function __construct(private PDO $db) {}

    public function accountList(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT a.id, a.family_member_id, a.institution, a.account_type, a.masked_number, a.balance, a.currency, a.last_synced_at, '
            . 'm.name AS member_name FROM financial_accounts a '
            . 'JOIN family_members m ON m.id = a.family_member_id AND m.user_id = a.user_id '
            . 'WHERE a.user_id = :user_id AND a.status = "active" AND m.status = "active" ORDER BY a.account_type, a.institution'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function transactions(int $userId, int $limit = 100, ?string $category = null): array
    {
        $sql = 'SELECT t.id, t.amount, t.direction, t.category, t.description, t.transaction_date, '
            . 'a.institution, a.masked_number FROM transactions t '
            . 'JOIN financial_accounts a ON a.id = t.account_id AND a.user_id = t.user_id '
            . 'JOIN family_members m ON m.id = a.family_member_id AND m.user_id = a.user_id AND m.status = "active" '
            . 'WHERE t.user_id = :user_id';
        $params = ['user_id' => $userId];
        if ($category !== null && $category !== '') {
            $sql .= ' AND t.category = :category';
            $params['category'] = $category;
        }
        $sql .= ' ORDER BY t.transaction_date DESC, t.id DESC LIMIT ' . max(1, min(200, $limit));
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function family(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT m.id, m.name, m.relationship, m.email, m.status, COUNT(a.id) AS account_count '
            . 'FROM family_members m LEFT JOIN financial_accounts a ON a.family_member_id = m.id AND a.status = "active" '
            . 'WHERE m.user_id = :user_id GROUP BY m.id ORDER BY m.id'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function familyOverview(int $userId): array
    {
        $membersStmt = $this->db->prepare(
            'SELECT m.id, m.name, m.relationship, m.email, m.status, m.created_at '
            . 'FROM family_members m WHERE m.user_id = :user_id ORDER BY m.id'
        );
        $membersStmt->execute(['user_id' => $userId]);
        $members = $membersStmt->fetchAll();

        $accountsStmt = $this->db->prepare(
            'SELECT a.id, a.family_member_id, a.institution, a.account_type, a.masked_number, a.balance, a.currency, a.status '
            . 'FROM financial_accounts a WHERE a.user_id = :user_id AND a.status = "active"'
        );
        $accountsStmt->execute(['user_id' => $userId]);
        $allAccounts = $accountsStmt->fetchAll();

        $accountsByMember = [];
        foreach ($allAccounts as $acc) {
            $memId = (int)$acc['family_member_id'];
            $accountsByMember[$memId][] = $acc;
        }

        $txStmt = $this->db->prepare(
            'SELECT t.amount, t.direction, a.family_member_id '
            . 'FROM transactions t JOIN financial_accounts a ON a.id = t.account_id '
            . 'WHERE t.user_id = :user_id'
        );
        $txStmt->execute(['user_id' => $userId]);
        $allTxs = $txStmt->fetchAll();

        $txByMember = [];
        foreach ($allTxs as $tx) {
            $memId = (int)$tx['family_member_id'];
            $txByMember[$memId][] = $tx;
        }

        $holdingsStmt = $this->db->prepare(
            'SELECT h.market_value, a.family_member_id '
            . 'FROM investment_holdings h JOIN financial_accounts a ON a.id = h.account_id '
            . 'WHERE h.user_id = :user_id'
        );
        $holdingsStmt->execute(['user_id' => $userId]);
        $allHoldings = $holdingsStmt->fetchAll();

        $holdingsByMember = [];
        foreach ($allHoldings as $h) {
            $memId = (int)$h['family_member_id'];
            $holdingsByMember[$memId][] = $h;
        }

        $familySavings = 0.0;
        $familySpend = 0.0;
        $familyDebt = 0.0;
        $familyCredit = 0.0;
        $familyInvestments = 0.0;

        $memberData = [];
        foreach ($members as $member) {
            $mId = (int)$member['id'];
            $isActive = ($member['status'] === 'active');
            $memAccounts = $accountsByMember[$mId] ?? [];
            $memTxs = $txByMember[$mId] ?? [];
            $memHoldings = $holdingsByMember[$mId] ?? [];

            $mSavings = 0.0;
            $mDebt = 0.0;
            $mCredit = 0.0;
            $mInvestments = 0.0;

            foreach ($memAccounts as $acc) {
                $bal = (float)$acc['balance'];
                $type = $acc['account_type'];
                if (in_array($type, ['checking', 'savings', 'wallet'], true)) {
                    if ($bal > 0) {
                        $mSavings += $bal;
                    }
                } elseif ($type === 'investment') {
                    if ($bal > 0) {
                        $mInvestments += $bal;
                    }
                } elseif ($type === 'credit') {
                    $mCredit += abs($bal);
                } elseif ($type === 'loan') {
                    $mDebt += abs($bal);
                }
            }

            $holdingTotal = 0.0;
            foreach ($memHoldings as $h) {
                $holdingTotal += (float)$h['market_value'];
            }
            if ($holdingTotal > 0 && $mInvestments === 0.0) {
                $mInvestments = $holdingTotal;
            }

            $mSpend = 0.0;
            $mIncome = 0.0;
            foreach ($memTxs as $tx) {
                $amt = (float)$tx['amount'];
                if ($tx['direction'] === 'expense') {
                    $mSpend += $amt;
                } elseif ($tx['direction'] === 'income') {
                    $mIncome += $amt;
                }
            }

            $netBalance = ($mSavings + $mInvestments) - ($mDebt + $mCredit);

            if ($isActive) {
                $familySavings += $mSavings;
                $familySpend += $mSpend;
                $familyDebt += $mDebt;
                $familyCredit += $mCredit;
                $familyInvestments += $mInvestments;
            }

            $memberData[] = [
                'id' => $mId,
                'name' => $member['name'],
                'relationship' => $member['relationship'],
                'email' => $member['email'] ?? '',
                'status' => $member['status'],
                'account_count' => count($memAccounts),
                'savings' => round($mSavings, 2),
                'spend' => round($mSpend, 2),
                'income' => round($mIncome, 2),
                'debt' => round($mDebt, 2),
                'credit' => round($mCredit, 2),
                'investments' => round($mInvestments, 2),
                'net_balance' => round($netBalance, 2),
            ];
        }

        $activeCount = count(array_filter($members, static fn($m) => $m['status'] === 'active'));
        $familyNetWorth = ($familySavings + $familyInvestments) - ($familyDebt + $familyCredit);

        return [
            'summary' => [
                'family_savings' => round($familySavings, 2),
                'family_spend' => round($familySpend, 2),
                'family_debt' => round($familyDebt, 2),
                'family_credit' => round($familyCredit, 2),
                'family_investments' => round($familyInvestments, 2),
                'family_net_worth' => round($familyNetWorth, 2),
                'active_member_count' => $activeCount,
                'total_account_count' => count($allAccounts),
            ],
            'members' => $memberData,
        ];
    }

    public function familyMemberDashboard(int $userId, int $memberId): ?array
    {
        $memberStmt = $this->db->prepare(
            'SELECT id, name, relationship, email, status, created_at FROM family_members '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $memberStmt->execute(['id' => $memberId, 'user_id' => $userId]);
        $member = $memberStmt->fetch();
        if (!$member) {
            return null;
        }

        $accStmt = $this->db->prepare(
            'SELECT id, institution, account_type, masked_number, balance, currency, status, last_synced_at '
            . 'FROM financial_accounts WHERE family_member_id = :member_id AND user_id = :user_id AND status = "active" '
            . 'ORDER BY account_type, institution'
        );
        $accStmt->execute(['member_id' => $memberId, 'user_id' => $userId]);
        $accounts = $accStmt->fetchAll();

        $accountIds = array_map(static fn($a) => (int)$a['id'], $accounts);

        $savings = 0.0;
        $investments = 0.0;
        $debt = 0.0;
        $credit = 0.0;

        foreach ($accounts as $acc) {
            $bal = (float)$acc['balance'];
            $type = $acc['account_type'];
            if (in_array($type, ['checking', 'savings', 'wallet'], true)) {
                if ($bal > 0) {
                    $savings += $bal;
                }
            } elseif ($type === 'investment') {
                if ($bal > 0) {
                    $investments += $bal;
                }
            } elseif ($type === 'credit') {
                $credit += abs($bal);
            } elseif ($type === 'loan') {
                $debt += abs($bal);
            }
        }

        $holdings = [];
        if (!empty($accountIds)) {
            $inClause = implode(',', $accountIds);
            $holdingsStmt = $this->db->prepare(
                "SELECT h.id, h.instrument, h.symbol, h.units, h.price, h.market_value, h.valuation_date, a.institution, a.masked_number "
                . "FROM investment_holdings h JOIN financial_accounts a ON a.id = h.account_id "
                . "WHERE h.account_id IN ($inClause) AND h.user_id = :user_id ORDER BY h.market_value DESC"
            );
            $holdingsStmt->execute(['user_id' => $userId]);
            $holdings = $holdingsStmt->fetchAll();
        }

        $holdingsSum = array_sum(array_map(static fn($h) => (float)$h['market_value'], $holdings));
        if ($holdingsSum > 0 && $investments === 0.0) {
            $investments = $holdingsSum;
        }

        $transactions = [];
        $spend = 0.0;
        $income = 0.0;
        if (!empty($accountIds)) {
            $inClause = implode(',', $accountIds);
            $txStmt = $this->db->prepare(
                "SELECT t.id, t.account_id, t.amount, t.direction, t.category, t.description, t.transaction_date, "
                . "a.institution, a.masked_number "
                . "FROM transactions t JOIN financial_accounts a ON a.id = t.account_id "
                . "WHERE t.account_id IN ($inClause) AND t.user_id = :user_id "
                . "ORDER BY t.transaction_date DESC, t.id DESC LIMIT 40"
            );
            $txStmt->execute(['user_id' => $userId]);
            $transactions = $txStmt->fetchAll();

            foreach ($transactions as $t) {
                if ($t['direction'] === 'expense') {
                    $spend += (float)$t['amount'];
                } elseif ($t['direction'] === 'income') {
                    $income += (float)$t['amount'];
                }
            }
        }

        $netBalance = ($savings + $investments) - ($debt + $credit);

        return [
            'member' => [
                'id' => (int)$member['id'],
                'name' => $member['name'],
                'relationship' => $member['relationship'],
                'email' => $member['email'] ?? '',
                'status' => $member['status'],
                'created_at' => $member['created_at'],
            ],
            'metrics' => [
                'savings' => round($savings, 2),
                'spend' => round($spend, 2),
                'income' => round($income, 2),
                'debt' => round($debt, 2),
                'credit' => round($credit, 2),
                'investments' => round($investments, 2),
                'net_balance' => round($netBalance, 2),
                'account_count' => count($accounts),
                'transaction_count' => count($transactions),
            ],
            'accounts' => $accounts,
            'transactions' => $transactions,
            'holdings' => $holdings,
        ];
    }

    public function createAccount(int $userId, array $data): int
    {
        $memberId = (int)$data['family_member_id'];
        $check = $this->db->prepare('SELECT id FROM family_members WHERE id = :id AND user_id = :user_id');
        $check->execute(['id' => $memberId, 'user_id' => $userId]);
        if (!$check->fetchColumn()) {
            throw new \InvalidArgumentException('Family member does not exist.');
        }

        $institution = trim((string)($data['institution'] ?? 'Demo Bank'));
        if ($institution === '') {
            $institution = 'Demo Bank';
        }
        $accountType = trim((string)($data['account_type'] ?? 'savings'));
        if (!in_array($accountType, ['checking', 'savings', 'credit', 'investment', 'loan', 'wallet'], true)) {
            $accountType = 'savings';
        }
        $masked = trim((string)($data['masked_number'] ?? ''));
        if ($masked === '') {
            $masked = '•••• ' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } elseif (!str_starts_with($masked, '•')) {
            $last4 = substr(preg_replace('/\D/', '', $masked), -4);
            $masked = '•••• ' . ($last4 ?: str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT));
        }
        $balance = (float)($data['balance'] ?? 0.0);
        if (in_array($accountType, ['credit', 'loan'], true) && $balance > 0) {
            $balance = -$balance;
        }

        $statement = $this->db->prepare(
            'INSERT INTO financial_accounts (user_id, family_member_id, provider_key, institution, account_type, masked_number, balance, currency, last_synced_at, is_demo) '
            . 'VALUES (:user_id, :member_id, "mock_bank", :institution, :account_type, :masked_number, :balance, "INR", NOW(), 1)'
        );
        $statement->execute([
            'user_id' => $userId,
            'member_id' => $memberId,
            'institution' => $institution,
            'account_type' => $accountType,
            'masked_number' => $masked,
            'balance' => $balance,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function holdings(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT h.id, h.account_id, h.instrument, h.symbol, h.units, h.price, h.buy_price, h.market_value, h.pnl, h.valuation_date, '
            . 'a.institution, a.masked_number, a.provider_key, a.client_id, m.name AS member_name FROM investment_holdings h '
            . 'JOIN financial_accounts a ON a.id = h.account_id AND a.user_id = h.user_id '
            . 'JOIN family_members m ON m.id = a.family_member_id AND m.user_id = a.user_id AND m.status = "active" '
            . 'WHERE h.user_id = :user_id ORDER BY h.market_value DESC'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function zerodhaAccount(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, institution, account_type, masked_number, client_id, balance, currency, last_synced_at, provider_key '
            . 'FROM financial_accounts WHERE user_id = :user_id AND provider_key = "zerodha_kite" AND status = "active" LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $acc = $statement->fetch();
        if (!$acc) {
            return null;
        }

        $holdingsStmt = $this->db->prepare(
            'SELECT id, instrument, symbol, units, price, buy_price, market_value, pnl, valuation_date '
            . 'FROM investment_holdings WHERE account_id = :account_id AND user_id = :user_id ORDER BY market_value DESC'
        );
        $holdingsStmt->execute(['account_id' => $acc['id'], 'user_id' => $userId]);
        $holdings = $holdingsStmt->fetchAll();

        $totalInvestment = 0.0;
        $totalMarketValue = 0.0;
        foreach ($holdings as $h) {
            $buyP = (float)$h['buy_price'];
            $units = (float)$h['units'];
            $cost = $buyP > 0 ? $buyP * $units : (float)$h['market_value'];
            $totalInvestment += $cost;
            $totalMarketValue += (float)$h['market_value'];
        }

        $totalPnl = $totalMarketValue - $totalInvestment;
        $pnlPercentage = $totalInvestment > 0 ? ($totalPnl / $totalInvestment) * 100 : 0.0;

        return [
            'account' => $acc,
            'holdings' => $holdings,
            'summary' => [
                'total_holdings_count' => count($holdings),
                'total_investment' => round($totalInvestment, 2),
                'total_market_value' => round($totalMarketValue, 2),
                'total_pnl' => round($totalPnl, 2),
                'pnl_percentage' => round($pnlPercentage, 2),
            ],
        ];
    }

    public function getOrCreateSelfMember(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM family_members WHERE user_id = :user_id AND relationship = "Self" AND status = "active" LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }

        $stmtAny = $this->db->prepare('SELECT id FROM family_members WHERE user_id = :user_id AND status = "active" ORDER BY id ASC LIMIT 1');
        $stmtAny->execute(['user_id' => $userId]);
        $anyId = $stmtAny->fetchColumn();
        if ($anyId) {
            return (int)$anyId;
        }

        $userStmt = $this->db->prepare('SELECT name FROM users WHERE id = :user_id');
        $userStmt->execute(['user_id' => $userId]);
        $userName = $userStmt->fetchColumn() ?: 'Sharath Kumar';

        $ins = $this->db->prepare('INSERT INTO family_members (user_id, name, relationship, status) VALUES (:user_id, :name, "Self", "active")');
        $ins->execute(['user_id' => $userId, 'name' => $userName]);
        return (int)$this->db->lastInsertId();
    }

    public function connectZerodhaDemat(int $userId, string $clientId, ?string $dematBoid = null): int
    {
        $selfMemberId = $this->getOrCreateSelfMember($userId);
        $clientId = trim($clientId);
        if ($clientId === '') {
            $clientId = 'DEMAT-' . random_int(100000, 999999);
        }
        $maskedNumber = $dematBoid && strlen($dematBoid) >= 4 ? 'BOID •••• ' . substr(preg_replace('/\D/', '', $dematBoid), -4) : 'Kite •••• ' . substr($clientId, -4);

        $check = $this->db->prepare('SELECT id FROM financial_accounts WHERE user_id = :user_id AND provider_key = "zerodha_kite"');
        $check->execute(['user_id' => $userId]);
        $existingId = $check->fetchColumn();

        if ($existingId) {
            $up = $this->db->prepare(
                'UPDATE financial_accounts SET client_id = :client_id, masked_number = :masked, institution = "Zerodha Broking Ltd (Kite)", '
                . 'last_synced_at = NOW(), status = "active" WHERE id = :id'
            );
            $up->execute(['client_id' => $clientId, 'masked' => $maskedNumber, 'id' => (int)$existingId]);
            return (int)$existingId;
        }

        $ins = $this->db->prepare(
            'INSERT INTO financial_accounts (user_id, family_member_id, provider_key, institution, account_type, masked_number, client_id, balance, currency, status, last_synced_at, is_demo) '
            . 'VALUES (:user_id, :member_id, "zerodha_kite", "Zerodha Broking Ltd (Kite)", "investment", :masked, :client_id, 0.00, "INR", "active", NOW(), 0)'
        );
        $ins->execute([
            'user_id' => $userId,
            'member_id' => $selfMemberId,
            'masked' => $maskedNumber,
            'client_id' => $clientId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function importZerodhaHoldings(int $userId, int $accountId, array $holdingsList, bool $replaceExisting = false): int
    {
        $chk = $this->db->prepare('SELECT id FROM financial_accounts WHERE id = :id AND user_id = :user_id');
        $chk->execute(['id' => $accountId, 'user_id' => $userId]);
        if (!$chk->fetchColumn()) {
            throw new \InvalidArgumentException('Demat account not found.');
        }

        $this->db->beginTransaction();
        try {
            if ($replaceExisting) {
                $del = $this->db->prepare('DELETE FROM investment_holdings WHERE account_id = :account_id AND user_id = :user_id');
                $del->execute(['account_id' => $accountId, 'user_id' => $userId]);
            }

            $ins = $this->db->prepare(
                'INSERT INTO investment_holdings (user_id, account_id, instrument, symbol, units, price, buy_price, market_value, pnl, valuation_date) '
                . 'VALUES (:user_id, :account_id, :instrument, :symbol, :units, :price, :buy_price, :market_value, :pnl, CURRENT_DATE)'
            );

            $importedCount = 0;
            foreach ($holdingsList as $item) {
                $symbol = strtoupper(trim((string)($item['symbol'] ?? '')));
                if ($symbol === '') continue;
                $instrument = trim((string)($item['instrument'] ?? $symbol));
                $units = (float)($item['units'] ?? 0);
                if ($units <= 0) continue;
                $buyPrice = (float)($item['buy_price'] ?? ($item['price'] ?? 0));
                $price = (float)($item['price'] ?? $buyPrice);
                $marketValue = round($units * $price, 2);
                $pnl = isset($item['pnl']) ? (float)$item['pnl'] : round($marketValue - ($units * $buyPrice), 2);

                $ins->execute([
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'instrument' => $instrument,
                    'symbol' => $symbol,
                    'units' => $units,
                    'price' => $price,
                    'buy_price' => $buyPrice,
                    'market_value' => $marketValue,
                    'pnl' => $pnl,
                ]);
                $importedCount++;
            }

            $totalValStmt = $this->db->prepare('SELECT COALESCE(SUM(market_value), 0) FROM investment_holdings WHERE account_id = :account_id AND user_id = :user_id');
            $totalValStmt->execute(['account_id' => $accountId, 'user_id' => $userId]);
            $totalVal = (float)$totalValStmt->fetchColumn();

            $upAcc = $this->db->prepare('UPDATE financial_accounts SET balance = :balance, last_synced_at = NOW() WHERE id = :id');
            $upAcc->execute(['balance' => $totalVal, 'id' => $accountId]);

            $this->db->commit();
            return $importedCount;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function parseZerodhaCsv(string $csvContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (empty($lines)) {
            return [];
        }

        $header = null;
        $holdings = [];

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (empty($row) || count(array_filter($row)) === 0) continue;

            if ($header === null) {
                $norm = array_map(static fn($c) => strtolower(trim((string)$c)), $row);
                $symIdx = null;
                $qtyIdx = null;
                $avgIdx = null;
                $ltpIdx = null;

                foreach ($norm as $idx => $col) {
                    if (in_array($col, ['instrument', 'symbol', 'tradingsymbol', 'stock'], true)) {
                        $symIdx = $idx;
                    } elseif (in_array($col, ['qty.', 'qty', 'quantity', 'units'], true)) {
                        $qtyIdx = $idx;
                    } elseif (str_contains($col, 'avg') || str_contains($col, 'buy') || str_contains($col, 'cost')) {
                        $avgIdx = $idx;
                    } elseif (in_array($col, ['ltp', 'price', 'last price', 'current price'], true)) {
                        $ltpIdx = $idx;
                    }
                }

                if ($symIdx !== null && $qtyIdx !== null) {
                    $header = ['sym' => $symIdx, 'qty' => $qtyIdx, 'avg' => $avgIdx, 'ltp' => $ltpIdx];
                }
                continue;
            }

            $symbol = strtoupper(trim((string)($row[$header['sym']] ?? '')));
            if ($symbol === '' || str_starts_with($symbol, 'TOTAL') || str_starts_with($symbol, 'DISCLAIMER')) continue;

            $qty = (float)preg_replace('/[^\d.]/', '', (string)($row[$header['qty']] ?? 0));
            if ($qty <= 0) continue;

            $avg = $header['avg'] !== null ? (float)preg_replace('/[^\d.]/', '', (string)($row[$header['avg']] ?? 0)) : 0;
            $ltp = $header['ltp'] !== null ? (float)preg_replace('/[^\d.]/', '', (string)($row[$header['ltp']] ?? 0)) : $avg;
            if ($ltp <= 0) $ltp = $avg;

            $holdings[] = [
                'instrument' => $symbol,
                'symbol' => $symbol,
                'units' => $qty,
                'buy_price' => $avg,
                'price' => $ltp,
                'market_value' => round($qty * $ltp, 2),
                'pnl' => round(($ltp - $avg) * $qty, 2),
            ];
        }

        return $holdings;
    }

    public function exchangeKiteToken(string $apiKey, string $apiSecret, string $requestToken): array
    {
        // Extract request_token if user pasted the full redirect URL
        if (str_contains($requestToken, 'request_token=')) {
            $parts = parse_url($requestToken);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
                if (!empty($query['request_token'])) {
                    $requestToken = (string)$query['request_token'];
                }
            }
        } elseif (preg_match('/request_token=([a-zA-Z0-9_\-]+)/', $requestToken, $matches)) {
            $requestToken = $matches[1];
        }
        $requestToken = trim($requestToken);
        if ($requestToken === '') {
            throw new \RuntimeException('Invalid or empty request token. Please complete the Zerodha login step.');
        }

        $checksum = hash('sha256', trim($apiKey) . $requestToken . trim($apiSecret));

        $ch = curl_init('https://api.kite.trade/session/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'api_key' => trim($apiKey),
            'request_token' => $requestToken,
            'checksum' => $checksum,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Kite-Version: 3',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('Failed to connect to Zerodha token service: ' . $err);
        }

        $json = json_decode((string)$res, true);
        if ($status !== 200 || !is_array($json) || ($json['status'] ?? '') !== 'success') {
            $msg = $json['message'] ?? 'Token exchange failed (HTTP ' . $status . ')';
            throw new \RuntimeException('Zerodha authentication error: ' . $msg);
        }

        return $json['data'] ?? [];
    }

    public function updateZerodhaClientId(int $userId, int $accountId, string $clientId): void
    {
        $stmt = $this->db->prepare('UPDATE financial_accounts SET client_id = :client_id WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['client_id' => $clientId, 'id' => $accountId, 'user_id' => $userId]);
    }

    public function syncZerodhaKiteApi(int $userId, int $accountId, string $apiKey, string $accessToken): array
    {
        $ch = curl_init('https://api.kite.trade/portfolio/holdings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . trim($apiKey) . ':' . trim($accessToken),
            'X-Kite-Version: 3'
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('Failed to connect to Zerodha Kite API: ' . $err);
        }

        $json = json_decode((string)$res, true);
        if ($status !== 200 || !is_array($json) || ($json['status'] ?? '') !== 'success') {
            $msg = $json['message'] ?? 'Zerodha Kite API returned error code ' . $status;
            throw new \RuntimeException($msg);
        }

        $kiteData = $json['data'] ?? [];
        $parsed = [];
        foreach ($kiteData as $item) {
            $sym = strtoupper((string)($item['tradingsymbol'] ?? ''));
            $qty = (float)($item['quantity'] ?? 0);
            $avg = (float)($item['average_price'] ?? 0);
            $ltp = (float)($item['last_price'] ?? $avg);
            $pnl = (float)($item['pnl'] ?? (($ltp - $avg) * $qty));
            $parsed[] = [
                'instrument' => $sym,
                'symbol' => $sym,
                'units' => $qty,
                'buy_price' => $avg,
                'price' => $ltp,
                'market_value' => round($qty * $ltp, 2),
                'pnl' => $pnl,
            ];
        }

        $imported = $this->importZerodhaHoldings($userId, $accountId, $parsed, true);
        return [
            'imported_count' => $imported,
            'holdings' => $parsed,
        ];
    }

    public function deleteHolding(int $userId, int $holdingId): bool
    {
        $stmt = $this->db->prepare('SELECT account_id FROM investment_holdings WHERE id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $holdingId, 'user_id' => $userId]);
        $accountId = $stmt->fetchColumn();
        if (!$accountId) {
            return false;
        }

        $del = $this->db->prepare('DELETE FROM investment_holdings WHERE id = :id AND user_id = :user_id');
        $del->execute(['id' => $holdingId, 'user_id' => $userId]);

        $totalValStmt = $this->db->prepare('SELECT COALESCE(SUM(market_value), 0) FROM investment_holdings WHERE account_id = :account_id AND user_id = :user_id');
        $totalValStmt->execute(['account_id' => $accountId, 'user_id' => $userId]);
        $totalVal = (float)$totalValStmt->fetchColumn();

        $upAcc = $this->db->prepare('UPDATE financial_accounts SET balance = :balance, last_synced_at = NOW() WHERE id = :id');
        $upAcc->execute(['balance' => $totalVal, 'id' => $accountId]);

        return true;
    }

    public function recurring(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, amount, cadence, next_due_date, status FROM recurring_payments '
            . 'WHERE user_id = :user_id ORDER BY next_due_date'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function notifications(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, type, message, due_date, is_read, created_at FROM notifications '
            . 'WHERE user_id = :user_id ORDER BY is_read, due_date IS NULL, due_date, created_at DESC LIMIT 50'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function payments(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, description, amount, state, receipt_reference, created_at FROM simulated_payments '
            . 'WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 30'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function personalFinance(int $userId, string $month): array
    {
        $statement = $this->db->prepare(
            'SELECT id, record_month, monthly_income, other_income, monthly_expenses, expense_categories, savings, '
            . 'investments, loans_debt, credit_card_balance, financial_goals, notes, created_at, updated_at '
            . 'FROM personal_finance_records WHERE user_id = :user_id ORDER BY record_month DESC, id DESC'
        );
        $statement->execute(['user_id' => $userId]);
        $records = $statement->fetchAll();
        $recordedMonths = [];
        foreach ($records as &$record) {
            $categories = json_decode((string)$record['expense_categories'], true);
            if (!is_array($categories)) {
                throw new \RuntimeException('A stored personal-finance category breakdown is invalid.');
            }
            $record['expense_categories'] = $categories;
            $m = substr((string)$record['record_month'], 0, 7);
            if (!in_array($m, $recordedMonths, true)) {
                $recordedMonths[] = $m;
            }
        }
        unset($record);
        $selected = array_values(array_filter(
            $records,
            static fn(array $record): bool => substr((string)$record['record_month'], 0, 7) === $month
        ));

        $analysis = [
            'record_count' => count($selected),
            'monthly_income' => 0.0,
            'other_income' => 0.0,
            'total_income' => 0.0,
            'total_expenses' => 0.0,
            'net_savings' => 0.0,
            'savings_rate' => null,
            'savings_balance' => 0.0,
            'investments' => 0.0,
            'loans_debt' => 0.0,
            'credit_card_balance' => 0.0,
            'total_debt' => 0.0,
            'cash_flow' => 0.0,
            'spending_by_category' => [],
            'highest_spending_category' => null,
            'goals' => [],
            'notes' => [],
            'recent_records' => [],
            'indicators' => [],
            'suggestions' => [],
        ];
        $categoryTotals = [];
        foreach ($selected as $record) {
            $analysis['monthly_income'] += (float)$record['monthly_income'];
            $analysis['other_income'] += (float)$record['other_income'];
            $analysis['total_expenses'] += (float)$record['monthly_expenses'];
            $analysis['savings_balance'] += (float)$record['savings'];
            $analysis['investments'] += (float)$record['investments'];
            $analysis['loans_debt'] += (float)$record['loans_debt'];
            $analysis['credit_card_balance'] += (float)$record['credit_card_balance'];

            $categories = $record['expense_categories'];
            foreach ($categories as $category) {
                if (!is_array($category) || !isset($category['category'], $category['amount'])) {
                    throw new \RuntimeException('A stored personal-finance category entry is invalid.');
                }
                $name = (string)$category['category'];
                $categoryTotals[$name] = ($categoryTotals[$name] ?? 0.0) + (float)$category['amount'];
            }
            if (trim((string)$record['financial_goals']) !== '') {
                $analysis['goals'][] = [
                    'month' => substr((string)$record['record_month'], 0, 7),
                    'text' => (string)$record['financial_goals'],
                ];
            }
            if (trim((string)$record['notes']) !== '') {
                $analysis['notes'][] = [
                    'month' => substr((string)$record['record_month'], 0, 7),
                    'text' => (string)$record['notes'],
                ];
            }
        }

        $analysis['monthly_income'] = round($analysis['monthly_income'], 2);
        $analysis['other_income'] = round($analysis['other_income'], 2);
        $analysis['total_income'] = round($analysis['monthly_income'] + $analysis['other_income'], 2);
        $analysis['total_expenses'] = round($analysis['total_expenses'], 2);
        $analysis['net_savings'] = round($analysis['total_income'] - $analysis['total_expenses'], 2);
        $analysis['cash_flow'] = $analysis['net_savings'];
        $analysis['savings_rate'] = $analysis['total_income'] > 0
            ? round(($analysis['net_savings'] / $analysis['total_income']) * 100, 1)
            : null;
        $analysis['savings_balance'] = round($analysis['savings_balance'], 2);
        $analysis['investments'] = round($analysis['investments'], 2);
        $analysis['loans_debt'] = round($analysis['loans_debt'], 2);
        $analysis['credit_card_balance'] = round($analysis['credit_card_balance'], 2);
        $analysis['total_debt'] = round($analysis['loans_debt'] + $analysis['credit_card_balance'], 2);
        arsort($categoryTotals, SORT_NUMERIC);
        $totalCategorySum = array_sum($categoryTotals);
        foreach ($categoryTotals as $category => $amount) {
            $analysis['spending_by_category'][] = [
                'category' => $category,
                'amount' => round($amount, 2),
                'percentage' => $totalCategorySum > 0 ? round(($amount / $totalCategorySum) * 100, 1) : 0.0,
            ];
        }
        $analysis['highest_spending_category'] = $analysis['spending_by_category'][0] ?? null;
        $analysis['recent_records'] = $selected;

        if ($selected !== []) {
            if ($analysis['cash_flow'] < 0) {
                $analysis['indicators'][] = [
                    'status' => 'warning',
                    'label' => 'Negative cash flow (-$' . number_format(abs($analysis['cash_flow']), 2) . ')',
                    'icon' => 'fa-triangle-exclamation',
                ];
            } else {
                $analysis['indicators'][] = [
                    'status' => 'positive',
                    'label' => 'Positive cash flow (+₹' . number_format($analysis['cash_flow'], 2) . ')',
                    'icon' => 'fa-circle-check',
                ];
            }

            if ($analysis['savings_rate'] !== null) {
                if ($analysis['savings_rate'] >= 20) {
                    $analysis['indicators'][] = [
                        'status' => 'positive',
                        'label' => 'Strong savings rate (' . $analysis['savings_rate'] . '%)',
                        'icon' => 'fa-piggy-bank',
                    ];
                } elseif ($analysis['savings_rate'] > 0) {
                    $analysis['indicators'][] = [
                        'status' => 'info',
                        'label' => 'Savings rate below 20% (' . $analysis['savings_rate'] . '%)',
                        'icon' => 'fa-scale-balanced',
                    ];
                } else {
                    $analysis['indicators'][] = [
                        'status' => 'warning',
                        'label' => 'Deficit savings rate (' . $analysis['savings_rate'] . '%)',
                        'icon' => 'fa-arrow-trend-down',
                    ];
                }
            }

            if ($analysis['total_debt'] === 0.0) {
                $analysis['indicators'][] = [
                    'status' => 'positive',
                    'label' => 'Debt-free: ₹0.00 total debt',
                    'icon' => 'fa-shield-halved',
                ];
            } elseif ($analysis['total_income'] > 0 && $analysis['total_debt'] >= $analysis['total_income']) {
                $analysis['indicators'][] = [
                    'status' => 'warning',
                    'label' => 'High debt level (₹' . number_format($analysis['total_debt'], 2) . ')',
                    'icon' => 'fa-triangle-exclamation',
                ];
            } else {
                $analysis['indicators'][] = [
                    'status' => 'info',
                    'label' => 'Debt recorded (₹' . number_format($analysis['total_debt'], 2) . ')',
                    'icon' => 'fa-landmark',
                ];
            }

            if ($analysis['total_expenses'] > 0) {
                $bufferMonths = round($analysis['savings_balance'] / $analysis['total_expenses'], 1);
                if ($bufferMonths >= 3.0) {
                    $analysis['indicators'][] = [
                        'status' => 'positive',
                        'label' => "Emergency cushion: {$bufferMonths} months",
                        'icon' => 'fa-vault',
                    ];
                } elseif ($bufferMonths >= 1.0) {
                    $analysis['indicators'][] = [
                        'status' => 'info',
                        'label' => "Emergency buffer: {$bufferMonths} months",
                        'icon' => 'fa-vault',
                    ];
                } else {
                    $analysis['indicators'][] = [
                        'status' => 'warning',
                        'label' => 'Low emergency buffer (< 1 month)',
                        'icon' => 'fa-shield-exclamation',
                    ];
                }
            }

            if ($analysis['investments'] > 0) {
                $analysis['indicators'][] = [
                    'status' => 'positive',
                    'label' => 'Investments: ₹' . number_format($analysis['investments'], 2),
                    'icon' => 'fa-chart-line',
                ];
            }

            // Suggestions based strictly on stored data
            if ($analysis['cash_flow'] < 0) {
                $topCat = $analysis['highest_spending_category'];
                $analysis['suggestions'][] = sprintf(
                    'Warning: Your expenses are higher than your income (negative cash flow of -₹%s). Review your largest spending categories%s to bring your budget back into positive cash flow.',
                    number_format(abs($analysis['cash_flow']), 2),
                    $topCat ? ' (such as ' . $topCat['category'] . ' at ₹' . number_format($topCat['amount'], 2) . ')' : ''
                );
            } elseif ($analysis['savings_rate'] !== null && $analysis['savings_rate'] >= 20) {
                $analysis['suggestions'][] = sprintf(
                    'Your savings rate is at least 20%% (currently %.1f%% with net +₹%s saved). Keep directing savings toward the goals you recorded.',
                    $analysis['savings_rate'],
                    number_format($analysis['net_savings'], 2)
                );
            } elseif ($analysis['savings_rate'] !== null) {
                $analysis['suggestions'][] = sprintf(
                    'Consider whether you can increase the amount set aside for savings based on your current cash flow (currently %.1f%%). Targeting 20%% would save approximately ₹%s monthly.',
                    $analysis['savings_rate'],
                    number_format(max(0, ($analysis['total_income'] * 0.20) - $analysis['net_savings']), 2)
                );
            }

            if ($analysis['credit_card_balance'] > 0) {
                $analysis['suggestions'][] = sprintf(
                    'You have a credit card balance of ₹%s. Prioritize paying this off to avoid high interest charges.',
                    number_format($analysis['credit_card_balance'], 2)
                );
            }

            if ($analysis['total_debt'] > 0 && $analysis['total_income'] > 0 && $analysis['total_debt'] >= $analysis['total_income']) {
                $analysis['suggestions'][] = sprintf(
                    'Your recorded debt is at least one month of income (₹%s debt vs ₹%s income). Review repayment priorities and your credit-card balance.',
                    number_format($analysis['total_debt'], 2),
                    number_format($analysis['total_income'], 2)
                );
            } elseif ($analysis['loans_debt'] > 0) {
                $analysis['suggestions'][] = sprintf(
                    'Your loan debt is ₹%s. Keep loan minimums accounted for in your monthly plan.',
                    number_format($analysis['loans_debt'], 2)
                );
            }

            if ($analysis['highest_spending_category'] !== null && $totalCategorySum > 0) {
                $topCat = $analysis['highest_spending_category'];
                $analysis['suggestions'][] = sprintf(
                    '%s is your highest listed spending category at ₹%s (%.1f%% of your expense-category total).',
                    $topCat['category'],
                    number_format($topCat['amount'], 2),
                    ($topCat['amount'] / $totalCategorySum) * 100
                );
            }

            if (!empty($analysis['goals'])) {
                $analysis['suggestions'][] = sprintf(
                    'Recorded goal: "%s". Maintain consistent tracking each month to reach your target milestone.',
                    $analysis['goals'][0]['text']
                );
            }
        }

        return [
            'month' => $month,
            'records' => $records,
            'analysis' => $analysis,
            'available_months' => $recordedMonths,
            'latest_month' => $recordedMonths[0] ?? null,
        ];
    }

    public function savePersonalFinance(int $userId, array $record, ?int $recordId = null): int
    {
        $fields = 'record_month = :record_month, monthly_income = :monthly_income, other_income = :other_income, '
            . 'monthly_expenses = :monthly_expenses, expense_categories = :expense_categories, savings = :savings, '
            . 'investments = :investments, loans_debt = :loans_debt, credit_card_balance = :credit_card_balance, '
            . 'financial_goals = :financial_goals, notes = :notes';
        $params = [
            'record_month' => $record['record_month'],
            'monthly_income' => $record['monthly_income'],
            'other_income' => $record['other_income'],
            'monthly_expenses' => $record['monthly_expenses'],
            'expense_categories' => json_encode($record['expense_categories'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'savings' => $record['savings'],
            'investments' => $record['investments'],
            'loans_debt' => $record['loans_debt'],
            'credit_card_balance' => $record['credit_card_balance'],
            'financial_goals' => $record['financial_goals'],
            'notes' => $record['notes'],
        ];
        if ($recordId === null) {
            $statement = $this->db->prepare(
                'INSERT INTO personal_finance_records (user_id, record_month, monthly_income, other_income, monthly_expenses, '
                . 'expense_categories, savings, investments, loans_debt, credit_card_balance, financial_goals, notes) '
                . 'VALUES (:user_id, :record_month, :monthly_income, :other_income, :monthly_expenses, :expense_categories, '
                . ':savings, :investments, :loans_debt, :credit_card_balance, :financial_goals, :notes)'
            );
            $statement->execute(['user_id' => $userId] + $params);
            return (int)$this->db->lastInsertId();
        }
        $statement = $this->db->prepare(
            'UPDATE personal_finance_records SET ' . $fields . ' WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $recordId, 'user_id' => $userId] + $params);
        return $statement->rowCount();
    }

    public function deletePersonalFinance(int $userId, int $recordId): bool
    {
        $statement = $this->db->prepare(
            'DELETE FROM personal_finance_records WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $recordId, 'user_id' => $userId]);
        return $statement->rowCount() > 0;
    }

    public function dashboard(int $userId): array
    {
        $accounts = $this->accountList($userId);
        $transactions = $this->transactions($userId, 8);
        $recurring = $this->recurring($userId);
        $holdings = $this->holdings($userId);
        $unread = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
        $unread->execute(['user_id' => $userId]);
        $unreadCount = (int)$unread->fetchColumn();

        // Check if user has real personal finance records
        $personalStmt = $this->db->prepare(
            'SELECT * FROM personal_finance_records WHERE user_id = :user_id ORDER BY record_month DESC, id DESC LIMIT 1'
        );
        $personalStmt->execute(['user_id' => $userId]);
        $latestPersonal = $personalStmt->fetch();

        if ($latestPersonal) {
            $income = (float)$latestPersonal['monthly_income'] + (float)$latestPersonal['other_income'];
            $expenses = (float)$latestPersonal['monthly_expenses'];
            $net = $income - $expenses;
            $savings = (float)$latestPersonal['savings'];
            $investments = (float)$latestPersonal['investments'];
            $debt = (float)$latestPersonal['loans_debt'] + (float)$latestPersonal['credit_card_balance'];
            $assets = $savings + $investments;
            $liabilities = $debt;
            $netWorth = $assets - $liabilities;
            $savingsRate = $income > 0 ? ($net / $income) * 100 : 0;
            $healthScore = min(100, max(25, (int)round(60 + ($net >= 0 ? 20 : -25) + ($savingsRate >= 20 ? 15 : ($savingsRate > 0 ? 5 : -10)) + ($debt === 0.0 ? 10 : 0))));

            return [
                'has_personal_records' => true,
                'record_month' => substr((string)$latestPersonal['record_month'], 0, 7),
                'net_worth' => round($netWorth, 2),
                'assets' => round($assets, 2),
                'liabilities' => round($liabilities, 2),
                'investment_value' => round($investments, 2),
                'savings_value' => round($savings, 2),
                'monthly_income' => round($income, 2),
                'monthly_expenses' => round($expenses, 2),
                'monthly_net' => round($net, 2),
                'account_count' => count($accounts),
                'unread_notifications' => $unreadCount,
                'accounts' => $accounts,
                'transactions' => $transactions,
                'upcoming' => array_slice($recurring, 0, 4),
                'health_score' => $healthScore,
                'health_explanation' => sprintf(
                    'Real financial health score based on your saved financial records (Income: ₹%s, Expenses: ₹%s, Net: ₹%s).',
                    number_format($income, 2),
                    number_format($expenses, 2),
                    number_format($net, 2)
                ),
            ];
        }

        $assets = 0.0;
        $liabilities = 0.0;
        foreach ($accounts as $account) {
            $balance = (float)$account['balance'];
            if (in_array($account['account_type'], ['credit', 'loan'], true)) {
                $liabilities += abs($balance);
            } else {
                $assets += $balance;
            }
        }
        $summary = $this->db->prepare(
            'SELECT COALESCE(SUM(CASE WHEN direction = "income" THEN amount ELSE 0 END), 0) AS income, '
            . 'COALESCE(SUM(CASE WHEN direction = "expense" THEN amount ELSE 0 END), 0) AS expenses '
            . 'FROM transactions WHERE user_id = :user_id AND transaction_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
        );
        $summary->execute(['user_id' => $userId]);
        $cashFlow = $summary->fetch();
        $netWorth = $assets - $liabilities;
        return [
            'has_personal_records' => false,
            'net_worth' => round($netWorth, 2),
            'assets' => round($assets, 2),
            'liabilities' => round($liabilities, 2),
            'investment_value' => round(array_sum(array_map(static fn(array $item): float => (float)$item['market_value'], $holdings)), 2),
            'monthly_income' => round((float)$cashFlow['income'], 2),
            'monthly_expenses' => round((float)$cashFlow['expenses'], 2),
            'monthly_net' => round((float)$cashFlow['income'] - (float)$cashFlow['expenses'], 2),
            'account_count' => count($accounts),
            'unread_notifications' => $unreadCount,
            'accounts' => $accounts,
            'transactions' => $transactions,
            'upcoming' => array_slice($recurring, 0, 4),
            'health_score' => min(100, max(35, (int)round(65 + (($netWorth > 0) ? 12 : -8) + (((float)$cashFlow['income'] > (float)$cashFlow['expenses']) ? 10 : -5)))),
            'health_explanation' => 'A demo indicator based on synthetic net worth and this month’s recorded cash flow; it is not a credit score or financial advice.',
        ];
    }
}
