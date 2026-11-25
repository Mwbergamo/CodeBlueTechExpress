#!/usr/bin/env python3
"""ConnectWise flow tests: ticket creation, SR# notice, approvals, retries, board sync.
Starts the API on :8080 and a Microsoft + ConnectWise mock on :8081. Needs php."""
import json, os, shutil, subprocess, sys, time, datetime, urllib.request, urllib.error, http.cookiejar

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = '/tmp/tetest_cw'
shutil.rmtree(T, ignore_errors=True)
os.makedirs(T + '/te-private')
cfg = """<?php return [
 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'test-secret',
 'redirect_uri' => 'http://127.0.0.1:8080/api/auth/callback.php',
 'mail_sender' => 'express@codebluetechnology.com',
 'login_base' => 'http://127.0.0.1:8081', 'graph_base' => 'http://127.0.0.1:8081',
 'booking_limit_per_visitor' => 100,
 'cw' => ['company_id' => 'co', 'public_key' => 'pub', 'private_key' => 'priv', 'client_id' => 'cw-client',
          'base_url' => 'http://127.0.0.1:8081', 'board' => 'Express', 'company' => 'TechExpress',
          'status_new' => 'New', 'status_scheduled' => 'Scheduled', 'status_denied' => 'Declined', 'sync_seconds' => 0],
];"""
open(T + '/te-private/config.php', 'w').write(cfg)
env = dict(os.environ, TE_PRIVATE_DIR=T + '/te-private', MOCK_DIR=T)
procs = [subprocess.Popen(['php', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', '127.0.0.1:8080', '-t', ROOT], env=env, stdout=subprocess.DEVNULL, stderr=open(T + '/app.err', 'w')),
         subprocess.Popen(['php', '-S', '127.0.0.1:8081', ROOT + '/tests/mock_ms.php'], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)]
time.sleep(1.2)

fails = 0
def check(name, cond, extra=''):
    global fails
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else '  ' + str(extra)))
    if not cond: fails += 1

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
def call(op, method, path, data=None, headers=None, base='http://127.0.0.1:8080'):
    h = dict(headers or {}); body = None
    if data is not None: body = json.dumps(data).encode(); h['Content-Type'] = 'application/json'
    try:
        r = op.open(urllib.request.Request(base + path, data=body, method=method, headers=h)); code, raw, hd = r.status, r.read(), r.headers
    except urllib.error.HTTPError as e:
        code, raw, hd = e.code, e.read(), e.headers
    try: js = json.loads(raw)
    except Exception: js = raw
    return code, js, hd
def mails():
    p = T + '/mail.log'
    return [json.loads(l) for l in open(p)] if os.path.exists(p) else []
def subjects(): return [m['req']['message']['subject'] for m in mails()]
def tickets():
    p = T + '/cw-tickets.json'
    return {int(k): v for k, v in json.load(open(p)).items()} if os.path.exists(p) else {}
def edit_tickets(fn):
    t = json.load(open(T + '/cw-tickets.json')); fn(t); json.dump(t, open(T + '/cw-tickets.json', 'w'))
def nwd(n):
    d = datetime.date.today() + datetime.timedelta(days=n)
    while d.weekday() > 4: d += datetime.timedelta(days=1)
    return d
def down(on):
    f = T + '/cw_down'
    if on: open(f, 'w').write('x')
    elif os.path.exists(f): os.remove(f)

pub = client(); staff = client()
D1, D2, D3, D4 = nwd(3), nwd(5), nwd(7), nwd(9)
n = [0]
def book(day, hour, name):
    n[0] += 1
    return call(pub, 'POST', '/api/appointments.php', dict(start=f'{day}T{hour:02d}:00', reason='Needs help', name=name, address='1 Main St, Warsaw, VA',
                                                         phone='(804) 555-0100', email=f'u{n[0]}@example.com'),
                headers={'CF-Connecting-IP': f'10.0.0.{n[0]}'})
def state():
    c, st, _ = call(staff, 'GET', '/api/manager.php'); return st if c == 200 else {'appts': [], '_code': c}
