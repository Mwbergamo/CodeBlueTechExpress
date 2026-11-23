#!/usr/bin/env python3
"""
Generates the CodeBlue Technology Express static pages from shared
header/footer parts plus per-page content. Run it after any content change,
then commit the generated .html files (those are what the server serves).

    python3 build.py            # writes index.html, about.html, ... sitemap.xml
    python3 build.py --preview  # also writes dist/preview.html (single file,
                                # hash-routed, everything inlined) for review

Same approach as the CBTWeb build.py. Copy rule carried over from that site:
no em dashes anywhere in visible copy.
"""
import base64
import mimetypes
import os
import re
import sys

ROOT = os.path.dirname(os.path.abspath(__file__))
SITE = "https://codebluetechexpress.com"
PHONE = "(804) 456-4500"
PHONE_TEL = "+18044564500"
EMAIL = "hello@codebluetechnology.com"
ADDRESS_1 = "5020 Richmond Rd. Suite\u00a0B"
ADDRESS_2 = "Warsaw, VA 22572"
TAGLINE = "Technology is what we do, people are why we do it"

NAV = [("About", "about.html"), ("Services", "services.html"), ("In-Store", "in-store.html"), ("Contact", "contact.html")]

FONTS = ('<link rel="preconnect" href="https://fonts.googleapis.com">'
         '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         '<link href="https://fonts.googleapis.com/css2?family=Jost:wght@500;600;700&amp;'
         'family=Libre+Franklin:wght@400;500;600;700&amp;display=swap" rel="stylesheet">')

ICON_LINKS = ('<link rel="icon" href="assets/img/favicon.ico" sizes="any">\n'
              '<link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png">\n'
              '<link rel="icon" type="image/png" sizes="192x192" href="assets/img/favicon-192.png">\n'
              '<link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">')

# --------------------------------------------------------------- icons
SPRITE = """<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0" aria-hidden="true" focusable="false">
<symbol id="i-laptop" viewBox="0 0 24 24"><rect x="4.5" y="5" width="15" height="10" rx="1.5"/><path d="M2.5 19h19"/></symbol>
<symbol id="i-desktop" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M9 20h6M12 16v4"/></symbol>
<symbol id="i-monitor" viewBox="0 0 24 24"><rect x="2.5" y="4" width="19" height="13" rx="1.5"/><path d="M8 21h8M12 17v4"/></symbol>
<symbol id="i-phone" viewBox="0 0 24 24"><rect x="7" y="2.5" width="10" height="19" rx="2.2"/><path d="M11 18.5h2"/></symbol>
<symbol id="i-printer" viewBox="0 0 24 24"><path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/></symbol>
<symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2.5 9a14 14 0 0 1 19 0"/><path d="M5.5 12.5a9.5 9.5 0 0 1 13 0"/><path d="M8.7 16a5 5 0 0 1 6.6 0"/><circle cx="12" cy="19.4" r="1" fill="currentColor"/></symbol>
<symbol id="i-camera" viewBox="0 0 24 24"><rect x="2.5" y="7" width="13" height="10" rx="2"/><path d="M15.5 11l6-3.5v9l-6-3.5"/></symbol>
<symbol id="i-cloud" viewBox="0 0 24 24"><path d="M7 18.5a4.2 4.2 0 0 1-.6-8.3A6 6 0 0 1 18 9.8a4.4 4.4 0 0 1-.5 8.7z"/></symbol>
<symbol id="i-store" viewBox="0 0 24 24"><path d="M3.5 9.5 5 4h14l1.5 5.5"/><path d="M3.5 9.5a2.75 2.75 0 0 0 5.5 0 2.75 2.75 0 0 0 6 0 2.75 2.75 0 0 0 5.5 0"/><path d="M5 12.5V20h14v-7.5"/><path d="M10 20v-4.5h4V20"/></symbol>
<symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M10 20v-6h4v6"/></symbol>
<symbol id="i-box" viewBox="0 0 24 24"><path d="M3 7.5 12 3.5l9 4-9 4z"/><path d="M3 7.5v9l9 4 9-4v-9"/><path d="M12 11.5v9"/></symbol>
<symbol id="i-keyboard" viewBox="0 0 24 24"><rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6.5 10h.01M10 10h.01M14 10h.01M17.5 10h.01M7.5 14h9"/></symbol>
<symbol id="i-chat" viewBox="0 0 24 24"><path d="M4 5.5h16v11H9.5L5 20.5v-4H4z"/></symbol>
<symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7.5 8.5 6 8.5-6"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-7-6.2-7-11.2a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.8" r="2.5"/></symbol>
<symbol id="i-call" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v3.5a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></symbol>
<symbol id="i-left" viewBox="0 0 24 24"><path d="m15 5-7 7 7 7"/></symbol>
<symbol id="i-right" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
<symbol id="i-people" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.4"/><path d="M17 14.2c2.4 0 4.2 1.9 4.2 4.3"/></symbol>
</svg>"""


