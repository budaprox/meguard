<?php
// MEGuard – settings
// Copy this file to config.php and edit that copy. Updates never overwrite config.php.
// tools\config-check.cmd lists settings added in newer versions that your config.php lacks.

return [

    // ---- Paths ---------------------------------------------------------------

    // MailEnable installation folder (contains the Queues and Logging folders)
    'mailenable_root' => 'C:\\Program Files (x86)\\Mail Enable',

    // Data folder: database, quarantine, diagnostics. Must NOT be reachable from the web!
    'data_dir' => __DIR__ . '\\data',

    // ---- Operating mode ------------------------------------------------------

    // 'live'    – the filter decides and acts
    // 'monitor' – analyse and log only, never touch the message (good for the first days)
    'mode' => 'live',

    // Save the queue file and headers of the first N messages to data\diag (0 = off)
    'diagnostic_capture' => 5,

    // Which MailEnable connectors' messages are scanned (2nd parameter of the pickup event)
    'connectors' => ['SMTP'],

    // Scan authenticated users (outgoing mail)? By default they are only logged.
    'scan_authenticated' => false,

    // Trusted sender IPs / networks: pass without a scan (e.g. the local web mail)
    'trusted_ips' => ['127.0.0.1', '::1'],

    // ---- SpamAssassin (spamd) ----------------------------------------------

    'spamd' => [
        'enabled'  => true,
        'host'     => '127.0.0.1',
        'port'     => 784,         // 784, so it can run next to MxScan's own spamd on 783
        'timeout'  => 30,          // seconds
        'max_size' => 262144,      // larger messages are not sent to SpamAssassin (bytes)
    ],

    // ---- DNS blocklists (checked against the sending IP) ----------------------

    // 'hit' => '*' any 127.0.0.x answer is a hit; or a specific address
    'dnsbl' => [
        ['zone' => 'zen.spamhaus.org', 'hit' => '*', 'score' => 6, 'enabled' => true],
        ['zone' => 'bl.spamcop.net',   'hit' => '127.0.0.2', 'score' => 4, 'enabled' => true],
    ],

    // ---- Country score (by the country of the SENDING SERVER) --------------------
    // Mail from allowed countries scores 0 (default: the Country filter page's list, EU + EEA + CH + UK + US).
    // Other countries add other_score, the listed high-risk sources add risk_score to the total.
    // Gmail/Outlook/etc. mail comes from the providers' (US/European) servers and is not affected.
    // OFF by default: switch it on once the Country filter page lists the countries you expect mail from.
    // Strict example: other_score = 8 → at least Junk; risk_score = 10 → quarantine.
    // If expected mail from such a place goes missing, whitelist the sender (the whitelist wins over everything).
    // The IP→country index is rebuilt weekly by the "MEGuard - geo" task (tools\geo-update.cmd -Install),
    // also when the firewall country filter itself is off.
    'country_score' => [
        'enabled'        => false,
        'allow'          => null,     // null = the Country filter page's countries; or your own list: ['US', 'CA', ...]
        'other_score'    => 8,
        'risk_score'     => 10,
        'risk_countries' => ['CN', 'HK', 'IN', 'ID', 'PH', 'VN', 'TH', 'MY', 'KH', 'BD', 'PK', 'LK', 'NP',
                             'BR', 'AR', 'CO', 'VE', 'PE', 'MX', 'NG', 'GH', 'KE', 'ZA', 'EG', 'MA', 'DZ', 'TN',
                             'RU', 'BY', 'KZ', 'UZ', 'IR', 'IQ', 'SY', 'TR', 'SA', 'AE', 'KP', 'KR', 'TW', 'SG'],
    ],

    // ---- Decision ------------------------------------------------------------

    // Score thresholds (sum of SpamAssassin + keyword rules + DNS lists + country score)
    'thresholds' => [
        'low'    => 8,
        'medium' => 10,
        'high'   => 15,
    ],

    // Actions: 'pass' | 'tag' | 'quarantine' | 'delete'
    // 'tag' = subject prefix + Junk header
    'actions' => [
        'low'       => 'tag',
        'medium'    => 'quarantine',
        'high'      => 'quarantine',   // once you trust it: 'delete'
        'blacklist' => 'quarantine',   // once you trust it: 'delete'
    ],

    'tag_subject' => [
        'low'    => '[SPAM?]',
        'medium' => '[SPAM-MEDIUM]',
        'high'   => '[SPAM-HIGH]',
        'blacklist' => '[SPAM-BLACKLIST]',
    ],

    // Makes MailEnable deliver to the Junk folder
    'junk_header' => 'X-ME-Content: Deliver-To=Junk',

    // "Repeat sender": if within window_hours one IP sends at least min_messages messages scoring min_score or more
    // each, AND from at least min_senders different sender addresses (random addresses) OR with the same subject to
    // at least min_recipients different recipients (then the current message needs subject_min_score, because a
    // legitimate newsletter can also go to many people), the IP is blacklisted for ban_days days.
    // Never applies to authenticated, trusted or whitelisted senders. Shown as "auto" on the Lists page.
    'burst' => [
        'enabled'        => true,
        'window_hours'   => 24,
        'min_messages'   => 5,
        'min_score'      => 3.0,
        'min_senders'    => 3,
        'min_recipients' => 8,
        'subject_min_score' => 5.0,
        'ban_days'       => 30,
    ],

    // SpamAssassin Bayes training (tools\install-bayes.cmd): nightly, from the quarantine and from Junk-folder
    // messages older than junk_min_age_days (spam), and from messages released from quarantine (ham).
    // junk_folders: folder names as stored by MailEnable (IMAP modified UTF-7 for non-ASCII names).
    'bayes' => [
        'enabled'           => true,
        'dbpath'            => 'C:\\Windows\\System32\\config\\systemprofile\\.spamassassin',
        'junk_folders'      => ['Junk E-mail', 'Lev&AOk-lszem&AOk-t', 'Junk', 'Spam'],
        'junk_min_age_days' => 7,
        'max_per_run'       => 3000,
    ],

    // Backup (tools\\install-backup.cmd: daily at 01:20): a consistent database snapshot + config.php, IPBan,
    // SpamAssassin and Bayes data in a dated ZIP. Let your existing backup system pick it up from there.
    'backup' => [
        'dir'        => 'C:\\MEGuard\\backup',
        'keep_days'  => 21,        // older ZIPs are deleted
        'quarantine' => false,     // include the quarantine too (bigger ZIP)
    ],

    // Quarantine retention in days (older items are deleted by maintenance)
    'quarantine_days' => 30,

    // Log retention in days
    'log_days' => 90,

    // ---- Health watchdog and web interface -------------------------------------

    // Health watchdog (tools\install-health.cmd): checks every 3 minutes and restarts what is needed.
    // alert_to: e-mail address for every automatic action (preferably a mailbox on this server)
    'health' => [
        'enabled'    => true,
        'spamd'      => true,     // restart SpamAssassin if it stops scanning
        'services'   => true,     // start stopped MailEnable services and IPBan
        'alert_to'   => '',       // e.g. 'admin@example.com'
        'alert_from' => '',       // empty = alert_to
        'smtp'       => '127.0.0.1:25',
        // Patience: if SpamAssassin runs but is slow (e.g. during nightly backups), it is restarted only after this
        // many failed checks in a row (every 3 minutes); within busy_hours after busy_factor times as many.
        // If spamd is not running at all, it is restarted at the 2nd check regardless.
        'sa_timeout'    => 60,             // seconds to wait for the test message
        'sa_slow_fails' => 5,              // about 15 minutes
        'busy_hours'    => '00:00-06:00',  // nightly backup window ('' = none)
        'busy_factor'   => 2,              // about 30 minutes within the window
    ],

    'web' => [
        // Password hash: created by tools\setpass.cmd
        'password_hash' => '',
        // Reachable only from these IPs/networks, e.g. ['203.0.113.10', '10.0.0.0/8'].
        // Empty list = from anywhere (then the password and the lockout protect it: 15-minute ban after 5 failures)
        'allowed_ips' => [],
        // redirect http -> https (not applied when opened locally on the server)
        'force_https' => true,
        // session length in minutes
        'session_minutes' => 240,
    ],

    // Time zone of the server (MailEnable and IPBan log local time). Use a PHP time zone name,
    // e.g. 'Europe/London', 'America/New_York', 'Australia/Sydney'. See https://www.php.net/timezones
    'timezone' => 'UTC',

    // Interface language: 'en' or 'hu' (add lang/xx.php to translate)
    'lang' => 'en',

    // Server name in headers and in the interface
    'server_name' => gethostname() ?: 'mailserver',
];
