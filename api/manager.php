<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/graph.php';
require __DIR__ . '/lib/connectwise.php';

/*
 * Staff API for the Schedule Manager (Microsoft 365 sign-in required).
 *   GET  ?action=state  -> everything the console shows, plus a CSRF token
 *   POST {action:...}   -> confirm | reschedule | deny | ask | finish | capacity | defaultcap | signout
 * Customer emails are written by the technician in the console, but the
 * recipient is ALWAYS the address on the request, never taken from the browser.
 */

function te_state(PDO $db, array $user, ?string $warning = null): array
{
    $since = (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
    $s = $db->prepare("SELECT * FROM appointments WHERE day >= ? AND status <> 'removed' ORDER BY day, hour, id");
    $s->execute([$since]);
    $appts = []; $ids = [];
    foreach ($s as $r) {
        $a = [
            'id' => 'R' . $r['id'], 'day' => $r['day'], 'hour' => (int) $r['hour'],
            'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'],
            'address' => $r['address'], 'reason' => $r['reason'], 'status' => $r['status'],
            'created' => (int) $r['created'], 'thread' => [],
        ];
        if (te_cw_enabled()) {
            $a['sr'] = $r['cw_ticket'] !== null ? (int) $r['cw_ticket'] : null;
            $a['cwWait'] = $r['cw_ticket'] === null && in_array($r['status'], TE_ACTIVE, true);
        }
        if ($r['ended_at'] !== null) { $a['endedAt'] = (int) $r['ended_at']; }
        if ($r['moved_day'] !== null) { $a['movedFrom'] = ['day' => $r['moved_day'], 'hour' => (int) $r['moved_hour']]; }
        $appts[(int) $r['id']] = $a; $ids[] = (int) $r['id'];
    }
    if ($ids) {
        $m = $db->query('SELECT appt_id, sender, at, text FROM messages WHERE appt_id IN (' . implode(',', $ids) . ') ORDER BY id');
        foreach ($m as $r) { $appts[(int) $r['appt_id']]['thread'][] = ['from' => $r['sender'], 'at' => (int) $r['at'], 'text' => $r['text']]; }
    }
    $cap = [];
    foreach ($db->query('SELECT day, cap FROM capacity') as $r) { $cap[$r['day']] = (int) $r['cap']; }
    $out = [];
    foreach ($db->query("SELECT * FROM outbox WHERE status='sent' ORDER BY id DESC LIMIT 200") as $r) {
        $out[] = ['id' => 'M' . $r['id'], 'kind' => $r['kind'], 'to' => $r['to_addr'], 'name' => $r['name'],
                  'subject' => $r['subject'], 'body' => $r['body'], 'at' => (int) $r['at']];
    }
    te_session();
    return ['ok' => true, 'me' => ['name' => $user['name'], 'email' => $user['email']], 'csrf' => $_SESSION['csrf'] ?? '',
            'appts' => array_values($appts), 'capacity' => (object) $cap, 'defaultCap' => te_default_cap($db), 'outbox' => $out,
            'cwOn' => te_cw_enabled(), 'cwWarning' => te_setting($db, 'cw_warning') ?: null, 'warning' => $warning];
}

function te_appt(PDO $db, $id): array
{
    $n = (int) ltrim((string) $id, 'R');
    $s = $db->prepare('SELECT * FROM appointments WHERE id=?');
    $s->execute([$n]);
    $r = $s->fetch();
    if (!$r) { te_fail(404, 'That request no longer exists.'); }
    return $r;
}

