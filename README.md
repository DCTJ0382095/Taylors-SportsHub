# Taylor's University SportsHub

SportsHub is a PHP web application for campus sports sessions. Students can discover sessions, reserve places, submit payment receipts, and track registrations. Club managers can publish sessions, review receipts, and record external refunds.

SportsHub covers club sessions only, with individual sign-up: there are no tournaments or team registrations.

The application is configured for Taylor's University student email addresses. It includes demonstration data and prototype features; it is not a payment gateway.

## Features

### Students

- Browse clubs and published sessions without signing in.
- Search and filter sessions by keyword, sport, date, and price.
- Create a student account, sign in, and manage profile details.
- Join free sessions immediately or reserve a paid place.
- Pay the member price on a club's sessions while you're a member of that club.
- Upload payment evidence and view receipt decisions and submission history.
- Follow registration status, deadlines, cancellations, and refund progress in My Activities.
- Request cancellation before a session starts.

### Club managers and administrators

- Manage sessions and participants within authorised clubs.
- Create drafts, publish sessions, close signup, and cancel sessions with a reason.
- Upload a session cover picture or pick a built-in one, and set an optional member price.
- Review payment evidence and reject receipts with an explanation.
- Record partial or full refunds made outside the application.
- Access protected evidence and retained receipt history.

Manager permissions come from club appointments, not from an email address or club membership. Seeded administrators have cross-club authority. Users cannot assign themselves these roles through signup.

## Registration and payments

Free signup confirms immediately. A paid reservation holds one place until the earlier of 24 hours after reservation or session start. The participant must pay externally and upload evidence before that deadline.

A timely receipt holds the place during review, including after session start. A manager checks external settlement before approving or rejecting it. Rejection opens a new resubmission window capped at session start.

| Status | Holds a place | Meaning |
| --- | --- | --- |
| `awaiting_payment` | Yes, until expiry | Paid reservation awaiting evidence. |
| `awaiting_approval` | Yes | Evidence awaits a manager's decision. |
| `payment_rejected` | Yes, until expiry | Corrected evidence may be submitted within the remaining window. |
| `confirmed` | Yes | Free signup or an approved paid registration. |
| `cancellation_pending` | Yes | Cancellation awaits a full recorded external refund. |
| `expired` | No | The payment or resubmission deadline passed. |
| `cancelled` | No | Cancellation and any required refund recording are complete. |

Student cancellation closes at session start. Free and unpaid places can be released immediately; approved paid places require a full external refund. Cancellation while a receipt is under review requires a settlement decision first.

SportsHub records payment and refund evidence. It does not transfer money or verify bank settlement automatically. Registration confirmation does not record attendance.

## Requirements

This guide assumes you use **XAMPP or MAMP** for local development. Use the package's Apache server, PHP 8.2 or later, and MySQL/MariaDB. For MAMP, select Apache rather than Nginx so the included `.htaccess` rules apply.

Ensure `pdo_mysql`, `mbstring`, and `fileinfo` are enabled in the PHP configuration used by your package. The application also needs a modern browser and write permission for `assets/uploads/` when testing financial uploads.

No separate PHP/database installation, Composer, npm, or frontend build step is needed to run the application. Node.js and npm are only needed for the optional browser test suites.

## Local setup with XAMPP or MAMP

### 1. Start the local servers

| Package | What to do |
| --- | --- |
| XAMPP | Open the XAMPP Control Panel or manager application and start **Apache** and **MySQL**. |
| MAMP | Open MAMP, select **Apache**, choose PHP 8.2 or later, and start the web and database servers. Note the web-server and MySQL ports shown in its settings. |

### 2. Place the project in the document root

Clone or download this repository into a folder named `sportshub` inside your package's document root (`htdocs`). Common examples are:

| Installation | Example project folder |
| --- | --- |
| XAMPP on Windows | `C:/xampp/htdocs/sportshub/` |
| MAMP on macOS | `/Applications/MAMP/htdocs/sportshub/` |
| Other XAMPP/MAMP installations | `<configured-document-root>/sportshub/` |

If you changed the installation location or document root, use the folder configured in your package instead. Avoid placing the project inside a second nested copy of the downloaded repository.

