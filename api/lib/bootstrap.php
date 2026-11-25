<?php
declare(strict_types=1);
/*
 * Shared setup for every API endpoint: config, database, JSON helpers,
 * session, and the scheduling rules (hours, capacity, status flow).
 *
 * Private files (config.php, the SQLite database, cached tokens) live in a
 * "te-private" folder OUTSIDE the web root. It is found by walking up from this
 * file, or set explicitly with the TE_PRIVATE_DIR environment variable.
 */

date_default_timezone_set('America/New_York');

const TE_HOURS      = [11, 12, 13, 14, 15];   // one-hour visits, 11 AM to 4 PM
const TE_MAX_CAP    = 6;
const TE_DEFAULT_CAP = 2;
const TE_WEEKS_AHEAD = 8;
const TE_ACTIVE     = ['pending', 'asked', 'confirmed'];

function te_private_dir(): ?string
{
    $env = getenv('TE_PRIVATE_DIR');
    if ($env && is_dir($env)) { return rtrim($env, '/\\'); }
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $dir = dirname($dir);
        if (is_dir($dir . '/te-private')) { return $dir . '/te-private'; }
    }
    return null;
}

function te_config(): array
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $dir = te_private_dir();
    if (!$dir || !is_file($dir . '/config.php')) {
        te_fail(503, 'The scheduling service is not set up yet.');
    }
    $cfg = require $dir . '/config.php';
    if (!is_array($cfg)) { te_fail(503, 'The scheduling service is not set up yet.'); }
    return $cfg;
}

function te_cfg(string $key, $default = null)
{
    $c = te_config();
    return array_key_exists($key, $c) && $c[$key] !== '' ? $c[$key] : $default;
}

/* ------------------------------------------------------------------ http */
function te_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function te_fail(int $code, string $msg): void
{
    te_json(['ok' => false, 'error' => $msg], $code);
}

function te_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 65536) { te_fail(413, 'Request too large.'); }
    $d = json_decode($raw, true);
    if (!is_array($d)) { te_fail(400, 'Send JSON.'); }
    return $d;
}

/** Browsers always send Origin on cross-site POSTs; refuse any that is not us. */
function te_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') { return; }
    $host = parse_url($origin, PHP_URL_HOST);
    $mine = $_SERVER['HTTP_HOST'] ?? '';
    $mine = preg_replace('/:\d+$/', '', $mine);
    if (!$host || strcasecmp($host, $mine) !== 0) { te_fail(403, 'Not allowed.'); }
}

function te_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/* --------------------------------------------------------------- session */
function te_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('te_mgr');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => te_is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

/** Returns the signed-in staff member or ends the request with 401. */
function te_require_staff(bool $write = false): array
{
    te_session();
    $u = $_SESSION['user'] ?? null;
    if (!is_array($u) || ($u['exp'] ?? 0) < time()) { te_fail(401, 'Sign in required.'); }
    if ($write) {
        te_same_origin();
        $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($tok) || $tok === '' || !hash_equals($_SESSION['csrf'] ?? '', $tok)) {
            te_fail(403, 'Session expired. Reload the page.');
        }
    }
    return $u;
}

/* -------------------------------------------------------------------- db */
function te_db(): PDO
{
    static $db = null;
    if ($db) { return $db; }
    te_config();                       // refuse to run until config.php exists
    $dir = te_private_dir();
    if (!$dir) { te_fail(503, 'The scheduling service is not set up yet.'); }
    if (!is_dir($dir . '/data') && !@mkdir($dir . '/data', 0700, true)) {
        te_fail(500, 'Cannot create the data folder.');
    }
    $db = new PDO('sqlite:' . $dir . '/data/schedule.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS appointments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  day TEXT NOT NULL, hour INTEGER NOT NULL,
  name TEXT NOT NULL, phone TEXT NOT NULL, email TEXT NOT NULL,
  address TEXT NOT NULL, reason TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'pending',
  created INTEGER NOT NULL, ended_at INTEGER,
  moved_day TEXT, moved_hour INTEGER
);
CREATE INDEX IF NOT EXISTS ix_appt_slot ON appointments(day, hour);
CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  appt_id INTEGER NOT NULL REFERENCES appointments(id),
  sender TEXT NOT NULL, at INTEGER NOT NULL, text TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS outbox (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  appt_id INTEGER, kind TEXT NOT NULL, to_addr TEXT NOT NULL, name TEXT NOT NULL,
  subject TEXT NOT NULL, body TEXT NOT NULL, at INTEGER NOT NULL,
  status TEXT NOT NULL, error TEXT, sent_by TEXT
);
CREATE TABLE IF NOT EXISTS capacity (day TEXT PRIMARY KEY, cap INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS ratelimit (ip TEXT NOT NULL, at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS ix_rl ON ratelimit(ip, at);
SQL);
    return $db;
}

/* ------------------------------------------------------------ scheduling */
function te_now_ms(): int { return (int) round(microtime(true) * 1000); }

function te_default_cap(PDO $db): int
{
    $v = $db->query("SELECT v FROM settings WHERE k='default_cap'")->fetchColumn();
    return $v === false ? TE_DEFAULT_CAP : max(0, min(TE_MAX_CAP, (int) $v));
}

function te_cap_of(PDO $db, string $day): int
{
    $s = $db->prepare('SELECT cap FROM capacity WHERE day=?');
    $s->execute([$day]);
    $v = $s->fetchColumn();
    return $v === false ? te_default_cap($db) : (int) $v;
}

function te_used(PDO $db, string $day, int $hour, ?int $exceptId = null): int
{
    $q = "SELECT COUNT(*) FROM appointments WHERE day=? AND hour=? AND status IN ('pending','asked','confirmed')";
    $p = [$day, $hour];
    if ($exceptId !== null) { $q .= ' AND id<>?'; $p[] = $exceptId; }
    $s = $db->prepare($q);
    $s->execute($p);
    return (int) $s->fetchColumn();
}

function te_confirmed(PDO $db, string $day, int $hour, ?int $exceptId = null): int
{
    $q = "SELECT COUNT(*) FROM appointments WHERE day=? AND hour=? AND status='confirmed'";
    $p = [$day, $hour];
    if ($exceptId !== null) { $q .= ' AND id<>?'; $p[] = $exceptId; }
    $s = $db->prepare($q);
    $s->execute($p);
    return (int) $s->fetchColumn();
}

function te_valid_day(string $day): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { return false; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    return $d && $d->format('Y-m-d') === $day;
}

function te_is_weekday(string $day): bool
{
    $n = (int) (new DateTimeImmutable($day))->format('N');
    return $n >= 1 && $n <= 5;
}

/** A bookable slot: weekday, store hour, in the future, inside the window. */
function te_slot_ok(string $day, int $hour): bool
{
    if (!te_valid_day($day) || !te_is_weekday($day) || !in_array($hour, TE_HOURS, true)) { return false; }
    $start = new DateTimeImmutable($day . ' ' . sprintf('%02d:00', $hour));
    $now = new DateTimeImmutable('now');
    return $start > $now && $start < $now->modify('+' . TE_WEEKS_AHEAD . ' weeks');
}

function te_slot_free(PDO $db, string $day, int $hour, ?int $exceptId = null): bool
{
    return te_used($db, $day, $hour, $exceptId) < te_cap_of($db, $day);
}

function te_clean(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}
