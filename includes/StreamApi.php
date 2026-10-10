<?php
/**
 * StreamApi Resolver for Flixraid
 * Resolves working multi-language & multi-quality streams for movies & TV series.
 */

class StreamApi
{
    private static ?string $playerDomain = null;
    private static string $tmdbKey = '8265bd1679663a7ea12ac168da84d2e8';
    private static string $moviesApiKey = '3a67e8866ae1d2bb9e81fe7f73315a56eb3bdf5e3e755c7554c8be6910aa6b13';

    /**
     * Get stream sources for movie or TV show episode
     */
    public static function getSources(string $type, int $tmdbId, ?int $season = 1, ?int $episode = 1): array
    {
        $cacheFile = CACHE_DIR . "/src_{$type}_{$tmdbId}_{$season}_{$episode}.json";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600 * 2)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (!empty($cached)) return $cached;
        }

        $sources = [];
        $imdbId = self::getImdbId($type, $tmdbId);

        if ($type === 'movie') {
            // 1. Try 8StreamApi (AllMovieLand) for multi-language streams
            if ($imdbId) {
                $eightStreams = self::get8StreamMovieSources($imdbId);
                if (!empty($eightStreams)) {
                    foreach ($eightStreams as $st) {
                        $sources[] = $st;
                    }
                }
            }

            // 2. Try MoviesApi (Vidora)
            $movieApiStreams = self::getMoviesApiMovieSources($tmdbId);
            if (!empty($movieApiStreams)) {
                foreach ($movieApiStreams as $st) {
                    $sources[] = $st;
                }
            }
        } else {
            // TV Shows: Fetch episode stream from MoviesApi (Direct HLS)
            $tvStreams = self::getMoviesApiTvSources($tmdbId, $season ?: 1, $episode ?: 1);
            if (!empty($tvStreams)) {
                foreach ($tvStreams as $st) {
                    $sources[] = $st;
                }
            }
        }

        if (!empty($sources)) {
            @file_put_contents($cacheFile, json_encode($sources));
        }

        return $sources;
    }

    /**
     * Get multi-language subtitles via OpenSubtitles / Stremio
     */
    public static function getSubtitles(string $type, int $tmdbId, ?int $season = 1, ?int $episode = 1): array
    {
        $cacheFile = CACHE_DIR . "/subs_{$type}_{$tmdbId}_{$season}_{$episode}.json";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400 * 3)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (!empty($cached)) return $cached;
        }

        $imdbId = self::getImdbId($type, $tmdbId);
        if (!$imdbId) return [];

        $url = ($type === 'movie') 
            ? "https://opensubtitles-v3.strem.io/subtitles/movie/{$imdbId}.json"
            : "https://opensubtitles-v3.strem.io/subtitles/series/{$imdbId}:{$season}:{$episode}.json";

        $json = self::httpGet($url);
        if (!$json) return [];

        $data = json_decode($json, true);
        if (empty($data['subtitles']) || !is_array($data['subtitles'])) return [];

        // Lang name map
        $langNames = [
            'eng' => 'English',
            'hin' => 'Hindi',
            'spa' => 'Spanish',
            'fre' => 'French',
            'ger' => 'German',
            'ita' => 'Italian',
            'por' => 'Portuguese',
            'pob' => 'Portuguese (Brazil)',
            'rus' => 'Russian',
            'ara' => 'Arabic',
            'tur' => 'Turkish',
            'tam' => 'Tamil',
            'tel' => 'Telugu',
            'mal' => 'Malayalam',
            'ben' => 'Bengali',
            'kor' => 'Korean',
            'jpn' => 'Japanese',
            'zho' => 'Chinese',
            'zht' => 'Chinese (Traditional)',
            'ind' => 'Indonesian',
            'tha' => 'Thai',
            'vie' => 'Vietnamese',
        ];

        $subs = [];
        $seen = [];
        foreach ($data['subtitles'] as $item) {
            if (empty($item['url']) || empty($item['lang'])) continue;
            $langCode = strtolower(trim($item['lang']));
            if (isset($seen[$langCode])) continue; // 1 cleanest track per language
            $seen[$langCode] = true;

            $langLabel = $langNames[$langCode] ?? strtoupper($langCode);
            // Proxy subtitle to avoid CORS / SSL errors
            $proxySub = 'proxy.php?url=' . urlencode($item['url']) . '&ref=' . urlencode('https://opensubtitles.org/');

            $subs[] = [
                'lang'  => $langCode,
                'label' => $langLabel,
                'url'   => $proxySub,
            ];
        }

        // Sort so English and Hindi are on top if available
        usort($subs, function($a, $b) {
            $p = ['eng' => 1, 'hin' => 2];
            $pa = $p[$a['lang']] ?? 99;
            $pb = $p[$b['lang']] ?? 99;
            if ($pa !== $pb) return $pa <=> $pb;
            return strcmp($a['label'], $b['label']);
        });

        if (!empty($subs)) {
            @file_put_contents($cacheFile, json_encode($subs));
        }

        return $subs;
    }

    /**
     * Get IMDb ID using TMDB external_ids
     */
    public static function getImdbId(string $type, int $tmdbId): ?string
    {
        $cacheFile = CACHE_DIR . "/ext_{$type}_{$tmdbId}.json";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400 * 7)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if (!empty($data['imdb_id'])) return $data['imdb_id'];
        }

        $url = "https://api.themoviedb.org/3/{$type}/{$tmdbId}/external_ids?api_key=" . self::$tmdbKey;
        $json = self::httpGet($url);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['imdb_id'])) {
                @file_put_contents($cacheFile, $json);
                return $data['imdb_id'];
            }
        }
        return null;
    }

    /**
     * Resolve 8StreamApi / AllMovieLand player domain
     */
    private static function getPlayerDomain(): string
    {
        if (self::$playerDomain) return self::$playerDomain;

        $cacheFile = CACHE_DIR . "/allmovieland_domain.txt";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600 * 3)) {
            $d = trim(file_get_contents($cacheFile));
            if ($d) {
                self::$playerDomain = $d;
                return $d;
            }
        }

        $js = self::httpGet('https://allmovieland.link/player.js?v=60%20128');
        if ($js && preg_match("/const AwsIndStreamDomain\s*=\s*'([^']+)'/", $js, $m)) {
            self::$playerDomain = trim($m[1]);
            @file_put_contents($cacheFile, self::$playerDomain);
            return self::$playerDomain;
        }

        return 'https://slast430did.com';
    }

    /**
     * Get movie sources from 8StreamApi (AllMovieLand)
     */
    private static function get8StreamMovieSources(string $imdbId): array
    {
        $domain = self::getPlayerDomain();
        $playUrl = "{$domain}/play/{$imdbId}";
        $html = self::httpGet($playUrl, [
            'Referer: https://google.com',
            'Origin: https://allmovieland.fun/'
        ]);

        if (!$html || !preg_match('/let p3 = (\{.+?\});/', $html, $m)) {
            return [];
        }

        $p3 = json_decode($m[1], true);
        if (empty($p3['file']) || empty($p3['key'])) {
            return [];
        }

        $playlistRes = self::httpGet($p3['file'], [
            "X-Csrf-Token: {$p3['key']}",
            "Referer: {$playUrl}",
            "Origin: {$domain}"
        ]);

        if (!$playlistRes) return [];
        $playlist = json_decode($playlistRes, true);
        if (!is_array($playlist)) return [];

        $sources = [];
        foreach ($playlist as $item) {
            if (empty($item['title']) || empty($item['file'])) continue;

            $langTitle = trim($item['title']);
            $path = ltrim($item['file'], '/') . '.txt';
            $streamApiUrl = "{$domain}/playlist/{$path}";

            $m3u8Res = self::httpGet($streamApiUrl, [
                "X-Csrf-Token: {$p3['key']}",
                "Referer: {$playUrl}",
                "Origin: {$domain}",
                "Accept: */*"
            ]);

            $masterM3u8 = trim($m3u8Res ?: '');
            if (preg_match('#^https?://#i', $masterM3u8)) {
                $proxyUrl = 'proxy.php?url=' . urlencode($masterM3u8) . '&ref=' . urlencode($playUrl);
                $sources[] = [
                    'label'   => $langTitle,
                    'lang'    => $langTitle,
                    'url'     => $proxyUrl,
                    'kind'    => 'hls',
                    'server'  => '8StreamApi',
                ];
            }
        }

        return $sources;
    }

    /**
     * Get movie sources from MoviesApi
     */
    private static function getMoviesApiMovieSources(int $tmdbId): array
    {
        $url = "https://moviesapi.to/api/vidora/v1/movie/{$tmdbId}";
        $json = self::httpGet($url, [
            'x-player-key: ' . self::$moviesApiKey,
            "Referer: https://moviesapi.to/movie/{$tmdbId}",
            'Origin: https://moviesapi.to'
        ]);

        if (!$json) return [];
        $data = json_decode($json, true);
        if (empty($data['result']) || empty($data['sources'])) return [];

        $sources = [];
        foreach ($data['sources'] as $src) {
            if (empty($src['url'])) continue;
            $proxyUrl = 'proxy.php?url=' . urlencode($src['url']) . '&ref=' . urlencode('https://moviesapi.to/');
            $sources[] = [
                'label'   => 'Server Direct (Multi-Quality)',
                'lang'    => 'Original / English',
                'url'     => $proxyUrl,
                'kind'    => 'hls',
                'server'  => 'MoviesApi',
            ];
        }
        return $sources;
    }

    /**
     * Get TV series episode sources from MoviesApi
     */
    private static function getMoviesApiTvSources(int $tmdbId, int $season, int $episode): array
    {
        $url = "https://moviesapi.to/api/vidora/v1/tv/{$tmdbId}/{$season}/{$episode}";
        $json = self::httpGet($url, [
            'x-player-key: ' . self::$moviesApiKey,
            "Referer: https://moviesapi.to/tv/{$tmdbId}/{$season}/{$episode}",
            'Origin: https://moviesapi.to'
        ]);

        if (!$json) return [];
        $data = json_decode($json, true);
        if (empty($data['result']) || empty($data['sources'])) return [];

        $sources = [];
        foreach ($data['sources'] as $src) {
            if (empty($src['url'])) continue;
            $proxyUrl = 'proxy.php?url=' . urlencode($src['url']) . '&ref=' . urlencode('https://moviesapi.to/');
            $sources[] = [
                'label'   => 'Direct HLS (Multi-Quality)',
                'lang'    => 'Original / Default',
                'url'     => $proxyUrl,
                'kind'    => 'hls',
                'server'  => 'MoviesApi',
            ];
        }
        return $sources;
    }

    private static function httpGet(string $url, array $headers = []): ?string
    {
        $ch = curl_init($url);
        $reqHeaders = array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept: */*',
        ], $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $reqHeaders,
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($body !== false && $code >= 200 && $code < 400) ? $body : null;
    }
}
