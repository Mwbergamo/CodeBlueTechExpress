<?php
declare(strict_types=1);
/*
 * Microsoft 365 plumbing: outbound HTTP, app-only Graph token, and sendMail.
 * Mail is sent AS a shared mailbox (config 'mail_sender') using the app's
 * Mail.Send application permission, so no one's password or session is needed.
 */

function te_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) { return [0, $err ?: 'network error']; }
    return [$code, (string) $out];
}

function te_login_base(): string { return rtrim((string) te_cfg('login_base', 'https://login.microsoftonline.com'), '/'); }
function te_graph_base(): string { return rtrim((string) te_cfg('graph_base', 'https://graph.microsoft.com'), '/'); }

/** App-only token for Graph, cached on disk until shortly before it expires. */
function te_graph_token(): string
{
    $dir = te_private_dir() . '/data';
    $file = $dir . '/graph-token.json';
    if (is_file($file)) {
        $c = json_decode((string) @file_get_contents($file), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time() + 120 && !empty($c['token'])) { return $c['token']; }
    }
    $form = http_build_query([
        'client_id' => te_cfg('client_id'),
        'client_secret' => te_cfg('client_secret'),
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ]);
    [$code, $res] = te_http('POST', te_login_base() . '/' . te_cfg('tenant_id') . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'], $form);
    $j = json_decode($res, true);
    if ($code !== 200 || !is_array($j) || empty($j['access_token'])) {
        $why = is_array($j) ? ($j['error_description'] ?? $j['error'] ?? '') : $res;
        throw new RuntimeException('Microsoft sign-in for mail failed: ' . substr(preg_replace('/\s+/', ' ', (string) $why), 0, 200));
    }
    @file_put_contents($file, json_encode(['token' => $j['access_token'], 'exp' => time() + (int) ($j['expires_in'] ?? 3000)]), LOCK_EX);
    @chmod($file, 0600);
    return $j['access_token'];
}

/** Sends a plain-text email from the shared mailbox. Throws on any failure. */
function te_send_mail(string $to, string $toName, string $subject, string $body): void
{
    $sender = (string) te_cfg('mail_sender');
    if ($sender === '') { throw new RuntimeException('No sender mailbox is configured.'); }
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $payload = json_encode([
        'message' => [
            'subject' => $subject,
            'body' => ['contentType' => 'Text', 'content' => $body],
            'toRecipients' => [['emailAddress' => ['address' => $to, 'name' => $toName]]],
        ],
        'saveToSentItems' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$code, $res] = te_http('POST', te_graph_base() . '/v1.0/users/' . rawurlencode($sender) . '/sendMail',
        ['Authorization: Bearer ' . te_graph_token(), 'Content-Type: application/json'], $payload);
    if ($code === 401) {                       // token revoked early: drop cache and retry once
        @unlink(te_private_dir() . '/data/graph-token.json');
        [$code, $res] = te_http('POST', te_graph_base() . '/v1.0/users/' . rawurlencode($sender) . '/sendMail',
            ['Authorization: Bearer ' . te_graph_token(), 'Content-Type: application/json'], $payload);
    }
    if ($code !== 202 && $code !== 200) {
        $j = json_decode($res, true);
        $why = is_array($j) ? ($j['error']['message'] ?? '') : $res;
        throw new RuntimeException('Microsoft 365 refused the email (' . $code . '): ' . substr(preg_replace('/\s+/', ' ', (string) $why), 0, 200));
    }
}
