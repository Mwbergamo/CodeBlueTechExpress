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
// ---- ConnectWise Manage stand-in: one board ("Express", id 7) with a few statuses.
if (preg_match('#^/v4_6_release/apis/3\.0(/.*)$#', $path, $m)) {
    header('Content-Type: application/json');
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode('co+pub:priv') || ($_SERVER['HTTP_CLIENTID'] ?? '') !== 'cw-client') {
        http_response_code(401); echo json_encode(['code' => 'Unauthorized', 'message' => 'Bad credentials (mock)']); exit;
    }
    if (is_file("$dir/cw_down")) { http_response_code(503); echo json_encode(['message' => 'ConnectWise is down (mock)']); exit; }
    $api = $m[1]; $method = $_SERVER['REQUEST_METHOD'];
    $statuses = [1 => 'New', 2 => 'Scheduled', 3 => 'Closed', 4 => 'Declined'];
    $tf = "$dir/cw-tickets.json";
    $tickets = is_file($tf) ? json_decode(file_get_contents($tf), true) : [];
    $save = function () use (&$tickets, $tf) { file_put_contents($tf, json_encode($tickets)); };
    $body = json_decode(file_get_contents('php://input'), true);
    file_put_contents("$dir/cw-calls.log", $method . ' ' . $api . "\n", FILE_APPEND);

    if ($method === 'GET' && $api === '/service/boards') {
        echo json_encode(strpos($_GET['conditions'] ?? '', 'Express') !== false ? [['id' => 7, 'name' => 'Express']] : []); exit;
    }
    if ($method === 'GET' && $api === '/service/boards/7/statuses') {
        echo json_encode(array_map(fn($id, $n) => ['id' => $id, 'name' => $n], array_keys($statuses), $statuses)); exit;
    }
    if ($method === 'POST' && $api === '/service/tickets') {
        if (($body['board']['id'] ?? 0) !== 7 || ($body['company']['identifier'] ?? '') !== 'TechExpress' || empty($body['summary'])) {
            http_response_code(400); echo json_encode(['code' => 'InvalidObject', 'message' => 'bad ticket (mock)']); exit;
        }
        $id = 50100 + count($tickets);
        $sid = $body['status']['id'] ?? 1;
        $tickets[$id] = array_merge($body, ['id' => $id, 'status' => ['id' => $sid, 'name' => $statuses[$sid]], 'closedFlag' => in_array($sid, [3, 4]), 'notes' => []]);
        $save(); echo json_encode($tickets[$id]); exit;
    }
    if (preg_match('#^/service/tickets/(\d+)$#', $api, $t) && $method === 'PATCH') {
        $id = (int) $t[1];
        if (!isset($tickets[$id])) { http_response_code(404); echo json_encode(['message' => 'not found (mock)']); exit; }
        foreach ($body as $op) {
            if ($op['path'] === '/status') { $sid = $op['value']['id']; $tickets[$id]['status'] = ['id' => $sid, 'name' => $statuses[$sid]]; $tickets[$id]['closedFlag'] = in_array($sid, [3, 4]); }
            if ($op['path'] === '/summary') { $tickets[$id]['summary'] = $op['value']; }
            if ($op['path'] === '/requiredDate') { $tickets[$id]['requiredDate'] = $op['value']; }
        }
        $save(); echo json_encode($tickets[$id]); exit;
    }
    if (preg_match('#^/service/tickets/(\d+)/notes$#', $api, $t) && $method === 'POST') {
        $id = (int) $t[1];
        if (!isset($tickets[$id])) { http_response_code(404); echo json_encode(['message' => 'not found (mock)']); exit; }
        $tickets[$id]['notes'][] = $body['text']; $save(); echo json_encode(['id' => 1]); exit;
    }
    if ($method === 'GET' && $api === '/service/tickets') {
        $out = [];
        foreach ($tickets as $t) {
            if (($t['board']['id'] ?? 0) !== 7 || stripos($t['summary'], 'Express Scheduling Request') === false) { continue; }
            $out[] = ['id' => $t['id'], 'status' => ['name' => $t['status']['name']], 'closedFlag' => $t['closedFlag']];
        }
        echo json_encode($out); exit;
    }
    http_response_code(404); echo json_encode(['message' => 'unknown mock route ' . $api]); exit;
}

http_response_code(404);
