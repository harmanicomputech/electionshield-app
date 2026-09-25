# Election Shield web app

The election-day situation room for the **Ebonyi State governorship election, Saturday 6 February 2027**. Coordinators use it on their phones to follow the Parallel Vote Tabulation (PVT) live:

- **PVT dashboard:** PUs reported, valid votes, turnout, votes by party.
- **Win condition on current figures:** section 179(2) of the 1999 Constitution. The winner needs the most votes *and* at least 25% in at least 9 of the 13 LGAs. The dashboard says whether the leader would be declared or face a run-off.
- **25% tracker:** every candidate's share in every LGA, against the 25% line.
- **Weak links:** LGAs where our candidate is under or near 25%, or where few PUs have reported.
- **Collation:** state → LGA → ward → polling unit, with the EC8A figures and reference of each result.
- **PU monitoring board:** for every PU, agent check-in, the latest materials report (arrived / incomplete / not arrived), result and open incidents, by LGA and ward, with a "need attention" filter.
- **Incident feed:** urgent incidents first, filters by status, type and LGA, a call link to the reporting agent, and **acknowledge → resolve** tracking with a note. Actions taken offline are queued on the phone and sent when the connection returns. An unacknowledged urgent incident shows as a red badge on the Incidents tab and an alert on the dashboard.
- **LGA map:** a schematic map of the 13 LGAs (one tile each, roughly where it lies) on the Dashboard, PUs and Incidents pages, switching between our candidate's share, results in, agents checked in and open incidents. Every tile shows its value as text.
- **Correction review:** corrections agents send by USSD are listed next to the result they would replace, with the change for each figure. Approving or rejecting goes through the USSD service's API (the source of truth), works offline, and correction alerts open this page.
- **Agents:** every agent with their PU's status and a one-tap call link, and the PUs gone silent (no check-in, no result, no agent), by LGA; admins can download the list.
- **Printable pages:** an evidence pack per PU for petitions (both sets of figures, result history, photos with fingerprints, field reports) and a one-page situation report; both print cleanly or save as PDF.
- **Housekeeping (admins, System page):** clear rehearsal data before the real election, and download a full backup as CSV files in one zip.
- **Accounts:** everyone can change their own name and password; an admin gives each coordinator a home LGA, which sets their urgent-incident alerts and default filters.
- **Official results vs PVT:** enter INEC's IReV result per PU (or mark "no upload on IReV") and the declared ward (EC8B) and LGA (EC8C) collations, by hand or by CSV import. Each PU is compared vote by vote with our agent's EC8A; collations are compared by share, since the PVT may not cover every PU yet. Admins can export the flagged PUs as CSV evidence for petitions.
- **EC8A photos:** coordinators upload the photographed result sheet for a result reference, and agents can send it themselves through a no-login link tied to their reference (the USSD service can put it in the SMS receipt; see `docs/WEB-APP-HANDOFF.md`). Photos are shrunk on the phone for 3G, queued on the device with no signal, kept byte for byte with a SHA-256 fingerprint, and checked by a coordinator as "matches" or "does not match" our figures. The evidence export lists each PU's photos and fingerprints.
- **Notifications (Web Push):** each person opts in per device (More → Notifications) to alerts for new urgent incidents and for corrections waiting for review, even with the app closed. An admin sets it up once with **System → Set up notifications**. On iPhone this needs iOS 16.4+ and the app added to the Home Screen. Logging out stops that device's alerts.
- **Broadcasts (SMS and WhatsApp):** admins send or schedule election updates to supporters, agents and coordinators, by LGA and ward, with an SMS part counter, a test send and a confirmation step, then follow delivery reports. Supporters join through the public page `/join` with explicit consent; STOP replies (Africa's Talking and WhatsApp) are honoured on every later broadcast. WhatsApp sends approved templates through the Meta Cloud API.
- **Digital town hall (public):** `/townhall` lists live and upcoming sessions; each session page embeds the YouTube or Facebook stream (loaded only when tapped, to spare data) and takes voters' questions. Nothing shows until a moderator approves it. Coordinators moderate under More → Town hall, put one question "on air" at a time, and open a full-screen presenter view for the host. One button drafts an SMS reminder broadcast to supporters.
- **Mobile first and installable (PWA):** it works at 360px, installs to the home screen, starts offline, and shows how old its data is when the network drops.

All data comes from the **Election Shield USSD service** (`harmanicomputech/claude`), where polling agents submit results by USSD. That service is the source of truth. This app keeps a copy, received two ways (see `docs/WEB-APP-HANDOFF.md`):

1. **Webhook** (real time): the USSD service POSTs each event to `/api/ussd-events`, signed with HMAC-SHA256.
2. **Read API** (safety net): every 3 minutes the scheduler pulls whatever changed since the last sync.

## Set-up (local)

```bash
composer install
cp .env.example .env    # then set DB_CONNECTION=sqlite for a quick local run
php artisan key:generate
php artisan migrate
php artisan serve
```

Set `ADMIN_PASSWORD` in `.env`, open `/login`, and create the first admin with it as the setup key. Then open **System** and follow the two connection steps.

## Connecting the USSD service

| On this app (`.env`) | On the USSD server (`election-shield/.env`) |
| --- | --- |
| `USSD_WEBHOOK_TOKEN` | `DASHBOARD_API_TOKEN` (same value) |
| `USSD_WEBHOOK_SECRET` | `DASHBOARD_WEBHOOK_SECRET` (same value) |
| (the URL shown on the System page) | `DASHBOARD_WEBHOOK_URL=https://<this app>/api/ussd-events` |
| `USSD_API_URL`, `USSD_API_TOKEN` | the USSD service's `/api` URL and its `ELECTION_API_TOKEN` |

Then:
1. On this app's **System** page, press **Full import** to pull the PU register, agents and everything submitted so far.
2. In the USSD console, press **Settings → Send all existing data to the dashboard**. Both are safe to repeat.

Unsigned or wrongly signed events are refused (401). Until both webhook values are set, every event is refused (503).

## How the data is kept correct

- Each webhook event is stored under its `Idempotency-Key` before we reply `2xx`, so a repeated event is applied once.
- Events can arrive in any order. Results are upserted by reference and never move back a stage. An approved correction supersedes the result it corrects, whichever arrives first.
- Only `accepted` results are counted, one per PU.
- Rehearsal data (`rehearsal: true`) is kept apart. The dashboards show real results *or* rehearsal data (System → Data shown), never both.

## Win-condition settings (`config/election.php`)

| Setting | Default | Meaning |
| --- | --- | --- |
| `ELECTION_SPREAD_SHARE` | 25 | % of valid votes needed in an LGA |
| `ELECTION_SPREAD_LGAS_REQUIRED` | 9 | LGAs needed (two-thirds of 13 = 8.67; **confirm the rounding with the legal team**) |
| `ELECTION_PRINCIPAL_PARTY` | empty | the party weak links are worked out for (empty = the current leader) |
| `ELECTION_NEAR_MARGIN` | 5 | "near 25%" means under 30% |
| `ELECTION_LOW_COVERAGE` | 50 | an LGA with fewer than 50% of PUs reported is a weak link |
| `ELECTION_DISCREPANCY_VOTES` | 10 | a PU is flagged when any party's IReV figure differs from our EC8A by this many votes |
| `ELECTION_DISCREPANCY_SHARE_POINTS` | 3 | a ward/LGA collation is flagged when any party's declared share differs from its PVT share by this many percentage points |

Shares are of total valid votes, which includes OTHERS. OTHERS is not a candidate, so it never "leads" or appears in the tracker.

## Deployment

Shared hosting (DirectAdmin/cPanel, no terminal): see `docs/DEPLOY-SHARED-HOSTING.md`. In short: build the zip with `scripts/build-shared-hosting.sh`, upload and extract it, fill in `.env`, and create the admin at `/login`. Hosts that forbid per-minute cron jobs need a free pinger (cron-job.org) opening the **pinger URL** from the System page every minute; background work also runs after page visits. Where a per-minute cron is allowed, `php artisan schedule:run` does the same.

## Still to build

In order of election-day value, per the brief:

1. ~~PVT dashboard with collation and the 25% tracker~~ (done)
2. ~~PU monitoring board and incident feed with acknowledge/resolve~~ (done). A map needs PU coordinates, which the register doesn't have yet; the board is the list view.
3. ~~Official results intake and comparison, with an evidence export~~ (done)
4. ~~EC8A photo upload, tied to the result reference, with offline queueing~~ (done). Next step on the USSD side: add the upload link to the agent's SMS receipt.
5. ~~Web Push for urgent incidents and corrections awaiting review~~ (done)
6. ~~Broadcast system (SMS/WhatsApp)~~ (done)
7. ~~Digital town hall~~ (done)

Every feature in the brief is now built. Next: rehearse end to end with the USSD sandbox, and add the photo upload link to the USSD SMS receipt.
