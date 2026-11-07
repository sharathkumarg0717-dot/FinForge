<?php
/**
 * FinForge Pro — Configuration Template
 *
 * Copy this file to config.local.php and fill in your values.
 * Keep config.local.php PRIVATE — it is excluded from version control.
 * Never put credentials in public/ or commit them to Git.
 *
 * Phase 1 adds: app.encryption_key (for token encryption in Phase 3)
 * Phase 5 adds: openai.api_key
 */
declare(strict_types=1);

return [

    // -----------------------------------------------------------------
    // Database (MySQL / MariaDB)
    // -----------------------------------------------------------------
    'db' => [
        'host'     => '127.0.0.1',          // XAMPP local: 127.0.0.1 | InfinityFree: sql107.infinityfree.com
        'port'     => 3306,
        'database' => 'finsync',            // InfinityFree: if0_43306084_finsync
        'username' => 'root',               // InfinityFree: if0_43306084
        'password' => '',                   // XAMPP default: blank | InfinityFree: your hosting password
        'charset'  => 'utf8mb4',
    ],

    // -----------------------------------------------------------------
    // Application
    // -----------------------------------------------------------------
    'app' => [
        'base_path'   => '',                // Set to '/FinSync/public' when in an htdocs subfolder
        'environment' => 'local',           // 'local' | 'production'

        // Phase 1 — AES-256-GCM encryption key for storing sensitive tokens at rest.
        // Generate with:  php -r "echo base64_encode(random_bytes(32));"
        // Must be exactly 32 bytes (44 chars base64). Keep this SECRET.
        'encryption_key' => '',
    ],

    // -----------------------------------------------------------------
    // Google Sign-In (optional)
    // -----------------------------------------------------------------
    'google' => [
        // Google OAuth 2.0 Web Client ID from https://console.cloud.google.com/
        'client_id' => '',
    ],

    // -----------------------------------------------------------------
    // Phase 3 — Zerodha Kite Connect (server-side only)
    // -----------------------------------------------------------------
    'zerodha' => [
        'api_key'    => '',  // Your Kite API Key (safe to store here, NOT in frontend JS)
        'api_secret' => '',  // Your Kite API Secret — NEVER expose this to the browser
    ],

    // -----------------------------------------------------------------
    // Phase 3 — Dhan HQ API
    // See https://dhanhq.co/dhan-hq-api for credentials
    // -----------------------------------------------------------------
    'dhan' => [
        'client_id'    => '',
        'access_token' => '',  // Dhan uses long-lived tokens — store encrypted in DB
    ],

    // -----------------------------------------------------------------
    // Phase 4 — Account Aggregator (Setu AA Sandbox)
    // Register free at https://setu.co/data/account-aggregator
    // -----------------------------------------------------------------
    'account_aggregator' => [
        'provider'      => 'setu',          // 'setu' | 'finvu'
        'client_id'     => '',              // From your Setu dashboard
        'client_secret' => '',              // Keep this secret
        'redirect_url'  => '',              // Your public HTTPS URL for consent callbacks
        'sandbox'       => true,            // Set to false in production
    ],

    // -----------------------------------------------------------------
    // Phase 5 — OpenAI API (Real AI Insights)
    // Get your key at https://platform.openai.com/account/api-keys
    // -----------------------------------------------------------------
    'openai' => [
        'api_key' => '',                    // sk-...  NEVER expose in frontend code
        'model'   => 'gpt-4o-mini',         // Cost-efficient model; change to gpt-4o for higher quality
        'enabled' => false,                 // Set to true once you have a valid API key
    ],

    // -----------------------------------------------------------------
    // Phase 1 — Email (for password reset)
    // InfinityFree does not support sendmail; use an SMTP relay service.
    // Free options: Mailjet (200/day), Brevo/Sendinblue (300/day)
    // -----------------------------------------------------------------
    'mail' => [
        'enabled'    => false,              // Set to true once SMTP is configured
        'driver'     => 'smtp',
        'host'       => 'in-v3.mailjet.com',
        'port'       => 587,
        'username'   => '',                 // Mailjet API Key
        'password'   => '',                 // Mailjet Secret Key
        'from_email' => 'noreply@yourdomain.com',
        'from_name'  => 'FinForge',
    ],
];
