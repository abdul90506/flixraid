<?php
/**
 * ------------------------------------------------------------
 *  CINEFLIX – Configuration
 * ------------------------------------------------------------
 *  Pure PHP streaming front-end.
 *  Data  : scraped live from https://www.themoviedb.org
 *  Player: own CINEFLIX HTML5/HLS player (assets/js/player.js)
 * ------------------------------------------------------------
 */

define('SITE_NAME',    'CINEFLIX');
define('SITE_TAGLINE', 'Unlimited movies, TV shows and more.');

// TMDB website (scraped) + image CDN
define('TMDB_SITE',  'https://www.themoviedb.org');
define('TMDB_IMG',   'https://image.tmdb.org/t/p/');
define('TMDB_LANG',  'en-US');

// Cache (folder must be writable by PHP)
define('CACHE_DIR',  __DIR__ . '/../cache');
define('TTL_LIST',    3 * 3600);   // lists / trending / genres
define('TTL_DETAIL', 24 * 3600);   // movie / tv / person details
define('TTL_SEARCH',  2 * 3600);   // search results
define('TTL_SEASON', 12 * 3600);   // season episodes

// HTTP
define('HTTP_TIMEOUT',      15);
define('HTTP_CONCURRENCY',   8);
define('HTTP_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36');

// Player theme color (hex without #)
define('PLAYER_COLOR', 'e50914');

// Cloudflare Stream Worker Proxy (Ultra-fast CDN HLS Proxy)
define('STREAM_WORKER_URL', 'https://steep-cell-9937stream.abdulwahab164978.workers.dev/');



// Genres (TMDB ids)
$MOVIE_GENRES = [
    28 => 'Action', 12 => 'Adventure', 16 => 'Animation', 35 => 'Comedy', 80 => 'Crime',
    99 => 'Documentary', 18 => 'Drama', 10751 => 'Family', 14 => 'Fantasy', 36 => 'History',
    27 => 'Horror', 10402 => 'Music', 9648 => 'Mystery', 10749 => 'Romance',
    878 => 'Science Fiction', 10770 => 'TV Movie', 53 => 'Thriller', 10752 => 'War', 37 => 'Western',
];
$TV_GENRES = [
    10759 => 'Action & Adventure', 16 => 'Animation', 35 => 'Comedy', 80 => 'Crime',
    99 => 'Documentary', 18 => 'Drama', 10751 => 'Family', 10762 => 'Kids', 9648 => 'Mystery',
    10763 => 'News', 10764 => 'Reality', 10765 => 'Sci-Fi & Fantasy', 10766 => 'Soap',
    10767 => 'Talk', 10768 => 'War & Politics', 37 => 'Western',
];

// Browse categories (TMDB site paths)
$CATEGORIES = [
    'movie' => [
        'popular'     => ['Popular',      '/movie'],
        'now-playing' => ['Now Playing',  '/movie/now-playing'],
        'upcoming'    => ['Upcoming',     '/movie/upcoming'],
        'top-rated'   => ['Top Rated',    '/movie/top-rated'],
    ],
    'tv' => [
        'popular'      => ['Popular',      '/tv'],
        'airing-today' => ['Airing Today', '/tv/airing-today'],
        'on-the-air'   => ['On The Air',   '/tv/on-the-air'],
        'top-rated'    => ['Top Rated',    '/tv/top-rated'],
    ],
];

/* ------------------------------------------------------------
 *  CINEFLIX Player
 *  Every title plays the demo stream below (open-licensed Blender films).
 *  Streams are tried in order – if one fails the player silently moves to the next.
 * ------------------------------------------------------------ */
$DEMO_STREAMS = [
    ['label' => 'Tears of Steel', 'url' => 'https://demo.unified-streaming.com/k8s/features/stable/video/tears-of-steel/tears-of-steel-wvtt.ism/.m3u8'],
    ['label' => 'Tears of Steel (backup)', 'url' => 'https://demo.unified-streaming.com/k8s/features/stable/video/tears-of-steel/tears-of-steel.ism/.m3u8'],
    ['label' => 'Big Buck Bunny', 'url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8'],
];
// Demo markers (seconds) – shows the "Skip Intro" button and the "Next Episode" card
define('DEMO_INTRO',   [4, 26]);
define('DEMO_CREDITS', 648);
// Seek step for ← / → and the 10s buttons
define('PLAYER_SKIP_SECONDS', 10);
