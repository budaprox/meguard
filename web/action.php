<?php
// MEGuard – POST actions
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
if (!csrf_check()) { flash(t('msg.csrf'), 'err'); redirect('index.php'); }

$do = (string)($_POST['do'] ?? '');
if ($do === 'logout') { $_SESSION = []; session_destroy(); redirect('login.php'); }
require_login();

$db = Db::get();
// back to the same page (own, relative URLs only)
$back = 'index.php';
$ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
if (!empty($ref['path']) && (empty($ref['host']) || $ref['host'] === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST))) {
    $page = basename($ref['path']);
    if (preg_match('/^[a-z]+\.php$/', $page)) $back = $page . (isset($ref['query']) ? '?' . $ref['query'] : '');
}

try {
    switch ($do) {
        case 'listadd':
            $list = $_POST['list'] === 'black' ? 'black' : 'white';
            $kind = in_array($_POST['kind'] ?? '', ['sender', 'recipient', 'ip'], true) ? $_POST['kind'] : 'sender';
            $value = strtolower(trim((string)($_POST['value'] ?? '')));
            if ($value === '' || mb_strlen($value) > 255) throw new RuntimeException('empty or too long value');
            if ($kind === 'ip' && !preg_match('#^[0-9a-f:.*]+(/\d{1,3})?$#i', $value)) throw new RuntimeException('invalid IP');
            $st = $db->prepare('INSERT OR IGNORE INTO lists(list, kind, value, comment, created) VALUES(?,?,?,?,?)');
            $st->execute([$list, $kind, $value, trim((string)($_POST['comment'] ?? '')), time()]);
            // if it was on the other list, remove it from there
            if ($st->rowCount() > 0) {
                $db->prepare('DELETE FROM lists WHERE list = ? AND kind = ? AND value = ?')->execute([$list === 'white' ? 'black' : 'white', $kind, $value]);
            }
            flash(t($st->rowCount() ? 'msg.added' : 'msg.exists', ['v' => $value]));
            break;

        case 'listdel':
            $db->prepare('DELETE FROM lists WHERE id = ?')->execute([(int)$_POST['id']]);
            flash(t('msg.deleted'));
            break;

        case 'release':
        case 'qdelete':
            $st = $db->prepare('SELECT qpath FROM messages WHERE id = ? AND action = ?');
            $st->execute([(int)$_POST['id'], 'quarantine']);
            $qp = (string)$st->fetchColumn();
            if ($qp === '') throw new RuntimeException('not in quarantine');
            if ($do === 'release') {
                Quarantine::release($qp);
                $db->prepare('UPDATE messages SET released = 1 WHERE id = ?')->execute([(int)$_POST['id']]);
                flash(t('msg.released'));
            } else {
                Quarantine::delete($qp);
                $db->prepare('UPDATE messages SET released = 2 WHERE id = ?')->execute([(int)$_POST['id']]);
                flash(t('msg.deleted'));
            }
            break;

        case 'rulesave':
            $id = (int)($_POST['id'] ?? 0);
            $type = in_array($_POST['type'] ?? '', ['text', 'regex', 'special'], true) ? $_POST['type'] : 'text';
            $scope = in_array($_POST['scope'] ?? '', ['subject', 'body', 'headers', 'all'], true) ? $_POST['scope'] : 'all';
            $name = trim((string)($_POST['name'] ?? ''));
            $expr = trim(str_replace("\r", '', (string)($_POST['expr'] ?? '')));
            $score = (float)str_replace(',', '.', (string)($_POST['score'] ?? '0'));
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            if ($name === '' || ($expr === '' && $type !== 'special')) throw new RuntimeException('missing name or expression');
            if ($type === 'regex' && @preg_match('~' . str_replace('~', '\~', $expr) . '~iu', '') === false) throw new RuntimeException('invalid regular expression');
            if ($type === 'special') $expr = strtoupper($name);
            if ($id > 0) {
                $db->prepare('UPDATE rules SET name=?, type=?, scope=?, expr=?, score=?, enabled=? WHERE id=?')
                   ->execute([$name, $type, $scope, $expr, $score, $enabled, $id]);
            } else {
                $db->prepare('INSERT INTO rules(name, type, scope, expr, score, enabled, created) VALUES(?,?,?,?,?,?,?)')
                   ->execute([$name, $type, $scope, $expr, $score, $enabled, time()]);
            }
            flash(t('msg.saved'));
            break;

        case 'unban':
            require_once dirname(__DIR__) . '/lib/IPBanInfo.php';
            IPBanInfo::unban(trim((string)($_POST['ip'] ?? '')));
            flash(t('att.unbanned', ['ip' => (string)$_POST['ip']]));
            break;

        case 'wl_add':
        case 'wl_del':
            require_once dirname(__DIR__) . '/lib/IPBanInfo.php';
            $wl = trim((string)($_POST['ip'] ?? ''));
            IPBanInfo::whitelistSet($wl, $do === 'wl_add');
            flash(t($do === 'wl_add' ? 'att.wl_added' : 'att.wl_removed', ['ip' => $wl]));
            break;

        case 'cfg_dismiss':
            // hide the notice bar – until the list of missing settings changes (next version)
            Db::meta('cfg_dismiss', (string)($_POST['sig'] ?? ''));
            break;

        case 'sa_restart':
            require_once dirname(__DIR__) . '/lib/Service.php';
            $r = Service::restartSpamd();
            if ($r['ok']) flash(t('alert.sa_ok'));
            else flash(t('alert.sa_fail', ['e' => $r['message']]), 'err');
            break;

        case 'geo_save':
            require_once dirname(__DIR__) . '/lib/Geo.php';
            $ports = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string)($_POST['ports'] ?? ''))), fn($p) => $p > 0 && $p < 65536)));
            $cc = array_values(array_unique(array_filter(array_map('strtoupper', (array)($_POST['countries'] ?? [])), fn($c) => preg_match('/^[A-Z]{2}$/', $c))));
            $ex = [];
            foreach (preg_split('/[\r\n,;]+/', (string)($_POST['exceptions'] ?? '')) as $e) {
                $e = trim($e);
                if ($e === '') continue;
                [$ipp] = explode('/', $e, 2);
                if (!filter_var($ipp, FILTER_VALIDATE_IP)) throw new RuntimeException('invalid exception: ' . $e);
                $ex[] = $e;
            }
            $enabled = !empty($_POST['enabled']);
            if ($enabled && (!$cc || !$ports)) throw new RuntimeException(t('geo.need'));
            if ($enabled && in_array(25, $ports, true)) throw new RuntimeException(t('geo.no25'));
            Geo::save(['enabled' => $enabled, 'countries' => $cc, 'ports' => $ports, 'exceptions' => $ex]);
            $r = Geo::runNow();
            if ($r['ok']) flash(t('geo.saved_run'));
            else flash(t('geo.saved_norun', ['e' => $r['message']]), 'warn');
            break;

        case 'svc_restart':
            require_once dirname(__DIR__) . '/lib/Tools.php';
            $names = ($_POST['set'] ?? '') === 'mail' ? Tools::MAIL_SET : [(string)($_POST['name'] ?? '')];
            $r = Tools::restart($names);
            $label = ($_POST['set'] ?? '') === 'mail' ? t('tools.mailsvc') : (string)($_POST['name'] ?? '');
            if ($r['ok']) flash(t('tools.restarted', ['s' => $label]));
            else flash(t('tools.failed', ['s' => $label, 'e' => $r['message']]), 'err');
            break;

        case 'ruledel':
            $db->prepare('DELETE FROM rules WHERE id = ?')->execute([(int)$_POST['id']]);
            flash(t('msg.deleted'));
            break;
    }
} catch (Throwable $e) {
    flash(t('msg.error', ['e' => $e->getMessage()]), 'err');
}
redirect($back);
