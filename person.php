<?php
require __DIR__ . '/includes/bootstrap.php';

$id = qi('id');
$p  = $id ? $tmdb->person($id) : null;

if (!$p) {
    http_response_code(404);
    layout_header('Not found', ['body' => 'plain']);
    echo '<div class="empty-state page"><h1>Person not found</h1><a class="btn btn-play" href="index.php">' . icon('home') . '<span>Back to Home</span></a></div>';
    layout_footer();
    exit;
}

$known = $p['known'];
// put the main department first
$credits = $p['credits'];
$mainDept = ($p['facts']['Known For'] ?? 'Acting') === 'Acting' ? 'Acting' : ($p['facts']['Known For'] ?? '');
if (isset($credits[$mainDept])) $credits = [$mainDept => $credits[$mainDept]] + $credits;

layout_header($p['name'], ['body' => 'person', 'desc' => $p['bio'][0] ?? '', 'image' => $p['profile'] ? img($p['profile'], 'w500') : '']);
?>
<div class="person-page">
  <aside class="person-side">
    <div class="person-photo">
      <?php if ($p['profile']): ?><img src="<?= e(img($p['profile'], 'h632')) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><div class="noimg person"><?= icon('user') ?></div><?php endif; ?>
    </div>
    <h1 class="person-name mobile-only"><?= e($p['name']) ?></h1>
    <dl class="person-facts">
      <?php foreach ($p['facts'] as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd><?php endforeach; ?>
    </dl>
  </aside>
  <div class="person-main">
    <h1 class="person-name desktop-only"><?= e($p['name']) ?></h1>
    <?php if ($p['bio']): ?>
      <div class="bio" id="bio">
        <?php foreach ($p['bio'] as $para): ?><p><?= e($para) ?></p><?php endforeach; ?>
      </div>
      <?php if (count($p['bio']) > 1 || mb_strlen($p['bio'][0]) > 500): ?><button class="link-btn" id="bioToggle">Read more</button><?php endif; ?>
    <?php else: ?>
      <p class="muted">We don't have a biography for <?= e($p['name']) ?>.</p>
    <?php endif; ?>

    <?php if ($known): ?>
      <h2 class="sub-h">Known For</h2>
      <div class="grid compact"><?php foreach ($known as $it) echo card($it); ?></div>
    <?php endif; ?>

    <?php if ($credits): ?>
      <h2 class="sub-h">Filmography</h2>
      <div class="chips" id="creditTabs">
        <?php $i = 0; foreach ($credits as $dept => $list): ?>
          <button class="chip<?= $i++ === 0 ? ' active' : '' ?>" data-dept="<?= e(md5($dept)) ?>"><?= e($dept) ?> <small><?= count($list) ?></small></button>
        <?php endforeach; ?>
      </div>
      <?php $i = 0; foreach ($credits as $dept => $list): ?>
        <ul class="credits-list" data-dept="<?= e(md5($dept)) ?>" <?= $i++ === 0 ? '' : 'hidden' ?>>
          <?php foreach ($list as $c): ?>
            <li>
              <span class="cy"><?= e($c['year'] ?: '—') ?></span>
              <a href="<?= e(title_url($c['type'], $c['id'])) ?>"><?= e($c['title']) ?></a>
              <span class="ct"><?= type_label($c['type']) ?></span>
              <?php if ($c['role']): ?><span class="cr">as <?= e($c['role']) ?></span><?php endif; ?>
              <a class="cplay" href="<?= e(watch_url($c['type'], $c['id'])) ?>" title="Play"><?= icon('play') ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php layout_footer(); ?>
