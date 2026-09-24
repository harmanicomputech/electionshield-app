# Election Shield: web app handoff brief

Paste or attach this file at the start of the new chat. It describes the existing **Election Shield USSD service** and how the new **Election Shield web app** receives its data.

## The project

- **Election:** Ebonyi State governorship election, **Saturday 6 February 2027**. Timezone Africa/Lagos.
- **Ballot as entered by agents:** APC (Francis Ogbonna Nwifuru), PDP (Ifeanyi Chukwuma Odii), LP (Splendor Oko Eze), and OTHERS (all other parties combined).
- **Polling units:** 3,308 PUs in 13 LGAs and 169 wards, from the INEC-verified register. INEC codes like `EB/212/02633/007` are stored as digits: `21202633007`.
- **Owner:** Kehinde Amusan (amusankehinde@gmail.com).

## Product scope: Software 3, Election Shield

**"Election Day Control & Protection System"**: making sure votes are protected, the process is transparent, and problems get a fast response.

| Feature | Already in the USSD service | Still to build (mostly in the web app) |
| --- | --- | --- |
| **1. Polling unit monitoring.** Agents report materials arriving, delays and irregularities. | Presence check-in per PU. **Materials status** (arrived / incomplete / not arrived, with a timestamp; the latest report counts). Incidents of type *delay*, *malpractice*, *vote suppression* and more. Missing-PU lists and SMS reminders. | Live PU status board and map by LGA and ward, showing check-in, materials and result per PU. |
| **2. Parallel Vote Tabulation (PVT).** Collect results from every PU and compare them with the official results. | EC8A figures from every PU (accredited, per party, rejected), a correction workflow, and collation by LGA and ward. | **Official results intake:** INEC's IReV result per PU and the declared ward and LGA collations (EC8B/EC8C), entered by hand or imported. **Comparison:** our figures against the official ones per PU, ward and LGA, flagging differences above a threshold and PUs where IReV shows no upload. Evidence export for petitions. EC8A **photo upload** tied to the result reference, since USSD can't carry images. |
| **3. Incident alert system.** Real-time alerts for violence, vote suppression and malpractice. | **Violence, vote suppression and malpractice** each send an instant SMS to the coordinators for that LGA plus state-wide coordinators, with email, dashboard events and an audit trail. Which types count as urgent is configurable. | A live incident feed and map, and acknowledge/resolve tracking for coordinators. |
| **4. Digital town hall.** The candidate engages voters through live sessions and Q&A. | Not covered: USSD can't carry it. | Embedded live stream (YouTube/Facebook), a question submission and moderation queue, and a schedule of sessions. Could share the broadcast list for reminders. |
| **5. WhatsApp/SMS broadcast system.** Election updates and mobilisation reminders. | SMS to *agents* through Africa's Talking (PINs, receipts, alerts, election-day reminders). | Audience lists (supporters by LGA and ward, agents, coordinators), opt-in and opt-out, scheduled campaigns, and delivery reports. **SMS:** Africa's Talking bulk SMS; watch the sender ID and do-not-disturb (DND) rules. **WhatsApp:** the WhatsApp Business Platform (Meta Cloud API or a provider) needs a verified business, pre-approved message templates, and recipients' opt-in. |

### Ebonyi election insight: the win condition

Under section 179(2) of the 1999 Constitution, a governorship candidate is declared elected with:
1. **the highest number of votes** (a plurality, not necessarily a majority), and
2. **at least 25% of the votes in at least two-thirds of the LGAs**. Ebonyi has **13 LGAs**, so this means **at least 9 LGAs** (two-thirds is 8.67). Confirm the rounding with the legal team.

Otherwise, a run-off is held. The web app should track this live from the PVT figures:
- the **25% tracker:** each candidate's share in each of the 13 LGAs, and how many LGAs they have reached 25% in (target 9)
- the **overall lead**
- **weak links:** LGAs where the candidate is under or near 25%, and LGAs with low result coverage (few PUs reported)

This is where Election Shield delivers "coverage across all LGAs, no weak links on election day". The USSD data already arrives with LGA and ward on every result.

## What already exists: Election Shield USSD

- **Repository:** `harmanicomputech/claude`, branch `claude/hello-i876f8`. Laravel 13, PHP 8.4, MySQL.
- **Live at** `https://ussd.techatronagency.com`, on DirectAdmin shared hosting with no terminal. Everything is managed through its admin console at `/admin`, and one cron job runs its background work.
- **Africa's Talking USSD:** sandbox channel `*384*92342#` for now; a live code has been applied for.
- **What agents do by USSD** (registered phone numbers only, with a 4-digit PIN for results):
  - confirm presence at their PU
  - report election materials status: arrived, incomplete or not arrived
  - submit the EC8A result: accredited voters, votes for each party, rejected votes
  - request a correction, which a coordinator must approve
  - report incidents: violence, vote suppression, malpractice, vote buying, delay, other; the first three send an SMS alert to coordinators
- **Admin console:**
  - results with collation by LGA and ward, incidents, polling units, agents
  - correction review, coordinators, users (admin and coordinator roles), audit log
  - rehearsal mode, CSV exports, printable agent cards

