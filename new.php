<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/rows.php';

$r = $tmdb->batch(fn(Tmdb $t) => [
    'week'     => $t->trending('week'),
    'today'    => $t->trending('today'),
    'playing'  => $t->lists('movie', 'now-playing')['items'],
    'upcoming' => $t->lists('movie', 'upcoming')['items'],
    'airing'   => $t->lists('tv', 'airing-today')['items'],
    'onair'    => $t->lists('tv', 'on-the-air')['items'],
    'popm'     => $t->lists('movie', 'popular', 2)['items'],
    'popt'     => $t->lists('tv', 'popular', 2)['items'],
]);

layout_header('New & Popular', ['page' => 'new', 'body' => 'browse']);
?>
<div class="page-head">
  <div class="page-title"><h1>New &amp; Popular</h1></div>
  <div class="chips">
    <a class="chip" href="#row-week">🔥 Everyone's Watching</a>
    <a class="chip" href="#row-today">🍿 Trending Today</a>
    <a class="chip" href="#row-airing">📺 New Episodes</a>
    <a class="chip" href="#row-playing">🎬 In Theaters</a>
    <a class="chip" href="#row-upcoming">⏰ Coming Soon</a>
  </div>
</div>
<div class="rows no-hero">
  <?= row('Top 10 This Week', array_slice($r['week'], 0, 10), ['variant' => 'top10', 'id' => 'row-week']) ?>
  <?= row('Trending Today', $r['today'], ['id' => 'row-today']) ?>


  <?= row('New in Theaters', $r['playing'], ['id' => 'row-playing', 'more' => 'movies.php?cat=now-playing']) ?>
  <?= row('Coming Soon', $r['upcoming'], ['id' => 'row-upcoming', 'more' => 'movies.php?cat=upcoming']) ?>
  <?= row('Airing Today', $r['airing'], ['id' => 'row-airing', 'more' => 'tv.php?cat=airing-today']) ?>
  <?= row('Currently On The Air', $r['onair'], ['more' => 'tv.php?cat=on-the-air']) ?>
  <?= row('Popular Movies', $r['popm'], ['more' => 'movies.php']) ?>
  <?= row('Popular Series', $r['popt'], ['more' => 'tv.php']) ?>
</div>
<?php layout_footer(); ?>
