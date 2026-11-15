<?php
require __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/lib/Geo.php';
require_login();

$cfg = Geo::load();
$st = Geo::status();
$known = Geo::knownCountries();
$sel = array_flip($cfg['countries']);
$names = [];
foreach (array_unique(array_merge($known, $cfg['countries'])) as $cc) $names[$cc] = Geo::name($cc);
asort($names, SORT_LOCALE_STRING);
// selected ones first
uksort($names, fn($a, $b) => [isset($sel[$b]), $names[$a]] <=> [isset($sel[$a]), $names[$b]]);

page_head(t('geo.title'), 'geo.php');
?>
<h1><?= h(t('geo.title')) ?></h1>
<p class="muted"><?= h(t('geo.intro')) ?></p>

<section class="card">
  <h2><?= h(t('geo.status')) ?></h2>
  <?php if (!$st): ?>
    <p class="muted"><?= h(t('geo.never')) ?></p>
  <?php else: ?>
    <p>
      <?php if (!empty($st['ok']) && !empty($st['enabled'])): ?>
        <span class="badge r-pass"><?= h(t('geo.active')) ?></span>
        <?= h(t('geo.active_text', ['c' => count((array)($st['countries'] ?? [])), 'b' => $st['blocks'] ?? 0, 'r' => $st['rules'] ?? 0, 'p' => implode(', ', (array)($st['ports'] ?? []))])) ?>
      <?php elseif (!empty($st['ok'])): ?>
        <span class="badge"><?= h(t('geo.off')) ?></span>
      <?php else: ?>
        <span class="badge r-error"><?= h(t('res.error')) ?></span> <?= h($st['message'] ?? '') ?>
      <?php endif; ?>
      <span class="muted">· <?= h(t('geo.updated', ['t' => $st['time'] ?? '?'])) ?></span>
    </p>
  <?php endif; ?>
</section>

<form method="post" action="action.php" class="card geoform">
  <?= csrf_field() ?><input type="hidden" name="do" value="geo_save">
  <label class="chk big"><input type="checkbox" name="enabled" value="1"<?= $cfg['enabled'] ? ' checked' : '' ?>> <?= h(t('geo.enable')) ?></label>

  <h3><?= h(t('geo.ports')) ?></h3>
  <input name="ports" value="<?= h(implode(', ', $cfg['ports'])) ?>" class="mono wide">
  <p class="muted small"><?= h(t('geo.ports_help')) ?></p>

  <h3><?= h(t('geo.countries')) ?> <small class="muted"><?= count($cfg['countries']) ?></small></h3>
  <div class="geobtns">
    <button type="button" class="small" data-geo-set="<?= h(implode(',', Geo::PRESET)) ?>"><?= h(t('geo.preset')) ?></button>
    <button type="button" class="small" data-geo-set="<?= h(implode(',', Geo::EU)) ?>"><?= h(t('geo.preset_eu')) ?></button>
    <button type="button" class="small" data-geo-set=""><?= h(t('geo.none')) ?></button>
    <input type="search" class="listfilter" placeholder="<?= h(t('lists.filter')) ?>" data-filter="#geolist" data-item="label">
  </div>
  <div class="geolist" id="geolist">
    <?php foreach ($names as $cc => $n): ?>
      <label><input type="checkbox" name="countries[]" value="<?= h($cc) ?>"<?= isset($sel[$cc]) ? ' checked' : '' ?>> <span class="mono">[<?= h($cc) ?>]</span> <?= h($n) ?></label>
    <?php endforeach; ?>
  </div>
  <?php if ($known === Geo::PRESET): ?><p class="muted small"><?= h(t('geo.more_after')) ?></p><?php endif; ?>

  <h3><?= h(t('geo.exceptions')) ?></h3>
  <textarea name="exceptions" rows="3" class="mono wide" placeholder="1.2.3.4&#10;5.6.7.0/24"><?= h(implode("\n", $cfg['exceptions'])) ?></textarea>
  <p class="muted small"><?= h(t('geo.exceptions_help')) ?></p>

  <div class="rfoot">
    <button class="primary"><?= h(t('geo.save_apply')) ?></button>
  </div>
  <p class="muted small"><?= h(t('geo.note')) ?></p>
</form>
<?php page_foot();
