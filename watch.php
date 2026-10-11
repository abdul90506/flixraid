<?php
/**
 * CINEFLIX – Netflix-style watch page.
 * Own HTML5 / hls.js player (no embeds). Every title plays the demo stream from config.php.
 * Below the player: details, episodes, More Like This and About (Netflix layout).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/StreamApi.php';

$type = q('type') === 'tv' ? 'tv' : 'movie';
$id   = qi('id');
$s    = max(0, qi('s', 1));
$e    = max(1, qi('e', 1));
$info = q('info') === '1';
$embed = q('embed') === '1';  // desktop: shown as a pop-up iframe over the browse page (home stays visible, blurred, behind it)   // open the details pop-up / title sheet first (card click) instead of auto-playing

$r = $id ? $tmdb->batch(fn(Tmdb $t) => [
    'd'       => $t->details($type, $id),
    'recs'    => $t->recommendations($type, $id),
    'logo'    => $t->logo($type, $id),
    'seasons' => $type === 'tv' ? $t->seasons($id) : [],
    'season'  => $type === 'tv' ? $t->season($id, $s) : null,
]) : ['d' => null];

$d = $r['d'] ?? null;
if (!$d) {
    http_response_code(404);
    layout_header('Not found', ['body' => 'plain']);
    echo '<div class="empty-state page"><h1>Title not found</h1><p>This title could not be loaded.</p><a class="btn btn-play" href="index.php">' . icon('home') . '<span>Back to Home</span></a></div>';
    layout_footer();
    exit;
}

/* ---------- episodes / prev / next ---------- */
$seasons = $r['seasons'] ?? [];
$season  = $r['season'] ?? null;
$episode = null;
$prev = $next = null;
$eps = [];

if ($type === 'tv') {
    usort($seasons, fn($a, $b) => ($a['number'] === 0 ? 999 : $a['number']) <=> ($b['number'] === 0 ? 999 : $b['number']));
    $eps = $season['episodes'] ?? [];
    foreach ($eps as $ep) if ((int)$ep['number'] === $e) $episode = $ep;
    $epNums = array_map(fn($x) => (int)$x['number'], $eps);
    $maxEp  = $epNums ? max($epNums) : 0;
    $seasonNums = array_values(array_filter(array_map(fn($x) => (int)$x['number'], $seasons), fn($n) => $n > 0));
    $epCount = [];
    foreach ($seasons as $x) $epCount[(int)$x['number']] = (int)$x['episodes'];

    if ($e > 1) $prev = ['s' => $s, 'e' => $e - 1];
    elseif ($s > 1 && in_array($s - 1, $seasonNums, true)) $prev = ['s' => $s - 1, 'e' => max(1, $epCount[$s - 1] ?? 1)];

    if ($maxEp && $e < $maxEp) $next = ['s' => $s, 'e' => $e + 1];
    elseif (!$maxEp && $e < ($epCount[$s] ?? 0)) $next = ['s' => $s, 'e' => $e + 1];
    elseif (in_array($s + 1, $seasonNums, true)) $next = ['s' => $s + 1, 'e' => 1];

    if ($next) {
        $nextEps = $next['s'] === $s ? $eps : (($tmdb->season($id, $next['s']))['episodes'] ?? []);
        foreach ($nextEps as $x) if ((int)$x['number'] === $next['e']) {
            $next['name'] = $x['name']; $next['still'] = $x['still'] ? img($x['still'], 'w300') : ''; $next['overview'] = $x['overview'];
        }
        $next['url'] = watch_url('tv', $id, $next['s'], $next['e']);
    }
    if ($prev) $prev['url'] = watch_url('tv', $id, $prev['s'], $prev['e']);
}

/* ---------- player data ---------- */
$backdrop = ($episode['still'] ?? null) ?: $d['backdrop'];
$bgImg    = $d['backdrop'] ? img($d['backdrop'], 'w1280') : ($backdrop ? img($backdrop, 'w1280') : '');
$logo     = $r['logo'] ? 'https://image.tmdb.org/t/p/w500' . $r['logo'] : '';
$match    = $d['rating'] ? (int)round($d['rating'] * 10) : null;
$director = array_values(array_filter($d['crew'] ?? [], fn($c) => in_array($c['job'], ['Director', 'Creator'], true)));
$cast     = array_slice($d['cast'] ?? [], 0, 8);
$genres   = array_column($d['genres'] ?? [], 'name');
$realSeasons = array_values(array_filter($seasons, fn($x) => (int)$x['number'] > 0));

$sources = StreamApi::getSources($type, $id, $s, $e);
$subtitles = StreamApi::getSubtitles($type, $id, $s, $e);

$data = [
    'type'      => $type,
    'id'        => $id,
    's'         => $type === 'tv' ? $s : null,
    'e'         => $type === 'tv' ? $e : null,
    'title'     => $d['title'],
    'epTitle'   => $episode['name'] ?? null,
    'poster'    => $d['poster'],
    'backdrop'  => $backdrop,
    'bg'        => $bgImg,
    'year'      => $d['year'],
    'titleUrl'  => title_url($type, $id),
    'sources'   => $sources,
    'subtitles' => $subtitles,
    'intro'     => DEMO_INTRO,
    'credits'   => DEMO_CREDITS,
    'skip'      => PLAYER_SKIP_SECONDS,
    'next'      => $next,
    'prev'      => $prev,
    'episodes' => array_map(fn($x) => [
        'number' => (int)$x['number'], 'name' => $x['name'], 'overview' => $x['overview'], 'runtime' => $x['runtime'],
        'still' => $x['still'] ? img($x['still'], 'w300') : '', 'url' => watch_url('tv', $id, $s, (int)$x['number']),
    ], $eps),
    'recs'     => array_map(fn($x) => [
        'title' => $x['title'], 'img' => !empty($x['backdrop']) ? img($x['backdrop'], 'w500') : (!empty($x['poster']) ? img($x['poster'], 'w342') : ''),
        'url' => watch_url($x['type'], (int)$x['id']),
    ], array_slice($r['recs'] ?? [], 0, 6)),
    'info'     => $info,
    'embed'    => $embed,
    'item'     => ['id' => $id, 'type' => $type, 'title' => $d['title'], 'poster' => $d['poster'], 'backdrop' => $d['backdrop'], 'year' => $d['year']],
];

