# Election Shield web app: progress tracker

Where the project stands and what comes next. Update it whenever something is finished.

Last updated: 25 September 2026.

## Built so far

All seven features in the brief (`docs/WEB-APP-HANDOFF.md`) are built and tested. There are 94 automated tests, and each feature was also checked in a real browser at phone (360px) and desktop width.

| # | Feature | Branch | Where in the app |
| --- | --- | --- | --- |
| 0 | Foundation: login and roles, first-admin setup key, signed webhook intake, read-API sync, audit log, System page, PWA (installable, offline), shared-hosting package | `main` | `/login`, System |
| 1 | PVT dashboard, collation LGA → ward → PU, 25% tracker (section 179(2)), weak links | `main` | Dashboard, Results |
| 2 | PU monitoring board (check-in, materials, results, incidents) and incident feed with acknowledge/resolve, working offline | `claude/pu-monitoring-incidents` | PUs, Incidents |
| 3 | Official results: IReV per PU, EC8B/EC8C collations, CSV import, comparison with the PVT, evidence export | `claude/official-results` | Results → Official vs PVT |
| 4 | EC8A photos tied to the result reference, the agents' no-login upload link, offline upload queue, review, fingerprints | `claude/ec8a-photos` | Results → EC8A photos, `/u/{ref}/{token}` |
| 5 | Web Push alerts for urgent incidents and corrections | `claude/web-push` | More → Notifications |
| 6 | SMS/WhatsApp broadcasts with consent, opt-outs and delivery reports; public sign-up | `claude/broadcasts` | More → Broadcasts, `/join` |
| 7 | Digital town hall: stream, moderated questions, presenter view, SMS reminder | `claude/town-hall` | `/townhall`, More → Town hall |

Branches 2–7 are **stacked**: each is built on the one before, so `claude/town-hall` contains everything. None of them is merged into `main` yet.

## Taking it to the server (first deployment)

The upload package is built from `claude/town-hall` with `scripts/build-shared-hosting.sh`. The full steps are in `docs/DEPLOY-SHARED-HOSTING.md`; this is the short checklist.

- [ ] 1. In DirectAdmin, create a subdomain, e.g. `shield.techatronagency.com`, and turn on SSL (Let's Encrypt).
- [ ] 2. Choose PHP 8.3 or 8.4 for it.
- [ ] 3. Create a MySQL database and user, separate from the USSD service's.
- [ ] 4. Upload `election-shield-web-shared-hosting.zip` to `domains/shield.techatronagency.com/` and extract it. `election-shield-web/` must sit next to `public_html/`, not inside it.
- [ ] 5. Edit `election-shield-web/.env`: `APP_URL`, the `DB_*` values, `USSD_API_TOKEN` (the USSD server's `ELECTION_API_TOKEN`), and `VAPID_SUBJECT` (`mailto:` plus your email).
- [ ] 6. Open `https://shield.techatronagency.com/login` and create the first admin, using `ADMIN_PASSWORD` from `.env` as the setup key.
- [ ] 7. In the USSD server's `election-shield/.env`, set `DASHBOARD_WEBHOOK_URL=https://shield.techatronagency.com/api/ussd-events`, and set `DASHBOARD_API_TOKEN` and `DASHBOARD_WEBHOOK_SECRET` to `USSD_WEBHOOK_TOKEN` and `USSD_WEBHOOK_SECRET` from this app's `.env`.
- [ ] 8. Add the cron job (every minute): `/usr/local/bin/php /home/USER/domains/shield.techatronagency.com/election-shield-web/artisan schedule:run >> /dev/null 2>&1`
- [ ] 9. On the System page, press **Full import**, then check that the polling-unit count reads 3,308.
- [ ] 10. In the USSD console, press **Settings → Send all existing data to the dashboard**, then check that System → **Last event** shows it.
- [ ] 11. On the System page, check that **Scheduler cron** reads "Running" (it can take up to 2 minutes).
- [ ] 12. On the System page, press **Set up notifications**, then turn them on for your phone under More → Notifications and press **Send a test**.
- [ ] 13. Install the app on a phone (Android: Install app; iPhone: Share → Add to Home Screen).

**Smoke test after installing:** submit a test result and a test incident on the USSD sandbox (rehearsal mode). Within seconds they should appear on the Dashboard, PUs and Incidents pages, and the urgent incident should raise a push alert.

Record any problem found here under "Issues from deployment", with the page and the message.

## Issues from deployment

- (none yet)

## Next, after deployment

1. **Merge the branches** into `main` (six stacked branches) once the deployment works, so `main` is what runs on the server.
2. **USSD side:** add the EC8A upload link to the agents' SMS receipt (formula in `docs/WEB-APP-HANDOFF.md`). This goes in the `harmanicomputech/claude` repo.
3. **Privacy:** remove the owner's email address from `docs/WEB-APP-HANDOFF.md`, because the repo is public.
4. **Rehearsal** with real phones: agents submit on the USSD sandbox; coordinators follow the dashboards, acknowledge incidents, check photos and receive alerts. Fix what it turns up.
5. **Broadcast set-up:** Africa's Talking API key, a DND-capable sender ID, and the delivery-report and opt-out callback URLs (`docs/DEPLOY-SHARED-HOSTING.md` §8). WhatsApp only if a verified Meta business and approved templates are in place.
6. **Before election day:** Lighthouse checks on a real Android phone (PWA installability, Performance ≥ 90), a disk-space check for photos, a database backup routine, and a switch of Data shown back to real results.
7. **GitHub Actions:** CI can't run until the GitHub account's billing lock is cleared.

## Decisions still open

- Two-thirds of 13 LGAs is taken as 9 (`ELECTION_SPREAD_LGAS_REQUIRED`). Confirm this with the legal team.
- `ELECTION_PRINCIPAL_PARTY`, the party weak links are worked out for, is empty (so it follows the leader).
- A PU map needs PU coordinates, which the register doesn't have.
