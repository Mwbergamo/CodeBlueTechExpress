#!/usr/bin/env python3
"""End-to-end API tests. Needs php; starts the app on :8080 and a Microsoft mock on :8081."""
import json, os, shutil, subprocess, sys, time, datetime, urllib.request, urllib.error, http.cookiejar

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = '/tmp/tetest'
shutil.rmtree(T, ignore_errors=True)
os.makedirs(T + '/te-private')
cfg = """<?php return [
 'tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'test-secret',
 'redirect_uri' => 'http://127.0.0.1:8080/api/auth/callback.php',
 'mail_sender' => 'express@codebluetechnology.com', 'notify_to' => ['staff@codebluetechnology.com'],
 'login_base' => 'http://127.0.0.1:8081', 'graph_base' => 'http://127.0.0.1:8081',
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
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)

def call(op, method, path, data=None, headers=None, base='http://127.0.0.1:8080'):
    h = dict(headers or {})
    body = None
    if data is not None:
        body = json.dumps(data).encode(); h['Content-Type'] = 'application/json'
    req = urllib.request.Request(base + path, data=body, method=method, headers=h)
    try:
        r = op.open(req); code, raw, hd = r.status, r.read(), r.headers
    except urllib.error.HTTPError as e:
        code, raw, hd = e.code, e.read(), e.headers
    try: js = json.loads(raw)
    except Exception: js = raw
    return code, js, hd

def mails():
    p = T + '/mail.log'
    return [json.loads(l) for l in open(p)] if os.path.exists(p) else []

def next_weekday(n_ahead):
    d = datetime.date.today() + datetime.timedelta(days=n_ahead)
    while d.weekday() > 4: d += datetime.timedelta(days=1)
    return d

def signin(op):
    c, _, h = call(op, 'GET', '/api/auth/login.php'); assert c == 302, c
    c, _, h = call(op, 'GET', h['Location'].replace('http://127.0.0.1:8081', ''), base='http://127.0.0.1:8081'); assert c == 302, c
    loc = h['Location']
    return call(op, 'GET', loc.replace('http://127.0.0.1:8080', ''))

try:
    pub = client()
    d1, d2, d3 = next_weekday(3), next_weekday(5), next_weekday(7)
    s1 = d1.isoformat() + 'T11:00'
    good = dict(start=s1, reason='Laptop is slow', name='Linda Carter', address='41 Main St, Heathsville, VA', phone='(804) 555-0188', email='linda@example.com')

    c, js, _ = call(pub, 'GET', f'/api/availability.php?from={d1}&to={d3}')
    check('availability starts empty', c == 200 and js == [], (c, js))
    c, js, _ = call(pub, 'POST', '/api/appointments.php', dict(good, email='nope'))
    check('bad email rejected', c == 400, (c, js))
    c, js, _ = call(pub, 'POST', '/api/appointments.php', dict(good, start=(datetime.date.today() - datetime.timedelta(days=2)).isoformat() + 'T11:00'))
    check('past time rejected', c == 400, (c, js))
    sat = d1 + datetime.timedelta(days=(5 - d1.weekday()) % 7 or 7)
    c, js, _ = call(pub, 'POST', '/api/appointments.php', dict(good, start=sat.isoformat() + 'T11:00'))
    check('weekend rejected', c == 400, (c, js))
    c, js, _ = call(pub, 'POST', '/api/appointments.php', dict(good, start=d1.isoformat() + 'T09:00'))
    check('outside store hours rejected', c == 400, (c, js))
    c, js, _ = call(pub, 'POST', '/api/appointments.php', good, headers={'Origin': 'https://evil.example'})
    check('foreign origin rejected', c == 403, (c, js))
    c, js, _ = call(pub, 'POST', '/api/appointments.php', good)
    check('booking accepted', c == 200 and js.get('ok') is True, (c, js))
    check('staff notified by Microsoft 365', any(m['req']['message']['toRecipients'][0]['emailAddress']['address'] == 'staff@codebluetechnology.com' for m in mails()), mails())
    c, js, _ = call(pub, 'POST', '/api/appointments.php', good)
    check('duplicate is idempotent', c == 200, (c, js))
    c, js, _ = call(pub, 'POST', '/api/appointments.php', dict(good, email='b@example.com', name='Tom Gray'))
    check('second seat accepted', c == 200, (c, js))
    c, js, _ = call(pub, 'POST', '/api/appointments.php', dict(good, email='c@example.com', name='Third'))
    check('third request refused (hour full)', c == 409, (c, js))
    c, js, _ = call(pub, 'GET', f'/api/availability.php?from={d1}&to={d3}')
    check('full hour shows as unavailable', js == [s1], js)
    c, js, _ = call(pub, 'GET', '/api/manager.php')
    check('manager state needs sign-in', c == 401, (c, js))
    c, js, _ = call(pub, 'GET', '/api/availability.php?from=bad&to=bad')
    check('bad range rejected', c == 400)

    staff = client()
    c, js, _ = signin(staff)
    check('sign-in redirects to /manage/', c == 302, (c, js))
    c, st, _ = call(staff, 'GET', '/api/manager.php')
    check('staff can load state', c == 200 and len(st['appts']) == 2 and st['me']['email'] == 'tech@codebluetechnology.com', (c, st))
    csrf = st['csrf']; H = {'X-CSRF-Token': csrf}
    linda = [a for a in st['appts'] if a['name'] == 'Linda Carter'][0]
    tom = [a for a in st['appts'] if a['name'] == 'Tom Gray'][0]
    check('state matches console model', linda['status'] == 'pending' and linda['hour'] == 11 and linda['thread'] == [], linda)

    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'confirm', 'id': linda['id'], 'subject': 's', 'body': 'b'})
    check('write without CSRF token refused', c == 403, (c, js))
    before = len(mails())
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'confirm', 'id': linda['id'], 'subject': 'Confirmed: your visit', 'body': 'Hi Linda, see you then.'}, H)
    ok = c == 200 and [a for a in js['appts'] if a['id'] == linda['id']][0]['status'] == 'confirmed'
    check('confirm updates status', ok, (c, js))
    last = mails()[-1]
    check('confirmation mailed to the request address, from shared mailbox',
          len(mails()) == before + 1 and last['req']['message']['toRecipients'][0]['emailAddress']['address'] == 'linda@example.com'
          and last['from'] == 'express@codebluetechnology.com' and last['req']['saveToSentItems'] is True, last)
    check('sent email appears in outbox', any(o['to'] == 'linda@example.com' for o in js['outbox']))

    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'confirm', 'id': linda['id'], 'subject': 's', 'body': 'b'}, H)
    check('cannot confirm twice', c == 409, (c, js))

    s2 = d2.isoformat()
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'reschedule', 'id': tom['id'], 'day': s2, 'hour': 12, 'subject': 'New time', 'body': 'Moved.'}, H)
    t = [a for a in js['appts'] if a['id'] == tom['id']][0] if c == 200 else {}
    check('reschedule moves the visit and remembers where from', c == 200 and t['day'] == s2 and t['hour'] == 12 and t['movedFrom']['day'] == d1.isoformat(), (c, js))
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'reschedule', 'id': tom['id'], 'day': s2, 'hour': 12, 'subject': 's', 'body': 'b'}, H)
    check('reschedule to the same time refused', c == 400, (c, js))
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'ask', 'id': tom['id'], 'question': 'What model is it?', 'subject': 'Question', 'body': 'What model?'}, H)
    t = [a for a in js['appts'] if a['id'] == tom['id']][0] if c == 200 else {}
    check('ask records the question in the thread', c == 200 and t['status'] == 'asked' and t['thread'][0]['text'] == 'What model is it?', (c, js))

    # mail failure must not change anything
    open(T + '/fail_mail', 'w').write('x')
    n_before = len(mails())
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'deny', 'id': tom['id'], 'subject': 's', 'body': 'b'}, H)
    c2, st2, _ = call(staff, 'GET', '/api/manager.php')
    still = [a for a in st2['appts'] if a['id'] == tom['id']][0]['status']
    check('failed email = 502 and no status change', c == 502 and still == 'asked' and len(mails()) == n_before, (c, js, still))
    os.remove(T + '/fail_mail')
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'deny', 'id': tom['id'], 'subject': 'About your request', 'body': 'Sorry.'}, H)
    check('deny works once mail is back', c == 200 and [a for a in js['appts'] if a['id'] == tom['id']][0]['status'] == 'denied', (c, js))

    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'finish', 'id': linda['id'], 'at': 30}, H)
    check('finish early frees the seat', c == 200 and [a for a in js['appts'] if a['id'] == linda['id']][0]['endedAt'] == 30, (c, js))
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'capacity', 'days': [d3.isoformat()], 'cap': 0}, H)
    check('capacity override saved', c == 200 and js['capacity'].get(d3.isoformat()) == 0, (c, js))
    c, js, _ = call(pub, 'GET', f'/api/availability.php?from={d3}&to={d3}')
    check('closed day shows all hours unavailable', len(js) == 5, js)
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'defaultcap', 'cap': 4}, H)
    check('default capacity saved', c == 200 and js['defaultCap'] == 4, (c, js))
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'capacity', 'days': ['2026-02-30'], 'cap': 2}, H)
    check('invalid date rejected', c == 400, (c, js))
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'hack', 'id': 'R1'}, H)
    check('unknown action rejected', c == 400, (c, js))

    tr = open(T + '/token-requests').read().count('1')
    check('Graph token fetched once and cached', tr == 1, tr)
    c, js, _ = call(staff, 'POST', '/api/manager.php', {'action': 'signout'}, H)
    c, js, _ = call(staff, 'GET', '/api/manager.php')
    check('sign-out ends the session', c == 401, (c, js))

    # sign-in hardening
    for label, claims in [('wrong tenant', {'tid': 'someone-else'}), ('wrong audience', {'aud': 'other-app'}),
                          ('expired token', {'exp': 1000}), ('wrong nonce', {'nonce': 'forged'})]:
        json.dump(claims, open(T + '/claims.json', 'w'))
        op = client(); c, js, _ = signin(op)
        c2, _, _ = call(op, 'GET', '/api/manager.php')
        check('sign-in refused: ' + label, c == 403 and c2 == 401, (c, c2))
    os.remove(T + '/claims.json')

    open(T + '/te-private/config.php', 'w').write(cfg.replace('test-secret', 'wrong-secret'))
    op = client(); c, js, _ = signin(op)
    check('bad client secret: refused, and Microsoft\'s reason is shown', c == 403 and b'invalid_grant' in js, (c, js[:200]))
    open(T + '/te-private/config.php', 'w').write(cfg)
    open(T + '/te-private/config.php', 'w').write(cfg.replace("'notify_to'", "'staff_emails' => ['boss@codebluetechnology.com'], 'notify_to'"))
    op = client(); c, js, _ = signin(op)
    check('sign-in refused when not on the staff list', c == 403, c)
    open(T + '/te-private/config.php', 'w').write(cfg)

    # forged state on callback
    op = client(); call(op, 'GET', '/api/auth/login.php')
    c, js, _ = call(op, 'GET', '/api/auth/callback.php?code=zzz&state=forged')
    check('callback with forged state refused', c == 403, c)

    # rate limit (5/hour/address): 4 successful bookings so far were counted (3 inserted + 0 dup)
    c = 200
    for i in range(8):
        c, js, _ = call(pub, 'POST', '/api/appointments.php', dict(good, start=next_weekday(10 + i).isoformat() + 'T13:00', email=f'r{i}@example.com'))
        if c == 429: break
    check('booking flood is rate-limited', c == 429, c)

    # not configured -> safe failure
    os.remove(T + '/te-private/config.php')
    c, js, _ = call(pub, 'GET', f'/api/availability.php?from={d1}&to={d1}')
    check('missing config fails safe with 503', c == 503, (c, js))
finally:
    for p in procs: p.terminate()
    err = open(T + '/app.err').read()
    if 'Fatal' in err or 'Warning' in err or 'Deprecated' in err:
        print('PHP messages:\n' + err[-1500:]); fails += 1
print('\n%d failure(s)' % fails)
sys.exit(1 if fails else 0)
