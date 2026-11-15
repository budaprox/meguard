# Installing MEGuard

Everything goes into **one folder, `C:\MEGuard`**: the program, SpamAssassin, IPBan, the data and (if used)
MEGuard's own PHP. The steps are ordered so that **mail keeps flowing exactly as before until step 8**,
the switch-over – and that step is undone by clearing one field in MailEnable Administrator.

Run every `.cmd` file **as administrator** (right-click → *Run as administrator*, or from an elevated command prompt).

> Paste only the command itself into the command prompt, never the `C:\MEGuard>` prompt in front of it –
> `>` means redirection there and can overwrite a file.

**Contents:** 1. Copy and configure · 2. PHP · 3. Import from MxScan · 4. SpamAssassin · 5. Protection ·
6. Web interface (IIS / Apache) · 7. Check · 8. Switch-over · 9. Retire MxScan · Updating · Reference

---

## 1. Copy and configure

1. Copy the **contents** of the zip's `MEGuard` folder to `C:\MEGuard`.
2. Copy `config.sample.php` to **`config.php`** and open it in a text editor (e.g. Notepad++):
   - `'mailenable_root'` – the MailEnable folder (default `C:\Program Files (x86)\Mail Enable`)
   - `'timezone'` – your server's time zone, e.g. `'Europe/London'` or `'America/New_York'`
     (MailEnable and IPBan log local time)
   - `'server_name'` – e.g. `'mail.example.com'`
   - `'health' => ['alert_to' => '...']` – where the watchdog sends alerts (preferably a mailbox on this server)
   - Optional for the first days: `'mode' => 'monitor'` – MEGuard then only scores and logs, it never touches a message.

## 2. PHP

MEGuard needs **PHP 8.1 or newer** with `pdo_sqlite`, `mbstring` and `iconv`.

- **Recommended – MEGuard's own PHP:** `tools\install-php.cmd`. It downloads the latest PHP 8.3 (x64) from
  windows.php.net (or uses a `php-8.x-Win32-…-x64.zip` you put into `C:\MEGuard`), writes `php.ini` and checks it.
  Any PHP already on the server (e.g. an old PHP 7 for legacy websites) stays untouched.
  If it reports a missing Visual C++ runtime: install https://aka.ms/vs/17/release/vc_redist.x64.exe and run it again.
  *With IIS you can skip this step: `tools\install-iis.cmd` (step 6) runs it for you.*
