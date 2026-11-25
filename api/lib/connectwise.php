<?php
declare(strict_types=1);
/*
 * ConnectWise Manage plumbing for the Express service board.
 *
 * Everything is driven by the 'cw' block in ~/te-private/config.php. When that
 * block is missing the scheduling service works exactly as before, without
 * ConnectWise. See docs/schedule-backend-setup.md for the settings.
 *
 * A website request becomes ONE ticket on the Express board:
 *   summary  "Express Scheduling Request - <name> - <Fri Dec 5, 11:00 AM>"
 * and the team is told by an email to express@ whose subject is
 *   "SR#<ticket number> - Express Scheduling Request"
 * Approving a request in the Schedule Manager moves the ticket to "Scheduled".
 */

function te_cw_cfg(): ?array
{
    $c = te_cfg('cw', null);
    if (!is_array($c)) { return null; }
    foreach (['company_id', 'public_key', 'private_key', 'client_id', 'board', 'company'] as $k) {
        if (empty($c[$k])) { return null; }
    }
    return $c;
}

function te_cw_enabled(): bool { return te_cw_cfg() !== null; }

function te_cw_phrase(): string { return (string) (te_cw_cfg()['phrase'] ?? 'Express Scheduling Request'); }

function te_cw_base(array $c): string
{
    return rtrim((string) ($c['base_url'] ?? 'https://na.myconnectwise.net'), '/') . '/' . ($c['release'] ?? 'v4_6_release') . '/apis/3.0';
}

