# Changelog

## 0.3.0 – first public release

- **English first:** interface, `config.sample.php`, tool output, alerts, code comments and documentation
  (`README.md`, `INSTALL.md`) are in English; the interface is also available in Hungarian (`'lang' => 'hu'`).
- **IIS support:** `tools\install-iis.cmd` publishes the web interface on IIS through FastCGI with MEGuard's own PHP –
  only built-in Windows components, the PHP handler applies to the /meguard application only.
- **MEGuard's own PHP** (`tools\install-php.cmd`, formerly `extra\sajat-php`) can download the latest PHP 8.3 by itself
  (checksum verified) and has an IIS-ready `php.ini`; `-NoWebService` skips the 127.0.0.1:8099 task.
- PHP is found in this order everywhere: `MEGuard\php`, `C:\Xampp\php`, then `php.exe` on the PATH.
- Safer defaults for servers anywhere in the world: the **country score is off** by default (enable it once the
  Country filter lists the countries you expect mail from), time zone `UTC` until you set yours.
- `tools\check.cmd` only tests the 127.0.0.1:8099 web service when the "MEGuard - web" task exists.
- English country names in the interface even without PHP's `intl` extension.
- Removed the one-off `tools\rename-lowercase.*` migration helper.
- Documentation: MxScan is optional; how to treat MailEnable's own SpamAssassin, Bayesian and antivirus filters;
  using an existing SpamAssassin instead of MEGuard's copy. MEGuard does not scan for viruses.

## 0.2.1 and earlier

Private releases running on the author's servers (filter, quarantine, lists, keyword rules, SpamAssassin
watchdog, Bayes training, IPBan, attacks page, country filter and country score, Resources page, health
watchdog, backup, MxScan import).