| Package settings | Example application URL |
| --- | --- |
| XAMPP with Apache on port 80 | [http://localhost/sportshub/](http://localhost/sportshub/) |
| MAMP with Apache on its default port 8888 | [http://localhost:8888/sportshub/](http://localhost:8888/sportshub/) |

Use the ports actually configured in your installation. See the [XAMPP Windows guide](https://www.apachefriends.org/faq_windows) and [MAMP port settings](https://documentation.mamp.info/en/MAMP-Mac/Reference/Settings/Ports/) for package-specific instructions.

### 3. Configure the database connection

Open [config/db.php](config/db.php) and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `DB_PORT` for your local database. Use the credentials configured in your XAMPP/MAMP installation and a dedicated development database name.

XAMPP commonly uses MySQL port `3306`; MAMP's default MySQL port is `8889`. Confirm the actual port in your package settings. The web-server port in the browser URL is separate from this database port.

The current connection does not read `DB_PORT`. To use the configured port, including MAMP's default, replace the `$dsn` assignment inside `get_db_connection()` with:

```php
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
```

Initial setup needs permission to create the database and tables and load demonstration records. Existing installations also need the permissions required by the receipt-history migration. No manual database creation or SQL import is needed when these permissions are available.

### 4. Set the error-page paths

Normal application links detect the installation prefix automatically. For a project folder named `sportshub`, update [.htaccess](.htaccess) to use:

```apache
ErrorDocument 403 /sportshub/403.php
ErrorDocument 404 /sportshub/404.php
ErrorDocument 500 /sportshub/500.php
```

If you choose a different folder name, change `/sportshub/` in these three paths to match it. Do not include the hostname or port in an `ErrorDocument` path.

Keep [assets/uploads/.htaccess](assets/uploads/.htaccess) in place and ensure Apache honours it. It blocks direct access to financial uploads; authorised access is provided through [evidence.php](evidence.php).

### 5. Open SportsHub

Visit the application URL for your package from step 2. On first visit, [config/setup.php](config/setup.php) creates the schema, imports demonstration data, and applies the receipt-history migration.

Do not manually rerun [database/seed.sql](database/seed.sql) against data you want to keep: it clears the seeded application tables.

Create a student account to explore the student journey. The configured signup domain is `@sd.taylors.edu.my`; this format check does not verify university identity. Local manager and administrator demonstration accounts are defined in the seed file. This guide intentionally does not reproduce account identities or credentials.

Use demonstration accounts and payment material only in an isolated development environment. Replace them and configure real operating procedures before public deployment.

## Using the application

| User | Typical workflow |
| --- | --- |
| Visitor | Open Explore Sessions or Sports Clubs, then view a session's details and availability. |
| Student | Sign up or sign in, join or reserve a session, submit evidence if required, and check My Activities for the next step. |
| Club manager | Open Manager Workspace, create or select a session, inspect participants, review receipts, and record external refunds where required. |
| Administrator | Use the same protected manager operations with cross-club authority. There is no separate general-purpose admin dashboard. |

Published session terms remain fixed, including title, club, sport, venue, date, times, price, and capacity. Managers may update descriptions and covers. Changed schedules or prices require cancellation and a replacement session with fresh signup.

Managers cannot approve their own payment or record their own refund; another authorised manager or administrator must act.

## Clubs and committees

SportsHub is for Taylor's University students only (`@sd.taylors.edu.my` accounts).

**Creating a club (administrator).** Clubs are created by the SportsHub administrator directly in the database, together with the club's first president:

    INSERT INTO clubs (name, slug, description, sport_type)
    VALUES ('Taylor''s Tennis Club', 'taylors-tennis-club', 'Weekly social and competitive tennis.', 'Tennis');

    INSERT INTO club_managers (club_id, user_id, appointed_by, role, can_manage_sessions, can_manage_signups, can_edit_club)
    VALUES (LAST_INSERT_ID(), (SELECT id FROM users WHERE email = 'student@sd.taylors.edu.my'), 1, 'president', 1, 1, 1);

A new club starts with no payment details. Before it can run sessions or paid memberships, its president must add the club's DuitNow QR and bank details in **Manager Workspace → Settings → Payment details**.

**Running a club (committee).** After that, the club is run entirely by its committee:

| Role | Can do |
| --- | --- |
| President (one per club) | Everything below, plus: add/remove committee members, set their permissions, change payment details, hand over the presidency |
| Committee member | Only what the president ticks: **Manage sessions**, **Manage sign-ups and payments**, **Edit club profile** |
| Administrator | Full control of every club |

A president cannot remove themselves; they must hand the presidency to another committee member first.

**Demo committees:** Alex Wong is president of Badminton, with Liam Tan (sign-ups and payments only) and Sara Lim (sessions only) on its committee. Chloe Lee is president of both Basketball and Pickleball; Pickleball has no payment details yet, so it can't run sessions. The comments in `database/seed.sql` explain every demo account, including a brand-new student and a suspended account.

## Club memberships

Students can pay a club to become a member for a set time. Committee members always count as members of their own club, without paying.

**Setting it up (committee members with "Manage sign-ups and payments").** In **Manager Workbench → Settings → Membership**, open or close memberships and set the price (RM 0 makes it free), the length (1, 3, 6 or 12 months) and the benefits shown on the club page. The club page's membership card has an **Edit** link to the same place.

**Joining (students).**

1. On the club page, choose **Apply for membership**. The price, length and bank details are saved at that moment.
2. Pay the club, then upload the receipt on the membership payment page.
3. A committee member with "Manage sign-ups and payments" reviews it in **Manager Workbench → Membership Sign-ups**. Approving starts the membership that day; rejecting needs a reason, and the student uploads a new receipt. Nobody can approve their own payment.

**Where students see it.** **My memberships** (the card icon next to your name) shows one card per club with its status and expiry. **Renew** only becomes available once a membership has expired, and uses the club's current price and length. Membership sign-ups also appear in **My Activities**, in the same tabs as session registrations.

**Where committees see it.** The **Members** button on each workspace club card lists the committee, paid members and expired memberships. Every receipt and decision is kept in a history on the payment and review pages.

**Demo memberships:** Danial Hakim is an active Badminton member and has applied to Basketball without paying. Mei Ling's Badminton membership has expired (try Renew) and her Basketball receipt was rejected. Ravi Kumar's Badminton receipt is waiting for review.

**Existing databases** upgrade automatically the first time you open the site. To get the demo memberships, drop the `tu_sportshub` database in phpMyAdmin and reload the site.

## Running sessions

**Creating a session (committee members with "Manage sessions").** In **Manager Workspace → Create new session**, write the description students will see on the session page, choose a cover picture (upload your own, or pick a built-in one), and set:

- **Price:** what non-members pay (0 makes it free).
- **Member price (optional):** what club members pay. That means committee members, and students with an active membership. It can't be more than the price, and 0 makes the session free for members. Leave it empty and everyone pays the same.

Prices, date, time, venue and capacity are locked once a session is published; the description and cover picture can still change.

**Payment details (president).** In **Settings → Payment details**, the president uploads the club's DuitNow QR, enters its bank details, and can add a payment note (for example, what to use as the payment reference). Students see all three when they pay. Until the QR and bank details are complete, the club can't create or publish sessions or open paid memberships, and the workspace and Club settings show a warning.

**Demo sessions:** the Footwork Clinic costs RM 15, or RM 10 for Badminton members. There's also a draft, a session with sign-up closed, a cancelled session with refunds in progress, one that has already happened, and a full one.

**Existing databases** upgrade automatically: existing clubs keep their payment details, and existing sessions start without a member price. To get the new demo data, drop the `tu_sportshub` database in phpMyAdmin and reload the site.

## Project layout

| Path | Purpose |
| --- | --- |
| `index.php`, `sessions.php`, `session-detail.php` | Home page and session discovery. |
| `clubs.php`, `club.php` | Club directory and profiles. |
| `register.php`, `login.php`, `profile.php` | Account registration, authentication, and profile settings. |
| `reserve.php`, `payment.php`, `upload-receipt.php`, `cancel-registration.php` | Registration and payment-evidence journey. |
| `my-activities.php` | The signed-in student's session registrations and club memberships, with status and history. |
| `memberships.php`, `membership-join.php`, `membership-payment.php`, `membership-receipt.php` | Club memberships: the student's memberships, applying and renewing, paying, and private receipt viewing. |
| `manager/` | Club-scoped session, participant, review, and refund operations. |
| `includes/` | Shared authentication, branding, navigation, error handling, and helpers. |
| `config/` | Application paths, database connection, setup, and migrations. |
| `database/` | Schema, demonstration seed data, and receipt-history migration. |
| `assets/css/`, `assets/js/`, `assets/images/` | Frontend styles, interactions, and imagery. |
| `assets/uploads/` | Private financial uploads, excluded from version control. |
| `tests/` | PHP checks, browser regressions, and disposable test fixtures. |

**Note:** `assets/css/style.css` is stored with unusual line endings. Saving it in VS Code adds a blank line after every line, so run `git diff --stat` before committing any change to it.

## Testing

Run commands from the project folder while XAMPP/MAMP's Apache and database servers are running. Use the PHP version bundled with your package, matching the version selected for the website.

Add your package's PHP executable directory to the terminal's `PATH` if `php` is not recognised. For XAMPP on Windows this is typically `<XAMPP-installation>/php/`; for MAMP on macOS, use the `bin` directory of the selected version under `<MAMP-installation>/bin/php/`. Run `php -v` to check the version before continuing.

### PHP suites

Run every PHP suite through the disposable-database runner:

```sh
php -d zend.assertions=1 -d assert.exception=1 -d assert.active=1 tests/run.php
```

The runner creates and drops a separate test database, so the test database account needs permission for both operations. Keep assertions enabled and use the runner rather than executing the seed-dependent test files directly.

To run only some suites, add their file names to the end of the command, for example `tests/run.php test_clubs.php`. The suites are:

| Suite | What it checks |
|---|---|
| `test_portability.php` | Installation paths, and database settings from environment variables. |
| `test_csrf.php` | Form tokens: creation, reuse, and rejection of missing or foreign tokens. |
| `test_helpers.php` | Student emails, club scope, places and expiry, cancellation rules, public pages (Home's tab title, started sessions hidden), and the profile page. |
| `test_receipt_history.php` | Upgrading older databases so every payment receipt is kept. |
| `test_clubs.php` | Committee titles, membership status, member prices, the payment-details rule, the club page, and the committee-title upgrade. |

Browser tests provide real HTTP-handler and concurrent-reservation coverage.

### Browser suites

These tests are optional and require Node.js, npm, and Google Chrome in addition to XAMPP/MAMP. Install the test dependencies outside the application checkout:

```sh
npm install --prefix ../sportshub-test-tools --no-save playwright @axe-core/playwright
```

Set `NODE_PATH` to that directory's `node_modules` and `SPORTSHUB_URL` to your running application URL.

PowerShell example for XAMPP on port 80:

```powershell
$env:NODE_PATH = (Resolve-Path ../sportshub-test-tools/node_modules).Path
$env:SPORTSHUB_URL = 'http://localhost/sportshub'
node tests/regressions.cjs
node tests/ux-journey.cjs
```

macOS shell example for MAMP on port 8888:

```sh
export NODE_PATH="$PWD/../sportshub-test-tools/node_modules"
export SPORTSHUB_URL='http://localhost:8888/sportshub'
node tests/regressions.cjs
node tests/ux-journey.cjs
```

Set `SPORTSHUB_URL` to the URL you use in your browser if your package, port, or project folder differs from these examples. The same commands work in a Linux shell with the appropriate XAMPP URL.

Run the browser suites sequentially; they share a disposable-fixture state file. They create generated test accounts and records and clean them up after execution. Browser requests can also run the application's normal overdue-reservation expiry checks.

- `regressions.cjs` checks targeted handler, receipt-history, keyboard-navigation, responsive-layout, and styling regressions.
- `ux-journey.cjs` checks registration, upload, review, cancellation, refunds, access boundaries, and final-place concurrency. It also runs axe checks and writes local screenshots/results to `docs/ux-evidence/`.

Finite automated checks do not establish complete security, accessibility, usability, or concurrency coverage. Real bank transfers and real-world committee workflows are not exercised by these suites.

## Current limitations

- The PHP suites check membership status, member prices and the payment-details rule through their helper functions. The membership payment and review pages were checked by hand.
- Forgot password is a placeholder; Remember me does not implement persistent login tokens.
- Reservation expiry is request-driven; no scheduled cleanup worker is included.
- Date filters include fixed demonstration months.
- The quoted amount and payment instructions (bank details and payment note) are saved with a reservation. The QR shown is always the club's current one, and SportsHub does not verify that it is a real payment destination.
- Receipt history is retained, but complete linked registration-attempt history is not implemented. Rebooking with existing financial evidence is blocked to preserve that evidence.
- Exceptional post-start withdrawal and late-discovered-transfer reconciliation have no dedicated workflow.

## Reporting issues and contributing

When reporting a problem, include the affected route, reproduction steps, expected and actual behaviour, and relevant PHP/browser versions. Remove names, account details, passwords, tokens, payment receipts, and other private information from reports, logs, and screenshots.

Keep changes focused, run the relevant suites, and describe the behaviour changed and how it was verified. Never commit real database credentials, financial evidence, production data, or local test-output folders.

## License

All rights reserved. No separate license file is currently included in this repository.
