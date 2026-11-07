<?php
declare(strict_types=1);

namespace FinSync\Services;

use FinSync\Core\Database;
use InvalidArgumentException;
use PDO;

final class BrokerService
{
    private PDO $db;
    /** @var array<string, BrokerInterface> */
    private array $brokers;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->brokers = [
            'zerodha' => new ZerodhaBroker($this->db),
            'dhan' => new DhanBroker($this->db),
            'groww' => new GrowwBroker($this->db),
        ];
    }

    public function getBroker(string $brokerCode): BrokerInterface
    {
        $code = strtolower(trim($brokerCode));
        if (!isset($this->brokers[$code])) {
            throw new InvalidArgumentException("Unsupported broker: $brokerCode. Supported: zerodha, dhan, groww.");
        }
        return $this->brokers[$code];
    }

    /**
     * Return status cards for all supported brokers for the given user.
     */
    public function getAllBrokers(int $userId): array
    {
        $result = [];
        foreach ($this->brokers as $code => $broker) {
            $result[$code] = $broker->getStatus($userId);
        }
        return $result;
    }

    public function connect(int $userId, string $brokerCode, array $params): array
    {
        return $this->getBroker($brokerCode)->connect($userId, $params);
    }

    public function disconnect(int $userId, string $brokerCode): bool
    {
        return $this->getBroker($brokerCode)->disconnect($userId);
    }

    public function sync(int $userId, string $brokerCode): array
    {
        return $this->getBroker($brokerCode)->syncHoldings($userId);
    }

    public function syncAll(int $userId): array
    {
        $results = [];
        foreach ($this->brokers as $code => $broker) {
            $status = $broker->getStatus($userId);
            if (($status['status'] ?? '') === 'connected') {
                try {
                    $results[$code] = $broker->syncHoldings($userId);
                } catch (\Throwable $e) {
                    $results[$code] = ['status' => 'error', 'message' => $e->getMessage()];
                }
            }
        }
        return $results;
    }

    public function getSyncLogs(int $userId, ?string $brokerCode = null): array
    {
        $sql = 'SELECT * FROM broker_sync_logs WHERE user_id = :user_id ';
        $params = ['user_id' => $userId];
        if ($brokerCode !== null && $brokerCode !== '') {
            $sql .= 'AND broker_code = :code ';
            $params['code'] = strtolower($brokerCode);
        }
        $sql .= 'ORDER BY created_at DESC LIMIT 50';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
