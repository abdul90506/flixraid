<?php
/**
 * Row registry – shared by the home page (server render) and api.php (lazy rows).
 * Each row: key => [title, callable(Tmdb): items[], explore-link, variant]
 */
function row_registry(): array
{
    return [
        'trending_today' => ['Trending Now',               fn(Tmdb $t) => $t->trending('today'),                       'new.php', 'poster'],
        'top10_movies'   => ['Top 10 Movies Today',        fn(Tmdb $t) => array_slice($t->lists('movie', 'popular')['items'], 0, 10), 'movies.php', 'top10'],
        'popular_tv'     => ['Popular Shows',              fn(Tmdb $t) => $t->lists('tv', 'popular')['items'],         'tv.php', 'poster'],
        'top10_tv'       => ['Top 10 Shows Today',         fn(Tmdb $t) => array_slice($t->lists('tv', 'popular', 2)['items'], 0, 10), 'tv.php', 'top10'],
        'trending_week'  => ['Trending This Week',         fn(Tmdb $t) => $t->trending('week'),                        'new.php', 'poster'],
        'now_playing'    => ['New in Theaters',            fn(Tmdb $t) => $t->lists('movie', 'now-playing')['items'],  'movies.php?cat=now-playing', 'poster'],
        'popular_movies' => ['Popular on CINEFLIX',        fn(Tmdb $t) => $t->lists('movie', 'popular', 2)['items'],  'movies.php', 'poster'],
        'top_movies'     => ['Critically Acclaimed Movies', fn(Tmdb $t) => $t->lists('movie', 'top-rated')['items'],   'movies.php?cat=top-rated', 'poster'],
        'airing_today'   => ['New Episodes Today',         fn(Tmdb $t) => $t->lists('tv', 'airing-today')['items'],    'tv.php?cat=airing-today', 'poster'],
        'g_action'       => ['Action & Adrenaline',        fn(Tmdb $t) => $t->genre('movie', 28)['items'],             'movies.php?genre=28', 'poster'],
        'top_tv'         => ['Binge-Worthy TV Shows',      fn(Tmdb $t) => $t->lists('tv', 'top-rated')['items'],       'tv.php?cat=top-rated', 'poster'],
        'g_comedy'       => ['Comedies',                   fn(Tmdb $t) => $t->genre('movie', 35)['items'],             'movies.php?genre=35', 'poster'],
        'on_the_air'     => ['Currently Airing Series',    fn(Tmdb $t) => $t->lists('tv', 'on-the-air')['items'],     'tv.php?cat=on-the-air', 'poster'],
        'g_horror'       => ['Horror Nights',              fn(Tmdb $t) => $t->genre('movie', 27)['items'],             'movies.php?genre=27', 'poster'],
        'g_scifi_tv'     => ['Sci-Fi & Fantasy Series',    fn(Tmdb $t) => $t->genre('tv', 10765)['items'],             'tv.php?genre=10765', 'poster'],
        'g_animation'    => ['Animation',                  fn(Tmdb $t) => $t->genre('movie', 16)['items'],             'movies.php?genre=16', 'poster'],
        'g_crime_tv'     => ['Crime TV Shows',             fn(Tmdb $t) => $t->genre('tv', 80)['items'],                'tv.php?genre=80', 'poster'],
        'upcoming'       => ['Coming Soon',                fn(Tmdb $t) => $t->lists('movie', 'upcoming')['items'],     'movies.php?cat=upcoming', 'poster'],
        'g_thriller'     => ['Edge-of-Your-Seat Thrillers', fn(Tmdb $t) => $t->genre('movie', 53)['items'],            'movies.php?genre=53', 'poster'],
        'g_romance'      => ['Romantic Movies',            fn(Tmdb $t) => $t->genre('movie', 10749)['items'],          'movies.php?genre=10749', 'poster'],
        'g_anime_tv'     => ['Animated Series',            fn(Tmdb $t) => $t->genre('tv', 16)['items'],                'tv.php?genre=16', 'poster'],
        'g_docs'         => ['Documentaries',              fn(Tmdb $t) => $t->genre('movie', 99)['items'],             'movies.php?genre=99', 'poster'],
        'g_family'       => ['Family Movie Night',         fn(Tmdb $t) => $t->genre('movie', 10751)['items'],          'movies.php?genre=10751', 'poster'],
    ];
}

function render_row_key(Tmdb $t, string $key, bool $lazy = false): string
{
    $reg = row_registry();
    if (!isset($reg[$key])) return '';
    [$title, $fn, $more, $variant] = $reg[$key];
    if ($lazy) return row($title, [], ['lazy' => $key, 'more' => $more, 'variant' => $variant, 'id' => 'row-' . $key]);
    $items = $fn($t) ?: [];
    return row($title, $items, ['more' => $more, 'variant' => $variant, 'id' => 'row-' . $key]);
}

/** Renders inner track HTML for a lazy row (used by api.php). */
function render_row_items(Tmdb $t, string $key): string
{
    $reg = row_registry();
    if (!isset($reg[$key])) return '';
    [, $fn, , $variant] = $reg[$key];
    $items = array_values(array_filter($t->batch(fn($tt) => $fn($tt)) ?: [], fn($i) => !empty($i['id'])));
    $html = '';
    foreach ($items as $i => $it) $html .= $variant === 'top10' ? card($it, $i + 1) : card($it);
    return $html;
}