/** One ConnectWise REST call. Returns decoded JSON; throws with a readable reason on any failure. */
function te_cw_call(string $method, string $path, $body = null): array
{
    $c = te_cw_cfg();
    if (!$c) { throw new RuntimeException('ConnectWise is not configured.'); }
    $headers = [
        'Authorization: Basic ' . base64_encode($c['company_id'] . '+' . $c['public_key'] . ':' . $c['private_key']),
        'clientId: ' . $c['client_id'],
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    [$code, $res] = te_http($method, te_cw_base($c) . $path, $headers,
        $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $what = 'ConnectWise ' . $method . ' ' . strtok($path, '?');
    if ($code === 0) { throw new RuntimeException($what . ' could not connect: ' . $res); }
    $j = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        $why = is_array($j) ? ($j['message'] ?? $j['code'] ?? '') : '';
        throw new RuntimeException($what . ' failed (' . $code . ')' . ($why !== '' ? ': ' . substr(preg_replace('/\s+/', ' ', (string) $why), 0, 200) : ''));
    }
    return is_array($j) ? $j : [];
}

/** Board id and status ids by name, cached for an hour. */
function te_cw_ids(): array
{
    $c = te_cw_cfg();
    $file = te_private_dir() . '/data/cw-cache.json';
    if (is_file($file)) {
        $k = json_decode((string) @file_get_contents($file), true);
        if (is_array($k) && ($k['exp'] ?? 0) > time() && ($k['board_name'] ?? '') === $c['board']) { return $k; }
    }
    $b = te_cw_call('GET', '/service/boards?fields=id,name&conditions=' . rawurlencode('name="' . $c['board'] . '"'));
    if (empty($b[0]['id'])) { throw new RuntimeException('ConnectWise board "' . $c['board'] . '" was not found.'); }
    $bid = (int) $b[0]['id'];
    $st = te_cw_call('GET', '/service/boards/' . $bid . '/statuses?pageSize=200&fields=id,name');
    $map = [];
    foreach ($st as $r) { if (isset($r['id'], $r['name'])) { $map[strtolower((string) $r['name'])] = (int) $r['id']; } }
    $k = ['board' => $bid, 'board_name' => $c['board'], 'statuses' => $map, 'exp' => time() + 3600];
    @file_put_contents($file, json_encode($k), LOCK_EX);
    @chmod($file, 0600);
    return $k;
}

function te_cw_status_id(string $name): int
{
    $ids = te_cw_ids();
    $id = $ids['statuses'][strtolower($name)] ?? null;
    if ($id === null) {
        @unlink(te_private_dir() . '/data/cw-cache.json');   // maybe a status was just added; look again next time
        throw new RuntimeException('Status "' . $name . '" does not exist on the ' . te_cw_cfg()['board'] . ' board.');
    }
    return $id;
}

function te_cw_summary(array $a): string
{
    $s = te_cw_phrase() . ' - ' . $a['name'] . ' - ' . te_when($a['day'], (int) $a['hour']);
    return function_exists('mb_substr') ? mb_substr($s, 0, 100) : substr($s, 0, 100);   // ConnectWise limit
}

function te_cw_required(array $a): string
{
    $d = new DateTimeImmutable($a['day'] . sprintf(' %02d:00', (int) $a['hour']), new DateTimeZone('America/New_York'));
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** Creates the ticket on the Express board and returns its number. */
function te_cw_create_ticket(array $a): int
{
    $c = te_cw_cfg();
    $ids = te_cw_ids();
    $body = [
        'summary' => te_cw_summary($a),
        'board' => ['id' => $ids['board']],
        'company' => ['identifier' => $c['company']],
        'contactName' => $a['name'],
        'contactPhoneNumber' => $a['phone'],
        'contactEmailAddress' => $a['email'],
        'requiredDate' => te_cw_required($a),
        'initialDescription' => "Website visit request (Schedule Manager #" . $a['id'] . ")\n\n"
            . 'When: ' . te_when($a['day'], (int) $a['hour']) . "\n"
            . 'Name: ' . $a['name'] . "\nPhone: " . $a['phone'] . "\nEmail: " . $a['email'] . "\nAddress: " . $a['address']
            . "\n\nWhat they need:\n" . $a['reason'],
    ];
    if (!empty($c['status_new'])) { $body['status'] = ['id' => te_cw_status_id((string) $c['status_new'])]; }
    if (!empty($c['ticket_extra']) && is_array($c['ticket_extra'])) { $body = array_merge($body, $c['ticket_extra']); }
    $r = te_cw_call('POST', '/service/tickets', $body);
    if (empty($r['id'])) { throw new RuntimeException('ConnectWise did not return a ticket number.'); }
    return (int) $r['id'];
}

function te_cw_patch(int $ticket, array $ops): void { te_cw_call('PATCH', '/service/tickets/' . $ticket, $ops); }

function te_cw_set_status(int $ticket, string $name): void
{
    te_cw_patch($ticket, [['op' => 'replace', 'path' => '/status', 'value' => ['id' => te_cw_status_id($name)]]]);
}

function te_cw_note(int $ticket, string $text): void
{
    te_cw_call('POST', '/service/tickets/' . $ticket . '/notes', [
        'text' => $text, 'detailDescriptionFlag' => false, 'internalAnalysisFlag' => true, 'resolutionFlag' => false,
    ]);
}

/**
 * Tickets on the Express board that carry our key phrase and are open or were
 * touched recently. Returns [ticketId => ['status' => name, 'closed' => bool]].
 */
function te_cw_board_tickets(): array
{
    $ids = te_cw_ids();
    $cond = 'board/id=' . $ids['board'] . ' and summary contains "' . te_cw_phrase() . '"'
          . ' and (closedFlag=false or lastUpdated>[' . gmdate('Y-m-d\TH:i:s\Z', time() - 45 * 86400) . '])';
    $r = te_cw_call('GET', '/service/tickets?pageSize=1000&fields=' . rawurlencode('id,status/name,closedFlag') . '&conditions=' . rawurlencode($cond));
    $out = [];
    foreach ($r as $t) {
        if (isset($t['id'])) { $out[(int) $t['id']] = ['status' => (string) ($t['status']['name'] ?? ''), 'closed' => !empty($t['closedFlag'])]; }
    }
    return $out;
}

/* ---------------------------------------------------------- queued updates */
/**
 * A "job" is what still has to be written to a ticket:
 *   ['status' => 'Scheduled', 'summary' => '...', 'required' => '...', 'notes' => ['...']]
 * Steps are removed as they succeed, so a retry never repeats finished work.
 */
function te_cw_apply(int $ticket, array &$job): void
{
    if (isset($job['status'])) { te_cw_set_status($ticket, (string) $job['status']); unset($job['status']); }
    if (isset($job['summary'])) {
        te_cw_patch($ticket, [
            ['op' => 'replace', 'path' => '/summary', 'value' => $job['summary']],
            ['op' => 'replace', 'path' => '/requiredDate', 'value' => $job['required']],
        ]);
        unset($job['summary'], $job['required']);
    }
    while (!empty($job['notes'])) { te_cw_note($ticket, (string) $job['notes'][0]); array_shift($job['notes']); }
}

/** Runs a job now (together with anything already queued for the ticket). Returns a warning if it could not finish. */
function te_cw_push(PDO $db, int $apptId, int $ticket, array $job): ?string
{
    $s = $db->prepare('SELECT cw_push FROM appointments WHERE id=?');
    $s->execute([$apptId]);
    $old = json_decode((string) $s->fetchColumn(), true);
    if (is_array($old)) {
        $job = array_merge($old, $job, ['notes' => array_merge($old['notes'] ?? [], $job['notes'] ?? [])]);
    }
    $err = null;
    try { te_cw_apply($ticket, $job); } catch (Throwable $e) { $err = $e->getMessage(); }
    $db->prepare('UPDATE appointments SET cw_push=?, cw_error=? WHERE id=?')
       ->execute([$err === null ? null : json_encode($job), $err, $apptId]);
    if ($err !== null) {
        error_log('te cw push: ' . $err);
        return 'ConnectWise could not be updated yet, so it will retry on its own. (' . $err . ')';
    }
    return null;
}

/* ---------------------------------------------------- new requests -> ticket */
function te_notice_subject(int $ticket): string
{
    return str_replace('{n}', (string) $ticket, (string) te_cfg('notice_subject', 'SR#{n} - Express Scheduling Request'));
}

/** Creates the ticket for a saved request, then sends the SR# notice to express@. */
function te_cw_open_ticket(PDO $db, array $a): int
{
    $ticket = te_cw_create_ticket($a);
    $db->prepare('UPDATE appointments SET cw_ticket=?, cw_error=NULL WHERE id=?')->execute([$ticket, $a['id']]);
    te_cw_send_notice($db, $a, $ticket);
    return $ticket;
}

function te_cw_send_notice(PDO $db, array $a, int $ticket): void
{
    $to = (string) te_cfg('notice_to', 'express@codebluetechnology.com');
    try {
        te_send_mail($to, 'CodeBlue Tech Express', te_notice_subject($ticket),
            'A website visit request was received and ticket #' . $ticket . " was created on the Express board.\n\n"
            . 'When: ' . te_when($a['day'], (int) $a['hour']) . "\nName: " . $a['name'] . "\nPhone: " . $a['phone']
            . "\nEmail: " . $a['email'] . "\nAddress: " . $a['address'] . "\n\nWhat they need:\n" . $a['reason']
            . "\n\nApprove or decline it in the Schedule Manager: https://" . ($_SERVER['HTTP_HOST'] ?? 'codebluetechexpress.com') . "/manage/\n");
        $db->prepare('UPDATE appointments SET cw_notified=1 WHERE id=?')->execute([$a['id']]);
    } catch (Throwable $e) {
        error_log('te notice: ' . $e->getMessage());     // retried by the sync
    }
}

/**
 * Keeps the local list in step with ConnectWise. Runs at most once a minute
 * (when the console loads): creates tickets that failed earlier, resends missed
 * notices, finishes queued ticket updates, and drops requests whose ticket has
 * left the Express board or been closed. Returns a warning for the console, or null.
 */
function te_cw_sync(PDO $db): ?string
{
    if (!te_cw_enabled()) { return null; }
    $every = (int) (te_cw_cfg()['sync_seconds'] ?? 60);
    $last = (int) te_setting($db, 'cw_last_sync', '0');
    if ($every > 0 && time() - $last < $every) { return te_setting($db, 'cw_warning') ?: null; }
    te_set_setting($db, 'cw_last_sync', (string) time());
    $warn = null;
    try {
        $q = $db->prepare("SELECT * FROM appointments WHERE cw_ticket IS NULL AND status IN ('pending','asked','confirmed') AND created > ? ORDER BY id LIMIT 10");
        $q->execute([te_now_ms() - 14 * 86400000]);
        foreach ($q->fetchAll() as $a) {
            try { te_cw_open_ticket($db, $a); }
            catch (Throwable $e) { $db->prepare('UPDATE appointments SET cw_error=? WHERE id=?')->execute([$e->getMessage(), $a['id']]); $warn = $e->getMessage(); }
        }
        foreach ($db->query('SELECT * FROM appointments WHERE cw_ticket IS NOT NULL AND cw_notified=0 ORDER BY id LIMIT 10')->fetchAll() as $a) {
            te_cw_send_notice($db, $a, (int) $a['cw_ticket']);
        }
        foreach ($db->query('SELECT id, cw_ticket, cw_push FROM appointments WHERE cw_ticket IS NOT NULL AND cw_push IS NOT NULL LIMIT 20')->fetchAll() as $a) {
            $w = te_cw_push($db, (int) $a['id'], (int) $a['cw_ticket'], []);
            if ($w) { $warn = $w; }
        }
        $board = te_cw_board_tickets();    // throws if ConnectWise is down: then nothing is hidden by mistake
        foreach ($db->query("SELECT id, cw_ticket, status FROM appointments WHERE cw_ticket IS NOT NULL AND status IN ('pending','asked','confirmed')")->fetchAll() as $a) {
            $t = $board[(int) $a['cw_ticket']] ?? null;
            if ($t === null) { $db->prepare("UPDATE appointments SET status='removed' WHERE id=?")->execute([$a['id']]); }
            elseif ($t['closed']) {
                $db->prepare('UPDATE appointments SET status=? WHERE id=?')->execute([$a['status'] === 'confirmed' ? 'completed' : 'denied', $a['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('te cw sync: ' . $e->getMessage());
        $warn = 'ConnectWise is not reachable right now (' . $e->getMessage() . '). Showing the last known requests.';
    }
    te_set_setting($db, 'cw_warning', $warn ?? '');
    return $warn;
}
