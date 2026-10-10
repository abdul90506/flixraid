<?php
/* ============================================================
 *  Helper functions + shared view partials
 * ============================================================ */

function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function q(string $key, $default = '')
{
    return isset($_GET[$key]) ? (is_string($_GET[$key]) ? trim($_GET[$key]) : $default) : $default;
}

function qi(string $key, int $default = 0): int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function img(?string $path, string $size = 'w342'): string
{
    return Tmdb::img($path, $size);
}

function title_url(string $type, int $id): string
{
    // Netflix has no separate title page: details open in the watch pop-up (desktop) / title sheet (mobile)
    return 'watch.php?type=' . ($type === 'tv' ? 'tv' : 'movie') . '&id=' . $id . '&info=1';
}

function watch_url(string $type, int $id, ?int $s = null, ?int $e = null): string
{
    if ($type === 'tv') return 'watch.php?type=tv&id=' . $id . '&s=' . ($s ?? 1) . '&e=' . ($e ?? 1);
    return 'watch.php?type=movie&id=' . $id;
}

function person_url(int $id): string
{
    return 'person.php?id=' . $id;
}

function type_label(string $type): string
{
    return $type === 'tv' ? 'Series' : 'Movie';
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Minimal item data stored in "My List" / localStorage */
function item_json(array $it): string
{
    return e(json_encode([
        'id'       => (int)$it['id'],
        'type'     => $it['type'],
        'title'    => $it['title'] ?? '',
        'poster'   => $it['poster'] ?? null,
        'backdrop' => $it['backdrop'] ?? null,
        'year'     => $it['year'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function icon(string $name): string
{
    static $icons = [
        'play'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14z"/></svg>',
        'plus'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
        'check'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>',
        'info'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9.5"/><path d="M12 11v6M12 7.5v.01" stroke-linecap="round" stroke-width="2.4"/></svg>',
        'search'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
        'close'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        'left'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>',
        'right'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>',
        'star'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4L2.8 9.5l6.4-.8z"/></svg>',
        'film'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/></svg>',
        'tv'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="6" width="19" height="13" rx="2.5"/><path d="M8 2.5l4 3.5 4-3.5"/></svg>',
        'home'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/></svg>',
        'fire'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 22c4.4 0 7.5-3 7.5-7.2 0-4.6-3.6-7.4-5-11.8-2.3 2-3.4 4.3-3.5 6.6C9.7 8.5 9 7.4 8.7 6 6.2 8.4 4.5 11.4 4.5 14.8 4.5 19 7.6 22 12 22z"/></svg>',
        'list'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h10M4 18h7M17 15v6M14 18h6"/></svg>',
        'share'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>',
        'trailer' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M10 9.2v5.6l4.8-2.8z" fill="currentColor"/></svg>',
        'back'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>',
        'next'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 5.5v13a1 1 0 0 0 1.5.86L16 13.6V18a1 1 0 0 0 2 0V6a1 1 0 0 0-2 0v4.4L6.5 4.64A1 1 0 0 0 5 5.5z"/></svg>',
        'prev'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 5.5v13a1 1 0 0 1-1.5.86L8 13.6V18a1 1 0 0 1-2 0V6a1 1 0 0 1 2 0v4.4l9.5-5.76A1 1 0 0 1 19 5.5z"/></svg>',
        'server'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01" stroke-linecap="round" stroke-width="3"/></svg>',
        'user'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" stroke-linecap="round"/></svg>',
        'down'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>',
        'volume'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M4 9.5h3.5L12 5v14l-4.5-4.5H4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" stroke-linecap="round"/></svg>',
    ];
    return '<span class="ico">' . ($icons[$name] ?? '') . '</span>';
}

/* ------------------------------------------------------------
 *  Cards
 * ------------------------------------------------------------ */

/** Poster card (2:3). $rank -> Top-10 style big number */
function card(array $it, ?int $rank = null): string
{
    $type  = $it['type'] ?? 'movie';
    $id    = (int)$it['id'];
    $title = $it['title'] ?? '';
    $href  = title_url($type, $id);
    $poster = $it['poster'] ?? null;
    $meta  = [];
    $meta[] = '<span class="tag">' . type_label($type) . '</span>';
    if (!empty($it['year']))   $meta[] = '<span>' . e($it['year']) . '</span>';
    if (!empty($it['rating'])) $meta[] = '<span class="rate">' . icon('star') . number_format((float)$it['rating'], 1) . '</span>';

    $img = $poster
        ? '<img loading="lazy" decoding="async" src="' . e(img($poster, 'w342')) . '" alt="' . e($title) . '">'
        : '<div class="noimg"><span>' . e($title) . '</span></div>';

    $rankHtml = $rank ? '<span class="rank" aria-hidden="true">' . $rank . '</span>' : '';
    return '<div class="card' . ($rank ? ' card-top' : '') . '" data-type="' . $type . '" data-id="' . $id . '">'
        . $rankHtml
        . '<div class="card-inner">'
        . '<a class="card-img" href="' . e($href) . '" aria-label="' . e($title) . '">' . $img . '</a>'
        . '<div class="card-info">'
        . '<div class="card-actions">'
        . '<a class="cbtn cbtn-play" href="' . e(watch_url($type, $id)) . '" title="Play">' . icon('play') . '</a>'
        . '<button class="cbtn" data-list-toggle="' . item_json($it) . '" title="My List">' . icon('plus') . '</button>'
        . '<a class="cbtn cbtn-more" href="' . e($href) . '" title="More info">' . icon('down') . '</a>'
        . '</div>'
        . '<h4>' . e($title) . '</h4>'
        . '<div class="card-meta">' . implode('', $meta) . '</div>'
        . '</div></div></div>';
}

/** Landscape card (16:9) – used for recommendations */
function wide_card(array $it): string
{
    $type  = $it['type'] ?? 'movie';
    $id    = (int)$it['id'];
    $title = $it['title'] ?? '';
    $bg    = $it['backdrop'] ?? null;
    $img   = $bg
        ? '<img loading="lazy" decoding="async" src="' . e(img($bg, 'w500')) . '" alt="' . e($title) . '">'
        : ($it['poster'] ? '<img loading="lazy" src="' . e(img($it['poster'], 'w342')) . '" alt="">' : '<div class="noimg"><span>' . e($title) . '</span></div>');
    $meta = [];
    if (!empty($it['rating'])) $meta[] = '<span class="match">' . (int)round($it['rating'] * 10) . '% Match</span>';
    if (!empty($it['year']))   $meta[] = '<span>' . e($it['year']) . '</span>';
    $meta[] = '<span class="tag">' . type_label($type) . '</span>';

    return '<div class="wcard" data-type="' . $type . '" data-id="' . $id . '">'
        . '<a class="wcard-img" href="' . e(title_url($type, $id)) . '">' . $img
        . '<span class="wcard-play">' . icon('play') . '</span></a>'
        . '<div class="wcard-body"><div class="wcard-top"><h4><a href="' . e(title_url($type, $id)) . '">' . e($title) . '</a></h4>'
        . '<button class="cbtn sm" data-list-toggle="' . item_json($it) . '" title="My List">' . icon('plus') . '</button></div>'
        . '<div class="card-meta">' . implode('', $meta) . '</div></div></div>';
}

function person_card(array $p): string
{
    $img = !empty($p['profile'])
        ? '<img loading="lazy" decoding="async" src="' . e(img($p['profile'], 'w185')) . '" alt="' . e($p['name']) . '">'
        : '<div class="noimg person">' . icon('user') . '</div>';
    $sub = $p['character'] ?? ($p['job'] ?? ($p['dept'] ?? ''));
    return '<a class="pcard" href="' . e(person_url((int)$p['id'])) . '">'
        . '<div class="pcard-img">' . $img . '</div>'
        . '<div class="pcard-name">' . e($p['name']) . '</div>'
        . ($sub ? '<div class="pcard-role">' . e($sub) . '</div>' : '')
        . '</a>';
}

/** Horizontal Netflix row */
function row(string $title, array $items, array $o = []): string
{
    $items = array_values(array_filter($items, fn($i) => !empty($i['id'])));
    if (!$items && empty($o['lazy'])) return '';
    $variant = $o['variant'] ?? 'poster';
    $more    = $o['more'] ?? '';
    $id      = $o['id'] ?? ('row' . substr(md5($title), 0, 6));
    $html = '';
    foreach ($items as $i => $it) {
        if ($variant === 'top10')     $html .= card($it, $i + 1);
        elseif ($variant === 'wide')  $html .= wide_card($it);
        else                          $html .= card($it);
    }
    if (!empty($o['lazy']) && !$items) {
        $html = str_repeat('<div class="card skeleton"><div class="card-inner"><div class="card-img"></div></div></div>', 8);
    }
    $lazyAttr = !empty($o['lazy']) ? ' data-lazy="' . e($o['lazy']) . '"' : '';
    return '<section class="row row-' . $variant . '" id="' . e($id) . '"' . $lazyAttr . '>'
        . '<div class="row-head"><h2>' . e($title) . '</h2>'
        . ($more ? '<a class="row-more" href="' . e($more) . '">Explore all ' . icon('right') . '</a>' : '')
        . '</div>'
        . '<div class="row-wrap">'
        . '<button class="row-arrow left" aria-label="Previous">' . icon('left') . '</button>'
        . '<div class="row-track">' . $html . '</div>'
        . '<button class="row-arrow right" aria-label="Next">' . icon('right') . '</button>'
        . '</div></section>';
}

/** Episode list (title page + watch sidebar) */
function episodes_html(int $showId, ?array $season, string $mode = 'title', int $activeEp = 0): string
{
    if (!$season || empty($season['episodes'])) {
        return '<div class="empty-state small">No episodes found for this season yet.</div>';
    }
    $s = (int)$season['number'];
    $out = '';
    foreach ($season['episodes'] as $ep) {
        $n = (int)$ep['number'];
        $still = !empty($ep['still'])
            ? '<img loading="lazy" decoding="async" src="' . e(img($ep['still'], $mode === 'watch' ? 'w300' : 'w500')) . '" alt="">'
            : '<div class="noimg">' . icon('film') . '</div>';
        $active = ($mode === 'watch' && $n === $activeEp) ? ' active' : '';
        $meta = array_filter([$ep['runtime'] ?? '', $ep['date'] ?? '']);
        $out .= '<a class="episode' . $active . '" href="' . e(watch_url('tv', $showId, $s, $n)) . '" data-s="' . $s . '" data-e="' . $n . '">'
            . '<div class="ep-num">' . $n . '</div>'
            . '<div class="ep-thumb">' . $still . '<span class="ep-play">' . icon('play') . '</span><span class="ep-progress"><i></i></span></div>'
            . '<div class="ep-body"><div class="ep-top"><h4>' . e($ep['name']) . '</h4>'
            . (!empty($ep['rating']) ? '<span class="rate">' . icon('star') . number_format((float)$ep['rating'], 1) . '</span>' : '')
            . '</div>'
            . '<div class="ep-meta">' . e(implode(' • ', $meta)) . '</div>'
            . ($mode === 'title' && !empty($ep['overview']) ? '<p>' . e($ep['overview']) . '</p>' : '')
            . '</div></a>';
    }
    return $out;
}

/* ------------------------------------------------------------
 *  Layout
 * ------------------------------------------------------------ */

function layout_header(string $title = '', array $o = []): void
{
    $page  = $o['page'] ?? '';
    $desc  = $o['desc'] ?? SITE_TAGLINE;
    $image = $o['image'] ?? '';
    $full  = $title ? $title . ' – ' . SITE_NAME : SITE_NAME . ' – ' . SITE_TAGLINE;
    $bodyClass = $o['body'] ?? '';
    $nav = [
        'home'  => ['index.php', 'Home', 'home'],
        'tv'    => ['tv.php', 'TV Shows', 'tv'],
        'movie' => ['movies.php', 'Movies', 'film'],
        'new'   => ['new.php', 'New & Popular', 'fire'],
        'list'  => ['mylist.php', 'My List', 'list'],
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($full) ?></title>
<meta name="description" content="<?= e(mb_substr($desc, 0, 160)) ?>">
<meta name="theme-color" content="#08080c">
<meta property="og:title" content="<?= e($full) ?>">
<meta property="og:description" content="<?= e(mb_substr($desc, 0, 200)) ?>">
<?php if ($image): ?><meta property="og:image" content="<?= e($image) ?>"><?php endif; ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%23e50914'/><path d='M24 18v28l22-14z' fill='white'/></svg>">
<link rel="preconnect" href="https://image.tmdb.org">
<?php if ($image && $page === 'home'): ?><link rel="preload" as="image" imagesrcset="<?= e(str_replace('/w1280/', '/w780/', $image)) ?> 780w, <?= e($image) ?> 1280w" imagesizes="100vw" fetchpriority="high"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<header class="nav" id="nav">
  <div class="nav-left">
    <a href="index.php" class="logo" aria-label="<?= e(SITE_NAME) ?>"><?= e(SITE_NAME) ?></a>
    <nav class="nav-links">
      <?php foreach ($nav as $k => [$href, $label]): ?>
        <a href="<?= $href ?>" class="<?= $page === $k ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="nav-right">
    <form class="search-box" id="searchBox" action="search.php" method="get" autocomplete="off" role="search">
      <button type="button" class="search-toggle" aria-label="Search"><?= icon('search') ?></button>
      <input type="search" name="q" id="searchInput" placeholder="Titles, people, genres" value="<?= e($page === 'search' ? q('q') : '') ?>" aria-label="Search">
      <button type="button" class="search-clear" aria-label="Clear"><?= icon('close') ?></button>
      <div class="suggest" id="suggest" hidden></div>
    </form>
    <a href="mylist.php" class="nav-avatar" title="My List"><span>CF</span></a>
  </div>
</header>
<nav class="tabbar">
  <?php foreach ($nav as $k => [$href, $label, $ic]): if ($k === 'new') continue; ?>
    <a href="<?= $href ?>" class="<?= $page === $k ? 'active' : '' ?>"><?= icon($ic) ?><span><?= e($label === 'TV Shows' ? 'Shows' : $label) ?></span></a>
  <?php endforeach; ?>
  <a href="search.php" class="<?= $page === 'search' ? 'active' : '' ?>"><?= icon('search') ?><span>Search</span></a>
</nav>
<main id="main">
<?php
}

function layout_footer(): void
{
    ?>
</main>
<footer class="footer">
  <div class="footer-inner">
    <a href="index.php" class="logo small"><?= e(SITE_NAME) ?></a>
    <div class="footer-links">
      <a href="movies.php">Movies</a><a href="tv.php">TV Shows</a><a href="new.php">New &amp; Popular</a>
      <a href="mylist.php">My List</a><a href="search.php">Search</a>
      <a href="movies.php?cat=top-rated">Top Rated Movies</a><a href="tv.php?cat=top-rated">Top Rated Shows</a>
    </div>
    <p class="disclaimer"><?= e(SITE_NAME) ?> does not host any files on its server. Streams are provided by the configured sources / third-party providers. Metadata &amp; images are provided by <a href="https://www.themoviedb.org" target="_blank" rel="noopener">TMDB</a>. This product uses TMDB data but is not endorsed or certified by TMDB.</p>
    <p class="copy">© <?= date('Y') ?> <?= e(SITE_NAME) ?></p>
  </div>
</footer>
<div class="modal" id="trailerModal" hidden>
  <div class="modal-backdrop" data-close></div>
  <div class="modal-box">
    <button class="modal-close" data-close aria-label="Close"><?= icon('close') ?></button>
    <div class="modal-video" id="trailerFrame"></div>
  </div>
</div>
<div class="toast" id="toast"></div>
<script>window.CF = { img: <?= json_encode(TMDB_IMG) ?> };</script>
<script type="speculationrules">{"prerender":[{"where":{"selector_matches":"a[href*='info=1']"},"eagerness":"moderate"}],"prefetch":[{"where":{"selector_matches":"a[href^='watch.php']"},"eagerness":"moderate"}]}</script>
<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
<?php
}

/** Search helper – supports type "all" (movies + tv interleaved) */
function search_any(Tmdb $tmdb, string $type, string $q, int $page = 1): array
{
    if ($type !== 'all') return $tmdb->search($type, $q, $page);
    $r = $tmdb->batch(fn(Tmdb $t) => ['m' => $t->search('movie', $q, $page), 's' => $t->search('tv', $q, $page)]);
    $m = $r['m']['items'] ?? []; $s = $r['s']['items'] ?? [];
    $items = [];
    for ($i = 0; $i < max(count($m), count($s)); $i++) {
        if (isset($m[$i])) $items[] = $m[$i];
        if (isset($s[$i])) $items[] = $s[$i];
    }
    return [
        'items' => $items,
        'total_pages' => max($r['m']['total_pages'] ?? 0, $r['s']['total_pages'] ?? 0),
        'total' => ($r['m']['total'] ?? 0) + ($r['s']['total'] ?? 0),
    ];
}