**The USSD service stays the source of truth for submissions.** The web app receives its data.

## How the web app gets the data

### 1. Push: a signed webhook (main path)

The USSD service POSTs every event to one URL on the web app. Configure it in the USSD server's `election-shield/.env`:

```
DASHBOARD_WEBHOOK_URL=https://<web-app>/api/ussd-events
DASHBOARD_API_TOKEN=<token the web app expects as a Bearer token>
DASHBOARD_WEBHOOK_SECRET=<shared secret for the HMAC signature>
```

Each request looks like this:

```http
POST {DASHBOARD_WEBHOOK_URL}
Content-Type: application/json
Authorization: Bearer {DASHBOARD_API_TOKEN}
Idempotency-Key: result.submitted:RS784321
X-Election-Shield-Event: result.submitted
X-Election-Shield-Signature: sha256=<hex HMAC-SHA256 of the raw body with DASHBOARD_WEBHOOK_SECRET>

{"event": "result.submitted", "sent_at": "2027-02-06T15:04:11+00:00", "data": { ... }}
```

The web app must:
- **Verify the signature:** `hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header)`. Also check the Bearer token.
- **Reply 2xx only after storing the event.** Anything else is retried 8 times over about 25 minutes, then every 15 minutes by a safety job.
- **Treat `Idempotency-Key` as unique.** The same event can arrive more than once.
- **Not assume events arrive in order.** Apply them by reference and status.

#### Events

| Event | `data` |
| --- | --- |
| `result.submitted` | A PU's first result (see the result payload below) |
| `result.correction_requested` | A result with `status: "pending"` and `corrects_reference` |
| `result.corrected` | A result with `status: "accepted"` plus `superseded_reference`. **Replace** that PU's figures. |
| `result.correction_rejected` | A result with `status: "rejected"` |
| `incident.reported` | `reference`, `polling_unit`, `type` (`violence`/`vote_suppression`/`malpractice`/`vote_buying`/`delay`/`other`), `type_label`, `urgent`, `note`, `agent`, `reported_at` |
| `materials.reported` | `id`, `polling_unit`, `status` (`arrived`/`incomplete`/`not_arrived`), `status_label`, `agent`, `reported_at`. Agents can report again; the latest report per PU is its current status. |
| `presence.confirmed` | `id`, `polling_unit`, `agent`, `confirmed_at` |

Every `data` object also has **`rehearsal: true|false`**. Keep rehearsal data apart from real results, or drop it.

#### The result payload

```json
{
  "reference": "RS784321",
  "status": "accepted",
  "polling_unit": { "code": "21202633007", "name": "Police Station Area 007", "ward": "Abakaliki Ward 01", "lga": "Abakaliki", "registered_voters": 1507 },
  "accredited_voters": 1200,
  "votes": { "APC": 610, "PDP": 402, "LP": 95, "OTHERS": 18 },
  "total_valid_votes": 1125,
  "rejected_votes": 21,
  "total_votes_cast": 1146,
  "corrects_reference": null,
  "agent": { "name": "Ada Obi", "phone_number": "+2348012345678" },
  "submitted_at": "2027-02-06T15:04:10+00:00",
  "reviewed_at": null,
  "reviewed_by": null,
  "review_note": null,
  "rehearsal": false
}
```

**Counting rule:** each PU has exactly one result with `status: "accepted"` at any time. Totals must count only accepted results. Statuses are `accepted`, `pending` (a correction awaiting review), `rejected` and `superseded` (an old result replaced by an approved correction).

#### Catching up on existing data

After connecting the webhook, press **Settings → Send all existing data to the dashboard** in the USSD admin console, or run `php artisan dashboard:backfill`. It re-sends everything with the same idempotency keys, so it's safe to repeat.

### 2. Pull: the read API (fetch and show USSD data)

The web app can also **fetch** everything the USSD service holds. Use this for the first full import, for staying in sync if a webhook is missed, and for pages that query the USSD data directly.

- **Base URL:** `https://ussd.techatronagency.com/api`
- **Auth:** `Authorization: Bearer {ELECTION_API_TOKEN}`. The token is in the USSD server's `.env`.
- **Call it from the web app's server only, never from the browser.** The token gives access to every agent's name and phone number, so it must never be shipped in front-end JavaScript. Pages and the PWA read from the web app's own backend.

| Endpoint | Returns | Filters |
| --- | --- | --- |
| `GET /results` | Results in the webhook shape, including `votes`, `status` and `corrects_reference` | `status`, `lga`, `ward`, `polling_unit` |
| `GET /results/{reference}` | One result | |
| `GET /incidents` | Incidents | `type`, `lga`, `ward`, `polling_unit` |
| `GET /presences` | Check-ins | `lga`, `ward`, `polling_unit` |
| `GET /materials` | Materials reports. `latest=1` gives the current status per PU. | `status`, `latest`, `lga`, `ward`, `polling_unit` |
| `GET /polling-units` | The register (code, name, ward, LGA, registered voters) | `lga`, `ward` |
| `GET /agents` | Agents: name, phone, assigned PU, `locked`, `last_seen_at`. PINs are never returned. | `lga` |
| `GET /reports/summary` | Totals, turnout, party votes, materials, incidents, and a breakdown by LGA | |
| `GET /reports/missing?type=presence\|results` | PUs with no check-in or no result, with their agents | `lga` |
| `GET /corrections`, `POST /corrections/{ref}/approve\|reject` | Correction review | `status` |

