<?php
// Stand-in for login.microsoftonline.com and graph.microsoft.com, for local tests only.
$dir = getenv('MOCK_DIR') ?: '/tmp/tetest';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

if (preg_match('#/([^/]+)/oauth2/v2.0/authorize$#', $path, $m)) {
    $code = bin2hex(random_bytes(6));
    file_put_contents("$dir/code-$code", json_encode(['nonce' => $_GET['nonce'], 'tid' => $m[1]]));
    header('Location: ' . $_GET['redirect_uri'] . '?code=' . $code . '&state=' . urlencode($_GET['state']));
    exit;
}
if (preg_match('#/([^/]+)/oauth2/v2.0/token$#', $path, $m)) {
    header('Content-Type: application/json');
    if (($_POST['grant_type'] ?? '') === 'client_credentials') {
        file_put_contents("$dir/token-requests", "1\n", FILE_APPEND);
        echo json_encode(['access_token' => 'graph-token', 'expires_in' => 3599]); exit;
    }
    $f = "$dir/code-" . ($_POST['code'] ?? '');
    if (!is_file($f) || ($_POST['client_secret'] ?? '') !== 'test-secret') { http_response_code(400); echo json_encode(['error' => 'invalid_grant']); exit; }
    $c = json_decode(file_get_contents($f), true);
    $opts = is_file("$dir/claims.json") ? json_decode(file_get_contents("$dir/claims.json"), true) : [];
    $claims = array_merge([
        'aud' => $_POST['client_id'], 'tid' => $c['tid'], 'iss' => 'http://127.0.0.1:8081/' . $c['tid'] . '/v2.0',
        'nonce' => $c['nonce'], 'exp' => time() + 3600, 'preferred_username' => 'tech@codebluetechnology.com', 'name' => 'Test Tech',
    ], $opts);
    echo json_encode(['id_token' => $b64('{"alg":"none"}') . '.' . $b64(json_encode($claims)) . '.sig', 'access_token' => 'x']); exit;
}
if (preg_match('#/v1.0/users/([^/]+)/sendMail$#', $path, $m)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer graph-token') { http_response_code(401); exit; }
    if (is_file("$dir/fail_mail")) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['error' => ['message' => 'Access is denied (mock)']]); exit; }
    file_put_contents("$dir/mail.log", json_encode(['from' => urldecode($m[1]), 'req' => json_decode(file_get_contents('php://input'), true)]) . "\n", FILE_APPEND);
    http_response_code(202); exit;
}
http_response_code(404);
