<?php
/**
 * StreamApi for Flixraid (Powered by VegaMovies Engine)
 * Replaces old proxy/embed streaming with direct VegaMovies driver streams (R2, HubCloud, G-Direct, Pixeldrain, Filepress)
 * Zero proxy streaming overhead, zero third-party embed iframes.
 */

require_once __DIR__ . '/VegaApi.php';

class StreamApi
{
    /**
     * Get stream sources for movie or TV show episode using VegaMovies
     */
    public static function getSources(string $type, int $tmdbId, ?int $season = 1, ?int $episode = 1): array
    {
        $res = self::getVegaData($type, $tmdbId, $season, $episode);
        return $res['sources'] ?? [];
    }

    /**
     * Get subtitles (empty or from OpenSubtitles if needed)
     */
    public static function getSubtitles(string $type, int $tmdbId, ?int $season = 1, ?int $episode = 1): array
    {
        return [];
    }

    /**
     * Full VegaMovies Data package for FlixRaid watch page
     */
    public static function getVegaData(string $type, int $tmdbId, ?int $season = 1, ?int $episode = 1, ?string $explicitTitle = '', ?string $desiredQuality = ''): array
    {
        $season = max(1, $season ?: 1);
        $episode = max(1, $episode ?: 1);

        $cacheFile = CACHE_DIR . "/src_vega_{$type}_{$tmdbId}_{$season}_{$episode}.json";
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 1800)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['sources'])) {
                return $cached;
            }
        }

        $res = VegaApi::getSourcesForTmdb($type, $tmdbId, $season, $episode, $explicitTitle);
        if (!empty($res['sources'])) {
            @file_put_contents($cacheFile, json_encode($res));
        }
        return $res;
    }

    /**
     * Get sources for a direct VegaMovies URL
     */
    public static function getSourcesForVegaUrl(string $url, string $type = 'movie', ?int $season = 1, ?int $episode = 1, ?string $desiredQuality = ''): array
    {
        return VegaApi::getSourcesForVegaPost($url, $type, $season, $episode, $desiredQuality);
    }
}
