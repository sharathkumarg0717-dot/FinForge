<?php
declare(strict_types=1);

namespace FinSync\Services;

/**
 * Synthetic provider boundary. Never accepts credentials or contacts an institution.
 * A future provider implementation must be separately reviewed and explicitly approved.
 */
final class MockProviderService
{
    public function normalizeAccount(array $sample): array
    {
        return [
            'provider' => 'mock_bank',
            'institution' => (string)$sample['institution'],
            'account_type' => (string)$sample['account_type'],
            'masked_number' => (string)$sample['masked_number'],
            'balance' => round((float)$sample['balance'], 2),
            'currency' => (string)($sample['currency'] ?? 'INR'),
            'is_demo' => true,
        ];
    }
}
