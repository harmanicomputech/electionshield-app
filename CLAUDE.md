# Election Shield web app

The situation room for the Ebonyi State governorship election (6 Feb 2027): PVT collation, the section 179(2) 25% tracker and weak links, for coordinators on phones. Laravel 13, MySQL in production, SQLite in-memory for tests. No Node build: plain CSS/JS in `public/`, because the host (DirectAdmin shared hosting) has no terminal.

The **Election Shield USSD service** (`harmanicomputech/claude`) is the source of truth for everything agents submit. This app receives a copy through a signed webhook and the USSD read API; `docs/WEB-APP-HANDOFF.md` is the integration contract. Never write back to or share a database with the USSD service.

## Commands

- `php artisan test`: run the tests
- `vendor/bin/pint`: format code (CI runs `pint --test`)
- `php artisan ussd:sync [--full]`: pull from the USSD read API (the scheduler runs it every 3 minutes)
- `scripts/build-shared-hosting.sh [--update]`: build the cPanel/DirectAdmin upload zip (see `docs/DEPLOY-SHARED-HOSTING.md`)

## Layout

- `routes/api.php`: `POST /api/ussd-events`, the webhook (`VerifyUssdWebhook` checks the Bearer token and HMAC signature)
- `app/Http/Controllers/Api/UssdEventController.php`: stores the event in `webhook_events` (unique `Idempotency-Key`), then applies it; replies 2xx only after both
- `app/Services/UssdIngestor.php`: every write from USSD data, shared by the webhook and the sync. Upserts by `reference` (results, incidents) or USSD `id` (materials, presences, agents)
- `app/Services/UssdSync.php`: read-API pull: cursors, `updated_since` = last `server_time` minus 1 minute, per-resource `sync_states`
- `app/Services/Collation.php`, `Tally.php`: figures by state/LGA/ward/PU; `SpreadTracker.php`: the 25% rule, the projection and weak links
- `app/Services/PuMonitor.php`, `PuStatus.php`, `MonitorTally.php`: the PU monitoring board (first check-in, latest materials report by `reported_at`, counted result, unresolved incidents)
- `app/Http/Controllers/Console/IncidentController.php`: the incident feed; acknowledge / resolve / reopen are safe to repeat (the offline queue replays them) and their columns are never touched by USSD ingest
- `app/Http/Controllers/Console/`, `resources/views/`: the web console. Users have `role` admin|coordinator; admin-only routes use `RequireAdmin`. The first admin is created at `/login` with ADMIN_PASSWORD as a setup key. Record sensitive actions with `App\Support\Audit::record()`
- `app/Support/Settings.php`: console settings (the host has no terminal to edit `.env`), including `data_view` (real or rehearsal)
- `config/election.php`: ballot, candidates, win-condition parameters; `config/services.php` → `ussd`: webhook and API credentials
- `public/sw.js`, `public/manifest.webmanifest`, `public/js/app.js`, `public/css/app.css`: PWA and front end
- `deploy/shared-hosting/`, `scripts/build-shared-hosting.sh`: upload package

## Rules that matter

- **Events arrive more than once and out of order.** Every ingest must be an idempotent upsert. A result never moves back a stage (`ResultStatus::stage()`: pending → accepted/rejected, accepted → superseded), and an accepted correction supersedes the result it corrects whichever arrives first.
- **Only `accepted` results count**, and real and rehearsal data are never mixed (`Result::scopeCounted()`); the console shows one or the other.
- **Times are stored in UTC** (`App\Support\Time::parse()`), shown in Africa/Lagos (`Time::local()`).
- **Mobile first** (360px) is an acceptance criterion: no horizontal scroll, 44px tap targets, tables use `table.stack` (cards on phones; `td.key` is the card title, `td.detail` sits behind the Details tap). Chart colours follow the party (`App\Support\Party::slot()`), never its rank, and every bar has a direct label.
- **The service worker caches only the data pages** (`/`, `/spread`, `/collation/*`, `/monitor/*`), network first, and the cache is cleared on logout. Never the incident feed or anything else showing agent phone numbers. Bump `VERSION` in `sw.js` when the shell changes.
- **Offline actions:** a form with `data-queue="<label>"` is posted in the background and, with no connection, queued in `localStorage` and sent when the connection returns or the app reopens (`public/js/app.js`). Its endpoint must be idempotent and answer JSON when `expectsJson()`.
- A Blade directive right after a letter (`arrived@if`) is not compiled; leave a space.
- Tests use parties `APC,PDP,LP,OTHERS` and fake USSD credentials (see `phpunit.xml`); `tests/Concerns/SendsUssdEvents.php` signs webhook deliveries and builds payloads.
