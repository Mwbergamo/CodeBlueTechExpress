/* CodeBlue Technology Express: site behavior.
   Everything here is guarded by element checks, so one file serves every page.
   Live connections come from window.TE_CONFIG (see config.js). With none set,
   the site runs in demo mode and says so on screen. */
(function () {
  'use strict';

  var CFG = window.TE_CONFIG || {};
  var HOURS = [11, 12, 13, 14, 15]; // slots start 11 AM through 3 PM, one hour each
  var MAX_WEEKS_AHEAD = 8;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function icon(id, cls) { return '<svg class="i ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + id + '"/></svg>'; }
  function postJSON(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (d) {
        if (!r.ok || d.ok === false) { throw new Error(d.error || 'Request failed'); }
        return d;
      });
    });
  }

  /* ---------------------------------------------------------------- nav */
  var toggle = $('#nav-toggle');
  var mobileNav = $('#nav-mobile');
  if (toggle && mobileNav) {
    toggle.addEventListener('click', function () {
      var open = mobileNav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* -------------------------------------------------------------- dates */
  function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function addDays(d, n) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate() + n); return x; }
  function mondayOf(d) { var x = startOfDay(d); var dow = x.getDay(); return addDays(x, dow === 0 ? -6 : 1 - dow); }
  function slotKey(d, h) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(h) + ':00'; }
  function slotStart(d, h) { return new Date(d.getFullYear(), d.getMonth(), d.getDate(), h, 0, 0); }
  function hourLabel(h) { var h12 = h % 12 === 0 ? 12 : h % 12; return h12 + ':00 ' + (h < 12 ? 'AM' : 'PM'); }
  function dayShort(d) { return d.toLocaleDateString('en-US', { weekday: 'short' }); }
  function monthDay(d) { return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }
  function longDay(d) { return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }); }
  function rangeLabel(d, h) { return longDay(d) + ', ' + hourLabel(h) + ' to ' + hourLabel(h + 1); }

  /* ------------------------------------------------------- availability */
  var booked = null;              // Set of slot keys from the server, or null in demo mode
  var pending = {};               // slots requested during this visit
  function demoBooked(key) {      // stable made-up pattern so the demo looks lived in
    var h = 0, i;
    for (i = 0; i < key.length; i++) { h = (h * 31 + key.charCodeAt(i)) >>> 0; }
    return h % 5 === 0;
  }
  function isBooked(key) { return booked ? booked[key] === true : demoBooked(key); }
  function isOpen(d, h) {
    var key = slotKey(d, h);
    return slotStart(d, h) > new Date() && !isBooked(key) && !pending[key];
  }
  function loadAvailability() {
    if (!CFG.availabilityUrl) { return Promise.resolve(); }
    var from = startOfDay(new Date());
    var to = addDays(from, MAX_WEEKS_AHEAD * 7);
    var url = CFG.availabilityUrl + (CFG.availabilityUrl.indexOf('?') < 0 ? '?' : '&') +
      'from=' + slotKey(from, 0).slice(0, 10) + '&to=' + slotKey(to, 0).slice(0, 10);
    return fetch(url).then(function (r) { return r.json(); }).then(function (list) {
      booked = {};
      (list || []).forEach(function (iso) { booked[String(iso).slice(0, 16)] = true; });
    }).catch(function () { booked = null; });
  }
  function nextOpen(n) {
    var out = [], d = startOfDay(new Date()), guard = 0;
    while (out.length < n && guard < MAX_WEEKS_AHEAD * 7) {
      if (d.getDay() >= 1 && d.getDay() <= 5) {
        HOURS.forEach(function (h) { if (out.length < n && isOpen(d, h)) { out.push({ d: d, h: h }); } });
      }
      d = addDays(d, 1); guard++;
    }
    return out;
  }

  /* -------------------------------------------------- hero ticket slots */
  function renderTicket() {
    var ul = $('#hero-slots');
    if (!ul) { return; }
    var slots = nextOpen(3);
    ul.innerHTML = slots.map(function (s) {
      return '<li><a href="' + esc(ul.getAttribute('data-href') || '#') + '"><span>' + esc(hourLabel(s.h)) +
        '</span><span class="day">' + esc(dayShort(s.d) + ', ' + monthDay(s.d)) + '</span></a></li>';
    }).join('') || '<li><a href="' + esc(ul.getAttribute('data-href') || '#') + '"><span>See the calendar</span><span class="day"></span></a></li>';
  }

  /* ---------------------------------------------------------- inventory */
  var inventory = [];
  var inventoryDemo = false;
  var CAT_ICON = { 'Laptops': 'laptop', 'Desktops': 'desktop', 'Printers & Ink': 'printer', 'Monitors': 'monitor', 'Networking': 'wifi', 'Accessories': 'keyboard' };
  var CAT_ORDER = ['Laptops', 'Desktops', 'Monitors', 'Printers & Ink', 'Networking', 'Accessories'];
  var money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

  function loadInventory() {
    if (CFG.inventoryUrl) {
      return fetch(CFG.inventoryUrl).then(function (r) { return r.json(); }).then(function (list) {
        inventory = Array.isArray(list) ? list : []; inventoryDemo = false;
      });
    }
    inventory = (window.TE_SAMPLE_INVENTORY || []).slice(); inventoryDemo = true;
    return Promise.resolve();
  }
  function stockInfo(qty) {
    if (qty <= 0) { return { cls: 'stock-out', text: 'Out of stock' }; }
    if (qty <= 3) { return { cls: 'stock-low', text: 'Low stock: ' + qty + ' left' }; }
    return { cls: 'stock-in', text: 'In stock: ' + qty };
  }
  function productCard(p) {
    var st = stockInfo(p.qty);
    var media = p.image
      ? '<img src="' + esc(p.image) + '" alt="' + esc(p.name) + '" loading="lazy">'
      : icon(CAT_ICON[p.category] || 'box');
    return '<article class="product' + (p.qty <= 0 ? ' is-out' : '') + '">' +
      '<div class="product-media">' + media + '</div>' +
      '<div class="product-body">' +
      '<div class="product-brand">' + esc(p.brand || '') + '</div>' +
      '<h3 class="product-name">' + esc(p.name) + '</h3>' +
      '<p class="product-desc">' + esc(p.description || '') + '</p>' +
      '<div class="product-foot"><span class="price">' + money.format(p.price) + '</span>' +
      '<span class="stock ' + st.cls + '">' + esc(st.text) + '</span></div>' +
      '</div></article>';
  }
  function showDemoNotes() {
    var demo = { inventory: inventoryDemo, booking: !CFG.appointmentUrl, contact: !CFG.contactUrl };
    $$('.demo-note').forEach(function (n) { n.classList.toggle('show', !!demo[n.getAttribute('data-for')]); });
  }

  function initFeatured() {
    var box = $('#home-featured');
    if (!box) { return; }
    var seen = {}, picks = [];
    inventory.forEach(function (p) {
      if (p.qty > 0 && !seen[p.category] && picks.length < 4) { seen[p.category] = true; picks.push(p); }
    });
    box.innerHTML = picks.map(productCard).join('');
  }

  function initShop() {
    var grid = $('#shop-grid');
    if (!grid) { return; }
    var state = { q: '', cat: 'All', sort: 'featured', inStock: true };
    var cats = CAT_ORDER.filter(function (c) { return inventory.some(function (p) { return p.category === c; }); });
    inventory.forEach(function (p) { if (cats.indexOf(p.category) < 0) { cats.push(p.category); } });
    var chipBox = $('#shop-cats');
    chipBox.innerHTML = ['All'].concat(cats).map(function (c) {
      return '<button type="button" class="chip-btn" data-cat="' + esc(c) + '" aria-pressed="' + (c === 'All') + '">' + esc(c) + '</button>';
    }).join('');

    function render() {
      var q = state.q.trim().toLowerCase();
      var list = inventory.filter(function (p) {
        if (state.inStock && p.qty <= 0) { return false; }
        if (state.cat !== 'All' && p.category !== state.cat) { return false; }
        if (q && (p.name + ' ' + (p.brand || '') + ' ' + (p.description || '') + ' ' + p.category).toLowerCase().indexOf(q) < 0) { return false; }
        return true;
      });
      if (state.sort === 'low') { list.sort(function (a, b) { return a.price - b.price; }); }
      if (state.sort === 'high') { list.sort(function (a, b) { return b.price - a.price; }); }
      if (state.sort === 'name') { list.sort(function (a, b) { return a.name.localeCompare(b.name); }); }
      $('#shop-count').textContent = 'Showing ' + list.length + ' of ' + inventory.length + ' items';
      grid.innerHTML = list.length ? list.map(productCard).join('') :
        '<div class="empty" style="grid-column:1/-1"><strong>Nothing matches that search.</strong><br>Try a different word, or call us at (804) 456-4500 and we will check the back room.</div>';
    }
    $('#shop-search').addEventListener('input', function (e) { state.q = e.target.value; render(); });
    $('#shop-sort').addEventListener('change', function (e) { state.sort = e.target.value; render(); });
    $('#shop-instock').addEventListener('change', function (e) { state.inStock = e.target.checked; render(); });
    chipBox.addEventListener('click', function (e) {
      var b = e.target.closest('.chip-btn');
      if (!b) { return; }
      state.cat = b.getAttribute('data-cat');
      $$('.chip-btn', chipBox).forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      render();
    });
    render();
  }

  /* ------------------------------------------------------------ booking */
  function initBooking() {
    var cal = $('#bk-cal');
    if (!cal) { return; }
    var today = startOfDay(new Date());
    var minWeek = mondayOf(today);
    if (today.getDay() === 0 || today.getDay() === 6) { minWeek = addDays(minWeek, 7); }
    var state = { week: minWeek, selected: null, activeDay: 0 };

    var elTitle = $('#bk-week-title'), elPrev = $('#bk-prev'), elNext = $('#bk-next');
    var elTabs = $('#bk-tabs'), elPicked = $('#bk-picked'), elForm = $('#bk-form');
    var elSent = $('#bk-sent'), elStatus = $('#bk-status');

    function pickActiveDay() {
      for (var i = 0; i < 5; i++) {
        var d = addDays(state.week, i);
        if (HOURS.some(function (h) { return isOpen(d, h); })) { state.activeDay = i; return; }
      }
      state.activeDay = 0;
    }

    function render() {
      var days = [0, 1, 2, 3, 4].map(function (i) { return addDays(state.week, i); });
      elTitle.textContent = 'Week of ' + monthDay(days[0]) + ' to ' + monthDay(days[4]);
      elPrev.disabled = state.week.getTime() <= minWeek.getTime();
      elNext.disabled = state.week.getTime() >= addDays(minWeek, MAX_WEEKS_AHEAD * 7).getTime();

      elTabs.innerHTML = days.map(function (d, i) {
        return '<button type="button" role="tab" class="day-tab" data-i="' + i + '" aria-selected="' + (i === state.activeDay) + '">' +
          esc(dayShort(d)) + '<small>' + esc(monthDay(d)) + '</small></button>';
      }).join('');

      cal.innerHTML = days.map(function (d, i) {
        var slots = HOURS.map(function (h) {
          var key = slotKey(d, h);
          var past = slotStart(d, h) <= new Date();
          var open = isOpen(d, h);
          var label = pending[key] ? 'Requested' : (past ? 'Past' : (open ? 'to ' + hourLabel(h + 1) : 'Booked'));
          return '<button type="button" class="slot" data-key="' + key + '" data-d="' + i + '" data-h="' + h + '"' +
            (open ? '' : ' disabled') + ' aria-pressed="' + (state.selected === key) + '"' +
            ' aria-label="' + esc(rangeLabel(d, h) + (open ? '' : ', unavailable')) + '">' +
            esc(hourLabel(h)) + '<small>' + esc(label) + '</small></button>';
        }).join('');
        return '<div class="cal-day' + (i === state.activeDay ? ' active' : '') + '">' +
          '<div class="cal-day-head">' + esc(dayShort(d)) + '<small>' + esc(monthDay(d)) + '</small></div>' + slots + '</div>';
      }).join('');
      updatePicked();
    }

    function selectedParts() {
      if (!state.selected) { return null; }
      var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2})/.exec(state.selected);
      return { d: new Date(+m[1], +m[2] - 1, +m[3]), h: +m[4] };
    }
    function updatePicked() {
      var p = selectedParts();
      if (p) {
        elPicked.className = 'picked';
        elPicked.textContent = rangeLabel(p.d, p.h);
      } else {
        elPicked.className = 'picked none';
        elPicked.textContent = 'Pick a time on the calendar to continue.';
      }
    }

    elPrev.addEventListener('click', function () { state.week = addDays(state.week, -7); pickActiveDay(); render(); });
    elNext.addEventListener('click', function () { state.week = addDays(state.week, 7); pickActiveDay(); render(); });
    elTabs.addEventListener('click', function (e) {
      var b = e.target.closest('.day-tab');
      if (!b) { return; }
      state.activeDay = +b.getAttribute('data-i'); render();
    });
    cal.addEventListener('click', function (e) {
      var b = e.target.closest('.slot');
      if (!b || b.disabled) { return; }
      state.selected = b.getAttribute('data-key');
      render();
      elStatus.textContent = '';
    });

    function setInvalid(id, bad) { var f = $('#' + id).closest('.field'); f.classList.toggle('invalid', bad); return bad; }
    elForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if ($('#bk-company').value) { return; } // honeypot
      var phoneDigits = $('#bk-phone').value.replace(/\D/g, '');
      var bad = [
        setInvalid('bk-reason', !$('#bk-reason').value.trim()),
        setInvalid('bk-name', !$('#bk-name').value.trim()),
        setInvalid('bk-address', !$('#bk-address').value.trim()),
        setInvalid('bk-phone', phoneDigits.length < 10)
      ].some(Boolean);
      if (!state.selected) {
        elStatus.className = 'form-status error';
        elStatus.textContent = 'Please pick a time on the calendar first.';
        return;
      }
      if (bad) { elStatus.className = 'form-status error'; elStatus.textContent = 'Please fix the highlighted fields.'; return; }
      var payload = {
        start: state.selected,
        reason: $('#bk-reason').value.trim(),
        name: $('#bk-name').value.trim(),
        address: $('#bk-address').value.trim(),
        phone: $('#bk-phone').value.trim()
      };
      var btn = $('#bk-submit'); btn.disabled = true; elStatus.className = 'form-status'; elStatus.textContent = 'Sending your request...';
      var send = CFG.appointmentUrl ? postJSON(CFG.appointmentUrl, payload) : Promise.resolve({ demo: true });
      send.then(function (res) {
        var p = selectedParts();
        pending[state.selected] = true;
        $('#bk-sent-when').textContent = rangeLabel(p.d, p.h);
        $('#bk-sent-demo').hidden = !res.demo;
        elForm.hidden = true; elPicked.hidden = true; elSent.hidden = false;
        state.selected = null; render();
        elSent.focus();
      }).catch(function () {
        elStatus.className = 'form-status error';
        elStatus.textContent = 'We could not send that request. Please call (804) 456-4500 and we will book you in.';
      }).then(function () { btn.disabled = false; });
    });
    $('#bk-again').addEventListener('click', function () {
      elForm.reset(); elForm.hidden = false; elPicked.hidden = false; elSent.hidden = true; elStatus.textContent = '';
      $$('.field.invalid', elForm).forEach(function (f) { f.classList.remove('invalid'); });
      updatePicked();
    });

    pickActiveDay();
    render();
    // After the real availability arrives, refresh the grid and hero ticket.
    loadAvailability().then(function () { renderTicket(); pickActiveDay(); render(); });
  }

  /* ------------------------------------------------------------ contact */
  function initContact() {
    var form = $('#ct-form');
    if (!form) { return; }
    var status = $('#ct-status'), sent = $('#ct-sent');
    function setInvalid(id, bad) { $('#' + id).closest('.field').classList.toggle('invalid', bad); return bad; }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if ($('#ct-company').value) { return; }
      var bad = [
        setInvalid('ct-first', !$('#ct-first').value.trim()),
        setInvalid('ct-last', !$('#ct-last').value.trim()),
        setInvalid('ct-phone', $('#ct-phone').value.replace(/\D/g, '').length < 10),
        setInvalid('ct-email', !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test($('#ct-email').value.trim())),
        setInvalid('ct-reason', !$('#ct-reason').value.trim())
      ].some(Boolean);
      if (bad) { status.className = 'form-status error'; status.textContent = 'Please fix the highlighted fields.'; return; }
      var payload = {
        firstName: $('#ct-first').value.trim(), lastName: $('#ct-last').value.trim(),
        business: $('#ct-business').value.trim(), phone: $('#ct-phone').value.trim(),
        email: $('#ct-email').value.trim(), reason: $('#ct-reason').value.trim()
      };
      var btn = $('#ct-submit'); btn.disabled = true; status.className = 'form-status'; status.textContent = 'Sending...';
      var send = CFG.contactUrl ? postJSON(CFG.contactUrl, payload) : Promise.resolve({ demo: true });
      send.then(function (res) {
        $('#ct-sent-demo').hidden = !res.demo;
        form.hidden = true; sent.hidden = false; sent.focus();
      }).catch(function () {
        status.className = 'form-status error';
        status.textContent = 'That did not send. Please call (804) 456-4500 or email hello@codebluetechnology.com.';
      }).then(function () { btn.disabled = false; });
    });
    $('#ct-again').addEventListener('click', function () {
      form.reset(); form.hidden = false; sent.hidden = true; status.textContent = '';
    });
  }

  /* --------------------------------------------------------------- boot */
  showDemoNotes();
  renderTicket();
  initBooking();
  initContact();
  loadAvailability().then(renderTicket);
  loadInventory().then(function () {
    showDemoNotes(); initFeatured(); initShop();
  }).catch(function () {
    var g = $('#shop-grid');
    if (g) { g.innerHTML = '<div class="empty" style="grid-column:1/-1">We could not load the inventory just now. Please call (804) 456-4500.</div>'; }
  });
})();
