# Schedule Manager backend: one-time setup

What this is: a small PHP service in `api/` that stores visit requests, lets staff
confirm, deny, ask or reschedule them in `/manage/`, and sends the customer emails
through Microsoft 365. It deploys with the site (`.cpanel.yml` copies `api/`).
Until the steps below are done it answers "not set up yet", the booking form tells
customers to call, and nothing is stored.

## What lives where

| Thing | Location | Public? |
|---|---|---|
| Code | `public_html/api/` (from this repo) | Endpoints only |
| Secrets | `~/te-private/config.php` | No, outside `public_html` |
| Data | `~/te-private/data/schedule.sqlite` | No, outside `public_html` |

`~` is the account home folder (`/home1/dxgtdzmy`), the folder that contains `public_html`.

## 1. Check the host

In cPanel, MultiPHP Manager: use PHP 8.1 or newer. In MultiPHP INI Editor or
"Select PHP Version", make sure the `pdo_sqlite` and `curl` extensions are on.

## 2. Register the app in Microsoft Entra

Entra admin center (entra.microsoft.com) > App registrations > New registration.

1. Name: `CodeBlue Tech Express Schedule Manager`. Supported account types: this
   organization only (single tenant).
2. Redirect URI, platform Web: `https://codebluetechexpress.com/api/auth/callback.php`
3. On the Overview page, copy the **Directory (tenant) ID** and **Application (client) ID**.
4. Certificates & secrets > New client secret. Copy the **Value** now (it is shown once).
   Put the expiry date in a calendar; sign-in and email stop working when it expires.
5. API permissions > Add a permission > Microsoft Graph > **Application permissions** >
   `Mail.Send`. Then "Grant admin consent". (`openid`, `profile`, `email` are already there.)

## 3. Limit what the app can mail as

`Mail.Send` as an application can send as ANY mailbox unless you restrict it. Have
your Microsoft 365 / Exchange admin limit this app to the one sender mailbox
(Exchange Online "application access policy", or RBAC for Applications). Do this
before going live.

## 4. Create the config file

In cPanel File Manager, open the home folder (not `public_html`), create a folder
`te-private`, and inside it create `config.php` using `docs/te-private-config.sample.php`
as the template. Fill in the IDs, the secret, the sender mailbox and the staff list.
Set `config.php` permissions to 600.

## 5. Deploy and test

1. Push, then in cPanel Git Version Control: Update from Remote, Deploy HEAD Commit.
2. Open `https://codebluetechexpress.com/manage/` and press **Sign in with Microsoft**.
   You should land on the console showing a **LIVE** badge.
3. In a private window, book a time on the In-Store page. The request should appear in
   the console within a minute, and `notify_to` should get an email.
4. Confirm it. The customer (use your own address) should get the email, and it should
   be in the sender mailbox's Sent Items.

## Behaviour worth knowing

- A decision is saved only after Microsoft 365 accepts the email. If mail fails, the
  console shows the error and nothing changes.
- The public calendar shows an hour as taken when its seats (default 2, set per day in
  the Capacity tab) are filled by pending, asked or confirmed requests.
- Customers can only request; staff decide. The recipient of every email is the address
  on the request, never one typed into the console.
- Backups: copy `~/te-private/data/schedule.sqlite` (cPanel Backup includes it).

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "The scheduling service is not set up yet" | `~/te-private/config.php` missing or not valid PHP |
| Sign-in page says "could not be verified" | Wrong tenant or client ID in `config.php` |
| Sign-in says "not on the list" | Add the address to `staff_emails` (lowercase) |
| "Microsoft 365 refused the email (403)" | Admin consent not granted, or the access policy excludes the sender mailbox |
| "Microsoft sign-in for mail failed" | Wrong or expired client secret |

## Tests

`python3 tests/run_tests.py` starts the API with a mock Microsoft server and checks
booking rules, capacity, staff actions, email handling and sign-in hardening.
Needs `php` with `curl` and `pdo_sqlite`.
