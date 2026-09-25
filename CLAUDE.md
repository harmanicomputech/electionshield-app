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
- `app/Services/ResultComparison.php`: PVT vs INEC. PUs vote by vote against `official_results` (IReV), wards/LGAs by share against `official_collations` (EC8B/EC8C). Official figures are entered in `Official*Controller`s and never mixed into PVT totals
- `app/Services/Ec8aPhotoStore.php`, `PhotoController`, `AgentUploadController`: EC8A photos on the private `local` disk (`storage/app/private/ec8a/`), unique per (reference, sha256) so replayed uploads are stored once. `App\Support\UploadLink` signs the agents' no-login `/u/{reference}/{token}` links with USSD_WEBHOOK_SECRET (documented in the handoff brief; the USSD service builds the same token); those routes skip CSRF
- `app/Services/PushNotifier.php`, `PushAlerts.php`, `PushController`: Web Push (minishlink/web-push). VAPID keys come from settings (System → Set up notifications) unless VAPID_* are in .env. `Incident`/`Result` `created` events call `PushAlerts` (topics: urgent incidents, other incidents, new accepted results, pending corrections), which only alerts for records from the last 30 minutes (so imports and backfills stay quiet) and sends after the response
- `app/Services/Broadcasting/`: `Audience` (consent rules: supporters/other need a channel opt-in, agents and coordinators get operational SMS, WhatsApp always needs opt-in, `opt_outs` always win), `BroadcastDispatcher` (freezes recipients into `broadcast_messages`, unique per phone), `SmsSender` (Africa's Talking bulk), `WhatsAppSender` (Cloud API templates); `App\Jobs\SendBroadcastBatch` only sends messages still `queued`, so retries never double-send. Provider callbacks are in `Api\MessagingCallbackController`
- `app/Http/Controllers/TownHallController.php` (public, no login) and `Console/TownHallManageController.php` (moderation, presenter view; sessions are admin-only): the digital town hall. Public pages show only approved/answered questions; visitors' IPs are kept only as a keyed hash
- `app/Services/LgaMap.php`, `partials/lga-map.blade.php`: the schematic LGA map (tile layout in `LgaMap::LAYOUT`; a test checks every register LGA has a tile)
- `app/Http/Controllers/Console/CorrectionController.php`, `app/Services/UssdApi.php`: correction review; decisions go to the USSD API (`POST /corrections/{ref}/approve|reject`), a 409 means already decided (we fetch `/results/{ref}` and apply it), network errors answer 502 so the offline queue retries
- `Console/AgentController.php` (call lists, never cached by the service worker), `Console/ReportController.php` + `resources/views/reports/` (evidence pack, situation report; print CSS hides the chrome), `app/Services/DataMaintenance.php` (clear rehearsal data; backup zip without passwords, settings, push keys or visitor hashes), `Console/AccountController.php`. `users.lga` is a coordinator's home LGA: default filters ("all" overrides) and `PushNotifier::toTopic(..., $lga)`
- `app/Http/Controllers/Console/IncidentController.php`: the incident feed; acknowledge / resolve / reopen are safe to repeat (the offline queue replays them) and their columns are never touched by USSD ingest
- `app/Http/Controllers/Console/`, `resources/views/`: the web console. Users have `role` admin|coordinator; admin-only routes use `RequireAdmin`. The first admin is created at `/login` with ADMIN_PASSWORD as a setup key. Record sensitive actions with `App\Support\Audit::record()`
- `app/Support/BackgroundRunner.php`: background work without a per-minute cron (the host forbids one): after web requests (`RunBackgroundWork` middleware, PHP-FPM/LiteSpeed only), the pinger URL `/cron/{token}`, or `artisan app:tick`. Runs `broadcast:dispatch`, the 3-minute `ussd:sync` slot and the queue under one lock. Disabled in tests (`BACKGROUND_RUNNER=false`)
- `app/Support/Settings.php`: console settings (the host has no terminal to edit `.env`), including `data_view` (real or rehearsal)
- `config/election.php`: ballot, candidates, win-condition parameters; `config/services.php` → `ussd`: webhook and API credentials
- `public/sw.js`, `public/manifest.webmanifest`, `public/js/app.js`, `public/css/app.css`: PWA and front end
- `deploy/shared-hosting/`, `scripts/build-shared-hosting.sh`: upload package

## Rules that matter

- **Events arrive more than once and out of order.** Every ingest must be an idempotent upsert. A result never moves back a stage (`ResultStatus::stage()`: pending → accepted/rejected, accepted → superseded), and an accepted correction supersedes the result it corrects whichever arrives first.
- **Only `accepted` results count**, and real and rehearsal data are never mixed (`Result::scopeCounted()`); the console shows one or the other.
- **Times are stored in UTC** (`App\Support\Time::parse()`), shown in Africa/Lagos (`Time::local()`).
- **Navigation:** a sidebar at 1024px and wider (`$sections` in `layouts/app.blade.php`), a top bar on tablets, a bottom tab bar on phones. Add new pages to the sidebar sections and, if they are for everyone, to the phone More menu.
- **Mobile first** (360px) is an acceptance criterion: no horizontal scroll, 44px tap targets, tables use `table.stack` (cards on phones; `td.key` is the card title, `td.detail` sits behind the Details tap). Chart colours follow the party (`App\Support\Party::slot()`), never its rank, and every bar has a direct label.
- **The service worker caches only the data pages** (`/`, `/spread`, `/collation/*`, `/monitor/*`), network first, and the cache is cleared on logout. Never the incident feed or anything else showing agent phone numbers. Bump `VERSION` in `sw.js` when the shell changes.
- **Offline photos:** forms with `data-photo` shrink the image (long side 2000px), and with no connection keep it in IndexedDB (`es-queue` → `photos`); both `app.js` and the service worker's `es-photos` Background Sync send it. Photo images are served by `PhotoController::image` behind login and never cached by the service worker.
- **Offline actions:** a form with `data-queue="<label>"` is posted in the background and, with no connection, queued in `localStorage` and sent when the connection returns or the app reopens (`public/js/app.js`). Its endpoint must be idempotent and answer JSON when `expectsJson()`.
- The IReV entry form must not show our PVT figures (so they can't sway what is typed); the comparison appears after saving.
- PU codes are numeric strings, so PHP turns them into integer array keys; compare with `strval` where it matters.
- In JS, read a form's URL with `form.getAttribute('action')`: a field named `action` replaces `form.action` (a test guards this).
- Never put a one-line `@php(...)` above a `@php … @endphp` block in the same view: Blade then swallows everything between them (a test guards this).
- A Blade directive right after a letter (`arrived@if`) is not compiled; leave a space.
- Tests use parties `APC,PDP,LP,OTHERS` and fake USSD credentials (see `phpunit.xml`); `tests/Concerns/SendsUssdEvents.php` signs webhook deliveries and builds payloads.
