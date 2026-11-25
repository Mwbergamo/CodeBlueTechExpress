<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/graph.php';

// Public booking intake. POST JSON { start, reason, name, address, phone, email }.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { te_fail(405, 'POST only.'); }
te_same_origin();
$in = te_body();
$db = te_db();

// Basic abuse limit: 5 requests per hour per visitor address.
$ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|te');
$db->prepare('DELETE FROM ratelimit WHERE at < ?')->execute([time() - 3600]);
$c = $db->prepare('SELECT COUNT(*) FROM ratelimit WHERE ip=?');
$c->execute([$ip]);
if ((int) $c->fetchColumn() >= 5) { te_fail(429, 'Too many requests. Please call us at (804) 456-4500.'); }

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

// Tell the team (best effort: the request is already saved either way).
$notify = te_cfg('notify_to', '');
foreach ((array) $notify as $to) {
    if (!is_string($to) || $to === '') { continue; }
    try {
        $when = (new DateTimeImmutable($day))->format('D M j') . ' at ' . sprintf('%d:00 %s', $hour % 12 ?: 12, $hour < 12 ? 'AM' : 'PM');
        te_send_mail($to, 'CodeBlue Tech Express', 'New visit request: ' . $when . ', ' . $name,
            "A new visit request needs a decision.\n\nWhen: $when\nName: $name\nPhone: $phone\nEmail: $email\nAddress: $address\n\nWhat they need:\n$reason\n\nOpen the Schedule Manager: https://" . ($_SERVER['HTTP_HOST'] ?? 'codebluetechexpress.com') . "/manage/\n");
    } catch (Throwable $e) {
        error_log('te notify: ' . $e->getMessage());
    }
}
te_json(['ok' => true]);
