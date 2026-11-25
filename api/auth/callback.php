<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/graph.php';

/*
 * Microsoft sends the technician back here with a one-time code. We trade it
 * (server to server, with the app secret) for an ID token, then check that the
 * token is for this app, this tenant, this sign-in, and not expired. Because the
 * token arrives straight from Microsoft's token endpoint over TLS, its signature
 * does not need re-verifying (OpenID Connect Core 3.1.3.7).
 */
function te_deny(string $why): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Sign-in problem</title>'
       . '<body style="font-family:system-ui;max-width:32rem;margin:4rem auto;padding:0 1rem">'
       . '<h1>We could not sign you in</h1><p>' . htmlspecialchars($why, ENT_QUOTES) . '</p>'
       . '<p><a href="/manage/">Back to the Schedule Manager</a></p></body>';
    exit;
}

te_config();
te_session();
if (!empty($_GET['error'])) { te_deny('Microsoft said: ' . substr((string) ($_GET['error_description'] ?? $_GET['error']), 0, 200)); }

$state = $_GET['state'] ?? '';
$code  = $_GET['code'] ?? '';
$want  = $_SESSION['oauth_state'] ?? '';
$nonce = $_SESSION['oauth_nonce'] ?? '';
unset($_SESSION['oauth_state'], $_SESSION['oauth_nonce']);
if (!is_string($state) || $state === '' || !hash_equals((string) $want, $state) || !is_string($code) || $code === '') {
    te_deny('That sign-in link has expired. Please start again.');
}

[$status, $res] = te_http('POST', te_login_base() . '/' . rawurlencode((string) te_cfg('tenant_id')) . '/oauth2/v2.0/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
        'client_id' => te_cfg('client_id'),
        'client_secret' => te_cfg('client_secret'),
        'code' => $code,
        'redirect_uri' => te_cfg('redirect_uri'),
        'grant_type' => 'authorization_code',
        'scope' => 'openid profile email',
    ]));
$tok = json_decode($res, true);
if ($status !== 200 || !is_array($tok) || empty($tok['id_token'])) { te_deny('Microsoft did not accept the sign-in. Please try again.'); }

$parts = explode('.', (string) $tok['id_token']);
if (count($parts) !== 3) { te_deny('Unexpected response from Microsoft.'); }
$claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), false), true);
if (!is_array($claims)) { te_deny('Unexpected response from Microsoft.'); }

$tenant = (string) te_cfg('tenant_id');
$okAud = ($claims['aud'] ?? '') === te_cfg('client_id');
$okTid = strcasecmp((string) ($claims['tid'] ?? ''), $tenant) === 0;
$okIss = ($claims['iss'] ?? '') === te_login_base() . '/' . $tenant . '/v2.0';
$okNonce = isset($claims['nonce']) && hash_equals((string) $nonce, (string) $claims['nonce']);
$okExp = (int) ($claims['exp'] ?? 0) > time();
if (!($okAud && $okTid && $okIss && $okNonce && $okExp)) { te_deny('That sign-in could not be verified.'); }

$email = strtolower((string) ($claims['preferred_username'] ?? $claims['email'] ?? $claims['upn'] ?? ''));
$name = (string) ($claims['name'] ?? $email);
$allowed = array_map('strtolower', (array) te_cfg('staff_emails', []));
if ($allowed && !in_array($email, $allowed, true)) { te_deny($email . ' is not on the list of people who can use the Schedule Manager.'); }

session_regenerate_id(true);
$_SESSION['user'] = ['name' => $name, 'email' => $email, 'exp' => time() + 3600 * (int) te_cfg('session_hours', 10)];
$_SESSION['csrf'] = bin2hex(random_bytes(20));
header('Location: /manage/');
exit;
