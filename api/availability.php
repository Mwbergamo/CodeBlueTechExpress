<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// GET ?from=YYYY-MM-DD&to=YYYY-MM-DD -> JSON list of slot starts that are NOT
// bookable ("YYYY-MM-DDTHH:00"): full hours and closed days. Names and details
// of who booked are never exposed here.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { te_fail(405, 'GET only.'); }
$from = (string) ($_GET['from'] ?? '');
$to   = (string) ($_GET['to'] ?? '');
if (!te_valid_day($from) || !te_valid_day($to) || $to < $from) { te_fail(400, 'Bad date range.'); }
$span = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
if ($span > 120) { te_fail(400, 'Range too large.'); }

$db = te_db();
$defaultCap = te_default_cap($db);
$caps = [];
$s = $db->prepare('SELECT day, cap FROM capacity WHERE day BETWEEN ? AND ?');
$s->execute([$from, $to]);
foreach ($s as $r) { $caps[$r['day']] = (int) $r['cap']; }
$used = [];
$s = $db->prepare("SELECT day, hour, COUNT(*) n FROM appointments WHERE day BETWEEN ? AND ? AND status IN ('pending','asked','confirmed') GROUP BY day, hour");
$s->execute([$from, $to]);
foreach ($s as $r) { $used[$r['day'] . '|' . $r['hour']] = (int) $r['n']; }

$out = [];
for ($d = new DateTimeImmutable($from); $d <= new DateTimeImmutable($to); $d = $d->modify('+1 day')) {
    $day = $d->format('Y-m-d');
    if (!te_is_weekday($day)) { continue; }
    $cap = $caps[$day] ?? $defaultCap;
    foreach (TE_HOURS as $h) {
        if (($used[$day . '|' . $h] ?? 0) >= $cap) { $out[] = sprintf('%sT%02d:00', $day, $h); }
    }
}
te_json($out);