def ic(name, cls=""):
    return '<svg class="i %s" aria-hidden="true"><use href="#i-%s"/></svg>' % (cls, name)


def store_photo():
    """Include the Warsaw storefront photo only if it has been added."""
    for ext in ("jpg", "jpeg", "png", "webp"):
        rel = "assets/img/warsaw-store." + ext
        if os.path.exists(os.path.join(ROOT, rel)):
            return ('<figure class="hero-photo"><img src="%s" alt="The CodeBlue Tech Express storefront '
                    'at 5020 Richmond Rd. in Warsaw, Virginia" width="1200" height="800"></figure>' % rel)
    return ""


# --------------------------------------------------------------- shared parts
def header(active):
    links = "".join(
        '<a href="%s" data-nav="%s"%s>%s</a>' % (href, href.split(".")[0], ' aria-current="page"' if href == active else "", label)
        for label, href in NAV)
    return """<header class="site-header">
  <div class="container">
    <a class="brand" href="index.html" data-nav="index" aria-label="CodeBlue Tech Express, home">
      <img class="brand-logo logo-on-light" src="assets/img/logo-full.png" alt="CodeBlue Technology Express" width="137" height="50">
      <img class="brand-logo logo-on-dark" src="assets/img/logo-white.png" alt="" width="137" height="50" aria-hidden="true">
    </a>
    <nav class="nav-desktop" aria-label="Main">%(links)s</nav>
    <div class="header-actions">
      <a class="header-phone" href="tel:%(tel)s">%(phone)s</a>
      <a class="btn btn-primary" href="in-store.html#book">Book a visit</a>
      <button class="nav-toggle" id="nav-toggle" type="button" aria-expanded="false" aria-controls="nav-mobile" aria-label="Menu">%(menu)s</button>
    </div>
  </div>
  <nav class="nav-mobile" id="nav-mobile" aria-label="Mobile">
    <div class="container">%(links)s<a href="tel:%(tel)s">Call %(phone)s</a></div>
  </nav>
</header>""" % {"links": links, "tel": PHONE_TEL, "phone": PHONE, "menu": ic("menu")}


def footer():
    return """<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <img class="footer-logo" src="assets/img/logo-white.png" alt="CodeBlue Technology Express" width="159" height="58">
        <p class="footer-tagline">%(tag)s.</p>
        <p>CodeBlue Tech Express, a CodeBlue Technology company serving the region since 2003.</p>
      </div>
      <div>
        <h4>Visit</h4>
        <p>%(a1)s<br>%(a2)s</p>
        <p>Appointments Monday to Friday, 11 AM to 4 PM</p>
      </div>
      <div>
        <h4>Talk to us</h4>
        <ul>
          <li><a href="tel:%(tel)s">%(phone)s</a></li>
          <li><a href="mailto:%(email)s">%(email)s</a></li>
        </ul>
      </div>
      <div>
        <h4>Explore</h4>
        <ul>
          <li><a href="index.html">Home</a></li>
          <li><a href="about.html">About</a></li>
          <li><a href="services.html">Services</a></li>
          <li><a href="in-store.html">In-Store</a></li>
          <li><a href="contact.html">Contact</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-base">
      <span>&copy; 2026 CodeBlue Technology Express</span>
      <span class="staff">Staff: <a href="register/index.html">Register</a> &middot; <a href="ratesheet/index.html">Rate Sheet</a></span>
    </div>
  </div>
</footer>""" % {"tag": TAGLINE, "a1": ADDRESS_1, "a2": ADDRESS_2, "tel": PHONE_TEL, "phone": PHONE, "email": EMAIL}


def head(title, desc):
    return """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>%(title)s</title>
<meta name="description" content="%(desc)s">
<meta property="og:title" content="%(title)s">
<meta property="og:description" content="%(desc)s">
<meta property="og:type" content="website">
<meta property="og:image" content="%(site)s/assets/img/og-card.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#00235d">
%(icons)s
%(fonts)s
<link rel="stylesheet" href="assets/styles.css">
</head>""" % {"title": title, "desc": desc, "fonts": FONTS, "site": SITE, "icons": ICON_LINKS}


