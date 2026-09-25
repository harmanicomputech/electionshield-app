# Deploying on DirectAdmin / cPanel (no terminal)

Everything is done through the hosting control panel and the app's own **System** page. You don't need SSH. It can sit on the same host as the USSD service, on its own subdomain (for example `shield.techatronagency.com`).

## What you need

- **PHP 8.3 or 8.4**, with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`, `ctype`.
- **A MySQL database** of its own. Don't reuse the USSD service's database.
- **HTTPS** on the subdomain (Let's Encrypt in the control panel). Installing the app and working offline both need it.
- **The zip:** `election-shield-web-shared-hosting.zip`, built with `scripts/build-shared-hosting.sh`. It includes every PHP library. The script prints the setup key.

## 1. Create the database

In **MySQL Databases**, create a database and a user with all privileges on it. Note the name, user and password.

## 2. Upload and extract

The zip contains two folders:

```
election-shield-web/   ← the application: must NOT be inside public_html
public_html/           ← web root: index.php, .htaccess, sw.js, manifest, css/js/icons
```

- **DirectAdmin:** in **File Manager**, open `domains/<subdomain>/`, upload the zip and extract it, so `election-shield-web/` sits next to `public_html/`.
- **If the subdomain's document root is a folder inside another site's `public_html`** (for example `public_html/shield/`), move the contents of the extracted `public_html/` there. `index.php` finds `election-shield-web/` up to three folders above itself.

Never put `election-shield-web/` inside `public_html`.

## 3. Edit `election-shield-web/.env`

Fill in every `CHANGE-ME`:

| Setting | Value |
| --- | --- |
| `APP_URL` | `https://shield.yourdomain.com` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from step 1 |
| `USSD_API_TOKEN` | `ELECTION_API_TOKEN` from the USSD server's `election-shield/.env` |
| `ELECTION_PRINCIPAL_PARTY` | the party weak links are worked out for, e.g. `APC` (optional) |

`APP_KEY`, `ADMIN_PASSWORD` (the setup key), `USSD_WEBHOOK_TOKEN` and `USSD_WEBHOOK_SECRET` are already generated.

## 4. Create the first admin

Open `https://shield.yourdomain.com/login`. Enter `ADMIN_PASSWORD` as the setup key, then your name, email and a password. This also creates the database tables. You land on the **System** page.

## 5. Connect the USSD service

In the **USSD server's** `election-shield/.env`, set:

```
DASHBOARD_WEBHOOK_URL=https://shield.yourdomain.com/api/ussd-events
DASHBOARD_API_TOKEN=<USSD_WEBHOOK_TOKEN from this app's .env>
DASHBOARD_WEBHOOK_SECRET=<USSD_WEBHOOK_SECRET from this app's .env>
```

Then:
1. On this app's **System** page, press **Full import**. It pulls the PU register (3,308 PUs), agents and all results.
2. In the USSD console, press **Settings → Send all existing data to the dashboard**.
3. On the System page, **Last event** should now show a recent event.

## 6. Keep background work running (no per-minute cron needed)

The catch-up sync (every 3 minutes), scheduled broadcasts and broadcast batches run in the background. Many shared hosts, DomainKing included, don't allow per-minute cron jobs, so the app doesn't depend on one. It uses the same approach as the USSD service:

1. **Automatically after page visits.** Once a page has gone to the browser, the app does any work that is due, at most every 15 seconds. You don't need to set anything up.
2. **A free pinger, every minute. Set this up.** It covers quiet periods. Create a free account at **cron-job.org** (or UptimeRobot) and add a job that opens the **pinger URL** from the System page (`https://…/cron/<secret>`) **every minute**. It's an ordinary web visit, so shared-hosting rules allow it. Keep the URL secret.
3. **Your host's cron, hourly, as a backup** (optional): `0 * * * * /usr/local/bin/php /home/USERNAME/domains/shield.yourdomain.com/election-shield-web/artisan app:tick >> /dev/null 2>&1`

If your host does allow a per-minute cron, `* * * * * … artisan schedule:run` works too. **Check it:** on the System page, **Background work** turns **Running** and says how it last ran (after a page visit, by the pinger, or by cron).

## 7. Turn on notifications

On the **System** page, press **Set up notifications** once. Then each coordinator opens **More → Notifications** on their phone and presses **Turn on notifications**. On iPhone, add the app to the Home Screen first (iOS 16.4 or later). Set `VAPID_SUBJECT` in `.env` to a contact email, as `mailto:you@example.com`.

## 8. Broadcasts (SMS and WhatsApp)

**SMS.** Put the Africa's Talking username, API key and (if you have one) approved sender ID in `.env`. Then, in the Africa's Talking dashboard (SMS → Callback URLs), set:

- **Delivery reports:** `https://shield.yourdomain.com/api/sms/delivery/<SMS_CALLBACK_SECRET>`
- **Bulk SMS opt-out:** `https://shield.yourdomain.com/api/sms/opt-out/<SMS_CALLBACK_SECRET>`

Nigerian networks block promotional SMS to numbers on the do-not-disturb (DND) list unless the sender ID is registered for it. Ask Africa's Talking about a registered or transactional sender ID before election week.

**WhatsApp** (optional) needs a verified business on the WhatsApp Business Platform and templates approved in WhatsApp Manager. Put the phone number ID, a permanent access token and the app secret in `.env`. In the Meta app, set the webhook to `https://shield.yourdomain.com/api/whatsapp`, with `WHATSAPP_VERIFY_TOKEN` as the verify token, and subscribe to **messages**.

**Who gets what:** supporters receive broadcasts only if they opted in (the public sign-up page `/join`, or an import marked "yes"). Agents and coordinators get operational SMS. Anyone who replies STOP is never messaged again on that channel. Batches are sent by the background work (step 6), about 100 numbers per request.

## 9. Add your team

Under **Users**, give each coordinator their own account. Admins manage users and the connection; coordinators see the dashboards. Every login and change is in the **Audit log**.

On phones: open the site in Chrome and press **Install app** (Android), or in Safari tap Share → **Add to Home Screen** (iPhone).

## EC8A photos and disk space

Photos are stored in `election-shield-web/storage/app/private/ec8a/`, outside the web root, and are shown only to logged-in users. Each is about 100–500 KB after the phone shrinks it, so photos of all 3,308 PUs need roughly 1–2 GB. Check your hosting plan's disk quota before election day, and back up that folder with the database afterwards, as it is evidence.

## Rehearsals

Results sent while the USSD service is in rehearsal mode are marked as rehearsal. Under **System → Data shown**, switch to **rehearsal data** during the practice and back to **real results** afterwards. Real and rehearsal figures are never added together.

## Updating

Build with `scripts/build-shared-hosting.sh --update`. The zip has no `.env`, so your settings are kept. Upload it, extract it over the old files, then press **System → Update database**.

## Troubleshooting

- **The System page says "Events are refused":** `USSD_WEBHOOK_TOKEN` / `USSD_WEBHOOK_SECRET` are empty.
- **The USSD console shows webhook failures with 401:** the token or secret differs between the two `.env` files.
- **Sync fails with 401:** `USSD_API_TOKEN` is not the USSD server's `ELECTION_API_TOKEN`.
- **The dashboard is empty during a rehearsal:** switch **Data shown** to rehearsal data.
