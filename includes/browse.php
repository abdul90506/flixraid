<?php
/** Shared browse page for movies.php / tv.php  (expects $BROWSE_TYPE) */
require_once __DIR__ . '/bootstrap.php';

$type   = $BROWSE_TYPE;
$genres = $type === 'tv' ? $TV_GENRES : $MOVIE_GENRES;
$cats   = $CATEGORIES[$type];
$genre  = qi('genre');
if ($genre && !isset($genres[$genre])) $genre = 0;
$cat    = isset($cats[q('cat')]) ? q('cat') : 'popular';

$res = $genre ? $tmdb->genre($type, $genre, 1) : $tmdb->lists($type, $cat, 1);
$label = $type === 'tv' ? 'TV Shows' : 'Movies';
$heading = $genre ? $genres[$genre] . ' ' . $label : $cats[$cat][0] . ' ' . $label;

layout_header($heading, ['page' => $type, 'body' => 'browse']);
?>
<div class="page-head">
  <div class="page-title">
    <h1><?= e($label) ?></h1>
    <div class="select genre-select">
      <select onchange="location.href='?genre='+this.value">
        <option value="">Genres</option>
        <?php foreach ($genres as $gid => $gname): ?>
          <option value="<?= $gid ?>" <?= $gid === $genre ? 'selected' : '' ?>><?= e($gname) ?></option>
        <?php endforeach; ?>
      </select>
      <?= icon('down') ?>
    </div>
  </div>
  <div class="chips">
    <?php foreach ($cats as $k => [$name]): ?>
      <a class="chip<?= !$genre && $k === $cat ? ' active' : '' ?>" href="?cat=<?= e($k) ?>"><?= e($name) ?></a>
    <?php endforeach; ?>
    <span class="chip-sep"></span>
    <?php foreach (array_slice($genres, 0, 9, true) as $gid => $gname): ?>
      <a class="chip ghost<?= $gid === $genre ? ' active' : '' ?>" href="?genre=<?= $gid ?>"><?= e($gname) ?></a>
    <?php endforeach; ?>
  </div>
  <h2 class="grid-title"><?= e($heading) ?></h2>
</div>

<?php if ($res['items']): ?>
<div class="grid" id="grid"
     data-infinite="api.php?action=browse&type=<?= $type ?>&<?= $genre ? 'genre=' . $genre : 'cat=' . e($cat) ?>"
     data-page="1" data-total="<?= (int)$res['total_pages'] ?>">
  <?php foreach ($res['items'] as $it) echo card($it); ?>
</div>
<div class="loader" id="gridLoader"><div class="spinner"></div></div>
<?php else: ?>
<div class="empty-state"><h2>Nothing to show right now</h2><p>TMDB could not be reached. Please refresh in a moment.</p></div>
<?php endif; ?>
<?php layout_footer();
