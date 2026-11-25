<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/graph.php';

// Sends the technician to Microsoft 365 to sign in (authorization-code flow).
te_config();
te_session();
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));
$_SESSION['oauth_nonce'] = bin2hex(random_bytes(16));
$q = http_build_query([
    'client_id' => te_cfg('client_id'),
    'response_type' => 'code',
    'redirect_uri' => te_cfg('redirect_uri'),
    'response_mode' => 'query',
    'scope' => 'openid profile email',
    'state' => $_SESSION['oauth_state'],
    'nonce' => $_SESSION['oauth_nonce'],
    'prompt' => 'select_account',
]);
header('Location: ' . te_login_base() . '/' . rawurlencode((string) te_cfg('tenant_id')) . '/oauth2/v2.0/authorize?' . $q);
exit;