SCRIPTS = ('<script src="assets/config.js"></script>\n<script src="assets/inventory-sample.js"></script>\n'
           '<script src="assets/app.js"></script>')

# --------------------------------------------------------------- pages
PAGES = {}

PAGES["index"] = dict(
    file="index.html", nav="",
    title="CodeBlue Tech Express | Walk-in and in-home tech help in Warsaw, VA",
    desc="Local technicians for walk-in and in-home help with computers, phones, printing and home networking across the Northern Neck and Middle Peninsula.",
    main="""
<section class="hero">
  <div class="container hero-grid">
    <div>
      %(photo)s
      <p class="eyebrow">Warsaw, Virginia &middot; Northern Neck and Middle Peninsula</p>
      <h1>Technology is what we do, <em>people</em> are why we do it</h1>
      <p class="lead">Bring in a computer, phone, printer or Wi-Fi problem and talk it through with a local technician. We also come to you.</p>
      <div class="hero-ctas">
        <a class="btn btn-primary" href="in-store.html#book">Book a visit %(arrow)s</a>
        <a class="btn btn-ghost" href="in-store.html#shop">See what is in stock</a>
      </div>
      <ul class="hero-facts">
        <li>%(check)s Walk-ins welcome</li>
        <li>%(check)s In-home visits across the region</li>
        <li>%(check)s Part of CodeBlue Technology since 2003</li>
      </ul>
    </div>
    <aside class="ticket" aria-labelledby="ticket-title">
      <div class="ticket-top">
        <p class="ticket-label">Next open times</p>
        <h2 class="ticket-title" id="ticket-title">Book a technician</h2>
        <p class="ticket-sub">One hour, Monday to Friday, 11 AM to 4 PM, in Warsaw.</p>
      </div>
      <div class="ticket-stub">
        <ul class="ticket-slots" id="hero-slots" data-href="in-store.html#book"></ul>
        <a class="text-link" href="in-store.html#book">See the full calendar %(arrow)s</a>
      </div>
    </aside>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">Three ways to get help</p>
      <h2>Real people, close to home</h2>
    </div>
    <div class="three">
      <div>
        <h3>%(store)s Walk in</h3>
        <p>Come see us in Warsaw. Sit down with a technician and explain what is going wrong in your own words.</p>
        <a class="text-link" href="in-store.html#book">Book a time %(arrow)s</a>
      </div>
      <div>
        <h3>%(home)s We come to you</h3>
        <p>In-home support across the Northern Neck and Middle Peninsula, for the problems that are easier to solve where the equipment lives.</p>
        <a class="text-link" href="services.html">Our services %(arrow)s</a>
      </div>
      <div>
        <h3>%(box)s Shop in store</h3>
        <p>New computers, printers and networking gear from brands like HP, Brother and Dell, ready to pick up.</p>
        <a class="text-link" href="in-store.html#shop">See what is in stock %(arrow)s</a>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">What we help with</p>
      <h2>If it is giving you trouble, bring it in</h2>
    </div>
    <ul class="support-list">
      <li>%(laptop)s<div><h3>Desktops, laptops and tablets</h3><p>Slow, broken, or just not working the way it used to.</p></div></li>
      <li>%(printer)s<div><h3>Printing</h3><p>Setup, connection problems, ink and toner.</p></div></li>
      <li>%(phone)s<div><h3>Mobile devices</h3><p>Phones and tablets, setup, moving over to a new one, and trouble with the one you have.</p></div></li>
      <li>%(wifi)s<div><h3>Home networking</h3><p>Wi-Fi that drops, dead zones and new equipment.</p></div></li>
      <li>%(camera)s<div><h3>Security cameras</h3><p>Help with the cameras that watch over your home.</p></div></li>
      <li>%(cloud)s<div><h3>Online services</h3><p>Email, accounts, passwords and the other things that live online.</p></div></li>
    </ul>
    <p style="margin-top:22px"><a class="text-link" href="services.html">All our services %(arrow)s</a></p>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">In stock now</p>
      <h2>On the shelf in Warsaw</h2>
    </div>
    <div class="demo-note" data-for="inventory">%(info)s<span>Sample items for design review. Live prices and quantities will come from the store register once it is connected.</span></div>
    <div class="products" id="home-featured"></div>
    <p style="margin-top:24px"><a class="text-link" href="in-store.html#shop">Browse everything in stock %(arrow)s</a></p>
  </div>
</section>

<section class="section">
  <div class="container split">
    <div>
      <p class="eyebrow">Who we serve</p>
      <h2>Homes and small offices across the region</h2>
    </div>
    <div>
      <p class="lead">Our technicians live and work here. If you are in the Northern Neck or the Middle Peninsula, you are in our neighborhood.</p>
      <ul class="chips" aria-label="Towns we serve">
        <li>Warsaw</li><li>Kilmarnock</li><li>Montross</li><li>Colonial Beach</li><li>Heathsville</li>
        <li>Irvington</li><li>White Stone</li><li>Callao</li><li>Lottsburg</li><li>Reedville</li>
        <li>Tappahannock</li><li>Urbanna</li><li>Saluda</li><li>Gloucester</li><li>Mathews</li><li>West Point</li>
      </ul>
    </div>
  </div>
</section>

<section class="tagline-band">
  <div class="container">
    <p class="big">%(tag)s.</p>
    <p class="small">Your technician is a neighbor, trained for today's toughest technical challenges and backed by the CodeBlue Technology support team and more than two decades of experience.</p>
  </div>
</section>

<section class="section">
  <div class="container cta-band">
    <h2>Not sure where to start?</h2>
    <div class="hero-ctas">
      <a class="btn btn-primary" href="tel:%(tel)s">%(call)s Call %(phonenum)s</a>
      <a class="btn btn-ghost" href="contact.html">Send us a message</a>
    </div>
  </div>
</section>
""")

