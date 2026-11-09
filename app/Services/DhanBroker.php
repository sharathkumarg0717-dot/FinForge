<?php
declare(strict_types=1);

namespace FinSync\Services;

use FinSync\Core\Database;
use FinSync\Core\Security;
use FinSync\Models\FinanceRepository;
use PDO;
use RuntimeException;
use Throwable;

final class DhanBroker implements BrokerInterface
{
    private PDO $db;
    private array $config;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->config = Database::config()['dhan'] ?? [];
    }

    public function getBrokerCode(): string
    {
        return 'dhan';
    }

    public function getBrokerName(): string
    {
        return 'DhanHQ API (v2)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getAuthUrl(int $userId): ?string
    {
        // DhanHQ uses direct access token generation from Dhan web portal (Profile -> DhanHQ APIs)
        return 'https://web.dhan.co';
    }

    public function getStatus(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM broker_connections WHERE user_id = :user_id AND broker_code = "dhan" LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $conn = $stmt->fetch() ?: null;

        $isExpired = false;
        if ($conn && $conn['token_expires_at'] && strtotime($conn['token_expires_at']) < time()) {
            $isExpired = true;
        }

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
            'broker_code' => 'dhan',
            'broker_name' => 'DhanHQ API (v2)',
            'is_configured' => true,
            'status' => $isExpired && ($conn['status'] ?? '') === 'connected' ? 'token_expired' : ($conn['status'] ?? 'disconnected'),
            'client_id' => $conn['client_id'] ?? null,
            'last_sync_at' => $conn['last_sync_at'] ?? null,
            'token_expires_at' => $conn['token_expires_at'] ?? null,
            'error_message' => $conn['error_message'] ?? null,
            'holdings_count' => $holdingsCount,
            'total_value' => round($totalValue, 2),
            'auth_url' => $this->getAuthUrl($userId),
            'is_live' => true,
        ];
    }

    public function connect(int $userId, array $params): array
    {
        $clientId = trim((string)($params['client_id'] ?? ($this->config['client_id'] ?? '')));
        $accessToken = trim((string)($params['access_token'] ?? ($this->config['access_token'] ?? '')));

        if ($clientId === '' || $accessToken === '') {
            throw new RuntimeException('Dhan Client ID and Access Token are required. Generate your 30-day token at web.dhan.co.');
        }

        // Test credentials by querying DhanHQ v2 holdings
        $ch = curl_init('https://api.dhan.co/v2/holdings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'access-token: ' . $accessToken,
                'client-id: ' . $clientId,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException('Could not connect to DhanHQ API: ' . $curlError);
        }

        $resData = json_decode((string)$response, true);
        if ($httpCode === 401 || $httpCode === 403) {
            throw new RuntimeException('DhanHQ authentication failed: Invalid Client ID or expired Access Token.');
        }

        if ($httpCode !== 200 || !is_array($resData)) {
            $msg = is_array($resData) ? ($resData['errorMessage'] ?? ($resData['remarks'] ?? "HTTP $httpCode")) : "HTTP $httpCode";
            throw new RuntimeException('DhanHQ API error: ' . $msg);
        }

        // Token is valid! Encrypt token using AES-256-GCM
        $encAccessToken = Security::encrypt($accessToken);

        // Dhan access tokens are valid for up to 30 days
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        // Ensure financial_accounts entry for Dhan
        $repo = new FinanceRepository($this->db);
        $selfMemberId = $repo->getOrCreateSelfMember($userId);
        $maskedNumber = 'Dhan •••• ' . substr($clientId, -4);

        $chk = $this->db->prepare('SELECT id FROM financial_accounts WHERE user_id = :user_id AND provider_key = "dhan_api"');
        $chk->execute(['user_id' => $userId]);
        $accountId = $chk->fetchColumn();

        if ($accountId) {
            $up = $this->db->prepare('UPDATE financial_accounts SET client_id = :cid, masked_number = :masked, last_synced_at = NOW(), status = "active" WHERE id = :id');
            $up->execute(['cid' => $clientId, 'masked' => $maskedNumber, 'id' => $accountId]);
        } else {
            $ins = $this->db->prepare('
                INSERT INTO financial_accounts (user_id, family_member_id, provider_key, institution, account_type, masked_number, client_id, balance, currency, status, last_synced_at, is_demo)
                VALUES (:user_id, :mid, "dhan_api", "Dhan (DhanHQ Broking)", "investment", :masked, :cid, 0.00, "INR", "active", NOW(), 0)
            ');
            $ins->execute([
                'user_id' => $userId,
                'mid' => $selfMemberId,
                'masked' => $maskedNumber,
                'cid' => $clientId,
            ]);
            $accountId = (int)$this->db->lastInsertId();
        }

        // Upsert broker_connections
        $stmt = $this->db->prepare('
            INSERT INTO broker_connections (user_id, broker_code, broker_name, client_id, account_id, access_token_enc, token_expires_at, status, sync_status, error_message, is_live)
            VALUES (:user_id, "dhan", "DhanHQ API (v2)", :client_id, :account_id, :access_token_enc, :expires_at, "connected", "idle", NULL, 1)
            ON DUPLICATE KEY UPDATE
              client_id = VALUES(client_id),
              account_id = VALUES(account_id),
              access_token_enc = VALUES(access_token_enc),
              token_expires_at = VALUES(token_expires_at),
              status = "connected",
              sync_status = "idle",
              error_message = NULL,
              is_live = 1,
              updated_at = NOW()
        ');
        $stmt->execute([
            'user_id' => $userId,
            'client_id' => $clientId,
            'account_id' => $accountId,
            'access_token_enc' => $encAccessToken,
            'expires_at' => $expiresAt,
        ]);

        // Auto-sync holdings with the freshly returned data
        $syncResult = $this->processHoldingsData($userId, (int)$accountId, $resData);

        return [
            'status' => 'connected',
            'client_id' => $clientId,
            'holdings_count' => $syncResult['holdings_count'],
            'total_value' => $syncResult['total_value'],
            'message' => 'DhanHQ account connected and portfolio synchronized successfully.',
        ];
    }

    public function disconnect(int $userId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE broker_connections 
            SET status = "disconnected", access_token_enc = NULL, token_expires_at = NULL, error_message = NULL 
            WHERE user_id = :user_id AND broker_code = "dhan"
        ');
        $stmt->execute(['user_id' => $userId]);
        return true;
    }

    public function syncHoldings(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM broker_connections WHERE user_id = :user_id AND broker_code = "dhan" LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $conn = $stmt->fetch();

        if (!$conn || empty($conn['access_token_enc'])) {
            throw new RuntimeException('Dhan is not connected. Please connect your Dhan account first.');
        }

        $accessToken = Security::decrypt((string)$conn['access_token_enc']);
        if (!$accessToken) {
            $this->updateError($userId, 'Could not decrypt access token. Please re-enter your Dhan token.');
            throw new RuntimeException('Token decryption failed. Please reconnect Dhan.');
        }

        $clientId = (string)($conn['client_id'] ?? '');

        $ch = curl_init('https://api.dhan.co/v2/holdings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'access-token: ' . $accessToken,
                'client-id: ' . $clientId,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            $this->updateError($userId, 'Connection error: ' . $curlError);
            $this->logSync($userId, (int)$conn['id'], 0, 0, 'failed', $curlError);
            throw new RuntimeException('Failed to connect to DhanHQ API: ' . $curlError);
        }

        if ($httpCode === 401 || $httpCode === 403) {
            $err = 'Dhan access token expired or revoked. Please generate a new token at web.dhan.co.';
            $up = $this->db->prepare('UPDATE broker_connections SET status = "token_expired", error_message = :err WHERE id = :id');
            $up->execute(['err' => $err, 'id' => $conn['id']]);
            $this->logSync($userId, (int)$conn['id'], 0, 0, 'failed', $err);
            throw new RuntimeException($err);
        }

        $resData = json_decode((string)$response, true);
        if ($httpCode !== 200 || !is_array($resData)) {
            $msg = is_array($resData) ? ($resData['errorMessage'] ?? 'Failed to fetch Dhan holdings') : "HTTP $httpCode";
            $this->updateError($userId, $msg);
            $this->logSync($userId, (int)$conn['id'], 0, 0, 'failed', $msg);
            throw new RuntimeException('DhanHQ error: ' . $msg);
        }

        $accountId = (int)($conn['account_id'] ?? 0);
        return $this->processHoldingsData($userId, $accountId, $resData, (int)$conn['id']);
    }

    private function processHoldingsData(int $userId, int $accountId, array $holdingsRaw, int $connId = 0): array
    {
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM investment_holdings WHERE account_id = :account_id AND user_id = :user_id');
            $del->execute(['account_id' => $accountId, 'user_id' => $userId]);

            $ins = $this->db->prepare('
                INSERT INTO investment_holdings (user_id, account_id, instrument, symbol, units, price, buy_price, market_value, pnl, valuation_date)
                VALUES (:user_id, :account_id, :instrument, :symbol, :units, :price, :buy_price, :market_value, :pnl, CURRENT_DATE)
            ');

            $count = 0;
            $totalVal = 0.0;

            // In DhanHQ v2, response is directly a list or nested under 'data'
            $items = isset($holdingsRaw['data']) && is_array($holdingsRaw['data']) ? $holdingsRaw['data'] : $holdingsRaw;

            foreach ($items as $h) {
                if (!is_array($h)) continue;
                $symbol = strtoupper(trim((string)($h['tradingSymbol'] ?? ($h['symbol'] ?? ''))));
                if ($symbol === '') continue;
                $instrument = trim((string)($h['securityId'] ?? $symbol));
                $units = (float)($h['totalQty'] ?? ($h['quantity'] ?? 0));
                if ($units <= 0) continue;

                $buyPrice = (float)($h['avgCostPrice'] ?? ($h['buy_price'] ?? 0));
                $lastPrice = (float)($h['lastPrice'] ?? ($h['ltp'] ?? $buyPrice));
                $mktVal = round($units * $lastPrice, 2);
                $pnl = isset($h['unrealizedProfit']) ? (float)$h['unrealizedProfit'] : round($mktVal - ($units * $buyPrice), 2);

                $ins->execute([
                    'user_id' => $userId,
                    'account_id' => $accountId,
                    'instrument' => $instrument,
                    'symbol' => $symbol,
                    'units' => $units,
                    'price' => $lastPrice,
                    'buy_price' => $buyPrice,
                    'market_value' => $mktVal,
                    'pnl' => $pnl,
                ]);
                $count++;
                $totalVal += $mktVal;
            }

            $upAcc = $this->db->prepare('UPDATE financial_accounts SET balance = :balance, last_synced_at = NOW() WHERE id = :id');
            $upAcc->execute(['balance' => $totalVal, 'id' => $accountId]);

            $upConn = $this->db->prepare('
                UPDATE broker_connections 
                SET last_sync_at = NOW(), sync_status = "success", status = "connected", error_message = NULL 
                WHERE user_id = :user_id AND broker_code = "dhan"
            ');
            $upConn->execute(['user_id' => $userId]);

            $this->db->commit();
            $this->logSync($userId, $connId, $count, $totalVal, 'success', null);

            return [
                'holdings_count' => $count,
                'total_value' => round($totalVal, 2),
                'message' => "Successfully synchronized $count holdings from Dhan.",
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->updateError($userId, 'Database error during Dhan sync: ' . $e->getMessage());
            $this->logSync($userId, $connId, 0, 0, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function updateError(int $userId, string $errorMessage): void
    {
        $stmt = $this->db->prepare('UPDATE broker_connections SET sync_status = "failed", error_message = :err WHERE user_id = :user_id AND broker_code = "dhan"');
        $stmt->execute(['err' => $errorMessage, 'user_id' => $userId]);
    }

    private function logSync(int $userId, int $connId, int $count, float $totalVal, string $status, ?string $err): void
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO broker_sync_logs (user_id, broker_connection_id, broker_code, sync_type, holdings_count, total_value, status, error_message)
                VALUES (:user_id, :conn_id, "dhan", "api", :count, :total_val, :status, :err)
            ');
            $stmt->execute([
                'user_id' => $userId,
                'conn_id' => $connId ?: null,
                'count' => $count,
                'total_val' => $totalVal,
                'status' => $status,
                'err' => $err,
            ]);
        } catch (Throwable $e) {
            error_log('Dhan sync log error: ' . $e->getMessage());
        }
    }
}