/* extra modal data */
$writers  = array_values(array_filter($d['crew'] ?? [], fn($c) => in_array($c['job'], ['Writer', 'Screenplay', 'Novel', 'Story', 'Teleplay', 'Author', 'Characters'], true)));
$origLang = $d['facts']['Original Language'] ?? '';
$status   = $d['facts']['Status'] ?? '';
$castAll  = array_slice($d['cast'] ?? [], 0, 12);
$tag = '';
if ($type === 'tv' && in_array($status, ['Returning Series', 'In Production'], true)) $tag = 'New Episodes';
elseif ((int)$d['year'] >= (int)date('Y') - 1) $tag = 'Recently Added';
$epRuntime = $episode['runtime'] ?? '';
$recsAll  = array_slice($r['recs'] ?? [], 0, 18);
$person = fn($list) => implode(', ', array_map(fn($c) => '<a href="' . e(person_url($c['id'])) . '">' . e($c['name']) . '</a>', $list));

$pageTitle = $type === 'tv' ? $d['title'] . ' S' . $s . ':E' . $e : $d['title'];

/* ---------- icons ---------- */
$I = [
    'back'   => '<svg viewBox="0 0 24 24"><path d="M15.5 4 7.5 12l8 8" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'play'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 3.8v16.4a.8.8 0 0 0 1.2.7l13.4-8.2a.8.8 0 0 0 0-1.4L7.2 3.1A.8.8 0 0 0 6 3.8Z"/></svg>',
    'pause'  => '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="5" y="3.5" width="4.6" height="17" rx="1"/><rect x="14.4" y="3.5" width="4.6" height="17" rx="1"/></svg>',
    'rew'    => '<svg viewBox="0 0 24 24" class="i-rew" fill="none"><g class="arr"><path d="M12.6 3.52A8.5 8.5 0 1 1 5.99 5.99" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M9 3.5 13.4.9v5.2Z" fill="currentColor" stroke="currentColor" stroke-width=".6" stroke-linejoin="round"/></g><text class="sk-n" x="12" y="15.2" font-size="8.2" font-weight="800" text-anchor="middle" fill="currentColor" font-family="Inter,Arial,sans-serif" letter-spacing="-.4">10</text></svg>',
    'fwd'    => '<svg viewBox="0 0 24 24" class="i-fwd" fill="none"><g class="arr"><path d="M11.4 3.52A8.5 8.5 0 1 0 18.01 5.99" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M15 3.5 10.6.9v5.2Z" fill="currentColor" stroke="currentColor" stroke-width=".6" stroke-linejoin="round"/></g><text class="sk-n" x="12" y="15.2" font-size="8.2" font-weight="800" text-anchor="middle" fill="currentColor" font-family="Inter,Arial,sans-serif" letter-spacing="-.4">10</text></svg>',
    'vol'    => '<svg viewBox="0 0 24 24"><path d="M4 9.5h3.5L12.5 5v14l-5-4.5H4z" fill="currentColor"/><path class="w1" d="M15.5 9a4 4 0 0 1 0 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path class="w2" d="M18 6.5a7.5 7.5 0 0 1 0 11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path class="mx" d="m16 9.5 5 5m0-5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    'next'   => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4.5 4.4v15.2a.7.7 0 0 0 1.1.6L16.4 12.6a.7.7 0 0 0 0-1.2L5.6 3.8a.7.7 0 0 0-1.1.6Z"/><rect x="17.6" y="4" width="2.6" height="16" rx=".8"/></svg>',
    'eps'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="14" height="12" rx="1.5"/><path d="M7 4.5h12.5A1.5 1.5 0 0 1 21 6v10" stroke-linecap="round"/></svg>',
    'subs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4.5h16a1 1 0 0 1 1 1V16a1 1 0 0 1-1 1h-8l-5 3.5V17H4a1 1 0 0 1-1-1V5.5a1 1 0 0 1 1-1Z" stroke-linejoin="round"/><path d="M7 9.5h4M13 9.5h4M7 13h7" stroke-linecap="round"/></svg>',
    'speed'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3.5 17a8.5 8.5 0 1 1 17 0" stroke-linecap="round"/><path d="m12 17 4.2-5.5" stroke-linecap="round"/><circle cx="12" cy="17" r="1.4" fill="currentColor"/></svg>',
    'hd'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M7 9v6M7 12h3.5M10.5 9v6M14 9v6h1.6a2.6 3 0 0 0 0-6Z" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'pip'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="4.5" width="19" height="15" rx="2"/><rect x="12" y="11.5" width="7" height="5" rx="1" fill="currentColor" stroke="none"/></svg>',
    'fs'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path class="fs-in" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/><path class="fs-out" d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>',
    'close'  => '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>',
    'help'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 10h1M9.5 10h1M13 10h1M16.5 10h1M7.5 14h9" stroke-linecap="round"/></svg>',
    'reload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M20 12a8 8 0 1 1-2.3-5.6"/><path d="M20 4v5h-5" stroke-linejoin="round"/></svg>',
    'lock'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" stroke-linecap="round"/></svg>',
    'unlock' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 7.7-1.5" stroke-linecap="round"/></svg>',
    'info'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5" stroke-linecap="round"/></svg>',
    'plus'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
    'share'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7.5 7.5 12 3l4.5 4.5"/><path d="M5 12v7a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19v-7"/></svg>',
    'skipi'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 5.2v13.6a.7.7 0 0 0 1.1.6L14 13v5.8a.7.7 0 0 0 1.1.6l8-6.8a.7.7 0 0 0 0-1.2l-8-6.8a.7.7 0 0 0-1.1.6V11L5.1 4.6a.7.7 0 0 0-1.1.6Z" transform="scale(.92) translate(-1 1)"/></svg>',
    'mute'   => '<svg viewBox="0 0 24 24"><path d="M4 9.5h3.5L12.5 5v14l-5-4.5H4z" fill="currentColor"/><path d="m16 9.5 5 5m0-5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    'check'  => '<svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'thumb'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><path class="th-o" d="M7.5 10.5 11 3.6c1.4 0 2.3 1.1 2 2.5l-.7 3.4h5.4a2 2 0 0 1 2 2.4l-1.3 6.3a2 2 0 0 1-2 1.6H7.5Zm0 0H4.2v9.3h3.3"/></svg>',
    'gear'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M5.5 18.5l1.7-1.7M16.8 7.2l1.7-1.7" stroke-linecap="round"/><circle cx="12" cy="12" r="6.6"/></svg>',
    'cc'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M10.5 10.2a2.2 2.2 0 1 0 0 3.6M17 10.2a2.2 2.2 0 1 0 0 3.6" stroke-linecap="round"/></svg>',
    'send'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><path d="M21 3 10.2 13.8M21 3l-6.8 18-4-7.2L3 9.8Z"/></svg>',
    'film'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/></svg>',
    'down'   => '<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'server' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
];
?><!DOCTYPE html>
<html lang="en"<?= $embed ? ' class="embed"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e('Watch ' . $pageTitle . ' – ' . SITE_NAME) ?></title>
<meta name="theme-color" content="#000000">
<meta name="description" content="<?= e(mb_substr($d['overview'], 0, 160)) ?>">
<meta property="og:title" content="<?= e($pageTitle . ' – ' . SITE_NAME) ?>">
<?php if ($bgImg): ?><meta property="og:image" content="<?= e($bgImg) ?>"><?php endif; ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%23e50914'/><path d='M24 18v28l22-14z' fill='white'/></svg>">
<link rel="preconnect" href="https://image.tmdb.org">
<?php if (preg_match('#^https?://[^/]+#', $sources[0]['url'] ?? '', $om)): ?><link rel="preconnect" href="<?= e($om[0]) ?>" crossorigin><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/player.css?v=<?= @filemtime(__DIR__ . '/assets/css/player.css') ?>">
<script type="speculationrules">{"prerender":[{"where":{"selector_matches":"a[href*='info=1']"},"eagerness":"moderate"}]}</script>
</head>
<body class="wpage<?= $info ? ' is-info' : ' is-playing' ?><?= $embed ? ' is-embed' : '' ?>"<?= $bgImg ? ' style="--wm-bg:url(\'' . e($bgImg) . '\')"' : '' ?>>
<a class="wm-brand" href="index.php"><?= e(SITE_NAME) ?></a>

