<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/graph.php';
require __DIR__ . '/lib/connectwise.php';

// Public booking intake. POST JSON { start, reason, name, address, phone, email }.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { te_fail(405, 'POST only.'); }
te_same_origin();
$in = te_body();
$db = te_db();

// Abuse limits: per visitor per hour, and overall per hour (so a flood from many
// addresses is still bounded). The site sits behind Cloudflare, where REMOTE_ADDR is
// Cloudflare's own address, so use the visitor address it passes along when valid.
$visitor = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
if (!filter_var($visitor, FILTER_VALIDATE_IP)) { $visitor = $_SERVER['REMOTE_ADDR'] ?? ''; }
$ip = hash('sha256', $visitor . '|te');
$db->prepare('DELETE FROM ratelimit WHERE at < ?')->execute([time() - 3600]);
$c = $db->prepare('SELECT COUNT(*) FROM ratelimit WHERE ip=?');
$c->execute([$ip]);
$total = (int) $db->query('SELECT COUNT(*) FROM ratelimit')->fetchColumn();
if ((int) $c->fetchColumn() >= (int) te_cfg('booking_limit_per_visitor', 5) || $total >= (int) te_cfg('booking_limit_total', 60)) {
    te_fail(429, 'Too many requests. Please call us at (804) 456-4500.');
}

$start = (string) ($in['start'] ?? '');
if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):00$/', $start, $m)) { te_fail(400, 'Pick a time on the calendar.'); }
$day = $m[1]; $hour = (int) $m[2];
if (!te_slot_ok($day, $hour)) { te_fail(400, 'That time is not available.'); }

$name    = te_clean((string) ($in['name'] ?? ''), 100);
$address = te_clean((string) ($in['address'] ?? ''), 200);
$reason  = te_clean((string) ($in['reason'] ?? ''), 2000);
$phone   = te_clean((string) ($in['phone'] ?? ''), 40);
$email   = strtolower(te_clean((string) ($in['email'] ?? ''), 120));
if ($name === '' || $address === '' || $reason === '') { te_fail(400, 'Please fill in every field.'); }
if (strlen(preg_replace('/\D/', '', $phone)) < 10) { te_fail(400, 'Please enter a phone number with area code.'); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { te_fail(400, 'Please enter a valid email address.'); }

$db->exec('BEGIN IMMEDIATE');
try {
    // Same person asking for the same hour twice: treat as already received.
    $d = $db->prepare("SELECT COUNT(*) FROM appointments WHERE email=? AND day=? AND hour=? AND status IN ('pending','asked','confirmed')");
    $d->execute([$email, $day, $hour]);
    if ((int) $d->fetchColumn() > 0) { $db->exec('COMMIT'); te_json(['ok' => true]); }

    if (!te_slot_free($db, $day, $hour)) { $db->exec('ROLLBACK'); te_fail(409, 'That time was just taken. Please pick another.'); }
    $db->prepare('INSERT INTO appointments (day,hour,name,phone,email,address,reason,status,created) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$day, $hour, $name, $phone, $email, $address, $reason, 'pending', te_now_ms()]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO ratelimit (ip, at) VALUES (?, ?)')->execute([$ip, time()]);
    $db->exec('COMMIT');
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->exec('ROLLBACK'); }
    error_log('te intake: ' . $e->getMessage());
    te_fail(500, 'We could not save that request.');
}

// Tell the team. With ConnectWise on: open the ticket, then email "SR#<n> - Express
// Scheduling Request" to express@. Either way the request is already saved.
$q = $db->prepare('SELECT * FROM appointments WHERE id=?');
$q->execute([$id]);
$row = $q->fetch();
$when = te_when($day, $hour);
$details = "When: $when\nName: $name\nPhone: $phone\nEmail: $email\nAddress: $address\n\nWhat they need:\n$reason\n";
$host = $_SERVER['HTTP_HOST'] ?? 'codebluetechexpress.com';

if (te_cw_enabled()) {
    try {
        te_cw_open_ticket($db, $row);
    } catch (Throwable $e) {
        error_log('te ticket: ' . $e->getMessage());
        $db->prepare('UPDATE appointments SET cw_error=? WHERE id=?')->execute([$e->getMessage(), $id]);
        $alert = array_filter(array_merge([(string) te_cfg('notice_to', 'express@codebluetechnology.com')], (array) te_cfg('notify_to', [])), 'is_string');
        foreach (array_unique($alert) as $to) {
            try {
                te_send_mail($to, 'CodeBlue Tech Express', 'ACTION: website visit request is not in ConnectWise yet (' . $when . ', ' . $name . ')',
                    "A visit request was saved, but the ConnectWise ticket could not be created. It will be retried automatically each time the Schedule Manager is opened.\n\nReason: " . $e->getMessage() . "\n\n" . $details . "\nhttps://$host/manage/\n");
            } catch (Throwable $e2) { error_log('te notify: ' . $e2->getMessage()); }
        }
    }
} else {
    foreach ((array) te_cfg('notify_to', '') as $to) {
        if (!is_string($to) || $to === '') { continue; }
        try {
            te_send_mail($to, 'CodeBlue Tech Express', 'New visit request: ' . $when . ', ' . $name,
                "A new visit request needs a decision.\n\n$details\nOpen the Schedule Manager: https://$host/manage/\n");
        } catch (Throwable $e) { error_log('te notify: ' . $e->getMessage()); }
    }
}
te_json(['ok' => true]);
