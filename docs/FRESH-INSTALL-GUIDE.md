# Election Shield: fresh installation guide (update 11)

This guide reinstalls the **web app** (`electionshield.techatronagency.com`) from scratch. It also brings the **USSD service** (`ussd.techatronagency.com`) up to date by replacing 9 of its files. The USSD service is **not** reinstalled: it holds your agents, PINs and every submission. The web app rebuilds its data from the USSD service after installing.

Allow about 30–45 minutes. Do the parts in order: A (USSD) first, then B (web app).

## What you need

| File | For | Size |
| --- | --- | --- |
| `0-election-shield-USSD-changed-files.zip` | USSD service: 9 changed files | tiny |
| `election-shield-web-FRESH-complete.zip` | Web app: everything (the application, its libraries and `public_html`), with a ready-made `.env` | 24 MB |

**Keep the web app's `.env` private.** It is already filled in with your settings: the web address, your database details, the tokens that connect it to the USSD service, and the same app key as before, so your cron-job.org pinger address does not change.

---

## Part A: update the USSD service (about 5 minutes)

1. **Back up first.** In DirectAdmin → **File Manager**, open `domains/ussd.techatronagency.com/`. Right-click `election-shield` → **Compress** (zip) so you have a copy to go back to.
2. **Upload** `0-election-shield-USSD-changed-files.zip` into `domains/ussd.techatronagency.com/`, the folder that contains `election-shield/`.
3. **Extract** it there. When asked, **overwrite** the existing files. These 9 files are replaced or added:

   | File (inside `election-shield/`) | What it is |
   | --- | --- |
   | `app/Http/Controllers/Api/AgentAccountController.php` | **new**: agent sign-in, adding agents and resetting PINs for the web app |
   | `app/Http/Controllers/Api/FieldController.php` | **new**: agents' web submissions, with the same rules as USSD |
   | `app/Models/Incident.php` | now records where a report came from (USSD or web app) |
   | `app/Models/MaterialReport.php` | the same |
   | `app/Models/Presence.php` | the same |
   | `app/Models/Result.php` | the same |
   | `app/Services/ElectionRecorder.php` | records the channel and allows longer web notes |
   | `database/migrations/2026_09_27_000001_add_channel_to_submissions.php` | **new**: adds the channel to the database |
   | `routes/api.php` | the new web-app routes |

4. **Update the database.** Open `https://ussd.techatronagency.com/admin`, log in, and on **Overview** press **Set up / update database**.
5. **Nothing changes in its `.env`.** `ELECTION_API_TOKEN` and the three `DASHBOARD_…` lines stay as they are.
6. **Check it:** dial `*384*92342#` and use the menu as usual. USSD works exactly as before.

---

## Part B: fresh installation of the web app

### B1. Before you start (5 minutes)

1. **Optional backup.** In the current web app, open **System → Backup** and download the zip. Everything in the web app comes back from the USSD service, but the backup keeps the town hall questions, broadcasts, contacts and audit log.
2. **Keep the old version** so you can go back. In File Manager, open `domains/electionshield.techatronagency.com/`:
   - rename `election-shield-web` to `election-shield-web-old`;
   - select everything **inside** `public_html` and **Compress** it to `public_html-old.zip`, then delete those files. Leave the empty `public_html` folder in place.

### B2. Empty the database (2 minutes)

The new version creates its tables itself, so the database must be empty.

1. DirectAdmin → **MySQL Management** → `techatron_shieldweb` → **phpMyAdmin**.
2. Click the database name on the left, tick **Check all** under the table list, choose **Drop**, and confirm.
3. The database now has no tables. Its name, user and password stay the same; the new `.env` already uses them.

(If you would rather keep the old data, create a **new** database and user instead, and change the four `DB_…` lines in `election-shield-web/.env` after step B3.)

### B3. Upload and extract (10 minutes)

1. In File Manager, open `domains/electionshield.techatronagency.com/`.
2. **Upload** `election-shield-web-FRESH-complete.zip` there.
3. **Extract** it in that same folder. Overwrite if asked.
4. Check the layout. It must look like this, with `election-shield-web` **next to** `public_html`, not inside it:

   ```
   domains/electionshield.techatronagency.com/
   ├── election-shield-web/        ← the app (has .env, app/, vendor/ …)
   └── public_html/                ← index.php, .htaccess, .user.ini, css/, js/, icons/, sw.js
   ```

   `.env`, `.htaccess` and `.user.ini` are hidden files. Turn on **Show hidden files** in File Manager if you can't see them.
