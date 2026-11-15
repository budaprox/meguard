<?php
require __DIR__ . '/_init.php';

$err = '';
$hash = $CFG['web']['password_hash'] ?? '';
// Brute-force protection: 5 failures per IP / 15 minutes, then lockout
const MEG_MAX_FAILS = 5;
const MEG_LOCK_SECONDS = 900;
$db = Db::get();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$db->prepare('DELETE FROM login_fail WHERE ts < ?')->execute([time() - 86400]);
$st = $db->prepare('SELECT COUNT(*), MAX(ts) FROM login_fail WHERE ip = ? AND ts > ?');
$st->execute([$ip, time() - MEG_LOCK_SECONDS]);
[$fails, $last] = $st->fetch(PDO::FETCH_NUM);
$locked = (int)$fails >= MEG_MAX_FAILS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($locked) {
        $err = t('login.locked', ['m' => (int)ceil(((int)$last + MEG_LOCK_SECONDS - time()) / 60)]);
    } elseif (!csrf_check()) {
        $err = t('msg.csrf');
    } elseif ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
        $db->prepare('DELETE FROM login_fail WHERE ip = ?')->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['seen'] = time();
        redirect('index.php');
    } else {
        $db->prepare('INSERT INTO login_fail(ip, ts) VALUES(?, ?)')->execute([$ip, time()]);
        meg_errlog('Failed login: ' . $ip);
        usleep(random_int(400000, 900000));
        $err = ((int)$fails + 1 >= MEG_MAX_FAILS) ? t('login.locked', ['m' => (int)(MEG_LOCK_SECONDS / 60)]) : t('login.wrong');
    }
}
page_head(t('login.title'));
?>
<div class="login card">
  <h1><?= h(t('login.title')) ?></h1>
  <?php if ($hash === ''): ?>
    <p class="muted"><?= h(t('login.nopass')) ?></p>
  <?php else: ?>
    <?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label><?= h(t('login.password')) ?><input type="password" name="password" autofocus autocomplete="current-password"></label>
      <button class="primary"><?= h(t('login.submit')) ?></button>
    </form>
  <?php endif; ?>
</div>
<?php page_foot();
