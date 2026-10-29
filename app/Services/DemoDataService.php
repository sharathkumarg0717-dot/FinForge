<?php
declare(strict_types=1);

namespace FinSync\Services;

use PDO;
use Throwable;

final class DemoDataService
{
    public function __construct(private PDO $db) {}

    public function seedFor(int $userId, string $name): void
    {
        $this->db->beginTransaction();
        try {
            $firstName = trim(explode(' ', $name)[0] ?? 'Alex');
            $family = $this->db->prepare('INSERT INTO family_members (user_id, name, relationship) VALUES (:user_id, :name, "Self")');
            $family->execute(['user_id' => $userId, 'name' => $firstName]);
            $selfId = (int)$this->db->lastInsertId();
            $partner = $this->db->prepare('INSERT INTO family_members (user_id, name, relationship) VALUES (:user_id, "Jordan (demo)", "Partner (synthetic)")');
            $partner->execute(['user_id' => $userId]);
            $partnerId = (int)$this->db->lastInsertId();

            $provider = new MockProviderService();
            $samples = [
                ['member' => $selfId, 'institution' => 'Northstar Community Bank', 'account_type' => 'checking', 'masked_number' => '•••• 4821', 'balance' => 8420.65],
                ['member' => $selfId, 'institution' => 'Northstar Community Bank', 'account_type' => 'savings', 'masked_number' => '•••• 1064', 'balance' => 24650.00],
                ['member' => $selfId, 'institution' => 'Pioneer Credit', 'account_type' => 'credit', 'masked_number' => '•••• 7712', 'balance' => -1240.35],
                ['member' => $partnerId, 'institution' => 'Evergreen Investments', 'account_type' => 'investment', 'masked_number' => '•••• 9208', 'balance' => 18540.20],
                ['member' => $selfId, 'institution' => 'Home loan (demo)', 'account_type' => 'loan', 'masked_number' => '•••• 3019', 'balance' => -68400.00],
                ['member' => $partnerId, 'institution' => 'Pocket Wallet (demo)', 'account_type' => 'wallet', 'masked_number' => '•••• 0082', 'balance' => 385.75],
            ];
            $accountInsert = $this->db->prepare(
                'INSERT INTO financial_accounts (user_id, family_member_id, provider_key, institution, account_type, masked_number, balance, currency, last_synced_at, is_demo) '
                . 'VALUES (:user_id, :member_id, "mock_bank", :institution, :account_type, :masked_number, :balance, "USD", NOW(), 1)'
            );
            $accountIds = [];
            foreach ($samples as $index => $sample) {
                $account = $provider->normalizeAccount($sample);
                $accountInsert->execute([
                    'user_id' => $userId,
                    'member_id' => $sample['member'],
                    'institution' => $account['institution'],
                    'account_type' => $account['account_type'],
                    'masked_number' => $account['masked_number'],
                    'balance' => $account['balance'],
                ]);
                $accountIds[$index] = (int)$this->db->lastInsertId();
            }

            $investmentInsert = $this->db->prepare(
                'INSERT INTO investment_holdings (user_id, account_id, instrument, symbol, units, price, market_value, valuation_date) '
                . 'VALUES (:user_id, :account_id, :instrument, :symbol, :units, :price, :value, CURRENT_DATE)'
            );
            foreach ([
                ['US Total Market Index Fund', 'USTM', 42.500000, 186.25, 7915.63],
                ['Global Bond Index Fund', 'GBND', 31.000000, 112.40, 3484.40],
                ['Clean Energy Basket (demo)', 'CLEN', 18.750000, 88.80, 1665.00],
            ] as $holding) {
                $investmentInsert->execute([
                    'user_id' => $userId,
                    'account_id' => $accountIds[3],
                    'instrument' => $holding[0],
                    'symbol' => $holding[1],
                    'units' => $holding[2],
                    'price' => $holding[3],
                    'value' => $holding[4],
                ]);
            }

            $transactionInsert = $this->db->prepare(
                'INSERT INTO transactions (user_id, account_id, amount, direction, category, description, transaction_date, provider_reference, is_demo) '
                . 'VALUES (:user_id, :account_id, :amount, :direction, :category, :description, :date, :reference, 1)'
            );
            $transactionSamples = [
                [0, 3250.00, 'income', 'Income', 'Monthly payroll (demo)', -2],
                [0, 68.45, 'expense', 'Groceries', 'Market basket', -1],
                [2, 42.80, 'expense', 'Dining', 'Harbor Cafe', -3],
                [0, 126.00, 'expense', 'Utilities', 'City utilities (demo)', -5],
                [5, 24.99, 'expense', 'Subscriptions', 'Stream service (demo)', -6],
                [0, 85.00, 'expense', 'Transport', 'Transit pass', -8],
                [0, 3250.00, 'income', 'Income', 'Monthly payroll (demo)', -17],
                [2, 54.10, 'expense', 'Groceries', 'Fresh Market', -19],
                [3, 125.00, 'income', 'Investment', 'Synthetic distribution', -21],
                [0, 148.25, 'expense', 'Healthcare', 'Community pharmacy', -24],
                [0, 74.30, 'expense', 'Dining', 'Family lunch', -27],
                [5, 14.50, 'expense', 'Transport', 'Ride fare (demo)', -29],
            ];
            foreach ($transactionSamples as $i => $item) {
                $transactionInsert->execute([
                    'user_id' => $userId,
                    'account_id' => $accountIds[$item[0]],
                    'amount' => $item[1],
                    'direction' => $item[2],
                    'category' => $item[3],
                    'description' => $item[4],
                    'date' => date('Y-m-d', strtotime($item[5] . ' days')),
                    'reference' => 'DEMO-' . $userId . '-' . ($i + 1),
                ]);
            }

            $recurring = $this->db->prepare(
                'INSERT INTO recurring_payments (user_id, name, amount, cadence, next_due_date, status, is_demo) '
                . 'VALUES (:user_id, :name, :amount, :cadence, :due, "active", 1)'
            );
            foreach ([
                ['Home loan installment (demo)', 820.00, 'monthly', 3],
                ['Electric utility estimate', 96.50, 'monthly', 6],
                ['Family streaming plan', 18.99, 'monthly', 11],
                ['Auto insurance estimate', 112.00, 'monthly', 16],
            ] as $item) {
                $recurring->execute([
                    'user_id' => $userId, 'name' => $item[0], 'amount' => $item[1],
                    'cadence' => $item[2], 'due' => date('Y-m-d', strtotime('+' . $item[3] . ' days')),
                ]);
            }

            $notification = $this->db->prepare(
                'INSERT INTO notifications (user_id, type, message, due_date, is_read) VALUES (:user_id, :type, :message, :due, 0)'
            );
            $notification->execute(['user_id' => $userId, 'type' => 'reminder', 'message' => 'A demo utility reminder is coming up.', 'due' => date('Y-m-d', strtotime('+6 days'))]);
            $notification->execute(['user_id' => $userId, 'type' => 'info', 'message' => 'Your FinSync workspace uses synthetic data only.', 'due' => null]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