PAGES["about"] = dict(
    file="about.html", nav="about.html",
    title="About | CodeBlue Tech Express",
    desc="CodeBlue Technology has served the Northern Neck and Middle Peninsula since 2003. CodeBlue Tech Express brings in-person tech support to local residents.",
    main="""
<section class="page-head">
  <div class="container">
    <p class="eyebrow">About</p>
    <h1>Local technicians, backed by CodeBlue Technology</h1>
    <p class="lead">CodeBlue Tech Express is the in-person side of our support effort, serving the Northern Neck and Middle Peninsula from Warsaw, Virginia.</p>
  </div>
</section>

<section class="section-tight">
  <div class="container">
    <ol class="timeline">
      <li>
        <span class="year">2003</span>
        <div><h3>CodeBlue Technology is founded</h3>
        <p>We have served the Mid-Atlantic region of the US since that time, and the Northern Neck and Middle Peninsula since our founding.</p></div>
      </li>
      <li>
        <span class="year">2017</span>
        <div><h3>A second location opens in Warsaw</h3>
        <p>We added a business office in Warsaw, Virginia to extend a permanent reach and to be present and available for all area businesses.</p></div>
      </li>
      <li>
        <span class="year">2026</span>
        <div><h3>CodeBlue Tech Express joins the effort</h3>
        <p>Express was added to serve the local population, because the need for in-person technical support has grown.</p></div>
      </li>
    </ol>
  </div>
</section>

<section class="section section-alt">
  <div class="container split">
    <div>
      <p class="eyebrow">Who you will meet</p>
      <h2>Technicians who live here</h2>
    </div>
    <div class="prose">
      <p>Our technicians are area locals, trained to handle today's toughest technical challenges. They are supported by CodeBlue Technology's long history of support staff and armed with the depth and breadth of their expertise.</p>
      <p>The store was designed so residents can walk in and talk directly with a technician about challenges with their computer, cell phone, online services, printing and networking. As technology grows more complex and more important, we want to be a local, friendly resource for everyone.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <p class="eyebrow">Our mission</p>
    <blockquote class="pullquote" style="margin-bottom:18px">Be present and positively memorable in the delivery of our technical support, while fostering great relationships with our community.</blockquote>
    <p style="color:var(--ink-soft)">We are local in both support and staff.</p>
  </div>
</section>

<section class="tagline-band">
  <div class="container">
    <p class="big">%(tag)s.</p>
  </div>
</section>

<section class="section">
  <div class="container cta-band">
    <h2>Come say hello</h2>
    <div class="hero-ctas">
      <a class="btn btn-primary" href="in-store.html#book">Book a visit %(arrow)s</a>
      <a class="btn btn-ghost" href="contact.html">Contact us</a>
    </div>
  </div>
</section>
""")

