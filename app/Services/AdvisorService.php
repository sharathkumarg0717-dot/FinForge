<?php
declare(strict_types=1);

namespace FinSync\Services;

final class AdvisorService
{
    public function respond(string $question, array $dashboard): array
    {
        $text = strtolower($question);
        if (str_contains($text, 'spend') || str_contains($text, 'expense')) {
            $message = 'Your synthetic records show ' . number_format((float)$dashboard['monthly_expenses'], 2)
                . ' in recorded expenses this month. Review the Transactions and category summary to see the demo entries behind that total.';
        } elseif (str_contains($text, 'save') || str_contains($text, 'cash')) {
            $message = 'This month’s synthetic income is ' . number_format((float)$dashboard['monthly_income'], 2)
                . ' and expenses are ' . number_format((float)$dashboard['monthly_expenses'], 2)
                . '. The displayed difference is a historical demo summary, not a savings recommendation.';
        } else {
            $message = 'Your current synthetic net-worth snapshot is ' . number_format((float)$dashboard['net_worth'], 2)
                . ' across ' . (int)$dashboard['account_count'] . ' demo accounts. Open Overview or Accounts to review the records used for this summary.';
        }
        return [
            'answer' => $message,
            'mode' => 'safe_local_fallback',
            'disclaimer' => 'Informational demo only—not financial, tax, investment, or legal advice. No action has been taken.',
        ];
    }
}