<!-- ===================== DETAILS MODAL (desktop) / SHEET (mobile) ===================== -->
<div class="wm" id="wm" role="dialog" aria-label="<?= e($d['title']) ?>">
 <div class="wm-card">
  <button class="wm-close" id="wmClose" aria-label="Close" title="Close (Esc)"><?= $I['close'] ?></button>

  <!-- hero -->
  <header class="wm-hero" id="wmHero">
    <div class="wm-hero-img" style="<?= $bgImg ? 'background-image:url(' . e($bgImg) . ')' : '' ?>"></div>
    <div class="wm-trailer" id="wmTrailerBox"></div>
    <div class="wm-hero-fade"></div>
    <button class="wm-hero-play" id="wmHeroPlay" aria-label="Play"><?= $I['play'] ?></button>
    <?php if (!empty($d['trailer'])): ?><button class="wm-chip-trailer" id="wmTrailerChip" data-yt="<?= e($d['trailer']) ?>"><?= $I['play'] ?>Trailer</button><?php endif; ?>
    <?php if ($tag): ?><span class="wm-tag"><?= e($tag) ?></span><?php endif; ?>
    <div class="wm-hero-info">
      <?php if ($logo): ?><img class="wm-logo" src="<?= e($logo) ?>" alt="<?= e($d['title']) ?>"><?php else: ?><h1 class="wm-logo-t"><?= e($d['title']) ?></h1><?php endif; ?>
      <div class="wm-prog" id="wmProg" hidden><div class="wm-prog-bar"><i id="wmProgFill"></i></div><span id="wmProgText"></span></div>
      <div class="wm-hero-btns">
        <button class="wm-play" id="wiPlay"><?= $I['play'] ?><span id="wiPlayLabel">Play</span></button>
        <button class="wm-round" id="wiList" title="Add to My List" aria-label="My List"><span class="i-add"><?= $I['plus'] ?></span><span class="i-ok"><?= $I['check'] ?></span></button>
        <button class="wm-round wm-rate" id="wiRate" title="I like this" aria-label="Rate"><?= $I['thumb'] ?></button>
      </div>
    </div>
  </header>

  <div class="wm-body">
    <!-- mobile title block -->
    <div class="wm-mhead">
      <div class="wm-kicker"><b>C</b><?= $type === 'tv' ? 'SERIES' : 'FILM' ?></div>
      <h1 class="wm-mtitle"><?= e($d['title']) ?></h1>
    </div>

    <div class="wm-cols">
      <div class="wm-left">
        <div class="wm-meta">
          <?php if ($match): ?><span class="wi-match"><?= $match ?>% match</span><?php endif; ?>
          <?php if ($d['year']): ?><span><?= e($d['year']) ?></span><?php endif; ?>
          <?php if ($d['certification']): ?><span class="wi-cert m-only"><?= e($d['certification']) ?></span><?php endif; ?>
          <?php if ($type === 'tv' && $realSeasons): ?><span><?= count($realSeasons) ?> Season<?= count($realSeasons) > 1 ? 's' : '' ?></span><?php elseif ($d['runtime']): ?><span><?= e($d['runtime']) ?></span><?php endif; ?>
          <span class="wi-badge">HD</span>
          <?php if ($origLang): ?><span class="wm-audio m-only"><?= $I['play'] ?><?= e($origLang) ?></span><?php endif; ?>
        </div>
        <div class="wm-rating d-only">
          <?php if ($d['certification']): ?><span class="wi-cert"><?= e($d['certification']) ?></span><?php endif; ?>
          <span><?= e(implode(', ', array_slice($genres, 0, 3))) ?></span>
        </div>
        <!-- mobile big buttons -->
        <div class="wm-mbtns m-only">
          <button class="wm-mplay" id="wiPlayM"><?= $I['play'] ?><span id="wiPlayLabelM">Play</span></button>
          <div class="wm-mprog" id="wmProgM" hidden><i id="wmProgFillM"></i><span id="wmProgTextM"></span></div>
          <?php if (!empty($d['trailer'])): ?><button class="wm-mbtn2" id="wiTrailerM"><?= $I['film'] ?><span>Trailer</span></button><?php endif; ?>
        </div>
        <?php if ($type === 'tv' && $episode && !$info): ?><h3 class="wm-eph">S<?= $s ?>:E<?= $e ?> “<?= e($episode['name']) ?>”</h3><?php endif; ?>
        <p class="wm-overview" id="wmOverview" title="Show more"><?= e(($type === 'tv' && !$info && !empty($episode['overview'])) ? $episode['overview'] : $d['overview']) ?></p>
        <?php if ($origLang): ?><div class="wm-langs d-only"><span>Audio</span><b><?= e($origLang) ?></b><b class="dim">English [Original]</b></div><?php endif; ?>
        <?php if ($cast): ?><p class="wm-castline m-only"><span>Cast:</span> <?= e(implode(', ', array_column(array_slice($cast, 0, 4), 'name'))) ?>… <a href="#more-details" data-goto="details">more</a></p><?php endif; ?>
        <?php if ($director): ?><p class="wm-castline m-only"><span><?= e($director[0]['job']) ?>:</span> <?= e($director[0]['name']) ?></p><?php endif; ?>
        <!-- mobile action row -->
        <div class="wm-actrow m-only">
          <button class="wm-act" id="wiListM"><span class="i-add"><?= $I['plus'] ?></span><span class="i-ok"><?= $I['check'] ?></span><b>My List</b></button>
          <button class="wm-act wm-rate" id="wiRateM"><?= $I['thumb'] ?><b>Rate</b></button>
          <button class="wm-act" id="wiShare"><?= $I['send'] ?><b>Share</b></button>
          <a class="wm-act" href="<?= e(title_url($type, $id)) ?>"><?= $I['info'] ?><b>Details</b></a>
        </div>
      </div>
      <div class="wm-right d-only">
        <?php if ($cast): ?><p><span>Cast:</span> <?= $person(array_slice($cast, 0, 3)) ?>, <a class="more" href="#about" data-goto="about"><i>more</i></a></p><?php endif; ?>
        <?php if ($genres): ?><p><span>Genres:</span> <?= e(implode(', ', $genres)) ?></p><?php endif; ?>
        <?php if (!empty($d['keywords'])): ?><p><span>This <?= $type === 'tv' ? 'Show' : 'Movie' ?> is:</span> <?= e(implode(', ', array_map('ucfirst', array_slice($d['keywords'], 0, 3)))) ?></p><?php endif; ?>
      </div>
    </div>

    <!-- mobile tabs -->
    <nav class="wm-tabs m-only" id="wmTabs">
      <?php if ($type === 'tv'): ?><button data-tab="episodes" class="on">Episodes</button><?php endif; ?>
      <button data-tab="more"<?= $type !== 'tv' ? ' class="on"' : '' ?>>More Like This</button>
      <button data-tab="details">More Details</button>
    </nav>

    <?php if ($type === 'tv'): ?>
    <section class="wm-sec wm-pane on" id="episodes" data-pane="episodes">
      <div class="wi-sec-head">
        <h2 class="d-only">Episodes</h2>
        <div class="wi-select">
          <select id="wiSeason" aria-label="Season">
            <?php foreach ($seasons as $x): ?><option value="<?= (int)$x['number'] ?>"<?= (int)$x['number'] === $s ? ' selected' : '' ?>><?= e($x['name']) ?><?= !empty($x['episodes']) ? ' (' . (int)$x['episodes'] . ' Episodes)' : '' ?></option><?php endforeach; ?>
            <?php if (!$seasons): ?><option value="<?= $s ?>">Season <?= $s ?></option><?php endif; ?>
          </select>
        </div>
      </div>
      <div class="wi-eps" id="wiEps">
        <?php foreach ($eps as $x): $n = (int)$x['number']; ?>
          <a class="ep<?= $n === $e && !$info ? ' cur' : '' ?>" href="<?= e(watch_url('tv', $id, $s, $n)) ?>">
            <span class="ep-n"><?= $n ?></span>
            <span class="ep-img"><?php if ($x['still']): ?><img src="<?= e(img($x['still'], 'w300')) ?>" alt="" loading="lazy"><?php endif; ?><span class="ep-pl"><?= $I['play'] ?></span></span>
            <span class="ep-txt"><span class="ep-tt"><b><?= e($x['name']) ?></b><span><?= e($x['runtime'] ?: '') ?></span></span><span class="ep-sub">E<?= $n ?> • S<?= $s ?><?= $x['runtime'] ? ' • ' . e($x['runtime']) : '' ?></span><p><?= e($x['overview'] ?: '') ?></p></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$eps): ?><p class="wi-empty">No episode information available for this season.</p><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($recsAll): ?>
    <section class="wm-sec wm-pane<?= $type !== 'tv' ? ' on' : '' ?>" id="more" data-pane="more">
      <h2 class="d-only">More Like This</h2>
      <div class="mlt-wrap collapsed" id="mltWrap">
        <div class="wi-grid" id="mltGrid">
          <?php foreach ($recsAll as $x): $pk = $x['type'] . ':' . (int)$x['id']; $m = !empty($x['rating']) ? (int)round($x['rating'] * 10) : 0; ?>
            <div class="mlt" data-pk="<?= e($pk) ?>">
              <a class="mlt-img" href="<?= e(title_url($x['type'], (int)$x['id'])) ?>" title="<?= e($x['title']) ?>">
                <?php if (!empty($x['backdrop'])): ?><img class="mlt-bd" src="<?= e(img($x['backdrop'], 'w780')) ?>" alt="" loading="lazy" decoding="async" onload="this.classList.add('ld')"><?php endif; ?>
                <?php if (!empty($x['poster'])): ?><img class="mlt-po" src="<?= e(img($x['poster'], 'w342')) ?>" alt="<?= e($x['title']) ?>" loading="lazy">
                <?php else: ?><span class="mlt-po mlt-po-t" data-pk="<?= e($pk) ?>"><?php if (!empty($x['backdrop'])): ?><img src="<?= e(img($x['backdrop'], 'w300')) ?>" alt="" loading="lazy"><?php endif; ?><b><?= e($x['title']) ?></b></span><?php endif; ?>
                <span class="mlt-rt"></span>
                <span class="mlt-name"><?= e($x['title']) ?></span>
                <span class="mlt-logo"></span>
              </a>
              <div class="mlt-body">
                <div class="mlt-row">
                  <div class="mlt-info">
                    <?php if ($m): ?><span class="wi-match"><?= $m ?>% match</span><?php endif; ?>
                    <div class="mlt-sub"><span class="wi-cert mlt-cert" hidden></span><span class="mlt-year"><?= e($x['year'] ?? '') ?></span><span class="mlt-len"></span><span class="mlt-type"><?= $x['type'] === 'tv' ? 'Series' : 'Movie' ?></span></div>
                  </div>
                  <button class="wm-round sm mlt-add" data-item='<?= e(json_encode(['id' => (int)$x['id'], 'type' => $x['type'], 'title' => $x['title'], 'poster' => $x['poster'] ?? '', 'backdrop' => $x['backdrop'] ?? '', 'year' => $x['year'] ?? ''])) ?>' title="Add to My List"><span class="i-add"><?= $I['plus'] ?></span><span class="i-ok"><?= $I['check'] ?></span></button>
                </div>
                <a class="mlt-title" href="<?= e(title_url($x['type'], (int)$x['id'])) ?>"><?= e($x['title']) ?></a>
                <p class="mlt-desc"></p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mlt-more"><button class="wm-round" id="mltToggle" aria-label="Show more"><?= $I['down'] ?></button></div>
      </div>
    </section>
    <?php endif; ?>

    <section class="wm-sec wm-pane" id="about" data-pane="details">
      <h2 class="d-only">About <b><?= e($d['title']) ?></b></h2>
      <table class="wm-about">
        <?php if ($director): ?><tr><th><?= $type === 'tv' ? 'Creators' : 'Director' ?></th><td><?= $person(array_slice($director, 0, 3)) ?></td></tr><?php endif; ?>
        <?php if ($writers): ?><tr><th>Writer</th><td><?= $person(array_slice($writers, 0, 4)) ?></td></tr><?php endif; ?>
        <?php if ($castAll): ?><tr><th>Cast</th><td><?= $person($castAll) ?></td></tr><?php endif; ?>
        <?php if ($genres): ?><tr><th>Genres</th><td><?= e(implode(', ', $genres)) ?></td></tr><?php endif; ?>
        <?php if (!empty($d['keywords'])): ?><tr><th>This <?= $type === 'tv' ? 'Show' : 'Movie' ?> is</th><td><?= e(implode(', ', array_map('ucfirst', array_slice($d['keywords'], 0, 6)))) ?></td></tr><?php endif; ?>
        <?php if ($d['certification']): ?><tr><th>Maturity rating</th><td><span class="wi-cert"><?= e($d['certification']) ?></span> <span class="dim">Recommended for audiences per local rating</span></td></tr><?php endif; ?>
        <?php if ($origLang): ?><tr><th>Original language</th><td><?= e($origLang) ?></td></tr><?php endif; ?>
        <?php if ($type === 'tv' && !empty($d['networks'])): ?><tr><th>Network</th><td><?= e(implode(', ', array_column($d['networks'], 'name'))) ?></td></tr><?php endif; ?>
        <?php if (!empty($d['release'])): ?><tr><th>Release</th><td><?= e($d['release']) ?></td></tr><?php endif; ?>
        <?php if ($status): ?><tr><th>Status</th><td><?= e($status) ?></td></tr><?php endif; ?>
      </table>
      <?php $castPics = array_slice($d['cast'] ?? [], 0, 15); if ($castPics): ?>
      <h3 class="wm-h3">Cast</h3>
      <div class="wm-cast">
        <?php foreach ($castPics as $c): ?>
          <a class="wm-person" href="<?= e(person_url($c['id'])) ?>">
            <span class="wm-ph"><?php if (!empty($c['profile'])): ?><img src="<?= e(img($c['profile'], 'w185')) ?>" alt="" loading="lazy"><?php else: ?><i><?= e(mb_substr($c['name'], 0, 1)) ?></i><?php endif; ?></span>
            <b><?= e($c['name']) ?></b><?php if (!empty($c['character'])): ?><small><?= e($c['character']) ?></small><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
  </div>
 </div>