PAGES["services"] = dict(
    file="services.html", nav="services.html",
    title="Services | CodeBlue Tech Express",
    desc="Walk-in consultations, in-home support, new home technology and small business equipment from CodeBlue Tech Express in Warsaw, VA.",
    main="""
<section class="page-head">
  <div class="container">
    <p class="eyebrow">Services</p>
    <h1>Technical support for your home and office</h1>
    <p class="lead">Desktops, laptops and tablets. Printing. Mobile devices. Home networking, security cameras and more. We solve the problem, and we speak up for you when technology gets in the way.</p>
  </div>
</section>

<section class="container">
  <div class="service-row">
    <div><span class="tag">%(store)s At the counter</span><h2>Walk-in help from a local technician</h2></div>
    <div>
      <p>Bring your device to our Warsaw location for a consultation and direct access to technical help. Explain the problem in your own words and talk it through face to face.</p>
      <a class="text-link" href="in-store.html#book">Book a time %(arrow)s</a>
    </div>
  </div>
  <div class="service-row">
    <div><span class="tag">%(home)s At your door</span><h2>In-home support across our region</h2></div>
    <div>
      <p>We offer in-home support throughout the Northern Neck and the Middle Peninsula. Our friendly technicians are available to solve and advocate for the technology issues people experience, on and off line.</p>
      <a class="text-link" href="contact.html">Ask about a home visit %(arrow)s</a>
    </div>
  </div>
  <div class="service-row">
    <div><span class="tag">%(box)s Ready to pick up</span><h2>New technology, chosen with you</h2></div>
    <div>
      <p>We carry new home technology from reputable manufacturers like HP, Brother, Dell and more, in store and ready to pick up.</p>
      <p>Your technician can walk you through the decisions that matter, such as how you will use it, whether it works with what you already own, and how to keep it secure, so your next piece of technology suits your needs.</p>
      <a class="text-link" href="in-store.html#shop">See what is in stock %(arrow)s</a>
    </div>
  </div>
  <div class="service-row">
    <div><span class="tag">%(wifi)s For work</span><h2>Small and medium business technology</h2></div>
    <div>
      <p>We also offer technology for small and medium businesses, such as access points, switching, firewalls and more. Get back up and running quickly with CodeBlue Tech Express.</p>
      <a class="text-link" href="contact.html">Talk to us about your office %(arrow)s</a>
    </div>
  </div>
</section>

<section class="section section-alt">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">What we support</p>
      <h2>The technology in your day</h2>
    </div>
    <ul class="support-list">
      <li>%(laptop)s<div><h3>Desktops, laptops and tablets</h3><p>Repairs, slowdowns, setup and upgrades.</p></div></li>
      <li>%(printer)s<div><h3>Printing</h3><p>Setup, connection problems, ink and toner.</p></div></li>
      <li>%(phone)s<div><h3>Mobile devices</h3><p>Phones and tablets, setup and trouble.</p></div></li>
      <li>%(wifi)s<div><h3>Home networking</h3><p>Wi-Fi coverage, routers and connections that keep dropping.</p></div></li>
      <li>%(camera)s<div><h3>Security cameras</h3><p>Help with the cameras that watch over your home.</p></div></li>
      <li>%(cloud)s<div><h3>Online services</h3><p>Email, accounts, passwords and other services that live online.</p></div></li>
    </ul>
    <p style="margin-top:22px;color:var(--ink-soft)">And more. If you are not sure we cover it, ask.</p>
  </div>
</section>

<section class="section">
  <div class="container cta-band">
    <h2>Tell us what is going on</h2>
    <div class="hero-ctas">
      <a class="btn btn-primary" href="in-store.html#book">Book a visit %(arrow)s</a>
      <a class="btn btn-ghost" href="contact.html">Contact us</a>
    </div>
  </div>
</section>
""")