- **An existing PHP 8.1+** (e.g. XAMPP's `C:\Xampp\php`): enable `extension=pdo_sqlite` in its `php.ini`.

All `.cmd` files and the filter use `C:\MEGuard\php\php.exe` if it exists, otherwise `C:\Xampp\php\php.exe`,
otherwise the first `php.exe` on the PATH.

## 3. Import lists and rules from MxScan (optional)

**Never used MxScan?** Skip this step. MEGuard works without it: SpamAssassin's rules and the built-in
defaults do the scoring, and you add whitelist/blacklist entries from the Log page as you go.

1. Run `tools\mdb_export.cmd`. It finds the MxScan database (read-only) and writes CSV files to
   `C:\MEGuard\import\<computer name>-export`.
2. Import them (several export folders may be given):
   ```
   cd C:\MEGuard
   php\php.exe tools\import_mxscan.php "C:\MEGuard\import\MAILSERVER-export"
   ```
   (Without MEGuard's own PHP use your PHP's path instead of `php\php.exe`. `dir C:\MEGuard\import` shows the folder name.)
3. Clean up – MxScan lists often contain overly broad patterns:
   - `tools\fix_whitelist.cmd` shows what it would fix; `tools\fix_whitelist.cmd --apply` does it
   - `tools\lists.cmd clear-black` empties the whole blacklist (optional; a backup is made first)

**Moving lists between MEGuard servers:** `tools\lists.cmd backup` on the old one, `tools\lists.cmd import <file.csv>` on the new one.

## 4. SpamAssassin (free, also for commercial use)

1. Download JAM Software's **SpamAssassin for Windows** zip (https://www.jam-software.com/spamassassin)
   and put it into `C:\MEGuard` or your Downloads folder.
2. `tools\install-spamd.cmd` – extracts it to `C:\MEGuard\spamassassin`, downloads the rules and creates:
   - **MEGuard - SpamAssassin spamd** – starts at boot on port 784, with a watchdog (restarted 10 s after any stop)
   - **MEGuard - SpamAssassin update** – updates the rules daily at 04:10
3. If `tools\check.cmd` (step 7) says that **uribl.com blocks your DNS server**, stop the useless lookups
   (faster scans, fewer timeouts) – once, in PowerShell, then restart SpamAssassin on the Resources page:
   ```
   Add-Content C:\MEGuard\spamassassin\etc\spamassassin\local.cf "`r`ndns_query_restriction deny multi.uribl.com`r`nscore URIBL_BLOCKED 0"
   ```
   Spamhaus refuses queries that come through large public resolvers (Google, Cloudflare…); use your own
   resolver or your provider's DNS on the mail server.
**Already running SpamAssassin for MailEnable?** (e.g. JAM's SpamAssassin as a service on port 783) You can let
MEGuard use it instead of installing a second copy: set `'spamd' => ['port' => 783, …]` in `config.php` and skip
`tools\install-spamd.cmd`. MEGuard's health watchdog and daily rule update then do not manage that SpamAssassin –
your own service does. Installing MEGuard's copy and stopping the old one is the simpler option to maintain.

4. **Bayes training** – `tools\install-bayes.cmd`: creates the **MEGuard - Bayes** task (daily at 02:50, SYSTEM),
   lets Bayes score after 100 spam / 100 ham, and starts the first run. It learns spam from the quarantine,
   from quarantined messages you delete and from users' Junk folders (messages older than 7 days); ham from
   messages released from quarantine. Restart SpamAssassin afterwards (Tools → Resources).

> **Repeat sender (automatic):** if one IP sends at least 5 suspicious (3+ points) messages within 24 hours from
> 3+ different (random) sender addresses – or with the same subject to 8+ recipients, scoring 5+ –, the IP is
> blacklisted for 30 days (marked "auto" on the Lists page, where it can be deleted). Settings: `config.php` → `burst`.

> **Backup:** `tools\install-backup.cmd` creates the **MEGuard - backup** task (daily at 01:20): a consistent
> database snapshot, `config.php`, the IPBan, SpamAssassin and Bayes settings in a dated ZIP
> (`C:\MEGuard\backup\MEGuard-YYYY-MM-DD_HHMMSS.zip`, kept for 21 days, with a `RESTORE.txt`).
> Let your own backup system pick it up from there. If no backup succeeds for 26 hours, the watchdog e-mails you.

## 5. Protection against break-in attempts

1. **IPBan** – `tools\install-ipban.cmd`: downloads the latest IPBan to `C:\MEGuard\ipban`, configures monitoring
   of MailEnable SMTP/POP/IMAP and RDP (5 failed attempts → banned for 1 hour, then 1 day, then 7 days) and starts
   it as a service. The server's own IP addresses are whitelisted automatically; add more with
   `-Whitelist "203.0.113.10,10.0.0.0/8"`. An older IPBan installation is stopped and its folder renamed to `.old`.
2. **Health watchdog** – `tools\install-health.cmd`: checks SpamAssassin and the mail services every 3 minutes,
   restarts whatever has stopped or hung, and e-mails `health` → `alert_to`. A SpamAssassin that is merely slow
   (e.g. during nightly backups) gets more patience: about 15 minutes, 30 within `busy_hours`.
3. **Country filter** (optional) – `tools\geo-update.cmd -Install`, then switch it on the **Country filter** page
   (after step 6). Login ports (POP, IMAP, submission, RDP) are then reachable only from the chosen countries;
   **mail reception (25) and webmail (443) always stay open.** Off by default.
   > If you protect the RDP port, from abroad you can reach the server only through another remote tool.
4. **Country score** (optional, `config.php` → `country_score`, off by default): adds points by the country of the
   *sending server* – 0 for allowed countries, `other_score` for the rest, `risk_score` for the listed high-risk
   sources. Gmail, Outlook.com etc. send from US/European servers and are not affected. Choose the allowed countries
   on the Country filter page first. The IP-to-country index it needs is built by the weekly **MEGuard - geo** task,
   so run `tools\geo-update.cmd -Install` even if you leave the firewall filter off.

## 6. Web interface

1. Password: `tools\setpass.cmd` → paste the printed `'password_hash' => '...'` line into the `'web'` section of `config.php`.
2. `'allowed_ips'`: if you work from fixed addresses, list them (empty = reachable from anywhere, protected by the
   password and a 15-minute IP lockout after 5 failed attempts).
3. Publish it with **one** of the following.

### A) IIS (also when MailEnable's web mail runs on IIS)

```
tools\install-iis.cmd
```

It installs MEGuard's own PHP if needed, enables the IIS *CGI* role service (FastCGI), creates a **MEGuard**
application pool and the **/meguard** application in the site that has an https binding (choose another with
`-Site "Site name"`). The `*.php` handler applies to this application only, so other sites and PHP versions on
the server are not affected. Then open `https://<your mail host>/meguard/`.

The pool runs as **LocalSystem**, like Apache does when XAMPP runs it as a service: the Resources page restarts
MailEnable services and SpamAssassin, which needs those rights. If you prefer a restricted pool, use
`-Identity ApplicationPoolIdentity`, grant it *Modify* on `C:\MEGuard\data` and *Read* on the MailEnable folder,
and restart services from the server instead. Remove everything with `tools\install-iis.cmd -Uninstall`.

### B) Apache with PHP 8.1+ (e.g. XAMPP)

Inside the https (443) `<VirtualHost>` of your web mail, before `</VirtualHost>`:
```
Alias /meguard "C:/MEGuard/web"
<Directory "C:/MEGuard/web">
    Options -Indexes
    AllowOverride None
    Require all granted
</Directory>
```
Restart Apache and open `https://<your mail host>/meguard/`. The restart buttons work when Apache runs as a
**Windows service** (XAMPP Control Panel → tick *Service* next to Apache).

### C) Apache with an older PHP (reverse proxy to MEGuard's own PHP)

`tools\install-php.cmd` also creates the **MEGuard - web** task, which serves the interface on `127.0.0.1:8099`.
In `httpd.conf` enable `mod_proxy`, `mod_proxy_http` and `mod_headers`, then inside the https `<VirtualHost>`
(remove any `Alias /meguard` lines):
```
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"
RedirectMatch ^/meguard$ /meguard/
ProxyPass        /meguard/ http://127.0.0.1:8099/
ProxyPassReverse /meguard/ http://127.0.0.1:8099/
```
(IIS with *Application Request Routing* can proxy to the same address, but option A is simpler.)

## 7. Check

`tools\check.cmd` – every important line should read `OK`. `WARN` lines are not blockers, but read them.

## 8. Switch-over (the only live step)

1. MailEnable Administrator → Servers → localhost → Services and Connectors → **MTA** → Properties.
2. Note the current content of **"Program to execute on mail file"** (e.g. the MxScan path) – you need it to go back.
3. Change it to **`C:\MEGuard\pickup.cmd`** and click OK.
4. Send a test message from outside (e.g. from Gmail) and look for it on MEGuard's **Log** page.
   Its headers contain an `X-MEGuard-Status:` line.

**To go back:** restore the value you noted in point 2.

**MailEnable's own filters** (Professional/Enterprise: MailEnable Administrator → Servers → localhost → Extensions →
Message Filter). Once MEGuard filters your mail:
- **SpamAssassin** – *Disable* it. Otherwise every message is scanned twice and the two decisions can contradict each other.
- **MailEnable Bayesian Filter** – *Disable* it. SpamAssassin's Bayes training in MEGuard does the same job.
- **MailEnable Antivirus Filter** – **keep it** as it is. MEGuard does not scan for viruses.

## 9. Retiring MxScan (after a few stable days)

- In MxScan Config: *Disable* + *Stop*.
- `Set-Service MxScanEngineME -StartupType Disabled` (PowerShell) – otherwise it starts again after a reboot.
- An old ME_FailToBan or IPBan installation is replaced by MEGuard's IPBan and country filter.

---

## Updating

Copy the new version's files over `C:\MEGuard`. `config.php` and the `data` folder are never overwritten.
New settings work with their defaults; the web interface shows a notice with the text to add to `config.php`
(also: `tools\config-check.cmd`). Re-run an `install-*.cmd` only when the release notes say so.

## Reference

| Folder / file | What it is |
|---|---|
| `config.php` | your settings |
| `data\meguard.sqlite` | log, lists, rules – one file (back it up!) |
| `data\quarantine\` | quarantine (daily folders, deleted after 30 days) |
| `data\logs\` | logs: `meguard-YYYYMMDD.log` (errors), `spamd.log`, `health.log`, `geo.log`, `backup.log`, `bayes.log` |
| `data\geo.json` | country filter settings |
| `data\geo\ip4.dat`, `ip6.dat` | IP-to-country index for the country score (rebuilt weekly) |
| `spamassassin\` | SpamAssassin (`etc\spamassassin\local.cf` – your own settings) |
| `ipban\` | IPBan (`ipban.config`) |
| `php\` | MEGuard's own PHP (if installed) |
| `backup\` | daily backup ZIPs |

| Scheduled task | When | What |
|---|---|---|
| MEGuard - SpamAssassin spamd | at boot | SpamAssassin, with watchdog |
| MEGuard - SpamAssassin update | daily 04:10 | rule update |
| MEGuard - health | every 3 minutes | health watchdog |
| MEGuard - Bayes | daily 02:50 | Bayes training |
| MEGuard - backup | daily 01:20 | backup ZIP |
| MEGuard - geo | Sundays 03:40 | country lists, IP-to-country index, country-filter firewall rules |
| MEGuard - IPBan firewall guard | every 15 minutes | keeps the IPBan firewall rules enabled |
| MEGuard - web | at boot | web interface for a reverse proxy (option C only) |

### Safety net

- If anything fails inside the filter (SpamAssassin down, database error…), the message **passes untouched**
  and the error is logged – MEGuard never loses mail.
- Spam is tagged and delivered to the Junk folder; strong spam and blacklisted mail go to the quarantine,
  from where one click releases them.
