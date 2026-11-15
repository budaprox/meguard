<?php
// MEGuard – country filter settings (data\geo.json) and status

declare(strict_types=1);

final class Geo
{
    public const TASK = 'MEGuard - geo';
    public const EU = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
    public const PRESET = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','NO','IS','LI','CH','GB','US'];
    // mail reception (25) and webmail (443) are deliberately not included
    public const DEFAULT_PORTS = [110, 995, 143, 993, 587, 465, 3389];

    public static function load(): array
    {
        $f = meg_data('geo.json');
        $c = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return array_merge(['enabled' => false, 'countries' => self::PRESET, 'ports' => self::DEFAULT_PORTS, 'exceptions' => []], is_array($c) ? $c : []);
    }

    public static function save(array $c): void
    {
        meg_ensure_dir(meg_data());
        file_put_contents(meg_data('geo.json'), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function status(): ?array
    {
        $f = meg_data('geo-status.json');
        if (!is_file($f)) return null;
        $raw = (string)file_get_contents($f);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        return json_decode($raw, true) ?: null;
    }

    /** Country codes found in the RIR lists (after the first update) */
    public static function knownCountries(): array
    {
        $f = meg_data('geo', 'countries.txt');
        $list = is_file($f) ? array_filter(array_map('trim', explode(',', (string)file_get_contents($f)))) : [];
        return $list ?: self::PRESET;
    }

    public static function name(string $cc): string
    {
        static $hu = ['AT'=>'Ausztria','BE'=>'Belgium','BG'=>'Bulgária','HR'=>'Horvátország','CY'=>'Ciprus','CZ'=>'Csehország','DK'=>'Dánia','EE'=>'Észtország','FI'=>'Finnország','FR'=>'Franciaország','DE'=>'Németország','GR'=>'Görögország','HU'=>'Magyarország','IE'=>'Írország','IT'=>'Olaszország','LV'=>'Lettország','LT'=>'Litvánia','LU'=>'Luxemburg','MT'=>'Málta','NL'=>'Hollandia','PL'=>'Lengyelország','PT'=>'Portugália','RO'=>'Románia','SK'=>'Szlovákia','SI'=>'Szlovénia','ES'=>'Spanyolország','SE'=>'Svédország','NO'=>'Norvégia','IS'=>'Izland','LI'=>'Liechtenstein','CH'=>'Svájc','GB'=>'Egyesült Királyság','US'=>'Egyesült Államok','RS'=>'Szerbia','UA'=>'Ukrajna','CA'=>'Kanada','RU'=>'Oroszország','CN'=>'Kína'];
        $lang = meg_config()['lang'] ?? 'en';
        if (class_exists('Locale')) {
            $n = Locale::getDisplayRegion('-' . $cc, $lang);
            if ($n && $n !== $cc) return $n;
        }
        static $en = ['AT'=>'Austria','BE'=>'Belgium','BG'=>'Bulgaria','HR'=>'Croatia','CY'=>'Cyprus','CZ'=>'Czechia','DK'=>'Denmark','EE'=>'Estonia','FI'=>'Finland','FR'=>'France','DE'=>'Germany','GR'=>'Greece','HU'=>'Hungary','IE'=>'Ireland','IT'=>'Italy','LV'=>'Latvia','LT'=>'Lithuania','LU'=>'Luxembourg','MT'=>'Malta','NL'=>'Netherlands','PL'=>'Poland','PT'=>'Portugal','RO'=>'Romania','SK'=>'Slovakia','SI'=>'Slovenia','ES'=>'Spain','SE'=>'Sweden','NO'=>'Norway','IS'=>'Iceland','LI'=>'Liechtenstein','CH'=>'Switzerland','GB'=>'United Kingdom','US'=>'United States','RS'=>'Serbia','UA'=>'Ukraine','CA'=>'Canada','RU'=>'Russia','CN'=>'China','AU'=>'Australia','NZ'=>'New Zealand','JP'=>'Japan','IN'=>'India','BR'=>'Brazil','MX'=>'Mexico','ZA'=>'South Africa','TR'=>'Turkey','IL'=>'Israel'];
        return $lang === 'hu' ? ($hu[$cc] ?? $cc) : ($en[$cc] ?? $cc);
    }

    /** Run the scheduled task now (changing the firewall needs administrator rights) */
    public static function runNow(): array
    {
        if (!function_exists('exec') || stripos(PHP_OS, 'WIN') !== 0) return ['ok' => false, 'message' => 'Works on Windows servers only.'];
        exec('schtasks /Run /TN "' . self::TASK . '" 2>&1', $o, $rc);
        if ($rc !== 0) return ['ok' => false, 'message' => t('geo.notask')];
        return ['ok' => true, 'message' => ''];
    }
}