PAGES["in-store"] = dict(
    file="in-store.html", nav="in-store.html",
    title="In-Store | CodeBlue Tech Express",
    desc="See what is in stock at CodeBlue Tech Express in Warsaw, VA, and book a one-hour appointment with a technician.",
    main="""
<section class="page-head">
  <div class="container">
    <p class="eyebrow">In-Store &middot; Warsaw, VA</p>
    <h1>See what is on the shelf, then book a technician</h1>
    <p class="lead">Browse the technology we have in stock, then reserve time to talk with a technician in the store.</p>
    <div class="subnav">
      <a class="btn btn-ghost" href="#shop">Shop what is in stock</a>
      <a class="btn btn-primary" href="#book">Book a technician</a>
    </div>
  </div>
</section>

<section class="section-tight" id="shop">
  <div class="container">
    <div class="demo-note" data-for="inventory">%(info)s<span>Sample items for design review. Live prices and quantities will come from the store register once it is connected.</span></div>
    <div class="shop-controls">
      <div class="shop-row">
        <div class="field-search">
          %(search)s
          <input type="search" id="shop-search" placeholder="Search laptops, printers, routers" aria-label="Search the store">
        </div>
        <label class="check"><input type="checkbox" id="shop-instock" checked> In stock only</label>
        <label class="sr" for="shop-sort" style="position:absolute;left:-9999px">Sort</label>
        <select id="shop-sort" style="width:auto">
          <option value="featured">Featured</option>
          <option value="low">Price: low to high</option>
          <option value="high">Price: high to low</option>
          <option value="name">Name</option>
        </select>
      </div>
      <div class="cat-chips" id="shop-cats" role="group" aria-label="Categories"></div>
    </div>
    <p class="result-count" id="shop-count" aria-live="polite"></p>
    <div class="products" id="shop-grid"></div>
  </div>
</section>

<section class="section section-alt" id="book">
  <div class="container">
    <div class="section-head">
      <p class="eyebrow">Book a technician</p>
      <h2>Reserve an hour, get a real conversation</h2>
      <p>Appointments run one hour, Monday to Friday, 11 AM to 4 PM Eastern. Tell us what you need help with and our team will confirm your visit.</p>
    </div>
    <div class="demo-note" data-for="booking">%(info)s<span>Demo mode: some times are shown as booked for illustration, and requests are not sent to the team yet.</span></div>
    <div class="book-grid">
      <div class="panel">
        <div class="week-nav">
          <button type="button" class="icon-btn" id="bk-prev" aria-label="Previous week">%(left)s</button>
          <h3 class="week-title" id="bk-week-title" aria-live="polite"></h3>
          <button type="button" class="icon-btn" id="bk-next" aria-label="Next week">%(right)s</button>
        </div>
        <div class="day-tabs" id="bk-tabs" role="tablist" aria-label="Day of the week"></div>
        <div class="cal" id="bk-cal"></div>
        <div class="legend">
          <span><i></i> Open</span><span><i class="sel"></i> Your pick</span><span><i class="off"></i> Unavailable</span>
        </div>
      </div>
      <div class="panel">
        <h3>Your visit</h3>
        <p class="picked none" id="bk-picked"></p>
        <form class="form" id="bk-form" novalidate>
          <div class="field">
            <label for="bk-reason">What do you need help with?</label>
            <textarea id="bk-reason" name="reason" rows="3" placeholder="My laptop is slow and keeps freezing"></textarea>
            <span class="err">Tell us a little about the problem.</span>
          </div>
          <div class="field">
            <label for="bk-name">Your name</label>
            <input type="text" id="bk-name" name="name" autocomplete="name">
            <span class="err">Please enter your name.</span>
          </div>
          <div class="field">
            <label for="bk-address">Address</label>
            <input type="text" id="bk-address" name="address" autocomplete="street-address">
            <span class="hint">Street, town and ZIP.</span>
            <span class="err">Please enter your address.</span>
          </div>
          <div class="field">
            <label for="bk-phone">Phone number</label>
            <input type="tel" id="bk-phone" name="phone" autocomplete="tel">
            <span class="err">Please enter a phone number with area code.</span>
          </div>
          <div class="hp" aria-hidden="true"><label for="bk-company">Company</label><input type="text" id="bk-company" tabindex="-1" autocomplete="off"></div>
          <button class="btn btn-primary btn-block" type="submit" id="bk-submit">Request this time</button>
          <p class="form-status" id="bk-status" role="status" aria-live="polite"></p>
        </form>
        <div class="sent" id="bk-sent" hidden tabindex="-1">
          <h3>Request received</h3>
          <p>We asked for:</p>
          <p class="when" id="bk-sent-when"></p>
          <p>Our team will confirm your appointment by phone.</p>
          <p class="demo" id="bk-sent-demo" hidden>Demo mode: nothing was actually sent.</p>
          <button type="button" class="btn" id="bk-again">Book another time</button>
        </div>
      </div>
    </div>
    <div class="soon">%(people)s<p style="margin:0"><strong>Customer portal coming soon.</strong> Check in, see your history and follow the status of your cases from your phone or computer.</p></div>
  </div>
</section>
""")