</div>
<button class="wm-fclose" id="wmFClose" aria-label="Close"><?= $I['close'] ?></button>

<!-- ===================== PLAYER (full-viewport layer) ===================== -->
<section class="stage" id="stage">
<div class="nfp is-loading is-paused<?= $type === 'tv' ? ' is-tv' : '' ?><?= $next ? ' has-next' : '' ?>" id="nfp" tabindex="-1">
  <video id="nfpVideo" playsinline webkit-playsinline preload="auto" crossorigin="anonymous"></video>
  <iframe id="nfpIframe" class="nfp-iframe" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen style="display:none;position:absolute;inset:0;width:100%;height:100%;border:0;z-index:2;background:#000;"></iframe>
  <div class="nfp-subs" id="nfpSubs" aria-live="off"></div>
  <div class="nfp-click" id="nfpClick"></div>
  <div class="nfp-shade"></div>
  <div class="nfp-ripple left" id="rippleL"><div><?= $I['rew'] ?><span id="rippleLText">10 seconds</span></div></div>
  <div class="nfp-ripple right" id="rippleR"><div><?= $I['fwd'] ?><span id="rippleRText">10 seconds</span></div></div>
  <div class="nfp-flash" id="nfpFlash"></div>

  <div class="nfp-loading" id="nfpLoading">
    <div class="nfp-loading-bg" style="<?= $bgImg ? 'background-image:url(' . e($bgImg) . ')' : '' ?>"></div>
    <div class="nfp-loading-inner">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="nfp-loading-logo"><?php else: ?><div class="nfp-loading-title"><?= e($d['title']) ?></div><?php endif; ?>
      <?php if ($type === 'tv'): ?><div class="nfp-loading-ep">S<?= $s ?>:E<?= $e ?><?= $episode ? ' · ' . e($episode['name']) : '' ?></div><?php endif; ?>
      <div class="nfp-ring" id="nfpRing" role="progressbar" aria-label="Loading"><span id="nfpPct">0%</span></div>
    </div>
  </div>
  <div class="nfp-buffer" id="nfpBuffer"><div class="nfp-spinner"></div></div>

  <!-- top bar: close X, + centred title on touch + Server switcher in embed mode -->
  <div class="nfp-top">
    <div class="nfp-top-title"><b><?= e($d['title']) ?></b><?php if ($type === 'tv'): ?><span>S<?= $s ?>:E<?= $e ?><?= $episode ? ' “' . e($episode['name']) . '”' : '' ?></span><?php endif; ?></div>
    <button class="nfp-icon nfp-server embed-server-btn" data-panel="server" title="Switch Server" style="display:none;" aria-label="Switch Server"><?= $I['server'] ?><span>Server</span></button>
    <button class="nfp-icon nfp-close" id="nfpClose" title="Close (Esc)" aria-label="Close player"><?= $I['close'] ?></button>
  </div>

  <div class="nfp-pauseinfo" id="nfpPauseInfo">
    <div class="pi-inner">
      <span class="pi-kicker">You're watching</span>
      <?php if ($logo): ?><img class="pi-logo" src="<?= e($logo) ?>" alt="<?= e($d['title']) ?>"><?php else: ?><h1><?= e($d['title']) ?></h1><?php endif; ?>
      <?php if ($type === 'tv'): ?><h2>Season <?= $s ?>: Ep. <?= $e ?><?= $episode ? ' “' . e($episode['name']) . '”' : '' ?></h2>
      <?php else: ?><h2><?= e(implode(' · ', array_filter([$d['year'], $d['certification'], $d['runtime']]))) ?></h2><?php endif; ?>
      <p><?= e(mb_strimwidth(($episode['overview'] ?? '') ?: $d['overview'], 0, 230, '…')) ?></p>
    </div>
    <span class="pi-paused">Paused</span>
  </div>

  <button class="nfp-bigplay" id="nfpBigPlay" aria-label="Play"><?= $I['play'] ?></button>

  <div class="nfp-center">
    <button class="nfp-icon c-skip" id="cRew" aria-label="Back 10 seconds" title="Back 10s (←)"><?= $I['rew'] ?></button>
    <button class="nfp-icon c-play nfp-pp" id="cPlay" aria-label="Play / Pause"><span class="i-play"><?= $I['play'] ?></span><span class="i-pause"><?= $I['pause'] ?></span></button>
    <button class="nfp-icon c-skip" id="cFwd" aria-label="Forward 10 seconds" title="Forward 10s (→)"><?= $I['fwd'] ?></button>
  </div>

  <div class="nfp-resume" id="nfpResume" hidden><span id="nfpResumeText"></span><button id="nfpStartOver">Start Over</button></div>
  <button class="nfp-skip" id="nfpSkip" hidden><i class="sk-fill" id="skFill"></i><span class="sk-txt">Skip Intro</span><span class="sk-ic"><?= $I['skipi'] ?></span></button>
  <?php if ($next): ?><a class="nfp-nextpill" id="nfpNextPill" href="<?= e($next['url']) ?>"><?= $I['play'] ?>Next Episode</a><?php endif; ?>
  <button class="nfp-unmute" id="nfpUnmute" hidden><span class="nfp-icon"><?= $I['mute'] ?></span><b>Tap to unmute</b></button>

  <div class="nfp-lock" id="nfpLock" hidden>
    <button class="nfp-unlock" id="nfpUnlock"><span class="nfp-icon"><?= $I['lock'] ?></span><b>Screen Locked</b><small>Tap to unlock</small></button>
  </div>

  <div class="nfp-bottom">
    <div class="nfp-progress-row">
      <div class="nfp-progress" id="nfpProgress">
        <div class="nfp-track">
          <div class="nfp-buffered" id="nfpBuffered"></div>
          <div class="nfp-played" id="nfpPlayed"></div>
          <div class="nfp-hoverbar" id="nfpHoverBar"></div>
        </div>
        <div class="nfp-thumb" id="nfpThumb"></div>
        <div class="nfp-preview" id="nfpPreview">
          <div class="pv-frame"><canvas id="pvCanvas" width="320" height="180"></canvas><div class="pv-spin"></div><span class="pv-time" id="pvTime">0:00</span></div>
          <i class="pv-arrow"></i>
        </div>
      </div>
      <span class="nfp-remaining" id="nfpRemaining">0:00</span>
    </div>

    <div class="nfp-controls desk-row">
      <div class="nfp-left">
        <button class="nfp-icon nfp-pp" id="btnPlay" title="Play (Space)"><span class="i-play"><?= $I['play'] ?></span><span class="i-pause"><?= $I['pause'] ?></span></button>
        <button class="nfp-icon" id="btnRew" title="Back 10 seconds (←)"><?= $I['rew'] ?></button>
        <button class="nfp-icon" id="btnFwd" title="Forward 10 seconds (→)"><?= $I['fwd'] ?></button>
        <div class="nfp-vol" id="nfpVol">
          <button class="nfp-icon" id="btnMute" title="Mute (M)"><?= $I['vol'] ?></button>
          <div class="nfp-vol-pop"><div class="nfp-vol-slider" id="volSlider"><div class="nfp-vol-fill" id="volFill"><i></i></div></div></div>
        </div>
        <span class="nfp-time" id="nfpTime">0:00 / 0:00</span>
      </div>
      <div class="nfp-title">
        <?php if ($type === 'tv'): ?><span class="ep-pill">S<?= $s ?> • E<?= $e ?></span><b><?= e($d['title']) ?></b><?= $episode ? '<i class="sep">–</i><em>' . e($episode['name']) . '</em>' : '' ?>
        <?php else: ?><b><?= e($d['title']) ?></b><?php endif; ?>
      </div>
      <div class="nfp-right">
        <?php if ($next): ?>
          <div class="nfp-hoverwrap">
            <a class="nfp-icon" id="btnNext" href="<?= e($next['url']) ?>" title="Next Episode (N)"><?= $I['next'] ?></a>
            <div class="nfp-nextprev">
              <div class="np-head">Next Episode</div>
              <div class="np-body">
                <?php if (!empty($next['still'])): ?><img src="<?= e($next['still']) ?>" alt="" loading="lazy"><?php endif; ?>
                <div><b><?= (int)$next['e'] ?>&nbsp; <?= e($next['name'] ?? ('Episode ' . $next['e'])) ?></b><p><?= e(mb_strimwidth($next['overview'] ?? '', 0, 140, '…')) ?></p></div>
              </div>
            </div>
          </div>
        <?php endif; ?>
        <button class="nfp-icon" data-panel="subs" title="Audio &amp; Subtitles"><?= $I['subs'] ?></button>
        <?php if ($type === 'tv'): ?><button class="nfp-icon" data-panel="episodes" title="Episodes"><?= $I['eps'] ?></button><?php endif; ?>
        <button class="nfp-icon nfp-server" data-panel="server" title="Server (Direct &amp; Embed)"><?= $I['server'] ?><small class="srvBadge" style="position:absolute;top:-3px;right:-10px;background:#22c55e;color:#fff;font-size:9px;font-weight:800;padding:1px 4px;border-radius:3px;line-height:1.3;">DIRECT</small></button>
        <button class="nfp-icon nfp-cc" id="btnCC" title="Subtitles on/off (C)"><?= $I['cc'] ?></button>
        <button class="nfp-icon nfp-q" data-panel="settings" title="Settings – speed &amp; quality"><?= $I['gear'] ?><small class="qBadge"></small></button>
        <button class="nfp-icon" id="btnPip" title="Picture in picture (P)" hidden><?= $I['pip'] ?></button>
        <button class="nfp-icon" id="btnFs" title="Full screen (F)"><?= $I['fs'] ?></button>
      </div>
    </div>

    <div class="nfp-actions touch-row">
      <button class="act" data-panel="server"><span class="nfp-icon"><?= $I['server'] ?></span><b><i class="lg">Server (</i><span id="actServer">Auto</span><i class="lg">)</i></b></button>
      <button class="act" data-panel="settings"><span class="nfp-icon"><?= $I['speed'] ?></span><b><i class="lg">Speed (</i><span id="actSpeed">1x</span><i class="lg">)</i></b></button>
      <button class="act" id="actLock"><span class="nfp-icon"><?= $I['unlock'] ?></span><b>Lock</b></button>
      <?php if ($type === 'tv'): ?><button class="act" data-panel="episodes"><span class="nfp-icon"><?= $I['eps'] ?></span><b>Episodes</b></button><?php endif; ?>
      <button class="act" data-panel="subs"><span class="nfp-icon"><?= $I['subs'] ?></span><b><i class="lg">Audio &amp; Subtitles</i><i class="sh">Audio</i></b></button>
      <?php if ($next): ?><a class="act" id="actNext" href="<?= e($next['url']) ?>"><span class="nfp-icon"><?= $I['next'] ?></span><b>Next Ep.</b></a><?php endif; ?>
      <button class="act act-fs" id="actFs"><span class="nfp-icon"><?= $I['fs'] ?></span><b><i class="lg">Full Screen</i><i class="sh">Screen</i></b></button>
    </div>
  </div>

  <div class="nfp-sheetbg" id="nfpSheetBg"></div>
  <div class="nfp-panel nfp-panel-subs" id="panel-subs">
    <button class="nfp-icon panel-x" data-close-panel><?= $I['close'] ?></button>
    <h4 class="sheet-title">Audio &amp; Subtitles</h4>
    <div class="ps-cols">
      <div class="ps-col"><h4>Audio</h4><ul id="audioList"></ul></div>
      <div class="ps-col"><h4>Subtitles</h4><ul id="subList"></ul></div>
    </div>
    <div class="ps-appear">
      <div class="ps-size"><span>Subtitle size</span><div id="subSize"><button data-size="s" title="Small">S</button><button data-size="m" title="Medium">M</button><button data-size="l" title="Large">L</button></div></div>
      <div class="ps-size"><span>Subtitle style</span><div id="subStyle"><button data-style="shadow" title="Drop shadow" class="st-shadow">Aa</button><button data-style="box" title="Black box" class="st-box">Aa</button><button data-style="yellow" title="Yellow" class="st-yellow">Aa</button></div></div>
    </div>
  </div>
  <div class="nfp-panel nfp-panel-server" id="panel-server">
    <button class="nfp-icon panel-x" data-close-panel><?= $I['close'] ?></button>
    <h4 class="sheet-title">Streaming Servers</h4>
    <p style="font-size:12px;opacity:0.75;margin:0 0 12px 0;">Switch between direct multi-quality HLS streams or embed fallback servers:</p>
    <ul id="serverListDirect"></ul>
  </div>
  <div class="nfp-panel nfp-panel-settings" id="panel-settings">
    <button class="nfp-icon panel-x" data-close-panel><?= $I['close'] ?></button>
    <h4 class="sheet-title">Settings</h4>
    <div class="st-tabs" role="tablist">
      <button class="st-tab on" type="button" data-st="server" title="Server" aria-label="Server"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg></button>
      <button class="st-tab" type="button" data-st="q" title="Quality" aria-label="Quality"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="15" width="3.2" height="6" rx=".6"/><rect x="8.2" y="11.5" width="3.2" height="9.5" rx=".6"/><rect x="13.4" y="7.5" width="3.2" height="13.5" rx=".6"/><rect x="18.6" y="3.5" width="3.2" height="17.5" rx=".6"/></svg></button>
      <button class="st-tab" type="button" data-st="audio" title="Audio &amp; Languages" aria-label="Audio &amp; Languages"><?= $I['subs'] ?></button>
      <button class="st-tab" type="button" data-st="cc" title="Subtitles" aria-label="Subtitles"><?= $I['cc'] ?></button>
      <button class="st-tab" type="button" data-st="sp" title="Playback speed" aria-label="Playback speed"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 8h5M3.5 12h4M2 16h5"/><circle cx="15.5" cy="12" r="7" fill="currentColor" stroke="none"/><path d="M15.5 8.2V12l2.6 1.8" stroke="#262626" stroke-width="1.8"/></svg></button>
    </div>
    <div class="st-sec st-server on" data-pane="server"><h4>Streaming Servers</h4><ul id="serverList"></ul></div>
    <div class="st-sec st-sp" data-pane="sp"><h4>Playback Speed</h4><div class="sp-line" id="speedList"></div></div>
    <div class="st-sec st-q" data-pane="q"><h4>Quality</h4><ul id="qualityList"></ul></div>
    <div class="st-sec st-audio" data-pane="audio"><h4>Audio &amp; Languages</h4><ul id="settingAudioList"></ul></div>
    <div class="st-sec st-cc" data-pane="cc"><button class="st-subset" type="button">Subtitle Settings</button><ul id="subList2"></ul></div>
  </div>
  <?php if ($type === 'tv'): ?>
  <div class="nfp-panel nfp-panel-eps" id="panel-episodes">
    <div class="pe-head">
      <div class="pe-select">
        <select id="peSeason" aria-label="Season">
          <?php foreach ($seasons as $x): ?><option value="<?= (int)$x['number'] ?>"<?= (int)$x['number'] === $s ? ' selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?>
          <?php if (!$seasons): ?><option value="<?= $s ?>">Season <?= $s ?></option><?php endif; ?>
        </select>
      </div>
      <button class="nfp-icon pe-close" data-close-panel><?= $I['close'] ?></button>
    </div>
    <div class="pe-list" id="peList"></div>
  </div>
  <?php endif; ?>

  <?php if ($next): ?>
  <div class="nfp-upnext" id="nfpUpNext" hidden>
    <div class="un-still"><?php if (!empty($next['still'])): ?><img src="<?= e($next['still']) ?>" alt=""><?php endif; ?></div>
    <div class="un-body">
      <span>Next Episode</span>
      <b>S<?= (int)$next['s'] ?>:E<?= (int)$next['e'] ?> <?= e($next['name'] ?? '') ?></b>
      <div class="un-actions">
        <button class="nfp-btn ghost" id="unCredits">Watch Credits</button>
        <a class="nfp-btn primary un-go" id="unGo" href="<?= e($next['url']) ?>"><i id="unFill"></i><span><?= $I['play'] ?> Next Episode</span></a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="nfp-end" id="nfpEnd" hidden>
    <div class="end-inner">
      <h3><?= $type === 'tv' ? 'You finished the season' : 'More Like This' ?></h3>
      <div class="end-grid" id="endGrid"></div>
      <div class="end-actions">
        <button class="nfp-btn" id="endReplay"><?= $I['reload'] ?> Watch Again</button>
        <button class="nfp-btn primary" id="endInfo">Details</button>
      </div>
    </div>
  </div>

  <div class="nfp-error" id="nfpError" hidden>
    <div>
      <h2>Whoops, something went wrong…</h2>
      <p id="nfpErrorMsg">We're having trouble playing this title right now. Please try again.</p>
      <div class="end-actions">
        <button class="nfp-btn primary" id="errRetry">Try Again</button>
        <button class="nfp-btn" id="errBack">Back</button>
      </div>
    </div>
  </div>

  <div class="nfp-help" id="nfpHelp" hidden>
    <div class="help-box">
      <button class="nfp-icon help-close" id="helpClose"><?= $I['close'] ?></button>
      <h3>Keyboard shortcuts</h3>
      <dl>
        <dt>Space / K</dt><dd>Play / Pause</dd>
        <dt>← / →</dt><dd>Back / Forward 10s</dd>
        <dt>↑ / ↓</dt><dd>Volume</dd>
        <dt>M</dt><dd>Mute</dd>
        <dt>F</dt><dd>Full screen</dd>
        <dt>C</dt><dd>Subtitles on/off</dd>
        <dt>S</dt><dd>Skip intro</dd>
        <dt>N</dt><dd>Next episode</dd>
        <dt>&lt; / &gt;</dt><dd>Speed</dd>
        <dt>0 – 9</dt><dd>Jump to 0–90%</dd>
        <dt>P</dt><dd>Picture in picture</dd>
        <dt>?</dt><dd>This help</dd>
        <dt>Esc</dt><dd>Close player</dd>
      </dl>
    </div>
  </div>
  <div class="nfp-toast" id="nfpToast"></div>
</div>
</section>

<div class="wi-toast" id="wiToast"></div>
<script>window.NFP = <?= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/js/vendor/hls.min.js?v=1"></script>
<script src="assets/js/player.js?v=<?= @filemtime(__DIR__ . '/assets/js/player.js') ?>"></script>
</body>
</html>
