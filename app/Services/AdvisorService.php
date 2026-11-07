<?php
declare(strict_types=1);

namespace FinSync\Services;

use FinSync\Core\Database;
use Throwable;

final class AdvisorService
{
    /**
     * Generate dynamic AI insights grounded in the user's real financial data.
     * Uses OpenAI or Gemini API when configured; returns clear setup status when unconfigured.
     */
    public function respond(string $question, array $dashboard): array
    {
        $config = Database::config();
        $openaiKey = trim((string)($config['openai']['api_key'] ?? ''));
        $openaiEnabled = (bool)($config['openai']['enabled'] ?? false);
        $geminiKey = trim((string)($config['gemini']['api_key'] ?? ''));
        $geminiEnabled = (bool)($config['gemini']['enabled'] ?? false);

        // Build real financial snapshot context (strictly anonymized aggregates)
        $income = (float)($dashboard['monthly_income'] ?? 0);
        $expenses = (float)($dashboard['monthly_expenses'] ?? 0);
        $net = (float)($dashboard['monthly_net'] ?? ($income - $expenses));
        $netWorth = (float)($dashboard['net_worth'] ?? 0);
        $investments = (float)($dashboard['investment_value'] ?? 0);
        $savingsRate = $income > 0 ? round(($net / $income) * 100, 1) : 0;
        $healthScore = (int)($dashboard['health_score'] ?? 50);

        $contextSummary = sprintf(
            "Monthly Income: ₹%s\nMonthly Expenses: ₹%s\nNet Monthly Savings: ₹%s (Savings Rate: %s%%)\nTotal Net Worth: ₹%s\nInvestment Portfolio Value: ₹%s\nFinancial Health Score: %d/100",
            number_format($income, 2),
            number_format($expenses, 2),
            number_format($net, 2),
            $savingsRate,
            number_format($netWorth, 2),
            number_format($investments, 2),
            $healthScore
        );

        // Check if OpenAI is enabled and configured
        if ($openaiEnabled && $openaiKey !== '') {
            $model = $config['openai']['model'] ?? 'gpt-4o-mini';
            try {
                $aiAnswer = $this->queryOpenAi($openaiKey, $model, $question, $contextSummary);
                return [
                    'answer'     => $aiAnswer,
                    'mode'       => 'live_ai_openai',
                    'model'      => $model,
                    'disclaimer' => 'Generated dynamically by OpenAI based on your real FinSync data. Not professional financial advice.',
                ];
            } catch (Throwable $e) {
                error_log('OpenAI API Error: ' . $e->getMessage());
                return [
                    'answer'     => 'AI service encountered an issue: ' . $e->getMessage() . '. Please verify your OpenAI API key and balance in config.local.php.',
                    'mode'       => 'api_error',
                    'disclaimer' => 'Error communicating with external AI endpoint.',
                ];
            }
        }

        // Check if Gemini is enabled and configured
        if ($geminiEnabled && $geminiKey !== '') {
            $model = $config['gemini']['model'] ?? 'gemini-1.5-flash';
            try {
                $aiAnswer = $this->queryGemini($geminiKey, $model, $question, $contextSummary);
                return [
                    'answer'     => $aiAnswer,
                    'mode'       => 'live_ai_gemini',
                    'model'      => $model,
                    'disclaimer' => 'Generated dynamically by Gemini based on your real FinSync data. Not professional financial advice.',
                ];
            } catch (Throwable $e) {
                error_log('Gemini API Error: ' . $e->getMessage());
                return [
                    'answer'     => 'Gemini AI service encountered an issue: ' . $e->getMessage() . '. Please verify your Gemini API key in config.local.php.',
                    'mode'       => 'api_error',
                    'disclaimer' => 'Error communicating with external AI endpoint.',
                ];
            }
        }

        // When no AI key is configured: Do NOT fake or hardcode answers!
        // Provide real dynamic calculation summary with clear configuration guidance.
        return [
            'answer' => sprintf(
                "FinSync AI Insights is ready. Based on your current dynamic records:\n\n" .
                "• Monthly Income: ₹%s\n" .
                "• Monthly Expenses: ₹%s\n" .
                "• Net Cash Flow: ₹%s (%s%% savings rate)\n" .
                "• Total Investment Portfolio: ₹%s\n\n" .
                "To enable real-time conversational AI intelligence powered by OpenAI or Gemini, enter your API key in config.local.php under 'openai' or 'gemini'.",
                number_format($income, 2),
                number_format($expenses, 2),
                number_format($net, 2),
                $savingsRate,
                number_format($investments, 2)
            ),
            'mode'       => 'unconfigured_notice',
            'disclaimer' => 'Computed dynamically from your MySQL records. Configure your OpenAI or Gemini key in config.local.php to activate conversational AI models.',
        ];
    }

    /**
     * Query OpenAI Chat Completions API
     */
    private function queryOpenAi(string $apiKey, string $model, string $question, string $context): string
    {
        $systemPrompt = "You are FinSync's senior FinTech AI financial intelligence advisor. " .
            "Analyze the user's real financial metrics provided below and answer their question clearly, " .
            "concisely, and objectively. Highlight strengths, potential risks, and practical suggestions. " .
            "Never guarantee financial returns. Keep the tone helpful, professional, and grounded strictly in their actual numbers.\n\n" .
            "User's Real Financial Context:\n" . $context;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $question],
            ],
            'temperature' => 0.4,
            'max_tokens'  => 600,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('cURL error: ' . $err);
        }

        $json = json_decode((string)$response, true);
        if ($httpCode !== 200) {
            $msg = $json['error']['message'] ?? "HTTP {$httpCode} from OpenAI";
            throw new \RuntimeException($msg);
        }

        return (string)($json['choices'][0]['message']['content'] ?? 'No response received from OpenAI.');
    }

    /**
     * Query Google Gemini API
     */
    private function queryGemini(string $apiKey, string $model, string $question, string $context): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $prompt = "You are FinSync's financial intelligence advisor. Ground your answer in the user's real financial data.\n\n" .
            "Financial Data:\n" . $context . "\n\n" .
            "User Question: " . $question;

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => 600,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('cURL error: ' . $err);
        }

        $json = json_decode((string)$response, true);
        if ($httpCode !== 200) {
            $msg = $json['error']['message'] ?? "HTTP {$httpCode} from Gemini";
            throw new \RuntimeException($msg);
        }

        return (string)($json['candidates'][0]['content']['parts'][0]['text'] ?? 'No response received from Gemini.');
    }
}
