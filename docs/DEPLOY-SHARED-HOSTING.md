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

## 6. Add the cron job

In **Cron Jobs**, add one job that runs every minute (`* * * * *`):

```
/usr/local/bin/php /home/USERNAME/domains/shield.yourdomain.com/election-shield-web/artisan schedule:run >> /dev/null 2>&1
```

Use the real path shown in File Manager and your host's PHP 8.3+ binary. Within two minutes, **Scheduler cron** on the System page turns **Running**. From then on it syncs every 3 minutes.

## 7. Turn on notifications

On the **System** page, press **Set up notifications** once. Then each coordinator opens **More → Notifications** on their phone and presses **Turn on notifications**. On iPhone, add the app to the Home Screen first (iOS 16.4 or later). Set `VAPID_SUBJECT` in `.env` to a contact email, as `mailto:you@example.com`.

## 8. Add your team

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