**Every list endpoint** accepts:
- `updated_since=<ISO time>`: only records created or changed since then. Status changes count, for example a result becoming `superseded`.
- `per_page` (up to 500, default 100).
- `cursor`, taken from the previous response.

Responses look like this:

```json
{
  "data": [ { "...": "same fields as the webhook event", "created_at": "…", "updated_at": "…" } ],
  "next_cursor": "eyJ…" ,
  "next_page_url": "https://…/api/results?cursor=eyJ…",
  "server_time": "2027-02-06T15:30:00+00:00",
  "rehearsal_mode": false
}
```

Records are ordered by `updated_at`, then `id`. Keep following `next_cursor` until it's `null`.

### Recommended sync strategy (both paths together)

1. **First import:** page through `/polling-units`, `/agents`, `/results?status=…` (all statuses), `/incidents`, `/presences` and `/materials`. **Upsert** results and incidents by `reference`, and the others by `id`.
2. **Real time:** the webhook (section 1) delivers each event within seconds.
3. **Safety net:** every 2–5 minutes, a scheduled job calls each list endpoint with `updated_since` set to the last `server_time` seen, minus 1 minute of overlap. Upserting makes the overlap harmless. This catches anything a webhook missed.
4. **Rehearsals:** webhook events carry `rehearsal: true|false`. Pulled records don't, but each response has `rehearsal_mode`. Rehearsal data is cleared in the USSD console before the real election.

## Web app requirements: mobile first and installable (PWA)

Most coordinators and field supervisors will use phones, often on weak networks. Treat these as acceptance criteria, not extras.

### Mobile responsive

- **Design mobile-first.** Build for a 360px-wide screen first, then scale up for tablet (≥ 768px) and desktop (≥ 1024px). No horizontal page scrolling at any width. Use a 16px side margin on phones.
- **Tap targets at least 44×44px.** Use a bottom tab bar or collapsible menu on phones, not a long top bar.
- **Wide tables become stacked cards on phones:** results, collation, PUs and incidents. Keep the key figure and status visible, and put details behind a tap.
- **Keep charts and maps readable at phone width.** Bars get direct labels. The map supports pinch-zoom and has a list alternative.
- **Keep the data small** for 3G: paginated lists, compressed JSON, no huge client-side bundles.
- **Test on real Android phones** (low and mid range, Chrome) and iPhone (Safari), not only desktop dev tools.

### Progressive Web App (installable, works offline)

- **Web app manifest:** name "Election Shield", short name, theme and background colours, `display: standalone`, `start_url`, and icons at 192px, 512px and a 512px maskable version.
- **HTTPS everywhere.** A service worker needs it.
- **Service worker:**
  - **App shell** (HTML, CSS, JS, icons) cached for an instant start and offline launch.
  - **Data** fetched *network-first, falling back to the last cached copy*, with a visible "Offline: showing data from 3:42 PM" banner and the time of the last successful sync.
  - **Queued actions:** anything the user submits while offline (a correction review, an incident acknowledgement, an EC8A photo upload) is stored locally and sent when the connection returns (Background Sync, with a retry on reopen as a fallback). The user sees "queued" and then "sent".
- **Install prompt:** an "Install app" button on Android (`beforeinstallprompt`) and a short "Add to Home Screen" guide for iPhone.
- **Push notifications (Web Push)** for urgent incidents (violence, vote suppression, malpractice) and for corrections waiting for review, sent by the web app's backend when the matching webhook event arrives. Users opt in per device. On iPhone this needs iOS 16.4+ and the app installed to the Home Screen.
- **Offline-safe authentication:** sessions survive going offline. Cached data is cleared on logout, and nothing sensitive (agent phone numbers) is kept longer than needed.
- **Target:** pass Chrome Lighthouse's PWA/installability checks, and score at least 90 for Performance on mobile.

## Decisions for the new chat

- **What the web app is for:** an internal situation room for coordinators, public or partner results, or both. This decides the authentication and what is shown.
- **Stack and hosting:** the same shared host (Laravel suits it), or somewhere else. Integrate through the webhook and read API above; don't share the USSD database directly. The front end must meet the mobile-first and PWA requirements above.
- **Features:** build in order of election-day value.
  1. PVT dashboard with collation and the 25% tracker
  2. PU monitoring board and incident feed
  3. Official-results intake and comparison
  4. EC8A photo upload
  5. Broadcast system
  6. Digital town hall
- **The EC8A photo:** USSD can't send images. A web or WhatsApp upload flow tied to the result reference would let coordinators check figures against the photographed result sheet.