def find(st, name):
    r = [a for a in st['appts'] if a['name'] == name]; return r[0] if r else None

try:
    # sign in as staff
    c, _, h = call(staff, 'GET', '/api/auth/login.php')
    c, _, h = call(staff, 'GET', h['Location'].replace('http://127.0.0.1:8081', ''), base='http://127.0.0.1:8081')
    c, _, _ = call(staff, 'GET', h['Location'].replace('http://127.0.0.1:8080', ''))
    check('staff signed in', c == 302)
    H = {'X-CSRF-Token': state()['csrf']}
    def act(body): return call(staff, 'POST', '/api/manager.php', body, H)

    # 1. a website request becomes a ticket and an SR# notice
    c, js, _ = book(D1, 11, 'Linda Carter')
    check('booking accepted', c == 200, (c, js))
    t = tickets()
    tk = t.get(50100, {})
    check('ticket created on the Express board with the key phrase, name and time',
          tk.get('summary', '').startswith('Express Scheduling Request - Linda Carter - ') and '11:00 AM' in tk.get('summary', '') and tk['board']['id'] == 7, tk.get('summary'))
    check('ticket carries the customer contact details', tk.get('contactEmailAddress') == 'u1@example.com' and tk.get('contactName') == 'Linda Carter', tk)
    check('notice emailed to express@ with the exact subject',
          'SR#50100 - Express Scheduling Request' in subjects() and
          any(m['req']['message']['toRecipients'][0]['emailAddress']['address'] == 'express@codebluetechnology.com' for m in mails()), subjects())
    st = state(); linda = find(st, 'Linda Carter')
    check('console shows the SR number and ConnectWise is on', linda and linda['sr'] == 50100 and linda['cwWait'] is False and st['cwOn'] is True, linda)

    # 2. Approved -> Scheduled
    c, js, _ = act({'action': 'confirm', 'id': linda['id'], 'subject': 'Confirmed', 'body': 'See you then.'})
    check('approve succeeds without warning', c == 200 and js.get('warning') is None, (c, js.get('warning')))
    tk = tickets()[50100]
    check('ticket moved to Scheduled', tk['status']['name'] == 'Scheduled', tk['status'])
    check('ticket note records who approved', any('Approved by Test Tech' in x for x in tk['notes']), tk['notes'])

    # 3. deny -> Declined
    book(D1, 11, 'Tom Gray'); st = state(); tom = find(st, 'Tom Gray')
    c, js, _ = act({'action': 'deny', 'id': tom['id'], 'subject': 'About your request', 'body': 'Sorry.'})
    check('deny moves the ticket to Declined', c == 200 and tickets()[tom['sr']]['status']['name'] == 'Declined', (c, js))

    # 4. ConnectWise down when the customer books: request kept, flagged, approval blocked, then healed
    down(True)
    c, js, _ = book(D2, 12, 'Zed Waiting')
    check('booking still saved while ConnectWise is down', c == 200, (c, js))
    check('staff alerted that it is not in ConnectWise', any(s.startswith('ACTION:') for s in subjects()), subjects())
    st = state(); zed = find(st, 'Zed Waiting')
    check('console shows it as waiting for ConnectWise, with a warning', zed and zed['cwWait'] is True and zed['sr'] is None and st.get('cwWarning'), (zed, st.get('cwWarning')))
    c, js, _ = act({'action': 'confirm', 'id': zed['id'], 'subject': 's', 'body': 'b'})
    check('cannot approve until the ticket exists (409)', c == 409, (c, js))
    down(False)
    st = state(); zed = find(st, 'Zed Waiting')
    check('sync creates the missing ticket once ConnectWise is back', zed and zed['sr'] and zed['cwWait'] is False and not st.get('cwWarning'), (zed, st.get('cwWarning')))
    check('and sends its SR# notice', f"SR#{zed['sr']} - Express Scheduling Request" in subjects(), subjects())
    c, js, _ = act({'action': 'confirm', 'id': zed['id'], 'subject': 'Confirmed', 'body': 'ok'})
    check('approval works after healing', c == 200 and tickets()[zed['sr']]['status']['name'] == 'Scheduled', (c, js))

    # 5. ConnectWise down at approval: customer told, local state kept, update retried later
    book(D2, 13, 'Amy Retry'); amy = find(state(), 'Amy Retry')
    down(True)
    c, js, _ = act({'action': 'confirm', 'id': amy['id'], 'subject': 'Confirmed', 'body': 'ok'})
    a2 = [a for a in js['appts'] if a['name'] == 'Amy Retry'][0] if c == 200 else {}
    check('approval stands, with a warning, when ConnectWise is down', c == 200 and a2.get('status') == 'confirmed' and js.get('warning'), (c, js.get('warning')))
    check('ticket not yet updated', tickets()[amy['sr']]['status']['name'] == 'New')
    down(False)
    state()
    tk = tickets()[amy['sr']]
    check('queued update is applied on the next sync (Scheduled + note)', tk['status']['name'] == 'Scheduled' and any('Approved by' in x for x in tk['notes']), tk)
    state()
    check('queued update is not repeated', sum('Approved by' in x for x in tickets()[amy['sr']]['notes']) == 1)

    # 6. ticket leaves the Express board -> request disappears and the seat is freed
    book(D3, 11, 'Bob Moved'); bob = find(state(), 'Bob Moved')
    edit_tickets(lambda t: t[str(bob['sr'])].update(board={'id': 99}))
    st = state()
    check('request hidden after its ticket leaves the board', find(st, 'Bob Moved') is None)
    c, js, _ = call(pub, 'GET', f'/api/availability.php?from={D3}&to={D3}')
    check('and the seat is free again', f'{D3}T11:00' not in js, js)

    # 7. ticket closed in ConnectWise
    book(D3, 12, 'Cy Closed'); book(D3, 13, 'Di Done')
    st = state(); cy, di = find(st, 'Cy Closed'), find(st, 'Di Done')
    act({'action': 'confirm', 'id': di['id'], 'subject': 'Confirmed', 'body': 'ok'})
    def close(t):
        for x in (cy, di): t[str(x['sr'])].update(status={'id': 3, 'name': 'Closed'}, closedFlag=True)
    edit_tickets(close)
    st = state()
    check('closed unapproved ticket shows as denied; closed approved one as completed',
          find(st, 'Cy Closed')['status'] == 'denied' and find(st, 'Di Done')['status'] == 'completed', (find(st, 'Cy Closed'), find(st, 'Di Done')))

    # 8. reschedule rewrites the ticket
    book(D4, 11, 'Eve Moved'); eve = find(state(), 'Eve Moved')
    c, js, _ = act({'action': 'reschedule', 'id': eve['id'], 'day': D4.isoformat(), 'hour': 14, 'subject': 'New time', 'body': 'Moved.'})
    tk = tickets()[eve['sr']]
    check('reschedule updates the ticket summary and note', c == 200 and '2:00 PM' in tk['summary'] and any('Moved by' in x for x in tk['notes']), (c, tk.get('summary'), tk.get('notes')))
    check('ticket summary keeps the key phrase', tk['summary'].startswith('Express Scheduling Request'))

    # 9. duplicate protection: one ticket per request
    c, js, _ = book(D4, 15, 'Una Once')
    n_before = len(tickets())
    state(); state(); state()
    check('repeated syncs never create duplicate tickets', len(tickets()) == n_before)

    # 10. bad ConnectWise credentials: request still saved
    open(T + '/te-private/config.php', 'w').write(cfg.replace("'priv'", "'wrong'"))
    c, js, _ = book(D4, 12, 'Vic Nocreds')
    check('booking survives bad ConnectWise credentials', c == 200, (c, js))
    open(T + '/te-private/config.php', 'w').write(cfg)
finally:
    for p in procs: p.terminate()
    err = open(T + '/app.err').read()
    if any(w in err for w in ('Fatal', 'Warning', 'Deprecated', 'Notice')):
        print('PHP messages:\n' + err[-1500:]); fails += 1
print('\n%d failure(s)' % fails)
sys.exit(1 if fails else 0)