PAGES["contact"] = dict(
    file="contact.html", nav="contact.html",
    title="Contact | CodeBlue Tech Express",
    desc="Call (804) 456-4500, email hello@codebluetechnology.com or stop by 5020 Richmond Rd. Suite B in Warsaw, VA.",
    main="""
<section class="page-head">
  <div class="container">
    <p class="eyebrow">Contact</p>
    <h1>Let us hear from you</h1>
    <p class="lead">Questions, introductions, or a problem you would like to talk through. Reach out any way you like.</p>
  </div>
</section>

<section class="section-tight" style="padding-bottom:72px">
  <div class="container contact-grid">
    <div>
      <ul class="contact-list">
        <li>%(call)s<div><span class="k">Phone</span><a href="tel:%(tel)s">%(phonenum)s</a></div></li>
        <li>%(mail)s<div><span class="k">Email</span><a href="mailto:%(email)s">%(email)s</a></div></li>
        <li>%(pin)s<div><span class="k">Visit us</span><span class="v">%(a1)s<br>%(a2)s</span></div></li>
        <li>%(clock)s<div><span class="k">Appointments</span><span class="v">Mon to Fri, 11 AM to 4 PM</span></div></li>
      </ul>
      <a class="btn btn-ghost" href="in-store.html#book">Book a visit %(arrow)s</a>
    </div>
    <div class="panel">
      <h2 style="font-size:1.6rem">Send a message</h2>
      <div class="demo-note" data-for="contact">%(info)s<span>Demo mode: this form does not send yet.</span></div>
      <form class="form" id="ct-form" novalidate>
        <div class="form-2">
          <div class="field"><label for="ct-first">First name</label><input type="text" id="ct-first" autocomplete="given-name"><span class="err">Please enter your first name.</span></div>
          <div class="field"><label for="ct-last">Last name</label><input type="text" id="ct-last" autocomplete="family-name"><span class="err">Please enter your last name.</span></div>
        </div>
        <div class="field"><label for="ct-business">Business name <span class="opt">(optional)</span></label><input type="text" id="ct-business" autocomplete="organization"></div>
        <div class="form-2">
          <div class="field"><label for="ct-phone">Phone number</label><input type="tel" id="ct-phone" autocomplete="tel"><span class="err">Please enter a phone number with area code.</span></div>
          <div class="field"><label for="ct-email">Email address</label><input type="email" id="ct-email" autocomplete="email"><span class="err">Please enter a valid email address.</span></div>
        </div>
        <div class="field"><label for="ct-reason">Reason for contact</label><textarea id="ct-reason" rows="5"></textarea><span class="err">Tell us how we can help.</span></div>
        <div class="hp" aria-hidden="true"><label for="ct-company">Company</label><input type="text" id="ct-company" tabindex="-1" autocomplete="off"></div>
        <button class="btn btn-primary btn-block" type="submit" id="ct-submit">Send message</button>
        <p class="form-status" id="ct-status" role="status" aria-live="polite"></p>
      </form>
      <div class="sent" id="ct-sent" hidden tabindex="-1">
        <h3>Thank you</h3>
        <p>We received your message and will get back to you soon.</p>
        <p class="demo" id="ct-sent-demo" hidden>Demo mode: nothing was actually sent.</p>
        <button type="button" class="btn" id="ct-again">Send another message</button>
      </div>
    </div>
  </div>
</section>
""")

ICONS = dict(arrow=ic("arrow"), check=ic("check"), store=ic("store", "i-lg"), home=ic("home", "i-lg"), box=ic("box", "i-lg"),
             laptop=ic("laptop"), printer=ic("printer"), phone=ic("phone"), wifi=ic("wifi"), camera=ic("camera"), cloud=ic("cloud"),
             info=ic("info"), search=ic("search"), left=ic("left"), right=ic("right"), people=ic("people", "i-lg"),
             call=ic("call"), mail=ic("mail"), pin=ic("pin"), clock=ic("clock"), tag=TAGLINE, tel=PHONE_TEL)
ICONS["phonenum"] = PHONE


def render_page(key):
    page = PAGES[key]
    html = head(page["title"], page["desc"]) + "\n<body>\n" + SPRITE + "\n" + header(page["nav"]) + \
        '\n<main id="main">' + page_body(page) + "</main>\n" + footer() + "\n" + SCRIPTS + "\n</body>\n</html>\n"
    return html


def page_body(page):
    vals = dict(ICONS)
    vals.update(photo=store_photo(), email=EMAIL, a1=ADDRESS_1, a2=ADDRESS_2)
    return page["main"] % vals


def write(path, text):
    full = os.path.join(ROOT, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, "w", encoding="utf-8", newline="\n") as f:
        f.write(text)


