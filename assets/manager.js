/* CodeBlue Tech Express: Schedule Manager (technician console).
   DEMO mode (TE_CONFIG.managerUrl not set): all data lives in memory and "sent"
   emails go to the Sent tab.
   LIVE mode (TE_CONFIG.managerUrl = api/manager.php): everything is loaded from and
   saved to the server, sign-in is Microsoft 365, and an action only counts as done
   once the server confirms the customer email was handed to Microsoft 365. */
(function () {
  'use strict';
  var CFG = window.TE_CONFIG || {};
  var DEMO = !CFG.managerUrl;
  var BASE = /\/manage\/?(index\.html)?$/.test(location.pathname) ? '../' : '';
  var HOURS = [11, 12, 13, 14, 15];
  var MAX_CAP = 6, DEFAULT_CAP = 2;
  var STORE = { name: 'CodeBlue Tech Express', addr: '5020 Richmond Rd. Suite B, Warsaw, VA 22572', phone: '(804) 456-4500' };
  var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var DOWL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DENY_REASONS = [
    'We are not able to take on that kind of work in the store.',
    'We are fully booked at that time and could not find another opening that fits.',
    'That request is outside the area we serve.',
    'We are unable to help with that request.'
  ];

  var $ = function (s, r) { return (r || document).querySelector(s); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ic(n, c) { return '<svg class="i ' + (c || '') + '" aria-hidden="true"><use href="#i-' + n + '"/></svg>'; }

  /* ---------------------------------------------------------------- dates */
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function key(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parse(k) { var p = k.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function addDays(d, n) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); return x; }
  function isWeekday(d) { return d.getDay() >= 1 && d.getDay() <= 5; }
  var TODAY = new Date(); TODAY = new Date(TODAY.getFullYear(), TODAY.getMonth(), TODAY.getDate());
  var TODAY_K = key(TODAY);
  function hourLabel(h) { return (h % 12 || 12) + ':00 ' + (h < 12 ? 'AM' : 'PM'); }
  function blockLabel(h) { return (h % 12 || 12) + (h < 12 ? ' AM' : ' PM') + ' to ' + ((h + 1) % 12 || 12) + ((h + 1) < 12 ? ' AM' : ' PM'); }
  function timeAt(h, m) { return (h % 12 || 12) + ':' + pad(m) + ' ' + (h < 12 ? 'AM' : 'PM'); }
  function dayLong(k) { var d = parse(k); return DOWL[d.getDay()] + ', ' + MON[d.getMonth()] + ' ' + d.getDate(); }
  function dayShort(k) { var d = parse(k); return DOW[d.getDay()] + ' ' + MON[d.getMonth()] + ' ' + d.getDate(); }

  // weekdays from this week's Monday for 4 weeks (capacity grid) and the upcoming 15 open-able weekdays
  var WEEKS = (function () {
    var mon = addDays(TODAY, -((TODAY.getDay() + 6) % 7)), out = [];
    for (var w = 0; w < 4; w++) { var wk = []; for (var d = 0; d < 5; d++) { wk.push(key(addDays(mon, w * 7 + d))); } out.push(wk); }
    return out;
  })();
  var UPCOMING = (function () {
    var out = [], d = TODAY;
    while (out.length < 15) { if (isWeekday(d)) { out.push(key(d)); } d = addDays(d, 1); }
    return out;
  })();
  function wd(i) { return UPCOMING[i]; }

  /* ---------------------------------------------------------------- state */
  var S = {
    signedIn: false, tab: 'requests', filter: 'action', sort: 'when',
    day: UPCOMING[0], capacity: {}, defaultCap: DEFAULT_CAP, appts: [], outbox: [], modal: null, toast: '', flash: null,
    checking: !DEMO, csrf: '', me: null, busy: false
  };

  /* ---------------------------------------------------------------- server (live mode) */
  function call(method, body) {
    var opts = { method: method, credentials: 'same-origin', headers: {} };
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF-Token'] = S.csrf; opts.body = JSON.stringify(body); }
    return fetch(CFG.managerUrl + (method === 'GET' ? '?action=state' : ''), opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, json: j }; });
    });
  }
  function take(j) {
    S.appts = j.appts || []; S.capacity = {};
    for (var k in (j.capacity || {})) { if (Object.prototype.hasOwnProperty.call(j.capacity, k)) { S.capacity[k] = j.capacity[k]; } }
    S.defaultCap = j.defaultCap; S.outbox = j.outbox || []; S.csrf = j.csrf || S.csrf; S.me = j.me || S.me; S.signedIn = true;
  }
  function load(quiet) {
    return call('GET').then(function (r) {
      S.checking = false;
      if (r.status === 200) { take(r.json); }
      else if (r.status === 401) { S.signedIn = false; S.modal = null; }
      else if (!quiet) { S.toast = 'Could not reach the server. Try again in a moment.'; }
      render();
    }).catch(function () { S.checking = false; if (!quiet) { S.toast = 'Could not reach the server. Try again in a moment.'; } render(); });
  }
  /** POST an action; resolves {ok, error}. On success the returned state replaces ours. */
  function post(body) {
    return call('POST', body).then(function (r) {
      if (r.status === 200) { take(r.json); return { ok: true }; }
      if (r.status === 401) { S.signedIn = false; S.modal = null; render(); return { ok: false, error: 'Your session ended. Please sign in again.' }; }
      return { ok: false, error: (r.json && r.json.error) || 'Something went wrong. Please try again.' };
    }).catch(function () { return { ok: false, error: 'Could not reach the server. Nothing was changed.' }; });
  }
  function saveCap(days, cap) {
    if (DEMO) { return; }
    post({ action: 'capacity', days: days, cap: cap }).then(function (r) { if (!r.ok) { toast(r.error); load(true); } else { render(); } });
  }

  function seed() {
    var n = 0;
    function A(o) { o.id = 'R' + (++n); o.thread = o.thread || []; o.created = o.created || Date.now() - n * 3600e3; return o; }
    S.appts = [
      A({ day: wd(0), hour: 11, name: 'Harold Pruitt', phone: '(804) 333-0142', email: 'hpruitt@example.com', address: '212 Court St, Warsaw, VA 22572', reason: 'Laptop would not start this morning.', status: 'completed', endedAt: 30 }),
      A({ day: wd(0), hour: 12, name: 'Marcy Dillard', phone: '(804) 333-0177', email: 'marcy.d@example.com', address: '88 Menokin Rd, Warsaw, VA 22572', reason: 'Setting up a new HP printer.', status: 'confirmed' }),
      A({ day: wd(1), hour: 11, name: 'Linda Carter', phone: '(804) 555-0188', email: 'linda.carter@example.com', address: '41 Main St, Heathsville, VA 22473', reason: 'Laptop is very slow and shows pop-up ads all the time.', status: 'pending' }),
      A({ day: wd(1), hour: 11, name: 'Tom Gray', phone: '(804) 555-0120', email: 'tgray@example.com', address: '9 Bay Rd, Kilmarnock, VA 22482', reason: 'Phone photos will not back up.', status: 'pending' }),
      A({ day: wd(1), hour: 11, name: 'Walter Dobbins', phone: '(804) 555-0161', email: 'wdobbins@example.com', address: '1500 Northumberland Hwy, Callao, VA 22435', reason: 'New desktop, need help moving my files over.', status: 'pending' }),
      A({ day: wd(2), hour: 11, name: 'Priya Natarajan', phone: '(804) 555-0109', email: 'priya.n@example.com', address: '17 River Dr, Warsaw, VA 22572', reason: 'Printer will not connect to the Wi-Fi.', status: 'pending' }),
      A({ day: wd(2), hour: 12, name: 'George Ellis', phone: '(804) 555-0134', email: 'gellis@example.com', address: '302 Hull St, Montross, VA 22520', reason: 'Not sure, the computer just acts strange.', status: 'asked',
        thread: [{ from: 'us', at: Date.now() - 5 * 3600e3, text: 'Hi George, can you tell us what kind of computer it is and what it does when it acts strange?' }] }),
      A({ day: wd(3), hour: 14, name: 'Janet Whitlock', phone: '(804) 555-0190', email: 'janet.w@example.com', address: '6 Church Ln, Warsaw, VA 22572', reason: '', status: 'pending' }),
      A({ day: wd(3), hour: 13, name: 'Ron Baxter', phone: '(804) 555-0146', email: 'rbaxter@example.com', address: '77 Lancaster Dr, Kilmarnock, VA 22482', reason: 'Security camera app stopped showing my cameras.', status: 'confirmed' }),
      A({ day: wd(3), hour: 13, name: 'Alice Moreno', phone: '(804) 555-0155', email: 'alice.m@example.com', address: '140 Beale Dr, Warsaw, VA 22572', reason: 'Email on my phone stopped syncing.', status: 'confirmed' }),
      A({ day: wd(4), hour: 15, name: 'Sam Reilly', phone: '(804) 555-0172', email: 'sreilly@example.com', address: '5 Oak Ln, Tappahannock, VA 22560', reason: 'Wants a quote on a full home network.', status: 'pending' }),
      A({ day: wd(5), hour: 11, name: 'Beth Carmody', phone: '(804) 555-0103', email: 'beth.c@example.com', address: '19 Shore Dr, Warsaw, VA 22572', reason: 'Tablet screen is cracked.', status: 'denied' })
    ];
    S.capacity[wd(4)] = 1;
    S.capacity[wd(6)] = 0;
    S.capacity[wd(7)] = 3;
  }

  /* ---------------------------------------------------------------- seats */
  function capOf(day) { return S.capacity.hasOwnProperty(day) ? S.capacity[day] : S.defaultCap; }
  function inBlock(day, h) { return S.appts.filter(function (a) { return a.day === day && a.hour === h; }); }
  function active(a) { return a.status === 'pending' || a.status === 'asked' || a.status === 'confirmed'; }
  function seats(day, h) {
    var list = inBlock(day, h), cap = capOf(day);
    var used = list.filter(active).length;
    var early = list.filter(function (a) { return a.status === 'completed' && a.endedAt != null && a.endedAt < 60; })
      .sort(function (x, y) { return x.endedAt - y.endedAt; });
    var free = Math.max(0, cap - used), over = Math.max(0, used - cap);
    var earlyFree = Math.min(free, early.length);
    return {
      cap: cap, used: used, free: free, over: over, earlyFree: earlyFree, fresh: free - earlyFree,
      opensFrom: earlyFree ? early[0].endedAt : null, confirmed: list.filter(function (a) { return a.status === 'confirmed'; }).length
    };
  }
  function publicLabel(day, h) {
    var s = seats(day, h);
    if (s.cap === 0) { return { cls: 'closed', t: 'Closed', n: '' }; }
    if (s.free === 0) { return { cls: 'full', t: 'Full', n: '' }; }
    if (s.fresh === 0 && s.earlyFree) { return { cls: '', t: 'Opens ' + timeAt(h, s.opensFrom), n: s.earlyFree + (s.earlyFree === 1 ? ' spot' : ' spots') }; }
    return { cls: '', t: s.free + (s.free === 1 ? ' spot open' : ' spots open'), n: s.earlyFree ? '+ ' + s.earlyFree + ' from ' + timeAt(h, s.opensFrom) : '' };
  }

  /* ---------------------------------------------------------------- emails */
  function emailFor(kind, a, ctx) {
    ctx = ctx || {};
    var first = (a.name || '').split(' ')[0] || 'there';
    var when = dayLong(a.day) + ' at ' + hourLabel(a.hour);
    var sig = '\n\nCodeBlue Tech Express\n' + STORE.addr + '\n' + STORE.phone + '\nTechnology is what we do, people are why we do it.';
    if (kind === 'confirm') {
      return { subject: 'Confirmed: your visit on ' + dayShort(a.day) + ' at ' + hourLabel(a.hour),
        body: 'Hi ' + first + ',\n\nGood news, your appointment is confirmed.\n\nWhen: ' + when + '\nWhere: ' + STORE.addr +
          '\n\nPlease bring the device you would like us to look at, along with its charger and any passwords you know. If you need to change your plans, call us at ' + STORE.phone + ' and we will help.\n\nWe look forward to seeing you.' + sig };
    }
    if (kind === 'reschedule') {
      var nw = dayLong(ctx.day) + ' at ' + hourLabel(ctx.hour);
      return { subject: 'New time for your visit: ' + dayShort(ctx.day) + ' at ' + hourLabel(ctx.hour),
        body: 'Hi ' + first + ',\n\nWe need to move your appointment. Here is the new time we have set aside for you.\n\nWas: ' + when + '\nNow: ' + nw + '\nWhere: ' + STORE.addr +
          '\n\nIf that time does not work for you, just reply to this email or call ' + STORE.phone + ' and we will find another one.\n\nThank you for your patience.' + sig };
    }
    if (kind === 'deny') {
      return { subject: 'About your request for ' + dayShort(a.day) + ' at ' + hourLabel(a.hour),
        body: 'Hi ' + first + ',\n\nThank you for reaching out. Unfortunately we are not able to schedule the visit you asked for on ' + when + '.\n\n' + (ctx.reason || DENY_REASONS[1]) +
          '\n\nWe would still like to help. Please call us at ' + STORE.phone + ' or reply to this email and we will work out the best next step.' + sig };
    }
    return { subject: 'A quick question about your visit on ' + dayShort(a.day),
      body: 'Hi ' + first + ',\n\nThanks for requesting a visit on ' + when + '. Before we confirm it, we have a quick question:\n\n' + (ctx.question || '') +
        '\n\nYou can reply to this email or call us at ' + STORE.phone + '. Your time is being held for you in the meantime.' + sig };
  }
  function sendMail(kind, a, subject, body) {
    // DATA hook: POST to the central mailer. In demo, keep a copy in the Sent tab.
    S.outbox.unshift({ id: 'M' + Date.now() + Math.random(), kind: kind, to: a.email, name: a.name, subject: subject, body: body, at: Date.now() });
  }

  /* ---------------------------------------------------------------- actions */
  function find(id) { return S.appts.filter(function (a) { return a.id === id; })[0]; }
  function toast(msg) { S.toast = msg; render(); clearTimeout(toast.t); toast.t = setTimeout(function () { S.toast = ''; render(); }, 4200); }
  function done(a, msg) { S.modal = null; S.flash = a.id; toast(msg); setTimeout(function () { S.flash = null; }, 1800); }

  function apply(kind, a, subject, body, ctx) {
    ctx = ctx || {};
    if (kind === 'confirm') { a.status = 'confirmed'; }
    if (kind === 'reschedule') { a.movedFrom = { day: a.day, hour: a.hour }; a.day = ctx.day; a.hour = ctx.hour; if (a.status === 'asked') { a.status = 'pending'; } }
    if (kind === 'deny') { a.status = 'denied'; }
    if (kind === 'ask') { a.status = 'asked'; a.thread.push({ from: 'us', at: Date.now(), text: ctx.question }); }
    sendMail(kind, a, subject, body);
    var labels = { confirm: 'Confirmed', reschedule: 'Rescheduled', deny: 'Denied', ask: 'Question sent' };
    done(a, labels[kind] + '. Email sent to ' + a.email + '.');
  }

  /* ---------------------------------------------------------------- views */
  function stamp(day) {
    var d = parse(day);
    return '<div class="stamp" aria-hidden="true"><span class="dow">' + DOW[d.getDay()].toUpperCase() + '</span><span class="dnum">' + d.getDate() + '</span><span class="mon">' + MON[d.getMonth()].toUpperCase() + '</span></div>';
  }
  function chip(a) {
    var m = { pending: ['chip-pending', 'Needs a decision'], asked: ['chip-asked', 'Waiting on customer'], confirmed: ['chip-confirmed', 'Confirmed'], completed: ['chip-completed', 'Finished'], denied: ['chip-denied', 'Denied'] }[a.status];
    return '<span class="chip ' + m[0] + '">' + m[1] + '</span>';
  }
  function card(a) {
    var s = seats(a.day, a.hour), act = a.status === 'pending' || a.status === 'asked';
    var note = '';
    if (active(a)) {
      note = s.over ? '<p class="seatnote bad">Over capacity: ' + s.used + ' requests for ' + s.cap + (s.cap === 1 ? ' seat' : ' seats') + ' this hour.</p>'
        : '<p class="seatnote">' + s.used + ' of ' + s.cap + (s.cap === 1 ? ' seat' : ' seats') + ' taken this hour.</p>';
    }
    var thread = a.thread.length ? '<details class="thread"><summary>' + a.thread.length + (a.thread.length === 1 ? ' message' : ' messages') + ' with ' + esc(a.name.split(' ')[0]) + '</summary>' +
      a.thread.map(function (m) { return '<div class="msg"><b>' + (m.from === 'us' ? 'We asked' : a.name.split(' ')[0] + ' replied') + '</b>' + esc(m.text) + '</div>'; }).join('') + '</details>' : '';
    var chips = chip(a) + (a.movedFrom ? '<span class="chip chip-moved">Moved from ' + dayShort(a.movedFrom.day) + '</span>' : '') + (s.over && active(a) ? '<span class="chip chip-warn">Over capacity</span>' : '');
    var canConfirm = s.confirmed < s.cap;
    var btns = '';
    if (act) {
      btns = '<button class="btn btn-primary btn-sm" data-act="confirm" data-id="' + a.id + '"' + (canConfirm ? '' : ' disabled title="All seats this hour are already confirmed"') + '>' + ic('check') + ' Confirm</button>' +
        '<button class="btn btn-ghost btn-sm" data-act="reschedule" data-id="' + a.id + '">' + ic('calendar') + ' Reschedule</button>' +
        '<button class="btn btn-ghost btn-sm" data-act="ask" data-id="' + a.id + '">' + ic('chat') + ' Ask a question</button>' +
        '<button class="btn btn-danger btn-sm" data-act="deny" data-id="' + a.id + '">Deny</button>';
    } else if (a.status === 'confirmed') {
      btns = '<button class="btn btn-ghost btn-sm" data-act="reschedule" data-id="' + a.id + '">' + ic('calendar') + ' Reschedule</button>' +
        '<button class="btn btn-ghost btn-sm" data-act="finish" data-id="' + a.id + '">' + ic('flag') + ' Finished</button>' +
        '<button class="btn btn-danger btn-sm" data-act="deny" data-id="' + a.id + '">Cancel</button>';
    }
    return '<article class="req' + (S.flash === a.id ? ' flash' : '') + '" id="' + a.id + '" aria-label="Request from ' + esc(a.name) + '">' +
      '<div class="req-top">' + stamp(a.day) + '<div class="req-when"><div class="req-time">' + hourLabel(a.hour) + ' to ' + hourLabel(a.hour + 1) + '</div><div class="req-chips">' + chips + '</div></div></div>' +
      '<div class="req-body"><h3>' + esc(a.name) + '</h3>' +
      '<ul class="req-contact"><li>' + ic('call') + '<a href="tel:' + esc(a.phone.replace(/\D/g, '')) + '">' + esc(a.phone) + '</a></li>' +
      '<li>' + ic('mail') + '<a href="mailto:' + esc(a.email) + '">' + esc(a.email) + '</a></li>' +
      '<li>' + ic('pin') + '<span>' + esc(a.address) + '</span></li></ul>' +
      '<p class="req-reason' + (a.reason ? '' : ' none') + '"><small>What they need</small>' + (a.reason ? esc(a.reason) : 'No details given.') + '</p>' + thread + note + '</div>' +
      (btns ? '<div class="req-actions">' + btns + '</div>' : '') + '</article>';
  }

  function vRequests() {
    var f = S.filter, list = S.appts.filter(function (a) {
      if (f === 'action') { return a.status === 'pending' || a.status === 'asked'; }
      if (f === 'all') { return true; }
      return a.status === f;
    });
    list.sort(function (x, y) { return S.sort === 'when' ? (x.day + pad(x.hour)).localeCompare(y.day + pad(y.hour)) : y.created - x.created; });
    var needs = S.appts.filter(function (a) { return a.status === 'pending' || a.status === 'asked'; }).length;
    var opts = [['action', 'Needs action'], ['confirmed', 'Confirmed'], ['completed', 'Finished'], ['denied', 'Denied'], ['all', 'All']]
      .map(function (o) { return '<option value="' + o[0] + '"' + (f === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
    return '<div class="m-intro"><h2>Booking requests</h2><p>' + needs + (needs === 1 ? ' request needs' : ' requests need') + ' a decision.</p></div>' +
      '<div class="toolbar"><label for="f-filter">Show</label><select id="f-filter" data-bind="filter">' + opts + '</select>' +
      '<label for="f-sort">Sort by</label><select id="f-sort" data-bind="sort"><option value="when"' + (S.sort === 'when' ? ' selected' : '') + '>Appointment time</option><option value="new"' + (S.sort === 'new' ? ' selected' : '') + '>Newest request</option></select></div>' +
      '<div class="reqs">' + (list.length ? list.map(card).join('') : '<div class="empty-state"><h3>All caught up</h3><p>Nothing here right now.</p></div>') + '</div>';
  }

  function vSchedule() {
    var day = S.day, cap = capOf(day);
    var chips = UPCOMING.map(function (k) {
      var d = parse(k), pend = S.appts.filter(function (a) { return a.day === k && (a.status === 'pending' || a.status === 'asked'); }).length;
      return '<button class="daychip' + (capOf(k) === 0 ? ' closed' : '') + '" data-act="day" data-day="' + k + '" aria-pressed="' + (k === day) + '">' + DOW[d.getDay()] + '<small>' + MON[d.getMonth()] + ' ' + d.getDate() + '</small>' + (pend ? '<span class="dot" aria-label="' + pend + ' pending">' + pend + '</span>' : '') + '</button>';
    }).join('');
    var pub = HOURS.map(function (h) { var p = publicLabel(day, h); return '<div class="pub ' + p.cls + '"><b>' + hourLabel(h) + '</b>' + esc(p.t) + (p.n ? '<br><small>' + esc(p.n) + '</small>' : '') + '</div>'; }).join('');
    var blocks = HOURS.map(function (h) {
      var s = seats(day, h), list = inBlock(day, h).filter(function (a) { return a.status !== 'denied'; });
      var pips = '', i, act = list.filter(active);
      for (i = 0; i < Math.max(s.cap, act.length); i++) {
        var a = act[i];
        pips += '<span class="pip ' + (!a ? '' : i >= s.cap ? 'over' : a.status === 'confirmed' ? 'on' : 'pend') + '" title="' + (a ? esc(a.name) : 'Open') + '"></span>';
      }
      var rows = list.map(function (a) {
        if (a.status === 'completed') {
          var pct = Math.round((a.endedAt || 60) / 60 * 100);
          return '<div class="brow done"><b>' + esc(a.name) + '</b><div class="meta"><span class="chip chip-completed">Finished ' + timeAt(h, a.endedAt || 0) + '</span></div><div class="bar"><span style="width:' + pct + '%"></span></div></div>';
        }
        return '<div class="brow"><b>' + esc(a.name) + '</b><div class="meta">' + chip(a) + '</div><small>' + esc(a.reason || 'No details given.') + '</small>' +
          (a.status === 'confirmed' ? '<button class="btn btn-ghost" data-act="finish" data-id="' + a.id + '">Finished</button>' : '<button class="btn btn-ghost" data-act="open" data-id="' + a.id + '">Review request</button>') + '</div>';
      }).join('');
      var openRows = '';
      if (s.cap && s.fresh) { openRows += '<div class="openseat">' + s.fresh + (s.fresh === 1 ? ' seat' : ' seats') + ' open to book</div>'; }
      if (s.earlyFree) { openRows += '<div class="openseat">' + s.earlyFree + (s.earlyFree === 1 ? ' seat' : ' seats') + ' open from ' + timeAt(h, s.opensFrom) + '</div>'; }
      return '<section class="block' + (s.over ? ' over' : '') + '" aria-label="' + blockLabel(h) + '"><div class="block-head"><h3>' + hourLabel(h) + '</h3><small>' + s.used + '/' + s.cap + ' booked</small></div>' +
        '<div class="pips" aria-hidden="true">' + pips + '</div>' + rows + openRows + (s.over ? '<p class="overnote">Over by ' + s.over + '. Reschedule one request.</p>' : '') + '</section>';
    }).join('');
    return '<div class="m-intro"><h2>Day schedule</h2><p>Each hour has one seat per available technician.</p></div><div class="daystrip" role="group" aria-label="Choose a day">' + chips + '</div>' +
      '<div class="public-strip"><h3>What the public calendar shows for this day</h3><p>Seats held by pending requests are already hidden from customers.</p><div class="pub-pills">' + pub + '</div></div>' +
      '<div class="day-head"><h2>' + dayLong(day) + '</h2>' + stepper(day, 'Technicians available') + '</div>' +
      (cap === 0 ? '<div class="empty-state"><h3>Closed for appointments</h3><p>Set at least one technician to open this day to the public.</p></div>' : '<div class="blocks">' + blocks + '</div>');
  }
  function stepper(day, label) {
    var c = capOf(day);
    return '<div class="stepper" role="group" aria-label="' + label + '"><span class="toolbar"><label>' + label + '</label></span>' +
      '<button class="icon-btn" data-act="cap" data-day="' + day + '" data-d="-1" aria-label="One fewer"' + (c <= 0 ? ' disabled' : '') + '>' + ic('minus') + '</button><span class="num" aria-live="polite">' + c + '</span>' +
      '<button class="icon-btn" data-act="cap" data-day="' + day + '" data-d="1" aria-label="One more"' + (c >= MAX_CAP ? ' disabled' : '') + '>' + ic('plus') + '</button></div>';
  }

  function vCapacity() {
    var top = '<div class="cap-top"><div><h3 style="margin:0 0 4px">Default for open days</h3><p>Used for any day you have not set yourself.</p></div>' +
      '<div class="stepper"><button class="icon-btn" data-act="defcap" data-d="-1" aria-label="Lower default"' + (S.defaultCap <= 0 ? ' disabled' : '') + '>' + ic('minus') + '</button><span class="num">' + S.defaultCap + '</span>' +
      '<button class="icon-btn" data-act="defcap" data-d="1" aria-label="Raise default"' + (S.defaultCap >= MAX_CAP ? ' disabled' : '') + '>' + ic('plus') + '</button></div></div>';
    var weeks = WEEKS.map(function (wk, wi) {
      var days = wk.map(function (k) {
        var d = parse(k), c = capOf(k), past = k < TODAY_K;
        var used = Math.max.apply(null, HOURS.map(function (h) { return seats(k, h).used; }));
        var over = Math.max.apply(null, HOURS.map(function (h) { return seats(k, h).over; }));
        var pctv = c ? Math.min(100, Math.round(used / c * 100)) : 0;
        return '<div class="capday' + (past ? ' past' : '') + (c === 0 ? ' zero' : '') + (over ? ' conflict' : '') + '"><h4>' + DOW[d.getDay()] + '<small>' + MON[d.getMonth()] + ' ' + d.getDate() + (k === TODAY_K ? ' (today)' : '') + '</small></h4>' +
          '<div class="stepper"><button class="icon-btn" data-act="cap" data-day="' + k + '" data-d="-1" aria-label="Fewer technicians on ' + dayShort(k) + '"' + (past || c <= 0 ? ' disabled' : '') + '>' + ic('minus') + '</button><span class="num">' + c + '</span>' +
          '<button class="icon-btn" data-act="cap" data-day="' + k + '" data-d="1" aria-label="More technicians on ' + dayShort(k) + '"' + (past || c >= MAX_CAP ? ' disabled' : '') + '>' + ic('plus') + '</button></div>' +
          '<div class="meter" aria-hidden="true"><span style="width:' + pctv + '%"></span></div>' +
          '<p class="small' + (over ? ' bad' : '') + '">' + (c === 0 ? 'Closed to the public' : over ? 'Over by ' + over + ' in a busy hour' : 'Busiest hour: ' + used + ' of ' + c) + '</p></div>';
      }).join('');
      var sel = '<option value="">Set all days to...</option>' + [0, 1, 2, 3, 4, 5, 6].map(function (n) { return '<option value="' + n + '">' + n + (n === 0 ? ' (closed)' : n === 1 ? ' technician' : ' technicians') + '</option>'; }).join('');
      return '<section class="week"><div class="week-head"><h3>' + (wi === 0 ? 'This week' : wi === 1 ? 'Next week' : 'Week of ' + dayShort(wk[0])) + '</h3><span class="set"><label for="wk' + wi + '" class="visually-hidden">Set whole week</label><select id="wk' + wi + '" data-week="' + wi + '">' + sel + '</select></span></div><div class="week-days">' + days + '</div></section>';
    }).join('');
    return '<div class="m-intro"><h2>Capacity</h2><p>How many technicians can take appointments each day. Zero closes the day.</p></div>' + top + weeks;
  }

  function vMail() {
    if (!S.outbox.length) { return '<div class="m-intro"><h2>Sent emails</h2></div><div class="empty-state"><h3>Nothing sent yet</h3><p>Confirm, reschedule, deny or ask a question and the email shows up here.</p></div>'; }
    var label = { confirm: 'Confirmed', reschedule: 'Rescheduled', deny: 'Denied', ask: 'Question' };
    return '<div class="m-intro"><h2>Sent emails</h2><p>' + (DEMO ? 'Demo mode: these were not actually delivered.' : 'Copies of what customers received.') + '</p></div><div class="mails">' +
      S.outbox.map(function (m) {
        return '<details class="mail"><summary><span class="chip ' + (m.kind === 'deny' ? 'chip-denied' : m.kind === 'confirm' ? 'chip-confirmed' : 'chip-asked') + '">' + label[m.kind] + '</span><span class="subj">' + esc(m.subject) + '</span><span class="to">to ' + esc(m.name) + ' (' + esc(m.to) + ')</span></summary><pre>' + esc(m.body) + '</pre></details>';
      }).join('') + '</div>';
  }

  /* ---------------------------------------------------------------- modals */
  function mailForm(kind, a, ctx, extra, title, sub, cta) {
    var m = emailFor(kind, a, ctx);
    return '<div class="backdrop" data-act="backdrop"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-title" tabindex="-1" id="modal"><h2 id="m-title">' + title + '</h2><p class="modal-sub">' + sub + '</p>' +
      '<form class="form" id="m-form" novalidate>' + (extra || '') +
      '<div class="field"><label for="m-to">To</label><input id="m-to" type="text" value="' + esc(a.name + ' <' + a.email + '>') + '" readonly></div>' +
      '<div class="field"><label for="m-subj">Subject</label><input id="m-subj" type="text" value="' + esc(m.subject) + '"></div>' +
      '<div class="field"><label for="m-body">Message</label><textarea id="m-body">' + esc(m.body) + '</textarea></div>' +
      '<p class="modal-err" id="m-err" role="alert"></p><div class="modal-actions"><button type="button" class="btn btn-ghost" data-act="close">Cancel</button><button type="submit" class="btn btn-primary">' + ic('send') + ' ' + cta + '</button></div></form></div></div>';
  }
  function vModal() {
    var M = S.modal; if (!M) { return ''; }
    var a = M.id ? find(M.id) : null;
    if (M.kind === 'confirm') { return mailForm('confirm', a, {}, '', 'Confirm ' + esc(a.name.split(' ')[0]) + '\'s visit', dayLong(a.day) + ' at ' + hourLabel(a.hour) + '. This sends the confirmation email.', 'Confirm and send'); }
    if (M.kind === 'deny') {
      var cancel = a.status === 'confirmed';
      var rs = '<div class="field"><label for="m-reason">Reason to share</label><select id="m-reason" data-modal="reason">' + DENY_REASONS.map(function (r, i) { return '<option value="' + i + '"' + (+M.reason === i ? ' selected' : '') + '>' + esc(r) + '</option>'; }).join('') + '</select></div>';
      return mailForm('deny', a, { reason: DENY_REASONS[M.reason || 0] }, rs, cancel ? 'Cancel this appointment' : 'Deny this request', 'The customer will be told and the seat is released.', cancel ? 'Cancel and send' : 'Deny and send');
    }
    if (M.kind === 'ask') {
      var q = '<div class="field"><label for="m-q">Your question</label><textarea id="m-q" style="min-height:90px" data-modal="question" placeholder="What would you like to ask?">' + esc(M.question || '') + '</textarea></div>';
      var html = mailForm('ask', a, { question: M.question || '' }, q, 'Ask ' + esc(a.name.split(' ')[0]) + ' a question', 'Their time stays held while you wait for an answer.', 'Send question');
      return html;
    }
    if (M.kind === 'reschedule') {
      var days = UPCOMING.map(function (k) {
        var ok = capOf(k) > 0 && HOURS.some(function (h) { return (k !== a.day || h !== a.hour) && seats(k, h).free > 0; });
        return '<button type="button" class="pick-day" data-act="pickday" data-day="' + k + '" aria-pressed="' + (M.day === k) + '"' + (ok ? '' : ' disabled') + '>' + DOW[parse(k).getDay()] + '<small>' + MON[parse(k).getMonth()] + ' ' + parse(k).getDate() + '</small></button>';
      }).join('');
      var hrs = M.day ? '<div class="pick-hours">' + HOURS.map(function (h) {
        var s = seats(M.day, h), same = M.day === a.day && h === a.hour;
        return '<button type="button" class="pick-day" data-act="pickhour" data-hour="' + h + '" aria-pressed="' + (M.hour === h) + '"' + (s.free > 0 && !same ? '' : ' disabled') + '>' + hourLabel(h) + '<small>' + (same ? 'Current' : s.free ? s.free + (s.free === 1 ? ' seat open' : ' seats open') : 'Full') + '</small></button>';
      }).join('') + '</div>' : '<p class="modal-sub">Pick a day first.</p>';
      var pick = '<div class="field"><label>New day</label><div class="pick-days">' + days + '</div><label>New hour</label>' + hrs + '</div>';
      if (!M.day || M.hour == null) {
        return '<div class="backdrop" data-act="backdrop"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-title" tabindex="-1" id="modal"><h2 id="m-title">Reschedule ' + esc(a.name.split(' ')[0]) + '\'s visit</h2><p class="modal-sub">Now: ' + dayLong(a.day) + ' at ' + hourLabel(a.hour) + '. Only hours with an open seat can be picked.</p><div class="form">' + pick +
          '<p class="modal-err" id="m-err" role="alert"></p><div class="modal-actions"><button type="button" class="btn btn-ghost" data-act="close">Cancel</button><button type="button" class="btn btn-primary" disabled>Pick a time</button></div></div></div></div>';
      }
      return mailForm('reschedule', a, { day: M.day, hour: M.hour }, pick, 'Reschedule ' + esc(a.name.split(' ')[0]) + '\'s visit', 'Now: ' + dayLong(a.day) + ' at ' + hourLabel(a.hour) + '.', 'Reschedule and send');
    }
    if (M.kind === 'finish') {
      var opts = [[15, 'about 15 minutes in'], [30, 'about 30 minutes in'], [45, 'about 45 minutes in'], [60, 'the full hour']].map(function (o) { return '<button type="button" class="btn ' + (M.at === o[0] ? 'btn-primary' : 'btn-ghost') + '" data-act="finishat" data-at="' + o[0] + '">' + (o[0] === 60 ? 'Full hour' : ':' + pad(o[0])) + '</button>'; }).join('');
      var at = M.at;
      return '<div class="backdrop" data-act="backdrop"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="m-title" tabindex="-1" id="modal"><h2 id="m-title">Finished early?</h2><p class="modal-sub">' + esc(a.name) + ', ' + hourLabel(a.hour) + '. Tell us when you wrapped up. ' +
        'Time left in the hour opens back up on the public calendar.</p><div class="form"><div class="modal-actions" style="justify-content:flex-start">' + opts + '</div>' +
        (at != null ? '<p class="modal-sub" style="margin:0">' + (at < 60 ? 'The seat opens again from ' + timeAt(a.hour, at) + '.' : 'The seat is released at the end of the hour.') + '</p>' : '') +
        '<div class="modal-actions"><button type="button" class="btn btn-ghost" data-act="close">Cancel</button><button type="button" class="btn btn-primary" data-act="finishgo"' + (at == null ? ' disabled' : '') + '>End appointment</button></div></div></div></div>';
    }
    return '';
  }

  /* ---------------------------------------------------------------- shell */
  function vGate() {
    if (S.checking) { return '<div class="gate"><div class="gate-card"><p>Loading...</p></div></div>'; }
    return '<div class="gate"><div class="gate-card"><img class="logo-on-light" src="' + BASE + 'assets/img/logo-full.png" alt="CodeBlue Technology Express"><img class="logo-on-dark" src="' + BASE + 'assets/img/logo-white.png" alt="" aria-hidden="true">' +
      '<h1>Schedule Manager</h1><p>For CodeBlue Tech Express technicians. Sign in with your CodeBlue Microsoft 365 account.</p>' +
      '<button class="btn btn-primary btn-block" data-act="signin">Sign in with Microsoft</button>' +
      '<p class="gate-note">' + (DEMO ? 'Demo mode: this button just opens the console with sample requests. The live version uses the same Microsoft 365 sign-in as Solutions Hub.' : 'Access is limited to CodeBlue staff.') + '</p></div></div>';
  }
  function vApp() {
    var needs = S.appts.filter(function (a) { return a.status === 'pending' || a.status === 'asked'; }).length;
    var tabs = [['requests', 'Requests', needs], ['schedule', 'Schedule', 0], ['capacity', 'Capacity', 0], ['mail', 'Sent emails', 0]];
    var main = S.tab === 'requests' ? vRequests() : S.tab === 'schedule' ? vSchedule() : S.tab === 'capacity' ? vCapacity() : vMail();
    return '<header class="site-header"><div class="container"><a class="brand" href="./" aria-label="Schedule Manager"><img class="brand-logo logo-on-light" src="' + BASE + 'assets/img/logo-full.png" alt="CodeBlue Technology Express" width="137" height="50"><img class="brand-logo logo-on-dark" src="' + BASE + 'assets/img/logo-white.png" alt="" width="137" height="50" aria-hidden="true"></a>' +
      '<div class="bar-right"><span class="bar-title">Schedule Manager</span><span class="pill">' + (DEMO ? 'DEMO' : 'LIVE') + '</span><button class="btn btn-ghost btn-sm" data-act="signout">Sign out</button></div></div></header>' +
      (DEMO ? '<div class="demo-bar"><div class="container">' + ic('info') + '<span>Demo mode. Sample requests, nothing is saved and no email is really sent.</span></div></div>' : '') +
      '<div class="tabs-wrap"><div class="container"><div class="tabs" role="tablist">' + tabs.map(function (t) {
        return '<button class="tab" role="tab" data-act="tab" data-tab="' + t[0] + '" aria-selected="' + (S.tab === t[0]) + '">' + t[1] + (t[2] ? ' <span class="count hot">' + t[2] + '</span>' : t[0] === 'mail' && S.outbox.length ? ' <span class="count">' + S.outbox.length + '</span>' : '') + '</button>';
      }).join('') + '</div></div></div><main class="m-main" id="main"><div class="container">' + main + '</div></main>';
  }
  function render() {
    var root = $('#root'), ae = document.activeElement, id = ae && ae.id, sx = window.scrollX, sy = window.scrollY;
    root.innerHTML = (S.signedIn ? vApp() : vGate()) + vModal() + (S.toast ? '<div class="toast" role="status">' + esc(S.toast) + '</div>' : '');
    window.scrollTo(sx, sy);
    if (S.modal) { var m = $('#modal'); if (m && !(ae && m.contains(ae))) { var f = $('#m-q') || $('#m-subj') || m; if (!root.dataset.focused || root.dataset.focused !== S.modal.kind + S.modal.id) { f.focus(); root.dataset.focused = S.modal.kind + S.modal.id; } } }
    else { root.dataset.focused = ''; if (id) { var again = document.getElementById(id); if (again) { again.focus(); } } }
  }

  /* ---------------------------------------------------------------- events */
  function openModal(kind, id) {
    var M = { kind: kind, id: id };
    if (kind === 'deny') { M.reason = 0; }
    S.modal = M; render();
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-act]'); if (!t) { return; }
    var act = t.getAttribute('data-act'), id = t.getAttribute('data-id');
    if (act === 'backdrop') { if (e.target === t) { S.modal = null; render(); } return; }
    if (act === 'signin') { if (DEMO) { S.signedIn = true; render(); } else { location.href = BASE + 'api/auth/login.php'; } return; }
    if (act === 'signout') {
      if (DEMO) { S.signedIn = false; S.modal = null; render(); }
      else { post({ action: 'signout' }).then(function () { S.signedIn = false; S.modal = null; S.appts = []; render(); }); }
      return;
    }
    if (act === 'tab') { S.tab = t.getAttribute('data-tab'); render(); window.scrollTo(0, 0); return; }
    if (act === 'day') { S.day = t.getAttribute('data-day'); render(); return; }
    if (act === 'cap') { var k = t.getAttribute('data-day'), c = capOf(k) + (+t.getAttribute('data-d')); S.capacity[k] = Math.max(0, Math.min(MAX_CAP, c)); render(); saveCap([k], S.capacity[k]); return; }
    if (act === 'defcap') {
      S.defaultCap = Math.max(0, Math.min(MAX_CAP, S.defaultCap + (+t.getAttribute('data-d')))); render();
      if (!DEMO) { post({ action: 'defaultcap', cap: S.defaultCap }).then(function (r) { if (!r.ok) { toast(r.error); load(true); } else { render(); } }); }
      return;
    }
    if (act === 'open') { S.tab = 'requests'; S.filter = 'all'; S.flash = id; render(); var el = document.getElementById(id); if (el) { el.scrollIntoView({ block: 'center' }); } setTimeout(function () { S.flash = null; render(); }, 1800); return; }
    if (act === 'confirm' || act === 'deny' || act === 'ask' || act === 'reschedule' || act === 'finish') { openModal(act, id); return; }
    if (act === 'close') { S.modal = null; render(); return; }
    if (act === 'pickday') { S.modal.day = t.getAttribute('data-day'); S.modal.hour = null; render(); return; }
    if (act === 'pickhour') { S.modal.hour = +t.getAttribute('data-hour'); render(); return; }
    if (act === 'finishat') { S.modal.at = +t.getAttribute('data-at'); render(); return; }
    if (act === 'finishgo') {
      var a = find(S.modal.id), at = S.modal.at, fin = function () { done(a, at < 60 ? 'Appointment ended. The seat reopens from ' + timeAt(a.hour, at) + '.' : 'Appointment finished.'); };
      if (DEMO) { a.status = 'completed'; a.endedAt = at; fin(); return; }
      post({ action: 'finish', id: a.id, at: at }).then(function (r) { if (r.ok) { fin(); } else { S.modal = null; toast(r.error); load(true); } });
      return;
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.getAttribute('data-bind')) { S[t.getAttribute('data-bind')] = t.value; render(); return; }
    if (t.getAttribute('data-week') != null) { if (t.value === '') { return; } var wkDays = WEEKS[+t.getAttribute('data-week')].filter(function (k) { return k >= TODAY_K; }); wkDays.forEach(function (k) { S.capacity[k] = +t.value; }); render(); if (wkDays.length) { saveCap(wkDays, +t.value); } return; }
    if (t.getAttribute('data-modal') === 'reason') { S.modal.reason = +t.value; render(); }
  });
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (t.getAttribute('data-modal') === 'question') {
      S.modal.question = t.value; var a = find(S.modal.id), m = emailFor('ask', a, { question: t.value }); $('#m-body').value = m.body;
    }
  });
  document.addEventListener('submit', function (e) {
    if (e.target.id !== 'm-form') { return; }
    e.preventDefault();
    var M = S.modal, a = find(M.id), subj = $('#m-subj').value.trim(), body = $('#m-body').value.trim(), err = $('#m-err');
    if (!subj || !body) { err.textContent = 'Subject and message cannot be empty.'; return; }
    if (M.kind === 'ask' && !(M.question || '').trim()) { err.textContent = 'Type your question first.'; $('#m-q').focus(); return; }
    if (M.kind === 'confirm' && seats(a.day, a.hour).confirmed >= seats(a.day, a.hour).cap) { err.textContent = 'All seats this hour are already confirmed.'; return; }
    var ctx = { day: M.day, hour: M.hour, question: (M.question || '').trim() };
    if (DEMO) { apply(M.kind, a, subj, body, ctx); return; }
    if (S.busy) { return; }
    S.busy = true; err.textContent = 'Sending...';
    var btn = e.target.querySelector('button[type="submit"]'); if (btn) { btn.disabled = true; }
    post({ action: M.kind, id: a.id, subject: subj, body: body, day: M.day, hour: M.hour, question: ctx.question }).then(function (r) {
      S.busy = false;
      if (r.ok) {
        var labels = { confirm: 'Confirmed', reschedule: 'Rescheduled', deny: 'Denied', ask: 'Question sent' };
        done(a, labels[M.kind] + '. Email sent to ' + a.email + '.');
      } else if (S.signedIn) {
        err.textContent = r.error; if (btn) { btn.disabled = false; }
        if (/already|no longer|no open seat|already confirmed/i.test(r.error)) { load(true); }
      }
    });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && S.modal) { S.modal = null; render(); } });

  if (DEMO) { seed(); render(); }
  else {
    render();
    load();
    // Pick up new requests without anyone reloading; never disturb an open dialog.
    setInterval(function () { if (S.signedIn && !S.modal && !S.busy && !document.hidden) { load(true); } }, 60000);
  }
})();
