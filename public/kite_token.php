<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$apiKey = trim((string)($_POST['api_key'] ?? 'dshtpypi0ppmlcxa'));
$apiSecret = trim((string)($_POST['api_secret'] ?? ''));
$requestTokenInput = trim((string)($_POST['request_token'] ?? ''));

$accessToken = null;
$error = null;
$userData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestToken = $requestTokenInput;
    // Extract request_token if user pasted the full redirect URL
    if (str_contains($requestToken, 'request_token=')) {
        $parts = parse_url($requestToken);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['request_token'])) {
                $requestToken = (string)$query['request_token'];
            }
        } elseif (preg_match('/request_token=([a-zA-Z0-9_\-]+)/', $requestToken, $matches)) {
            $requestToken = $matches[1];
        }
    }
    $requestToken = trim($requestToken);

    if ($apiKey === '' || $apiSecret === '' || $requestToken === '') {
        $error = 'Please fill in all three fields: API Key, API Secret, and Request Token (or redirected URL).';
    } else {
        $checksum = hash('sha256', $apiKey . $requestToken . $apiSecret);

        $ch = curl_init('https://api.kite.trade/session/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'api_key' => $apiKey,
            'request_token' => $requestToken,
            'checksum' => $checksum,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Kite-Version: 3',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            $error = 'cURL error connecting to Kite: ' . $err;
        } else {
            $json = json_decode((string)$res, true);
            if ($status === 200 && is_array($json) && ($json['status'] ?? '') === 'success') {
                $accessToken = (string)($json['data']['access_token'] ?? '');
                $userData = $json['data'] ?? [];
            } else {
                $error = $json['message'] ?? 'Zerodha returned HTTP ' . $status . ': ' . (string)$res;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zerodha Kite Access Token Generator</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 25px; margin: 0; }
        .card { max-width: 620px; margin: 30px auto; background: #1e293b; border-radius: 12px; padding: 28px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); border: 1px solid #334155; }
        h1 { margin-top: 0; font-size: 22px; color: #ffffff; display: flex; align-items: center; gap: 10px; }
        .kite-badge { background: #ff5722; color: #fff; padding: 3px 8px; border-radius: 6px; font-size: 13px; font-weight: bold; }
        label { display: block; margin-top: 14px; font-size: 13px; color: #94a3b8; font-weight: 500; }
        input { width: 100%; box-sizing: border-box; padding: 10px 12px; margin-top: 6px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #f8fafc; font-size: 14px; }
        input:focus { outline: none; border-color: #3b82f6; }
        button { margin-top: 20px; width: 100%; padding: 12px; background: #ff5722; color: #fff; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background .15s; }
        button:hover { background: #f4511e; }
        .success-box { background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #a7f3d0; padding: 16px; border-radius: 8px; margin-top: 20px; }
        .token-display { background: #0f172a; padding: 10px; border-radius: 6px; border: 1px dashed #34d399; font-family: monospace; font-size: 14px; word-break: break-all; color: #34d399; margin: 10px 0; select-all: all; }
        .error-box { background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 14px; border-radius: 8px; margin-top: 20px; font-size: 14px; }
        .note { font-size: 12px; color: #64748b; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="card">
        <h1><span class="kite-badge">Kite</span> Generate Daily Access Token</h1>
        <p style="color:#94a3b8; font-size:13px; margin-bottom:20px;">Exchange your Kite API Key, API Secret, and daily Request Token for your live <b>access_token</b>.</p>

        <?php if ($accessToken): ?>
            <div class="success-box">
                <b style="color:#34d399;">✓ Access Token Generated Successfully!</b><br>
                <small style="color:#94a3b8;">User: <?= htmlspecialchars((string)($userData['user_name'] ?? '')) ?> (<?= htmlspecialchars((string)($userData['user_id'] ?? '')) ?>)</small>
                
                <div class="token-display" id="token-box"><?= htmlspecialchars($accessToken, ENT_QUOTES, 'UTF-8') ?></div>
                <button type="button" style="background:#3b82f6; margin-top:5px;" onclick="navigator.clipboard.writeText(document.getElementById('token-box').innerText); alert('Access Token Copied!');">Copy Access Token</button>
                <p style="font-size:12px; color:#cbd5e1; margin-top:10px;">Paste this copied token into the <b>Kite Access Token</b> box on your FinSync website and click Sync!</p>
            </div>
        <?php elseif ($error): ?>
            <div class="error-box">
                <b>Error:</b> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <label>Kite API Key
                <input type="text" name="api_key" value="<?= htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label>Kite API Secret
                <input type="password" name="api_secret" placeholder="Your 32-character API Secret" value="<?= htmlspecialchars($apiSecret, ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label>Redirected URL or Request Token
                <input type="text" name="request_token" placeholder="Paste the redirected URL from your browser address bar" value="<?= htmlspecialchars($requestTokenInput, ENT_QUOTES, 'UTF-8') ?>" required>
                <div class="note">After clicking Authorize on Zerodha, copy the URL from your browser's address bar and paste it here.</div>
            </label>

            <button type="submit">Generate Access Token</button>
        </form>
    </div>
</body>
</html>
