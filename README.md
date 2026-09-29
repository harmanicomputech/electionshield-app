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
- **Notifications (Web Push):** each person opts in per device (More → Notifications) to alerts for urgent incidents, other incidents, each new result and corrections waiting for review (each topic can be turned on or off; the LGA ones go to state-wide people and to those whose home LGA it is), even with the app closed. An admin sets it up once with **System → Set up notifications**. On iPhone this needs iOS 16.4+ and the app added to the Home Screen. Logging out stops that device's alerts.
- **Broadcasts (SMS and WhatsApp):** admins send or schedule election updates to supporters, agents and coordinators, by LGA and ward, with an SMS part counter, a test send and a confirmation step, then follow delivery reports. Supporters join through the public page `/join` with explicit consent; STOP replies (Africa's Talking and WhatsApp) are honoured on every later broadcast. WhatsApp sends approved templates through the Meta Cloud API.
- **Digital town hall (public):** `/townhall` lists live and upcoming sessions; each session page embeds the YouTube or Facebook stream (loaded only when tapped, to spare data) and takes voters' questions. Nothing shows until a moderator approves it. Coordinators moderate under More → Town hall, put one question "on air" at a time, and open a full-screen presenter view for the host. One button drafts an SMS reminder broadcast to supporters.
- **Roles and permissions:** admins edit roles on the Roles page, ticking what each may do (see the dashboards, respond to incidents, acknowledge results, review corrections, see or add agents, enter official results, review or delete evidence, broadcasts, town hall, users, system, audit, export). Admin, Coordinator, Observer (view only, no phone numbers) and Agent are built in; add your own, e.g. "LGA supervisor". Every page and menu item follows them.
- **Agents in the web app:** staff add agents and reset PINs on the Agents page (through the USSD service, so the agent works on USSD too; the PIN can be sent by SMS). Agents sign in with their phone number and the same 4-digit PIN as on USSD (wrong PINs count towards the same lock-out) and get their own pages: check in, materials, submit a result or a correction, report incidents, **with photos and videos**, and My reports. Their submissions go to the USSD service, which applies the USSD rules and sends the SMS receipt and alerts; everything is marked "via Web app" or "via USSD". The forms work offline and are sent when the network returns.
- **Photos and videos:** agents attach up to 6 files per report (videos up to 100 MB, `MEDIA_MAX_VIDEO_MB`), shrinking photos on the phone. They show on the incident feed, on Photos & videos (checked / doubtful) and in the evidence pack, with SHA-256 fingerprints. Result photos go to EC8A photos.
- **Proof for materials:** on the agent pages, "Arrived (complete)" and "Arrived (incomplete)" need at least one photo or video of the materials ("Not arrived yet" doesn't). The files show on **Photos & videos → Materials** (with Checked / Doubtful) and as a 📎 link on the ward's PU list. Materials reported by USSD can't carry a photo, so an "arrived" report from USSD shows "No photo (USSD)".
- **Location checks:** the app asks for the phone's location when it opens. Check-in on the agent pages does not work without it (it asks again if it was refused or dismissed). Admins see, on **Election day → Locations**, where each agent was when they checked in, how far from their polling unit (allowed: 300 m plus the phone's GPS error, `CHECKIN_RADIUS_M`), and a flag for faked-looking positions (outside Ebonyi, impossibly exact GPS, a stale position); away or suspicious check-ins pop up until reviewed. PU locations are set from a trusted check-in ("Use as the PU's location"). Coordinators are tracked too (when the app opens, every 10 minutes while it is open, and with each action) but never blocked; refusing shows as "Location not shared". Which roles are tracked is the "Location is recorded" permission; admins are never tracked. USSD check-ins have no location and show "USSD: no location".
- **Pop-ups for new submissions:** new results, corrections and incidents pop up in the situation room with the channel (USSD or web app), the agent's name and number, the PU, and the figures or note. Acknowledge or resolve from the pop-up; Remind me (5, 15, 30 or 60 minutes) snoozes it for you only; closing it brings it back in 5 minutes until someone answers. Urgent ones sound and vibrate. Who gets which follows their permissions and home LGA.
- **IReV, fetched automatically:** on Official vs PVT → IReV (automatic), choose the election on INEC's IReV once; while the pinger runs, the app walks Ebonyi's wards every couple of minutes, notices each EC8A upload, matches the PU to our register (by code, or LGA + ward + PU number; unmatched ones can be matched by hand), downloads the sheet, reads it with AI and saves the figures as the official result, marked **not yet checked** until a person compares them with the sheet and saves. A person's own entry is never overwritten. IReV publishes images, not figures, and has no official API: this follows the routes IReV's own web page uses (`IREV_API_URL`), so it can break if INEC changes them.
- **Reading IReV sheets with AI:** on a PU's official result page, upload the IReV result sheet (image or PDF) or paste its IReV link; Claude reads the figures into the form for a person to check and save, and the sheet is kept with the result. The reading never sees our agent's figures. Needs `ANTHROPIC_API_KEY`.
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
