<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/rows.php';

$eager = ['trending_today', 'top10_movies', 'popular_tv'];
$lazy  = ['top10_tv', 'trending_week', 'now_playing', 'popular_movies', 'top_movies', 'airing_today', 'g_action',
          'top_tv', 'g_comedy', 'on_the_air', 'g_horror', 'g_scifi_tv', 'g_animation', 'g_crime_tv', 'upcoming',
          'g_thriller', 'g_romance', 'g_anime_tv', 'g_docs', 'g_family'];

// 1) lists needed for the first screen – fetched in parallel
$reg = row_registry();
$data = $tmdb->batch(function (Tmdb $t) use ($reg, $eager) {
    $out = [];
    foreach ($eager as $k) $out[$k] = $reg[$k][1]($t);
    return $out;
});

// 2) hero: top trending titles + their details & logos (parallel)
$heroItems = array_slice(array_values(array_filter($data['trending_today'] ?? [], fn($i) => !empty($i['poster']))), 0, 6);
$hero = $tmdb->batch(function (Tmdb $t) use ($heroItems) {
    $out = [];
    foreach ($heroItems as $it) {
        $d = $t->details($it['type'], $it['id']);
        $l = $t->logo($it['type'], $it['id']);
        if ($d) { $d['logo'] = $l; $out[] = $d; }
    }
    return $out;
});
$hero = array_values(array_filter($hero, fn($h) => !empty($h['backdrop'])));

layout_header('', ['page' => 'home', 'body' => 'home', 'image' => $hero ? img($hero[0]['backdrop'], 'w1280') : '']);
?>

<?php if ($hero): ?>
<section class="hero" id="hero" data-interval="9000">
  <?php foreach ($hero as $i => $h):
      $genres = array_slice(array_column($h['genres'], 'name'), 0, 3);
  ?>
  <article class="hero-slide<?= $i === 0 ? ' active' : '' ?>" data-index="<?= $i ?>">
    <div class="hero-bg">
      <img src="<?= e(img($h['backdrop'], 'w1280')) ?>"
           srcset="<?= e(img($h['backdrop'], 'w780')) ?> 780w, <?= e(img($h['backdrop'], 'w1280')) ?> 1280w"
           sizes="100vw" alt="" decoding="async" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
    </div>
    <div class="hero-shade"></div>
    <div class="hero-content">
      <div class="hero-kicker">
        <span class="kicker-badge"><?= $h['type'] === 'tv' ? 'SERIES' : 'FILM' ?></span>
        <span class="kicker-rank"><b>#<?= $i + 1 ?></b> in Trending Today</span>
      </div>
      <?php if (!empty($h['logo'])): ?>
        <h1 class="hero-logo"><img src="<?= e(img($h['logo'], 'w500')) ?>" alt="<?= e($h['title']) ?>"></h1>
      <?php else: ?>
        <h1 class="hero-title"><?= e($h['title']) ?></h1>
      <?php endif; ?>
      <div class="hero-meta">
        <?php if ($h['rating']): ?><span class="match"><?= (int)round($h['rating'] * 10) ?>% Match</span><?php endif; ?>
        <?php if ($h['year']): ?><span><?= e($h['year']) ?></span><?php endif; ?>
        <?php if ($h['certification']): ?><span class="cert"><?= e($h['certification']) ?></span><?php endif; ?>
        <?php if ($h['runtime']): ?><span><?= e($h['runtime']) ?></span><?php endif; ?>
        <?php if ($h['type'] === 'tv' && $h['episodes']): ?><span><?= (int)$h['episodes'] ?> Episodes</span><?php endif; ?>
        <span class="hd">HD</span>
      </div>
      <p class="hero-overview"><?= e($h['overview']) ?></p>
      <?php if ($genres): ?><div class="hero-genres"><?= e(implode('  •  ', $genres)) ?></div><?php endif; ?>
      <div class="hero-actions">
        <a class="btn btn-play" href="<?= e(watch_url($h['type'], $h['id'])) ?>" data-resume="<?= $h['type'] ?>:<?= $h['id'] ?>"><?= icon('play') ?><span>Play</span></a>
        <a class="btn btn-glass" href="<?= e(title_url($h['type'], $h['id'])) ?>"><?= icon('info') ?><span>More Info</span></a>
        <button class="btn-circle" data-list-toggle="<?= item_json($h) ?>" title="My List"><?= icon('plus') ?></button>
        <?php if ($h['trailer']): ?>
          <button class="btn-circle" data-trailer="<?= e($h['trailer']) ?>" title="Watch trailer"><?= icon('trailer') ?></button>
        <?php endif; ?>
      </div>
    </div>
  </article>
  <?php endforeach; ?>
  <div class="hero-thumbs" role="tablist">
    <?php foreach ($hero as $i => $h): ?>
      <button class="hero-thumb<?= $i === 0 ? ' active' : '' ?>" data-go="<?= $i ?>" aria-label="<?= e($h['title']) ?>">
        <img src="<?= e(img($h['backdrop'], 'w300')) ?>" alt="" loading="lazy"><i class="bar"><b></b></i>
      </button>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
<section class="hero hero-fallback"><div class="hero-content"><h1 class="hero-title"><?= e(SITE_NAME) ?></h1><p class="hero-overview"><?= e(SITE_TAGLINE) ?></p></div></section>
<?php endif; ?>

<div class="rows">
  <!-- client side rows (localStorage) -->
  <section class="row row-wide" id="continueRow" hidden>
    <div class="row-head"><h2>Continue Watching</h2><button class="row-more" id="clearHistory">Clear</button></div>
    <div class="row-wrap">
      <button class="row-arrow left" aria-label="Previous"><?= icon('left') ?></button>
      <div class="row-track"></div>
      <button class="row-arrow right" aria-label="Next"><?= icon('right') ?></button>
    </div>
  </section>

  <?php
  echo row($reg['trending_today'][0], $data['trending_today'] ?? [], ['more' => 'new.php', 'id' => 'row-trending_today']);
  ?>

  <section class="row row-poster" id="myListRow" hidden>
    <div class="row-head"><h2>My List</h2><a class="row-more" href="mylist.php">Explore all <?= icon('right') ?></a></div>
    <div class="row-wrap">
      <button class="row-arrow left" aria-label="Previous"><?= icon('left') ?></button>
      <div class="row-track"></div>
      <button class="row-arrow right" aria-label="Next"><?= icon('right') ?></button>
    </div>
  </section>

  <?php
  echo row($reg['top10_movies'][0], $data['top10_movies'] ?? [], ['variant' => 'top10', 'more' => 'movies.php', 'id' => 'row-top10_movies']);
  echo row($reg['popular_tv'][0], $data['popular_tv'] ?? [], ['more' => 'tv.php', 'id' => 'row-popular_tv']);
  foreach ($lazy as $k) echo render_row_key($tmdb, $k, true);
  ?>
</div>

<?php layout_footer(); ?>
