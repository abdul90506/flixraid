<?php
require __DIR__ . '/includes/bootstrap.php';

$qStr = mb_substr(q('q'), 0, 80);
$type = in_array(q('type'), ['all', 'movie', 'tv', 'person'], true) ? q('type') : 'all';
$page = 1;

$res = null; $people = []; $counts = ['movie' => 0, 'tv' => 0, 'person' => 0];
if ($qStr !== '') {
    $b = $tmdb->batch(fn(Tmdb $t) => [
        'movie'  => $t->search('movie', $qStr),
        'tv'     => $t->search('tv', $qStr),
        'person' => $t->search('person', $qStr),
    ]);
    foreach ($counts as $k => $_) $counts[$k] = $b[$k]['total'] ?? count($b[$k]['items'] ?? []);
    $res    = search_any($tmdb, $type, $qStr, 1);
    $people = $b['person']['items'] ?? [];
    usort($people, fn($a, $b) => empty($a['profile']) <=> empty($b['profile']));
    $people = array_slice($people, 0, 12);
} else {
    $trending = $tmdb->trending('week');
}

layout_header($qStr !== '' ? "Search: $qStr" : 'Search', ['page' => 'search', 'body' => 'search-page']);
?>
<div class="page-head search-head">
  <form class="big-search" action="search.php" method="get" id="bigSearch" autocomplete="off">
    <?= icon('search') ?>
    <input type="search" name="q" id="bigSearchInput" value="<?= e($qStr) ?>" placeholder="Search movies, TV shows, people…" autofocus>
    <input type="hidden" name="type" value="<?= e($type) ?>" id="bigSearchType">
    <span class="big-search-spin spinner sm" hidden></span>
  </form>

  <div class="chips" id="searchTabs" <?= $qStr === '' ? 'hidden' : '' ?>>
    <?php
    $tabs = ['all' => 'All', 'movie' => 'Movies', 'tv' => 'TV Shows', 'person' => 'People'];
    foreach ($tabs as $k => $label):
        $c = $k === 'all' ? $counts['movie'] + $counts['tv'] : $counts[$k];
    ?>
      <button class="chip<?= $k === $type ? ' active' : '' ?>" data-type="<?= $k ?>"><?= $label ?> <small data-count="<?= $k ?>"><?= $c ? number_format($c) : '' ?></small></button>
    <?php endforeach; ?>
  </div>
</div>

<div id="searchResults">
<?php if ($qStr === ''): ?>
  <h2 class="grid-title pad">Popular searches this week</h2>
  <div class="grid"><?php foreach ($trending as $it) echo card($it); ?></div>
  <h2 class="grid-title pad">Browse by genre</h2>
  <div class="genre-tiles">
    <?php foreach ($MOVIE_GENRES as $gid => $g): ?><a class="genre-tile" href="movies.php?genre=<?= $gid ?>" style="--h:<?= ($gid * 37) % 360 ?>"><?= e($g) ?></a><?php endforeach; ?>
  </div>
<?php else: ?>
  <?php if ($type === 'all' && $people): ?>
    <section class="people-strip">
      <h2 class="grid-title pad">People</h2>
      <div class="row-track people"><?php foreach ($people as $p) echo person_card($p); ?></div>
    </section>
  <?php endif; ?>
  <?php if ($res && $res['items']): ?>
    <h2 class="grid-title pad"><?= $type === 'person' ? 'People' : 'Titles' ?> matching “<?= e($qStr) ?>”</h2>
    <div class="grid<?= $type === 'person' ? ' grid-people' : '' ?>" id="grid"
         data-infinite="api.php?action=search&type=<?= e($type) ?>&q=<?= e(urlencode($qStr)) ?>"
         data-page="1" data-total="<?= (int)$res['total_pages'] ?>">
      <?php foreach ($res['items'] as $it) echo $type === 'person' ? person_card($it) : card($it); ?>
    </div>
    <div class="loader" id="gridLoader"><div class="spinner"></div></div>
  <?php else: ?>
    <div class="empty-state">
      <h2>No results for “<?= e($qStr) ?>”</h2>
      <p>Try different keywords, or search for a movie, show, actor or director.</p>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php layout_footer(); ?>