def data_uri(rel):
    full = os.path.join(ROOT, rel)
    mime = mimetypes.guess_type(full)[0] or "application/octet-stream"
    with open(full, "rb") as f:
        return "data:%s;base64,%s" % (mime, base64.b64encode(f.read()).decode())


def build_preview():
    """One self-contained page: all five pages as hash-routed sections."""
    css = open(os.path.join(ROOT, "assets/styles.css"), encoding="utf-8").read()
    js = "\n".join(open(os.path.join(ROOT, "assets", n), encoding="utf-8").read()
                   for n in ("config.js", "inventory-sample.js", "app.js"))
    routes = [("index", "home"), ("about", "about"), ("services", "services"), ("in-store", "in-store"), ("contact", "contact")]
    sections = []
    for key, route in routes:
        sections.append('<div class="route" data-route="%s" hidden>%s</div>' % (route, page_body(PAGES[key])))
    inner = "\n".join(sections)
    hdr = header("")
    ftr = footer()
    ftr = re.sub(r'<span class="staff">.*?</span>', "", ftr, flags=re.S)

    def hash_links(html):
        html = html.replace("in-store.html#book", "#book").replace("in-store.html#shop", "#shop-top")
        html = html.replace('href="index.html"', 'href="#home"')
        for _, href in NAV:
            html = html.replace('href="%s"' % href, 'href="#%s"' % href.split(".")[0])
        return html
    inner, hdr, ftr = hash_links(inner), hash_links(hdr), hash_links(ftr)
    # in-page subnav anchors inside the In-Store route must keep working as routes too
    inner = inner.replace('href="#shop"', 'href="#shop-top"').replace('data-href="#book"', 'data-href="#book"')
    body = SPRITE + hdr + '<main id="main">' + inner + "</main>" + ftr
    body = re.sub(r'(?:\.\./)?assets/img/[A-Za-z0-9_.-]+', lambda m: data_uri(m.group(0)), body)

    router = """
(function () {
  var known = ['home', 'about', 'services', 'in-store', 'contact'];
  function go() {
    var h = (location.hash || '#home').slice(1), target = h, anchor = null;
    if (h === 'book') { target = 'in-store'; anchor = 'book'; }
    if (h === 'shop-top') { target = 'in-store'; anchor = 'shop'; }
    if (known.indexOf(target) < 0) { target = 'home'; }
    Array.prototype.forEach.call(document.querySelectorAll('.route'), function (r) { r.hidden = r.getAttribute('data-route') !== target; });
    Array.prototype.forEach.call(document.querySelectorAll('[data-nav]'), function (a) {
      var n = a.getAttribute('data-nav'); n = n === 'index' ? 'home' : n;
      if (n === target && a.closest('.nav-desktop, .nav-mobile')) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
    });
    var nav = document.getElementById('nav-mobile'), tg = document.getElementById('nav-toggle');
    if (nav) { nav.classList.remove('open'); } if (tg) { tg.setAttribute('aria-expanded', 'false'); }
    var el = anchor ? document.getElementById(anchor) : null;
    if (el) { el.scrollIntoView(); } else { window.scrollTo(0, 0); }
  }
  window.addEventListener('hashchange', go); go();
})();
"""
    fonts = FONTS.replace("&amp;", "&")
    fav = '<link rel="icon" type="image/png" href="%s">' % data_uri("assets/img/favicon-32.png")
    page = ('<title>CodeBlue Tech Express</title>\n<meta name="claude:viewport-fit-sides" content="cover">\n%s\n%s\n<style>\n%s\n</style>\n%s\n<script>\n%s\n%s\n</script>\n'
            % (fav, fonts, css, body, js, router))
    write("dist/preview.html", page)


def build_sitemap():
    urls = ["index.html"] + [h for _, h in NAV]
    items = "".join("  <url><loc>%s/%s</loc></url>\n" % (SITE, "" if u == "index.html" else u) for u in urls)
    write("sitemap.xml", '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n%s</urlset>\n' % items)
    write("robots.txt", "User-agent: *\nAllow: /\nDisallow: /register/\nDisallow: /ratesheet/\nDisallow: /auth/\nSitemap: %s/sitemap.xml\n" % SITE)


def main():
    for key in PAGES:
        write(PAGES[key]["file"], render_page(key))
        print("wrote", PAGES[key]["file"])
    build_sitemap()
    if "--preview" in sys.argv:
        build_preview()
        print("wrote dist/preview.html")


if __name__ == "__main__":
    main()
