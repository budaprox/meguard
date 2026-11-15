<?php
require __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/lib/Geo.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = Db::get()->prepare('SELECT * FROM messages WHERE id = ?');
$st->execute([$id]);
$m = $st->fetch();
if (!$m) redirect('index.php');
$d = json_decode((string)$m['details'], true) ?: [];

$senderAddr = $m['sender'];
$senderDomain = str_contains($senderAddr, '@') ? substr($senderAddr, strpos($senderAddr, '@') + 1) : '';

$qExists = false; $qHead = '';
if ($m['action'] === 'quarantine' && $m['qpath'] && !$m['released']) {
    try {
        $raw = Quarantine::read($m['qpath'], 65536);
        if ($raw !== null) {
            $qExists = true;
            $p = strpos($raw, "\r\n\r\n");
            $qHead = $p === false ? $raw : substr($raw, 0, $p);
        }
    } catch (Throwable) { }
}

function row(string $label, string $html): void { echo '<tr><th>' . h($label) . '</th><td>' . $html . '</td></tr>'; }

function act_btn(string $do, array $fields, string $label, string $cls = ''): string
{
    $s = '<form method="post" action="action.php" class="inline">' . csrf_field() . '<input type="hidden" name="do" value="' . h($do) . '">';
    foreach ($fields as $k => $v) $s .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    $conf = str_contains($cls, 'danger') ? ' data-confirm="' . h(t('confirm.delete')) . '"' : '';
    return $s . '<button class="' . h($cls) . '"' . $conf . '>' . h($label) . '</button></form>';
}

page_head(t('view.title'), 'index.php');
?>
<p><a href="index.php" class="back" data-back><?= h(t('view.back')) ?></a></p>

<div class="grid2">
<section class="card">
  <h2><?= h($m['subject'] ?: '—') ?></h2>
  <table class="kv">
    <?php
    row(t('log.time'), h(fmt_time((int)$m['ts'], true) . ':' . date('s', (int)$m['ts'])));
    row(t('log.sender'), '<span class="mono">' . h($m['sender'] ?: '—') . '</span>');
    row(t('view.from_hdr'), h($m['from_hdr']));
    row(t('view.recipients'), '<span class="mono">' . h($m['recipients']) . '</span>');
    row(t('log.ip'), '<span class="mono">' . h($m['ip']) . '</span>');
    if ($m['auth']) row(t('view.auth'), h($m['account']));
    row(t('view.size'), h(fmt_size((int)$m['size'])));
    row(t('view.file'), '<span class="mono">' . h($m['msgfile']) . '</span>');
    row(t('view.duration'), h($m['duration_ms'] . ' ms'));
    ?>
  </table>
</section>

