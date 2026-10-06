# CodeBlue Technology Express (codebluetechexpress.com)

Customer-facing site and staff apps for the CodeBlue Technology Express retail
store.

## How it fits together

There is **one central install** of the server side: the SolutionsHub repo
(`Mwbergamo/SolutionsHub`), deployed to `portal.codebluetechnology.com`. It owns
the SQLite databases, the ConnectWise and Alt Pay credentials, and the
Microsoft 365 sign-in.

This repo holds only what is specific to the Express domain:

| In this repo (committed) | Linked from the central install (not committed) |
|---|---|
| `index.html` and the customer-facing site | `register/api/` |
| `register/` front end (HTML/JS/CSS) | `ratesheet/api/` |
| `ratesheet/` front end (HTML/JS/CSS) | `auth/`, `login.php`, `logout.php` |

The Register and Rate Sheet front ends are copies of the ones in SolutionsHub.
They call their API with relative paths (`api/...`), so on this domain those
calls land on the linked central API. Both stores therefore share one catalog,
one inventory count, one sales/returns history and one set of Alt Pay statuses.

> **Keeping the copies in sync:** a fix made to `register/` or `ratesheet/`
> front-end files in SolutionsHub has to be copied here too (and vice versa).
> The API side never needs this, because it is linked, not copied.

## One-time setup

### 1. Entra ID (Azure) -- add the new sign-in return address
In the existing sign-in app registration (the one SolutionsHub already uses):
Authentication -> Web -> Redirect URIs -> add

- `https://codebluetechexpress.com/auth/callback.php`
- `https://www.codebluetechexpress.com/auth/callback.php` (only if `www` will be used)

### 2. Central `auth-config.php` (on the server, in the SolutionsHub folder)
Add a `redirect_uris` map next to the existing `redirect_uri` (see
`auth-config.sample.php` in SolutionsHub for the exact shape). Hosts not listed
keep using the original `redirect_uri`, so the portal is unaffected.

### 3. Deploy the central code change first
SolutionsHub's `login.php`, `auth/callback.php` and `auth/session.php` need the
small change that picks the redirect address by domain. Commit, push and pull
that on cPanel **before** testing sign-in on this domain.

### 4. Deploy this repo
In cPanel -> Git Version Control, create a repository for this GitHub repo with
its deployment path set to the document root of codebluetechexpress.com
(Domains -> the domain's "Document Root").

### 5. Link the central server side
In cPanel -> Terminal, from this site's document root:

```
bash deploy/link-central.sh /home/<cpanel-user>/public_html/portal
bash deploy/link-central.sh /home/<cpanel-user>/public_html/portal --check
```

(Use the real path of the SolutionsHub folder.) It never deletes anything and is
safe to re-run.

### 6. Test
- `https://codebluetechexpress.com/register/` -> redirects to Microsoft sign-in,
  returns to the Register, catalog loads.
- Make a test sale on one domain; confirm it shows in sale history on the other.
- `https://codebluetechexpress.com/ratesheet/` -> same sign-in, requests list.

## Known limits (for now)
- Customer emails sent by the Rate Sheet (signup links, logo) still use
  `portal.codebluetechnology.com` addresses, hard-coded in
  `ratesheet/api/requests.php` and `register/api/signup-email.php`.
- Staff sign-in is limited to `@codebluetechnology.com` accounts.
- Apps are branded "CodeBlue Technology" until the Express branding pass.

## Not built yet
Customer-facing home page, in-stock view (read-only endpoint on the central
register API), services and promotions, customer service-request login
(ConnectWise), and in-store scheduling.