/** Sends the email first; only a delivered email lets the change go through. */
function te_mail_or_fail(PDO $db, array $a, string $kind, array $in, array $user): int
{
    $subject = te_clean((string) ($in['subject'] ?? ''), 200);
    $body = trim((string) ($in['body'] ?? ''));
    if ($subject === '' || $body === '') { te_fail(400, 'Subject and message cannot be empty.'); }
    if (strlen($body) > 20000) { te_fail(400, 'Message is too long.'); }
    $err = null;
    try { te_send_mail($a['email'], $a['name'], $subject, $body); } catch (Throwable $e) { $err = $e->getMessage(); }
    $db->prepare('INSERT INTO outbox (appt_id,kind,to_addr,name,subject,body,at,status,error,sent_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$a['id'], $kind, $a['email'], $a['name'], $subject, $body, te_now_ms(), $err === null ? 'sent' : 'failed', $err, $user['email']]);
    if ($err !== null) { error_log('te mail: ' . $err); te_fail(502, 'The email could not be sent, so nothing was changed. ' . $err); }
    return (int) $db->lastInsertId();
}

/** Writes what the technician just did onto the ConnectWise ticket (status, notes, new time). */
function te_cw_after(PDO $db, array $before, string $act, array $ctx, array $user): ?string
{
    if (!te_cw_enabled()) { return null; }
    $row = te_appt($db, $before['id']);
    if (empty($row['cw_ticket'])) { return null; }
    $c = te_cw_cfg();
    $who = $user['name'] !== '' ? $user['name'] : $user['email'];
    $job = ['notes' => []];
    if ($act === 'confirm') {
        $job['status'] = (string) ($c['status_scheduled'] ?? 'Scheduled');
        $job['notes'][] = 'Approved by ' . $who . ' in the Schedule Manager for ' . te_when($row['day'], (int) $row['hour']) . '. Confirmation emailed to the customer.';
    } elseif ($act === 'deny') {
        if (!empty($c['status_denied'])) { $job['status'] = (string) $c['status_denied']; }
        $job['notes'][] = 'Declined by ' . $who . ' in the Schedule Manager. Customer emailed.';
    } elseif ($act === 'ask') {
        $job['notes'][] = 'Question sent to the customer by ' . $who . ': ' . ($ctx['question'] ?? '');
    } elseif ($act === 'reschedule') {
        $job['summary'] = te_cw_summary($row);
        $job['required'] = te_cw_required($row);
        $job['notes'][] = 'Moved by ' . $who . ' from ' . te_when($before['day'], (int) $before['hour']) . ' to ' . te_when($row['day'], (int) $row['hour']) . '. Customer emailed.';
    } elseif ($act === 'finish') {
        if (!empty($c['status_completed'])) { $job['status'] = (string) $c['status_completed']; }
        $at = (int) ($ctx['at'] ?? 60);
        $job['notes'][] = 'Visit ended by ' . $who . ($at < 60 ? ' about ' . $at . ' minutes in.' : ' after the full hour.');
    }
    return te_cw_push($db, (int) $row['id'], (int) $row['cw_ticket'], $job);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $user = te_require_staff(false);
    if (($_GET['action'] ?? 'state') !== 'state') { te_fail(400, 'Unknown action.'); }
    $db = te_db();
    te_cw_sync($db);
    te_json(te_state($db, $user));
}
if ($method !== 'POST') { te_fail(405, 'GET or POST only.'); }

$user = te_require_staff(true);
$in = te_body();
$act = (string) ($in['action'] ?? '');
$db = te_db();

if ($act === 'signout') {
    te_session();
    $_SESSION = [];
    session_destroy();
    te_json(['ok' => true]);
}

if ($act === 'capacity') {
    $days = $in['days'] ?? [];
    $cap = (int) ($in['cap'] ?? -1);
    if (!is_array($days) || !$days || count($days) > 31 || $cap < 0 || $cap > TE_MAX_CAP) { te_fail(400, 'Bad capacity.'); }
    $db->exec('BEGIN');
    $st = $db->prepare('INSERT INTO capacity (day, cap) VALUES (?, ?) ON CONFLICT(day) DO UPDATE SET cap=excluded.cap');
    foreach ($days as $d) {
        if (!is_string($d) || !te_valid_day($d)) { $db->exec('ROLLBACK'); te_fail(400, 'Bad date.'); }
        $st->execute([$d, $cap]);
    }
    $db->exec('COMMIT');
    te_json(te_state($db, $user));
}
if ($act === 'defaultcap') {
    $cap = (int) ($in['cap'] ?? -1);
    if ($cap < 0 || $cap > TE_MAX_CAP) { te_fail(400, 'Bad capacity.'); }
    $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute(['default_cap', (string) $cap]);
    te_json(te_state($db, $user));
}

$a = te_appt($db, $in['id'] ?? '');
$id = (int) $a['id'];
$open = in_array($a['status'], ['pending', 'asked'], true);

if ($act === 'finish') {
    $at = (int) ($in['at'] ?? -1);
    if ($a['status'] !== 'confirmed' || !in_array($at, [15, 30, 45, 60], true)) { te_fail(409, 'That visit cannot be ended.'); }
    $db->prepare("UPDATE appointments SET status='completed', ended_at=? WHERE id=?")->execute([$at, $id]);
    te_json(te_state($db, $user, te_cw_after($db, $a, 'finish', ['at' => $at], $user)));
}

if (!in_array($act, ['confirm', 'deny', 'ask', 'reschedule'], true)) { te_fail(400, 'Unknown action.'); }

if ($act === 'confirm') {
    if (!$open) { te_fail(409, 'That request was already handled.'); }
    if (te_cw_enabled() && empty($a['cw_ticket'])) { te_fail(409, 'This request is not in ConnectWise yet, so it cannot be approved. It retries automatically; try again in a minute.'); }
    if (te_confirmed($db, $a['day'], (int) $a['hour'], $id) >= te_cap_of($db, $a['day'])) { te_fail(409, 'All seats this hour are already confirmed.'); }
}
if ($act === 'deny' && !$open) { te_fail(409, 'That request was already handled.'); }
if ($act === 'ask') {
    if (!$open) { te_fail(409, 'That request was already handled.'); }
    $question = te_clean((string) ($in['question'] ?? ''), 2000);
    if ($question === '') { te_fail(400, 'Type your question first.'); }
}
if ($act === 'reschedule') {
    if (!$open && $a['status'] !== 'confirmed') { te_fail(409, 'That visit cannot be moved.'); }
    $nd = (string) ($in['day'] ?? ''); $nh = (int) ($in['hour'] ?? -1);
    if (!te_slot_ok($nd, $nh) || ($nd === $a['day'] && $nh === (int) $a['hour'])) { te_fail(400, 'Pick a different, open time.'); }
    if (!te_slot_free($db, $nd, $nh, $id)) { te_fail(409, 'That hour has no open seat.'); }
}

$kind = $act;
te_mail_or_fail($db, $a, $kind, $in, $user);

$db->exec('BEGIN IMMEDIATE');
if ($act === 'confirm') {
    $db->prepare("UPDATE appointments SET status='confirmed' WHERE id=?")->execute([$id]);
} elseif ($act === 'deny') {
    $db->prepare("UPDATE appointments SET status='denied' WHERE id=?")->execute([$id]);
} elseif ($act === 'ask') {
    $db->prepare("UPDATE appointments SET status='asked' WHERE id=?")->execute([$id]);
    $db->prepare('INSERT INTO messages (appt_id, sender, at, text) VALUES (?, ?, ?, ?)')->execute([$id, 'us', te_now_ms(), $question]);
} else {
    $db->prepare("UPDATE appointments SET moved_day=?, moved_hour=?, day=?, hour=?, status=CASE WHEN status='asked' THEN 'pending' ELSE status END WHERE id=?")
       ->execute([$a['day'], $a['hour'], $nd, $nh, $id]);
}
$db->exec('COMMIT');
te_json(te_state($db, $user, te_cw_after($db, $a, $act, ['question' => $question ?? ''], $user)));
