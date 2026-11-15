<?php
require __DIR__ . '/_init.php';
require_login();

$rows = Db::get()->query('SELECT * FROM rules ORDER BY enabled DESC, name')->fetchAll();
$types = ['text', 'regex', 'special'];
$scopes = ['subject', 'body', 'headers', 'all'];

function rule_form(array $r, array $types, array $scopes): void
{
    $id = (int)($r['id'] ?? 0);
    ?>
    <form method="post" action="action.php" class="rule <?= ($r['enabled'] ?? 1) ? '' : 'off' ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="rulesave"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="rhead">
        <input name="name" value="<?= h($r['name'] ?? '') ?>" placeholder="<?= h(t('rules.name')) ?>" required class="rname">
        <select name="type"><?php foreach ($types as $x): ?><option value="<?= $x ?>"<?= ($r['type'] ?? 'text') === $x ? ' selected' : '' ?>><?= h(t('rules.' . $x)) ?></option><?php endforeach; ?></select>
        <select name="scope"><?php foreach ($scopes as $x): ?><option value="<?= $x ?>"<?= ($r['scope'] ?? 'all') === $x ? ' selected' : '' ?>><?= h(t('scope.' . $x)) ?></option><?php endforeach; ?></select>
        <label class="sc"><?= h(t('rules.score')) ?> <input name="score" value="<?= h(isset($r['score']) ? (string)(float)$r['score'] : '3') ?>" size="4"></label>
        <label class="chk"><input type="checkbox" name="enabled" value="1"<?= ($r['enabled'] ?? 1) ? ' checked' : '' ?>> <?= h(t('rules.enabled')) ?></label>
        <?php if ($id && $r['hits']): ?><span class="muted small"><?= (int)$r['hits'] ?> × · <?= h(fmt_time((int)$r['last_hit'], true)) ?></span><?php endif; ?>
      </div>
      <textarea name="expr" rows="<?= max(1, min(6, substr_count($r['expr'] ?? '', "\n") + 1)) ?>" class="mono" placeholder="<?= h(t('rules.expr')) ?>"><?= h($r['expr'] ?? '') ?></textarea>
      <div class="rfoot">
        <button class="primary small"><?= h(t('rules.save')) ?></button>
        <?php if ($id): ?><button class="small danger" name="do" value="ruledel" data-confirm="<?= h(t('confirm.delete')) ?>"><?= h(t('rules.del')) ?></button><?php endif; ?>
      </div>
    </form>
    <?php
}

page_head(t('rules.title'), 'rules.php');
?>
<h1><?= h(t('rules.title')) ?></h1>
<p class="muted"><?= h(t('rules.help')) ?></p>
<details class="card"><summary><?= h(t('rules.new')) ?></summary><?php rule_form([], $types, $scopes); ?></details>
<input type="search" class="listfilter" placeholder="<?= h(t('lists.filter')) ?>" data-filter="#rules" data-item=".rule">
<div id="rules">
<?php
$on = array_filter($rows, fn($r) => (int)$r['enabled'] === 1);
$off = array_filter($rows, fn($r) => (int)$r['enabled'] !== 1);
foreach ($on as $r) rule_form($r, $types, $scopes);
if ($off): ?>
  <h3 class="offhead"><?= h(t('rules.disabled', ['n' => count($off)])) ?></h3>
  <p class="muted small"><?= h(t('rules.disabled_help')) ?></p>
<?php foreach ($off as $r) rule_form($r, $types, $scopes);
endif; ?>
</div>
<?php page_foot();