<section class="card">
  <h2><?= h(t('view.decision')) ?></h2>
  <p class="big"><?= badge($m['result']) ?> <span class="muted">→ <?= h(t('act.' . $m['action'])) ?></span>
    <?php if ($m['score'] !== null): ?><span class="score"><?= h(number_format((float)$m['score'], 1)) ?></span><?php endif; ?></p>
  <?php if ($m['released']): ?><p class="flash ok"><?= h(t('view.released')) ?></p><?php endif; ?>
  <table class="kv">
    <?php
    if (!empty($d['whitelist_ignored'])) row(t('view.wl_ignored'), '<span class="warnc">' . h($d['whitelist_ignored']) . '</span>');
    if (!empty($d['list'])) row(t('view.listhit'), h(t('lists.' . $d['list']['kind'])) . ': <span class="mono">' . h($d['list']['value']) . '</span>');
    if (!empty($d['burst'])) row(t('view.burst'), h($d['burst']['comment'] ?? ''));
    if (array_key_exists('keywords', $d)) {
        $kw = array_map(fn($k) => h($k['name']) . ' <b>' . h((string)$k['score']) . '</b>', $d['keywords']);
        row(t('view.keywords'), $kw ? implode('<br>', $kw) : '<span class="muted">' . h(t('view.none')) . '</span>');
    }
    if (array_key_exists('dnsbl', $d)) {
        $dn = array_map(fn($k) => h($k['zone']) . ' <span class="muted">' . h($k['answer']) . '</span> <b>' . h((string)$k['score']) . '</b>', $d['dnsbl']);
        if (!empty($d['dnsbl_errors'])) foreach ($d['dnsbl_errors'] as $e) $dn[] = '<span class="warnc">' . h($e) . '</span>';
        row(t('view.dnsbl'), $dn ? implode('<br>', $dn) : '<span class="muted">' . h(t('view.none')) . '</span>');
    }
    if (!empty($d['country'])) {
        $co = $d['country'];
        $cls = $co['kind'] === 'allowed' ? 'muted' : 'warnc';
        row(t('view.country'), h(Geo::name($co['cc'])) . ' <span class="mono muted">' . h($co['cc']) . '</span> <span class="' . $cls . '">'
            . h(t('view.country.' . $co['kind'])) . '</span> <b>' . h((string)$co['score']) . '</b>');
    }
    if ($m['sa_score'] !== null) {
        row(t('view.sa'), '<b>' . h(number_format((float)$m['sa_score'], 1)) . '</b><div class="syms">' .
            implode(' ', array_map(fn($s) => '<span>' . h($s) . '</span>', $d['sa_symbols'] ?? [])) . '</div>');
    }
    if (!empty($d['sa_error'])) row(t('view.sa_error'), '<span class="warnc">' . h($d['sa_error']) . '</span>');
    if (!empty($d['sa_skipped'])) row(t('view.sa'), '<span class="muted">skipped: ' . h($d['sa_skipped']) . '</span>');
    if (!empty($d['would'])) row(t('view.would'), h(t('act.' . $d['would'])));
    if (!empty($d['error'])) row(t('res.error'), '<span class="warnc mono">' . h($d['error']) . '</span>');
    if (!empty($d['note'])) row('', '<span class="muted">' . h($d['note']) . '</span>');
    ?>
  </table>

  <h3><?= h(t('view.actions')) ?></h3>
  <div class="actions">
    <?php
    if ($qExists) {
        echo act_btn('release', ['id' => $m['id']], t('view.release'), 'primary');
        echo act_btn('qdelete', ['id' => $m['id']], t('view.qdelete'), 'danger');
    } elseif ($m['action'] === 'quarantine' && !$m['released']) {
        echo '<span class="muted">' . h(t('view.qgone')) . '</span>';
    }
    echo '</div><div class="actions">';
    if ($senderAddr !== '') {
        echo act_btn('listadd', ['list' => 'white', 'kind' => 'sender', 'value' => $senderAddr, 'back' => $m['id']], t('view.wl_sender'), 'good');
        if ($senderDomain) echo act_btn('listadd', ['list' => 'white', 'kind' => 'sender', 'value' => '*@' . $senderDomain, 'back' => $m['id']], t('view.wl_domain'), 'good');
        echo act_btn('listadd', ['list' => 'black', 'kind' => 'sender', 'value' => $senderAddr, 'back' => $m['id']], t('view.bl_sender'), 'bad');
        if ($senderDomain) echo act_btn('listadd', ['list' => 'black', 'kind' => 'sender', 'value' => '*@' . $senderDomain, 'back' => $m['id']], t('view.bl_domain'), 'bad');
    }
    if ($m['ip'] !== '' && Dnsbl::isPublicIp($m['ip'])) {
        echo act_btn('listadd', ['list' => 'black', 'kind' => 'ip', 'value' => $m['ip'], 'back' => $m['id']], t('view.bl_ip'), 'bad');
    }
    ?>
  </div>
</section>
</div>

<?php if ($qHead !== ''): ?>
<section class="card">
  <h2><?= h(t('view.headers')) ?></h2>
  <pre class="headers"><?= h(Message::toUtf8($qHead, '')) ?></pre>
</section>
<?php endif;
page_foot();
