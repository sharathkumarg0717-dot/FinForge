<?php
declare(strict_types=1);

namespace FinSync\Services;

use FinSync\Core\Database;
use FinSync\Models\FinanceRepository;
use PDO;
use RuntimeException;
use Throwable;

final class GrowwBroker implements BrokerInterface
{
    private PDO $db;
    private array $config;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->config = Database::config()['groww'] ?? [];
    }

    public function getBrokerCode(): string
    {
        return 'groww';
    }

    public function getBrokerName(): string
    {
        return 'Groww (Portfolio & Statements)';
    }

    public function isConfigured(): bool
    {
        // Public retail trading API is not provided by Groww.
        return !empty($this->config['api_key']);
    }

    public function getAuthUrl(int $userId): ?string
    {
        return null; // No public retail OAuth endpoint provided by Groww
    }

    public function getStatus(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM broker_connections WHERE user_id = :user_id AND broker_code = "groww" LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $conn = $stmt->fetch() ?: null;

        $holdingsCount = 0;
        $totalValue = 0.0;
        if ($conn && !empty($conn['account_id'])) {
            $hStmt = $this->db->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(market_value), 0) as val FROM investment_holdings WHERE account_id = :acc_id AND user_id = :user_id');
            $hStmt->execute(['acc_id' => $conn['account_id'], 'user_id' => $userId]);
            $hRow = $hStmt->fetch();
            $holdingsCount = (int)($hRow['cnt'] ?? 0);
            $totalValue = (float)($hRow['val'] ?? 0.0);
        }

        return [
            'broker_code' => 'groww',
            'broker_name' => 'Groww (Portfolio & Statements)',
            'is_configured' => $this->isConfigured(),
            'status' => $conn['status'] ?? 'disconnected',
            'client_id' => $conn['client_id'] ?? null,
            'last_sync_at' => $conn['last_sync_at'] ?? null,
            'error_message' => $conn['error_message'] ?? null,
            'holdings_count' => $holdingsCount,
            'total_value' => round($totalValue, 2),
            'auth_url' => null,
            'is_live' => false, // Clearly identified as file import / statement sync
            'notice' => 'Groww does not provide a public retail REST API for individual personal accounts. You can securely import your Groww Holdings CSV statement without sharing credentials.',
        ];
    }

    public function connect(int $userId, array $params): array
    {
        // If user submitted CSV statement:
        if (!empty($params['csv_content'])) {
            return $this->importGrowwCsv($userId, (string)$params['csv_content']);
        }

        // If Groww B2B partner API key was configured
        if ($this->isConfigured()) {
            throw new RuntimeException('Groww B2B partner gateway is configured but individual user authentication must be initiated through partner portal.');
        }

        throw new RuntimeException(
            'Groww currently does not provide a public retail API for individual investor login. '
            . 'Please use the "Import Groww CSV" option to securely load your holdings without exposing account credentials.'
        );
    }

    public function disconnect(int $userId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE broker_connections 
            SET status = "disconnected", access_token_enc = NULL, error_message = NULL 
            WHERE user_id = :user_id AND broker_code = "groww"
        ');
        $stmt->execute(['user_id' => $userId]);
        return true;
    }

    public function syncHoldings(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM broker_connections WHERE user_id = :user_id AND broker_code = "groww" LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $conn = $stmt->fetch();

        if (!$conn || ($conn['status'] ?? '') !== 'connected') {
            throw new RuntimeException('Groww is not connected. Upload your Groww Holdings CSV report to sync.');
        }

        return [
            'holdings_count' => 0,
            'total_value' => 0.0,
            'message' => 'Groww data is statement-based. Please re-import your latest Groww CSV report for updated prices.',
        ];
    }

    public function importGrowwCsv(int $userId, string $csvContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (empty($lines)) {
            throw new RuntimeException('Uploaded CSV file is empty.');
        }

        $header = null;
        $items = [];

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
                    if (str_contains($col, 'stock name') || str_contains($col, 'symbol') || str_contains($col, 'company')) {
                        $symIdx = $idx;
                    } elseif (str_contains($col, 'shares') || str_contains($col, 'qty') || str_contains($col, 'quantity')) {
                        $qtyIdx = $idx;
                    } elseif (str_contains($col, 'avg') || str_contains($col, 'buy')) {
                        $avgIdx = $idx;
                    } elseif (str_contains($col, 'market price') || str_contains($col, 'ltp') || str_contains($col, 'current')) {
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

            $avg = $header['avg'] !== null ? (float)preg_replace('/[^\d.]/', '', (string)($row[$header['avg']] ?? 0)) : 0.0;
            $ltp = $header['ltp'] !== null ? (float)preg_replace('/[^\d.]/', '', (string)($row[$header['ltp']] ?? 0)) : $avg;
            if ($ltp <= 0) $ltp = $avg;

            $items[] = [
                'instrument' => $symbol,
                'symbol' => $symbol,
                'units' => $qty,
                'buy_price' => $avg,
                'price' => $ltp,
                'market_value' => round($qty * $ltp, 2),
                'pnl' => round(($ltp - $avg) * $qty, 2),
            ];
        }

        if (empty($items)) {
            throw new RuntimeException('No valid stock holdings found in the uploaded Groww CSV file.');
        }

        // Ensure financial_accounts entry for Groww
        $repo = new FinanceRepository($this->db);
        $selfMemberId = $repo->getOrCreateSelfMember($userId);

        $chk = $this->db->prepare('SELECT id FROM financial_accounts WHERE user_id = :user_id AND provider_key = "groww_csv"');
        $chk->execute(['user_id' => $userId]);
        $accountId = $chk->fetchColumn();

        if (!$accountId) {
            $ins = $this->db->prepare('
                INSERT INTO financial_accounts (user_id, family_member_id, provider_key, institution, account_type, masked_number, client_id, balance, currency, status, last_synced_at, is_demo)
                VALUES (:user_id, :mid, "groww_csv", "Groww (Nextbillion Technology)", "investment", "Groww •••• Demat", "GROWW-IMPORT", 0.00, "INR", "active", NOW(), 0)
            ');
            $ins->execute(['user_id' => $userId, 'mid' => $selfMemberId]);
            $accountId = (int)$this->db->lastInsertId();
        }

        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM investment_holdings WHERE account_id = :acc_id AND user_id = :user_id');
            $del->execute(['acc_id' => $accountId, 'user_id' => $userId]);

            $insH = $this->db->prepare('
                INSERT INTO investment_holdings (user_id, account_id, instrument, symbol, units, price, buy_price, market_value, pnl, valuation_date)
                VALUES (:user_id, :account_id, :instrument, :symbol, :units, :price, :buy_price, :market_value, :pnl, CURRENT_DATE)
            ');

            $count = 0;
            $totalVal = 0.0;

            foreach ($items as $item) {
                $insH->execute([
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'instrument' => $item['instrument'],
                    'symbol' => $item['symbol'],
                    'units' => $item['units'],
                    'price' => $item['price'],
                    'buy_price' => $item['buy_price'],
                    'market_value' => $item['market_value'],
                    'pnl' => $item['pnl'],
                ]);
                $count++;
                $totalVal += (float)$item['market_value'];
            }

            // Update account balance
            $upAcc = $this->db->prepare('UPDATE financial_accounts SET balance = :balance, last_synced_at = NOW() WHERE id = :id');
            $upAcc->execute(['balance' => $totalVal, 'id' => $accountId]);

            // Upsert broker_connections
            $upConn = $this->db->prepare('
                INSERT INTO broker_connections (user_id, broker_code, broker_name, client_id, account_id, status, sync_status, last_sync_at, is_live)
                VALUES (:user_id, "groww", "Groww (Portfolio & Statements)", "GROWW-IMPORT", :acc_id, "connected", "success", NOW(), 0)
                ON DUPLICATE KEY UPDATE
                  account_id = VALUES(account_id),
                  status = "connected",
                  sync_status = "success",
                  last_sync_at = NOW(),
                  is_live = 0,
                  updated_at = NOW()
            ');
            $upConn->execute(['user_id' => $userId, 'acc_id' => $accountId]);

            // Sync log
            $stmtLog = $this->db->prepare('
                INSERT INTO broker_sync_logs (user_id, broker_code, sync_type, holdings_count, total_value, status, error_message)
                VALUES (:user_id, "groww", "csv", :count, :total_val, "success", NULL)
            ');
            $stmtLog->execute([
                'user_id' => $userId,
                'count' => $count,
                'total_val' => $totalVal,
            ]);

            $this->db->commit();

            return [
                'status' => 'connected',
                'holdings_count' => $count,
                'total_value' => round($totalVal, 2),
                'message' => "Successfully imported $count holdings from Groww CSV.",
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
