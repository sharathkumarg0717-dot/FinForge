<?php
declare(strict_types=1);

namespace FinSync\Services;

interface BrokerInterface
{
    /**
     * Unique internal broker identifier ('zerodha', 'dhan', 'groww').
     */
    public function getBrokerCode(): string;

    /**
     * Human-friendly broker name (e.g. 'Zerodha Kite Connect').
     */
    public function getBrokerName(): string;

    /**
     * Whether server-level API keys or requirements are configured.
     */
    public function isConfigured(): bool;

    /**
     * Return broker OAuth / login authorization URL, if supported.
     */
    public function getAuthUrl(int $userId): ?string;

    /**
     * Establish or finalize a connection for the given user.
     * @param int $userId
     * @param array $params (e.g. request_token, client_id, access_token)
     * @return array Status and connection summary
     */
    public function connect(int $userId, array $params): array;

    /**
     * Disconnect the broker and clear stored credentials.
     */
    public function disconnect(int $userId): bool;

    /**
     * Fetch real holdings from broker and update FinSync database.
     * @return array ['holdings_count' => int, 'total_value' => float, 'holdings' => array]
     */
    public function syncHoldings(int $userId): array;

    /**
     * Retrieve current connection and sync status for the user.
     */
    public function getStatus(int $userId): array;
}