5. Delete the zip from the server.

### B4. Check the settings (5 minutes)

Open `election-shield-web/.env` in the File Manager editor. These are already filled in; check them:

| Setting | Value |
| --- | --- |
| `APP_URL` | `https://electionshield.techatronagency.com` |
| `APP_KEY` | the same as before, so the pinger address is unchanged |
| `ADMIN_PASSWORD` | the **setup key** for step B6 (the same as before) |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `techatron_shieldweb` / `techatron_shieldweb` / your password |
| `USSD_WEBHOOK_TOKEN`, `USSD_WEBHOOK_SECRET` | the same as before, matching the USSD service's `DASHBOARD_API_TOKEN` and `DASHBOARD_WEBHOOK_SECRET` |
| `USSD_API_URL`, `USSD_API_TOKEN` | `https://ussd.techatronagency.com/api` and the USSD service's `ELECTION_API_TOKEN` |
| `USSD_SERVICE_CODE` | `"*384*92342#"` (shown to agents) |
| `MEDIA_MAX_VIDEO_MB` | `100` (largest agent video) |
| `VAPID_SUBJECT` | `mailto:support@hostcommsummit.com` (contact for push notifications) |

Add these when you have them (the app works without them):

| Setting | What it switches on |
| --- | --- |
| `ANTHROPIC_API_KEY=` | Reading IReV result sheets with AI (key from console.anthropic.com). Without it, IReV sheets are downloaded for a person to enter. |
| `AFRICASTALKING_USERNAME=` and `AFRICASTALKING_API_KEY=` | SMS broadcasts (the same Africa's Talking account as the USSD service). |

### B5. PHP settings (5 minutes)

DirectAdmin → **Select PHP version** (or **PHP settings**) for `electionshield.techatronagency.com`:

1. **PHP 8.3 or 8.4.**
2. Extensions switched on: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `zip`, `bcmath` (and `gmp` if offered: it makes push notifications faster).
3. Upload limits. `public_html/.user.ini` asks for 128 MB; if the host ignores it, set these by hand so agents can send videos of up to 100 MB:
   - `upload_max_filesize = 128M`
   - `post_max_size = 130M`
   - `max_execution_time = 300`
   - `memory_limit = 256M`

### B6. Create the first admin (2 minutes)

1. Open `https://electionshield.techatronagency.com/login`.
2. You see **Create the first admin**. Enter:
   - **Setup key:** the `ADMIN_PASSWORD` value from `.env`;
   - your name, email and a password of at least 10 characters.
3. Press **Create admin account**. This builds the database and loads the register of **3,308 polling units**, then opens the System page with "Welcome …".

If you see "Database not reachable" instead, check the `DB_…` lines in `.env`.

### B7. Connect to the USSD service (5 minutes)

On the **System** page:

1. **Read API:** press **Full import**. This brings in the agents and everything already submitted. The Sync status list fills in.
2. **Webhook:** in the USSD console (`ussd.techatronagency.com/admin`), open **Settings** and press **Send all existing data to the dashboard**. Back on the web app's System page, **Last event** shows a time from just now.
3. **Background work:** it should read **Running (by the pinger)** within a couple of minutes. The pinger address is unchanged because `APP_KEY` is the same. If it doesn't, copy the **pinger URL** from the System page into your cron-job.org job again.
4. **Agents' photos, videos and AI:**
   - **Largest upload** should be at least 100 MB (otherwise see B5);
   - **Agent sign-in** should read **Through the USSD service**.

### B8. Notifications (5 minutes)

1. System → **Set up notifications**. This creates new keys, because the database is new.
2. On each phone or computer that should get alerts, open **Notifications**, press **Turn on notifications**, then **Send a test**. Devices that had notifications before must turn them on again.

### B9. Your team, roles and agents (10 minutes)

1. **Roles** (Admin → Roles): check what **Coordinator** and **Observer** may do. Untick or tick permissions as you like; changes apply straight away. Add your own roles if needed (for example "LGA supervisor").
2. **Users** (Admin → Users): add each staff member with their role and, for coordinators, a **home LGA**. That sets which pop-ups and alerts they get and their default filters.
3. **Agents** (Election day → Agents): agents come from the USSD service (step B7).
   - To add a new one, use **＋ Add an agent**: name, phone, PU code, and tick **Send the PIN by SMS**.
   - To give an existing agent a new PIN, use **Reset PIN**.
   - Agents sign in at `https://electionshield.techatronagency.com/login?as=agent` with their phone number and the **same 4-digit PIN they use on USSD**.

### B10. IReV, fetched automatically (5 minutes)

1. Open **Official vs PVT → IReV (automatic)** and press **Find the election on IReV**.
   - Before election day there is no 2027 election on IReV yet. For a rehearsal, choose a past Ebonyi election.
2. Choose it, press **Follow this election**, then **Check now**.
3. Read the counts on the right:
   - **Read and saved** or **Downloaded**: it works.
   - **IReV refused the download**: IReV's image store doesn't serve your server. Each sheet's link is listed for a person to open and enter by hand.
   - **Not matched to our register**: the IReV polling units don't match your register's codes or names. Match them by typing our PU code, or replace the register with INEC's (System → Polling unit register).
4. A result saved automatically shows **Not yet checked**. Open the PU (Official vs PVT → Enter IReV result → "show them"), compare the figures with the sheet and press **Save**.
5. After a rehearsal, turn automatic fetching **off**, or follow the real election when it appears.

### B11. Test it (10 minutes)

Use **rehearsal mode** in the USSD console (Settings) while you test, then clear the test data afterwards.

| Test | What should happen |
| --- | --- |
| Log in as an agent on a phone (`/login?as=agent`) | The agent's own Home page: check in, materials, result, incident |
| Open the app as an agent | The phone asks to use your location: tap **Allow** |
| Agent: **I'm at my polling unit**, then a materials button | "Presence confirmed", "Materials report saved". If location was refused: "Location is needed to check in…" |
| Admin: **Election day → Locations** | The check-in with a map link. Tap **Use as the PU's location** once for a PU you trust; later check-ins show "At the PU" or how far away they were |
| Agent: **Submit result** with a photo of the sheet | "Result submitted. Ref: RS…. With 1 photo." |
| Agent: **Report an incident**, "Violence", with a photo or short video | "Incident logged" |
| Agent: turn on flight mode and report a delay | "Saved on this phone"; when back online, "Sent from this phone …" |
| Staff dashboard, within 20 seconds | Pop-ups for the incident and the result, "via Web app", with the agent's name and number |
| Pop-up: **Resolve…** → note → **Mark resolved** | It disappears for everyone |
| Pop-up: **Remind me… in 5 min** | It comes back in 5 minutes |
| Incidents page | The incident with "via Web app" and its photo or video |
| Results → Photos & videos | The files, with Checked / Doubtful buttons |
| Dial USSD as usual | Works as before; its reports show "via USSD" |
| Log in as a coordinator | No Users, Roles or System in the menu |

When you're done:
- **USSD console:** clear the test data, and turn rehearsal mode off.
- **Web app:** System → **Clear rehearsal data**.

### B12. When everything works

Delete `election-shield-web-old` and `public_html-old.zip` (or keep them for a week).

---

## If something goes wrong

| What you see | What to do |
| --- | --- |
| **500 error / blank page** | Check PHP is 8.3+, and that `election-shield-web` sits next to `public_html`. The error details are in `election-shield-web/storage/logs/`. |
| "the election-shield folder was not found" | `election-shield-web/` must be next to `public_html/`, not inside it. |
| "Database not reachable" | Check `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env` and that the user has all privileges on the database. |
| The setup form doesn't appear (login form instead) | The database still has users: empty it (B2), or log in with an existing account. |
| "Wrong setup key" | Use the exact `ADMIN_PASSWORD` value from `.env`. |
| Full import fails with 401 | `USSD_API_TOKEN` must equal `ELECTION_API_TOKEN` in the USSD service's `.env`. |
| Agents get "Could not reach the USSD service" | Same as above; also check that Part A was done and **Set up / update database** was pressed in the USSD console. |
| **Last event** never changes | The USSD service's `DASHBOARD_API_TOKEN` / `DASHBOARD_WEBHOOK_SECRET` must equal `USSD_WEBHOOK_TOKEN` / `USSD_WEBHOOK_SECRET` here, and its background work must be running. |
| Videos fail to upload | Raise the upload limits (B5); the System page shows the limit in force. |
| No pop-ups | The person's role needs "Respond to incidents" / "Acknowledge results", and pop-ups only cover reports from the last 12 hours. |
| No push notifications | Set up notifications (B8) and turn them on again on each device. |

## Going back

If you need the old version:
1. Rename `election-shield-web` to `election-shield-web-new` and `election-shield-web-old` back to `election-shield-web`.
2. Extract `public_html-old.zip` into `public_html`.

The emptied database needs the old tables back: restore it from your host's backup, or run a fresh setup of the old version with its setup key. The USSD service's changes work with both versions.
