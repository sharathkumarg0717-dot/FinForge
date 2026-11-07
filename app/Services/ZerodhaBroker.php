<?php
declare(strict_types=1);

namespace FinSync\Services;

use FinSync\Core\Database;
use FinSync\Core\Security;
use FinSync\Models\FinanceRepository;
use PDO;
use RuntimeException;
use Throwable;

final class ZerodhaBroker implements BrokerInterface
{
    private PDO $db;
    private array $config;
    private string $apiKey;
    private string $apiSecret;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->config = Database::config()['zerodha'] ?? [];
        $this->apiKey = trim((string)($this->config['api_key'] ?? ''));
        $this->apiSecret = trim((string)($this->config['api_secret'] ?? ''));
    }

    public function getBrokerCode(): string
    {
        return 'zerodha';
    }

    public function getBrokerName(): string
    {
        return 'Zerodha Kite Connect';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function getAuthUrl(int $userId): ?string
    {
        if ($this->apiKey === '') {
            return null;
        }
        return 'https://kite.zerodha.com/connect/login?v=3&api_key=' . rawurlencode($this->apiKey);
    }

    public function getStatus(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM broker_connections WHERE user_id = :user_id AND broker_code = "zerodha" LIMIT 1');
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
            'broker_code' => 'zerodha',
            'broker_name' => 'Zerodha Kite Connect',
            'is_configured' => $this->isConfigured(),
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
        if (!$this->isConfigured()) {
            throw new RuntimeException('Zerodha API Key or Secret is not configured in server configuration.');
        }

        $rawToken = trim((string)($params['request_token'] ?? ''));
        if (str_contains($rawToken, 'request_token=')) {
            $parts = parse_url($rawToken);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
                $rawToken = (string)($query['request_token'] ?? $rawToken);
            }
        } elseif (preg_match('/request_token=([a-zA-Z0-9_\-]+)/', $rawToken, $matches)) {
            $rawToken = $matches[1];
        }

        if ($rawToken === '') {
            throw new RuntimeException('Request token is missing. Please click Connect to authorize through Zerodha.');
        }

        $checksum = hash('sha256', $this->apiKey . $rawToken . $this->apiSecret);
        $ch = curl_init('https://api.kite.trade/session/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'api_key' => $this->apiKey,
                'request_token' => $rawToken,
                'checksum' => $checksum,
            ]),
            CURLOPT_HTTPHEADER => [
                'X-Kite-Version: 3',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException('Could not connect to Zerodha: ' . $curlError);
        }

        $resData = json_decode((string)$response, true);
        if ($httpCode !== 200 || !is_array($resData) || ($resData['status'] ?? '') !== 'success') {
            $msg = $resData['message'] ?? ("Kite authentication failed (HTTP $httpCode)");
            throw new RuntimeException('Zerodha Kite error: ' . $msg);
        }

        $sessionInfo = $resData['data'] ?? [];
        $accessToken = (string)($sessionInfo['access_token'] ?? '');
        $clientId = (string)($sessionInfo['user_id'] ?? 'KITE-USER');
        if ($accessToken === '') {
            throw new RuntimeException('Zerodha returned empty access token.');
        }

        $encAccessToken = Security::encrypt($accessToken);
        $expiresAt = date('Y-m-d 06:00:00', strtotime('+1 day'));

        $repo = new FinanceRepository($this->db);
        $accountId = $repo->connectZerodhaDemat($userId, $clientId);

        $stmt = $this->db->prepare('
            INSERT INTO broker_connections (user_id, broker_code, broker_name, client_id, account_id, access_token_enc, token_expires_at, status, sync_status, error_message, is_live)
            VALUES (:user_id, "zerodha", "Zerodha Kite Connect", :client_id, :account_id, :access_token_enc, :expires_at, "connected", "idle", NULL, 1)
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

        $syncResult = $this->syncHoldings($userId);

        return [
            'status' => 'connected',
            'client_id' => $clientId,
            'holdings_count' => $syncResult['holdings_count'],
            'total_value' => $syncResult['total_value'],
            'message' => 'Zerodha Kite connected and portfolio synchronized successfully.',
        ];
    }

    public function disconnect(int $userId): bool
    {
        $stmt = $this->db->prepare('
            UPDATE broker_connections 
            SET status = "disconnected", access_token_enc = NULL, token_expires_at = NULL, error_message = NULL 
            WHERE user_id = :user_id AND broker_code = "zerodha"
        ');
        $stmt->execute(['user_id' => $userId]);
        return true;
    }

    public function syncHoldings(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM broker_connections WHERE user_id = :user_id AND broker_code = "zerodha" LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $conn = $stmt->fetch();

        if (!$conn || empty($conn['access_token_enc'])) {
            throw new RuntimeException('Zerodha is not connected. Please connect your Zerodha account first.');
        }

        $accessToken = Security::decrypt((string)$conn['access_token_enc']);
        if (!$accessToken) {
            $this->updateError($userId, 'Could not decrypt access token. Please re-authenticate.');
            throw new RuntimeException('Token decryption failed. Please reconnect your broker.');
        }

        $ch = curl_init('https://api.kite.trade/portfolio/holdings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: token ' . $this->apiKey . ':' . $accessToken,
                'X-Kite-Version: 3',
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            $this->updateError($userId, 'Connection error: ' . $curlError);
            $this->logSync($userId, (int)$conn['id'], 0, 0, 'failed', $curlError);
            throw new RuntimeException('Failed to connect to Zerodha API: ' . $curlError);
        }

        $resData = json_decode((string)$response, true);
        if ($httpCode === 403 || (isset($resData['error_type']) && $resData['error_type'] === 'TokenException')) {
            $err = 'Zerodha Kite session expired. Daily re-authentication is required by Kite Connect.';
            $up = $this->db->prepare('UPDATE broker_connections SET status = "token_expired", error_message = :err WHERE id = :id');
            $up->execute(['err' => $err, 'id' => $conn['id']]);
            $this->logSync($userId, (int)$conn['id'], 0, 0, 'failed', $err);
            throw new RuntimeException($err);
        }

        if ($httpCode !== 200 || !is_array($resData) || ($resData['status'] ?? '') !== 'success') {
            $msg = $resData['message'] ?? ("Failed to fetch holdings (HTTP $httpCode)");
            $this->updateError($userId, $msg);
            $this->logSync($userId, (int)$conn['id'], 0, 0, 'failed', $msg);
            throw new RuntimeException('Zerodha error: ' . $msg);
        }

        $holdingsRaw = $resData['data'] ?? [];
        $accountId = (int)($conn['account_id'] ?? 0);
        if (!$accountId) {
            $repo = new FinanceRepository($this->db);
            $accountId = $repo->connectZerodhaDemat($userId, $conn['client_id'] ?: 'KITE-USER');
        }

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

            foreach ($holdingsRaw as $h) {
                $symbol = strtoupper(trim((string)($h['tradingsymbol'] ?? '')));
                if ($symbol === '') continue;
                $instrument = trim((string)($h['instrument_token'] ?? $symbol));
                $units = (float)($h['quantity'] ?? 0);
                if ($units <= 0) continue;

                $buyPrice = (float)($h['average_price'] ?? 0);
                $lastPrice = (float)($h['last_price'] ?? $buyPrice);
                $mktVal = round($units * $lastPrice, 2);
                $pnl = isset($h['pnl']) ? (float)$h['pnl'] : round($mktVal - ($units * $buyPrice), 2);

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
                WHERE id = :id
            ');
            $upConn->execute(['id' => $conn['id']]);

            $this->db->commit();
            $this->logSync($userId, (int)$conn['id'], $count, $totalVal, 'success', null);

            return [
                'holdings_count' => $count,
                'total_value' => round($totalVal, 2),
                'message' => "Successfully synchronized $count holdings from Zerodha.",
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->updateError($userId, 'Database error during holdings sync: ' . $e->getMessage());
            $this->logSync($userId, (int)$conn['id'], 0, 0, 'failed', $e->getMessage());
            throw $e;
        }
    }

    private function updateError(int $userId, string $errorMessage): void
    {
        $stmt = $this->db->prepare('UPDATE broker_connections SET sync_status = "failed", error_message = :err WHERE user_id = :user_id AND broker_code = "zerodha"');
        $stmt->execute(['err' => $errorMessage, 'user_id' => $userId]);
    }

    private function logSync(int $userId, int $connId, int $count, float $totalVal, string $status, ?string $err): void
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO broker_sync_logs (user_id, broker_connection_id, broker_code, sync_type, holdings_count, total_value, status, error_message)
                VALUES (:user_id, :conn_id, "zerodha", "api", :count, :total_val, :status, :err)
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
            error_log('Broker sync log error: ' . $e->getMessage());
        }
    }
}
