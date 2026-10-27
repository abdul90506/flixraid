<?php
/**
 * VegaApi for FlixRaid
 * Direct integration with VegaMovies (https://new1.vegamovies.lease/)
 * Provides search, post scraping, driver resolution (R2, HubCloud, G-Direct, Pixeldrain, Filepress),
 * multi-audio detection, and TMDB smart release matching.
 */

class VegaApi
{
    public const VEGA_BASE = 'https://new1.vegamovies.lease';

    /**
     * cURL GET request with desktop browser headers and SSL bypass
     */
    public static function curlGet(string $url, string $referer = self::VEGA_BASE . '/', array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: ' . $referer
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_TIMEOUT        => 16,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return ['status' => $status, 'body' => $body ?: '', 'url' => $effectiveUrl];
    }

    /**
     * Clean raw string URLs
     */
    public static function cleanUrl(string $u): string
    {
        return str_replace(['\/', '&amp;'], ['/', '&'], trim($u));
    }

    /**
     * Search VegaMovies via ts-search.php
     */
    public static function search(string $query, int $page = 1): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) return ['found' => 0, 'hits' => []];

        $cacheFile = CACHE_DIR . '/vega_s_' . md5($q . '_' . $page) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 1800)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $searchUrl = self::VEGA_BASE . "/ts-search.php?q=" . urlencode($q) . "&page={$page}";
        $res = self::curlGet($searchUrl, self::VEGA_BASE . "/?s=" . urlencode($q), ['Accept: application/json, text/plain, */*']);

        if ($res['status'] !== 200 || empty($res['body'])) {
            return ['found' => 0, 'hits' => []];
        }

        $data = json_decode($res['body'], true);
        $hits = [];
        if (isset($data['hits']) && is_array($data['hits'])) {
            foreach ($data['hits'] as $hit) {
                $doc = $hit['document'] ?? [];
                $postPath = $doc['permalink'] ?? '';
                if (strpos($postPath, 'http') === 0) {
                    $parsed = parse_url($postPath);
                    $postPath = $parsed['path'] ?? '';
                }
                $fullUrl = strpos($postPath, 'http') === 0 ? $postPath : self::VEGA_BASE . (strpos($postPath, '/') === 0 ? '' : '/') . $postPath;
                $hits[] = [
                    'id'        => $doc['id'] ?? '',
                    'title'     => $doc['post_title'] ?? '',
                    'permalink' => $postPath,
                    'fullUrl'   => $fullUrl,
                    'watchUrl'  => 'watch.php?url=' . urlencode($fullUrl),
                    'thumbnail' => $doc['post_thumbnail'] ?? '',
                    'date'      => $doc['post_date'] ?? ''
                ];
            }
        }

        $out = ['found' => $data['found'] ?? count($hits), 'hits' => $hits];
        @file_put_contents($cacheFile, json_encode($out));
        return $out;
    }

    /**
     * Scrape a VegaMovies post page for qualities, drives, and episodes
     */
    public static function scrapePost(string $postUrl): array
    {
        $targetUrl = trim($postUrl);
        if (strpos($targetUrl, 'http') !== 0) {
            $targetUrl = self::VEGA_BASE . '/' . ltrim($targetUrl, '/');
        }
        $targetUrl = preg_replace('#https?://[^/]+#', self::VEGA_BASE, $targetUrl);

        $cacheFile = CACHE_DIR . '/vega_p_' . md5($targetUrl) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['qualities'])) return $cached;
        }

        $res = self::curlGet($targetUrl);
        if ($res['status'] !== 200 || empty($res['body'])) {
            return ['error' => "Failed to fetch post: {$res['status']}"];
        }

        $html = $res['body'];

        // Title
        $pageTitle = '';
        if (preg_match('/<title>(.*?)<\/title>/i', $html, $tm)) {
            $pageTitle = trim(preg_replace('/\|\s*Vegamovies.*$/i', '', str_replace(['&#038;', '&#8211;'], ['&', '-'], $tm[1])));
        }

        // Audio Info
        $audioInfo = '';
        if (preg_match('/\{([^}]+)\}/i', $pageTitle, $lm) || 
            preg_match('/Dual Audio\s*\{?([^}\]|]+)\}?/i', $pageTitle, $lm) || 
            preg_match('/\[([^\]]*(?:Hindi|English|Tamil|Telugu|Dual|Multi)[^\]]*)\]/i', $pageTitle, $lm)) {
            $audioInfo = !empty($lm[1]) ? "Dual Audio {{$lm[1]}}" : trim($lm[0]);
        } elseif (preg_match('/Language:\s*([^<\n\r]+)/i', $html, $bm)) {
            $audioInfo = trim($bm[1]);
        }
        if (empty($audioInfo)) {
            if (stripos($pageTitle, 'dual audio') !== false) $audioInfo = 'Dual Audio [Hindi + English]';
            elseif (stripos($pageTitle, 'hindi dubbed') !== false || stripos($pageTitle, 'hindi org') !== false) $audioInfo = 'Hindi Dubbed / Multi Audio';
            else $audioInfo = 'Dual Audio {Hindi-English}';
        }

        // Detailed Audio Tracks
        $audioTracks = [];
        $searchScope = $pageTitle . ' ' . substr($html, 0, 15000);
        if (preg_match('/hindi/i', $searchScope)) $audioTracks[] = 'Hindi [ORG / Main]';
        if (preg_match('/english/i', $searchScope)) $audioTracks[] = 'English [Dual Audio]';
        if (preg_match('/tamil/i', $searchScope)) $audioTracks[] = 'Tamil';
        if (preg_match('/telugu/i', $searchScope)) $audioTracks[] = 'Telugu';
        if (preg_match('/malayalam/i', $searchScope)) $audioTracks[] = 'Malayalam';
        if (preg_match('/kannada/i', $searchScope)) $audioTracks[] = 'Kannada';
        if (preg_match('/bengali/i', $searchScope)) $audioTracks[] = 'Bengali';
        if (preg_match('/japanese/i', $searchScope)) $audioTracks[] = 'Japanese';
        if (preg_match('/korean/i', $searchScope)) $audioTracks[] = 'Korean';

        if (empty($audioTracks)) {
            $audioTracks = ['Hindi [ORG / Main]', 'English [Dual Audio]'];
        } elseif (count($audioTracks) === 1 && stripos($searchScope, 'dual audio') !== false) {
            if (!in_array('English [Dual Audio]', $audioTracks)) $audioTracks[] = 'English [Dual Audio]';
            elseif (!in_array('Hindi [ORG / Main]', $audioTracks)) $audioTracks[] = 'Hindi [ORG / Main]';
        }

        // Quality Info
        $qualityInfo = '';
        if (preg_match('/Quality:\s*([^<\n\r]+)/i', $html, $qm) || preg_match('/(480p\s*\|\|\s*720p\s*\|\|\s*1080p[^<\n\r]*)/i', $html, $qm)) {
            $qualityInfo = trim($qm[1]);
        }

        // Poster
        $poster = '';
        if (preg_match('/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png|webp))["\'][^>]*class=["\'][^"\']*aligncenter/i', $html, $pm) ||
            preg_match('/<img[^>]+src=["\'](https:\/\/[^"\']+\/wp-content\/uploads\/[^"\']+\.(?:jpg|jpeg|png|webp))["\']/i', $html, $pm)) {
            $poster = $pm[1];
        }

        $isSeries = (bool)(preg_match('/season|episode|series/i', $targetUrl) || preg_match('/Season|Episode|Series/i', $pageTitle));

        preg_match_all('/<(h[1-6]|p|div)[^>]*>([\s\S]*?)<\/\1>/i', $html, $allMatches, PREG_SET_ORDER);
        $seasons = [];
        $movieQualities = [];
        $currentSeason = null;
        $lastHeading = '';

        foreach ($allMatches as $m) {
            $tag = strtolower($m[1]);
            $content = $m[2];
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($content)));

            if (strpos($tag, 'h') === 0 && strlen($text) > 4 && !preg_match('/screenshots|must see|official portal/i', $text)) {
                $lastHeading = $text;
            }

            if ($isSeries) {
                if (preg_match('/\b(?:Season|S)\s*(\d+)\b/i', $text, $sm) && (strpos($tag, 'h') === 0 || strpos($text, '**') !== false || stripos($text, 'season') !== false)) {
                    $sNum = intval($sm[1]);
                    $foundIdx = -1;
                    foreach ($seasons as $k => $s) {
                        if ($s['seasonNumber'] === $sNum) { $foundIdx = $k; break; }
                    }
                    if ($foundIdx === -1) {
                        $newSeason = ['seasonNumber' => $sNum, 'title' => "Season {$sNum}", 'qualities' => []];
                        $seasons[] = $newSeason;
                        $currentSeason = &$seasons[count($seasons) - 1];
                    } else {
                        $currentSeason = &$seasons[$foundIdx];
                    }
                }
            }

            // Look for nexdrive links
            if (strpos($content, 'nexdrive.beer') !== false) {
                preg_match_all('/<a[^>]*href=["\'](https:\/\/nexdrive\.beer\/[^"\']+)["\'][^>]*>([\s\S]*?)<\/a>/i', $content, $lm, PREG_SET_ORDER);
                if (!empty($lm)) {
                    $qual = '720p';
                    $qRef = $lastHeading . ' ' . $text;
                    if (preg_match('/2160p|4k/i', $qRef)) $qual = '4K 2160p';
                    elseif (preg_match('/1080p/i', $qRef)) $qual = '1080p';
                    elseif (preg_match('/720p/i', $qRef)) $qual = '720p';
                    elseif (preg_match('/480p/i', $qRef)) $qual = '480p';

                    $isHevc = (bool)preg_match('/hevc|x265|10bit/i', $qRef);
                    $is60Fps = (bool)preg_match('/60fps/i', $qRef);
                    $size = '';
                    if (preg_match('/\[([0-9.]+\s*[MG]B[^\]]*)\]/i', $qRef, $szm)) {
                        $size = $szm[1];
                    }

                    $drives = [];
                    foreach ($lm as $linkMatch) {
                        $btnText = trim(str_replace(['&#x26a1;', '&#8211;'], ['⚡', '-'], strip_tags($linkMatch[2])));
                        if (empty($btnText) || stripos($btnText, 'download') !== false) {
                            if (strpos($linkMatch[1], 'vcloud') !== false) $btnText = '⚡ V-Cloud';
                            elseif (strpos($linkMatch[1], 'fastdl') !== false) $btnText = '⚡ G-Direct';
                            else $btnText = '⚡ Fast Server';
                        }
                        $drives[] = [
                            'name' => $btnText,
                            'url'  => $linkMatch[1]
                        ];
                    }

                    $qualityItem = [
                        'quality'     => $qual,
                        'heading'     => $lastHeading ?: substr($text, 0, 70),
                        'size'        => $size,
                        'isHevc'      => $isHevc,
                        'is60Fps'     => $is60Fps,
                        'nexdriveUrl' => $drives[0]['url'] ?? '',
                        'drives'      => $drives
                    ];

                    if ($isSeries && $currentSeason !== null) {
                        $currentSeason['qualities'][] = $qualityItem;
                    } else {
                        $movieQualities[] = $qualityItem;
                    }
                }
            }
        }

        if ($isSeries && empty($seasons) && !empty($movieQualities)) {
            $seasons[] = [
                'seasonNumber' => 1,
                'title' => 'Season 1',
                'qualities' => $movieQualities
            ];
        }

        $resData = [
            'title'       => $pageTitle,
            'poster'      => $poster,
            'audioInfo'   => $audioInfo,
            'audioTracks' => $audioTracks,
            'qualityInfo' => $qualityInfo,
            'isSeries'    => $isSeries,
            'seasons'     => $isSeries ? $seasons : null,
            'qualities'   => !$isSeries ? $movieQualities : ($seasons[0]['qualities'] ?? $movieQualities)
        ];

        @file_put_contents($cacheFile, json_encode($resData));
        return $resData;
    }

    /**
     * Resolve VCloud driver links (R2, HubCloud, Pixeldrain, Gofile)
     */
    public static function resolveVCloud(?string $vcloudUrl): ?array
    {
        if (!$vcloudUrl) return null;
        $res = self::curlGet($vcloudUrl, 'https://nexdrive.beer/');
        if ($res['status'] !== 200) return null;

        if (!preg_match('/var\s+url\s*=\s*atob\(atob\([\'"]([^\'"]+)[\'"]\)\)/i', $res['body'], $m)) {
            return null;
        }

        $tokenUrl = base64_decode(base64_decode($m[1]));
        $tRes = self::curlGet($tokenUrl, $vcloudUrl);
        if ($tRes['status'] !== 200) return null;

        $tHtml = $tRes['body'];
        $r2Url = preg_match('/href=[\'"](https:\/\/[^\'"]*r2\.cloudflarestorage\.com[^\'"]+)[\'"]/i', $tHtml, $rm) ? self::cleanUrl($rm[1]) : null;
        $pixeldrainUrl = preg_match('/href=[\'"](https:\/\/[^\'"]*pixeldrain[^\'"]+)[\'"]/i', $tHtml, $pm) ? self::cleanUrl($pm[1]) : null;
        $gofileUrl = preg_match('/href=[\'"](https:\/\/[^\'"]*gofile\.io[^\'"]+)[\'"]/i', $tHtml, $gm) ? self::cleanUrl($gm[1]) : null;
        $gpdlUrl = preg_match('/href=[\'"](https:\/\/[^\'"]*hubcloud\.ist[^\'"]+)[\'"]/i', $tHtml, $hm) ? self::cleanUrl($hm[1]) : null;

        return [
            'r2Url'         => $r2Url,
            'pixeldrainUrl' => $pixeldrainUrl,
            'gofileUrl'     => $gofileUrl,
            'gpdlUrl'       => $gpdlUrl
        ];
    }

    /**
     * Resolve FastDL URL to Google UserContent link
     */
    public static function resolveFastDL(?string $fastdlUrl, string $referer = 'https://nexdrive.beer/'): ?string
    {
        if (!$fastdlUrl) return null;
        $res = self::curlGet($fastdlUrl, $referer);
        if ($res['status'] !== 200) return null;

        if (preg_match('/var\s+reurl\s*=\s*["\']([^"\']+)["\']/i', $res['body'], $m)) {
            $directFull = self::cleanUrl($m[1]);
            $parts = parse_url($directFull);
            parse_str($parts['query'] ?? '', $query);
            return $query['link'] ?? $directFull;
        }
        return null;
    }

    /**
     * Resolve any Nexdrive / FastDL / VCloud URL to direct servers and stream sources
     */
    public static function resolveNexdrive(string $targetUrl): array
    {
        $targetUrl = trim($targetUrl);
        if (empty($targetUrl)) return ['servers' => []];

        $cacheFile = CACHE_DIR . '/vega_res_' . md5($targetUrl) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 1800)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['servers'])) return $cached;
        }

        // 1. Direct V-Cloud URL
        if (strpos($targetUrl, 'vcloud.beer') !== false) {
            $vdata = self::resolveVCloud($targetUrl);
            $servers = [];
            if ($vdata) {
                if ($vdata['r2Url']) $servers[] = ['id' => 'r2', 'name' => 'Server 1: Cloudflare R2', 'badge' => 'Direct Ultra Fast Stream', 'url' => $vdata['r2Url'], 'type' => 'stream'];
                if ($vdata['gpdlUrl']) $servers[] = ['id' => 'gpdl', 'name' => 'Server 2: HubCloud 10Gbps', 'badge' => '10Gbps Speed', 'url' => $vdata['gpdlUrl'], 'type' => 'stream'];
                if ($vdata['pixeldrainUrl']) $servers[] = ['id' => 'pixeldrain', 'name' => 'Server 3: Pixeldrain', 'badge' => 'Direct Cloud', 'url' => $vdata['pixeldrainUrl'], 'type' => 'stream'];
                if ($vdata['gofileUrl']) $servers[] = ['id' => 'gofile', 'name' => 'Server 4: Gofile', 'badge' => 'High Speed', 'url' => $vdata['gofileUrl'], 'type' => 'stream'];
            }
            $primary = $servers[0]['url'] ?? '';
            $out = [
                'isEpisodeList'    => false,
                'servers'          => $servers,
                'primaryStreamUrl' => $primary
            ];
            @file_put_contents($cacheFile, json_encode($out));
            return $out;
        }

        // 2. Direct FastDL URL
        if (strpos($targetUrl, 'fastdl.beer') !== false) {
            $gUrl = self::resolveFastDL($targetUrl);
            $servers = [];
            if ($gUrl) {
                $servers[] = ['id' => 'google', 'name' => 'Server 1: Google UserContent', 'badge' => 'Instant Google Stream', 'url' => $gUrl, 'type' => 'stream'];
            }
            $out = [
                'isEpisodeList'    => false,
                'servers'          => $servers,
                'primaryStreamUrl' => $servers[0]['url'] ?? ''
            ];
            @file_put_contents($cacheFile, json_encode($out));
            return $out;
        }

        // 3. Nexdrive Page Resolution
        $res = self::curlGet($targetUrl);
        if ($res['status'] !== 200 || empty($res['body'])) {
            return ['servers' => []];
        }

        $html = $res['body'];

        // Check for Episode List (for Web Series)
        $hasEpisodeHeadings = (bool)preg_match('/-:Episodes:|\bEpisode\s*\d+\b|\bEp\s*\d+\b/i', $html);
        if ($hasEpisodeHeadings) {
            preg_match_all('/<(?:h[1-6]|p|div)[^>]*>([\s\S]*?)<\/(?:h[1-6]|p|div)>/i', $html, $epMatches);
            $episodes = [];
            $currentEpLabel = '';

            foreach ($epMatches[1] as $content) {
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
                if (preg_match('/episode|\bep\s*\d/i', $text) && strpos($content, 'href=') === false) {
                    $currentEpLabel = trim(str_replace(['-', ':'], '', $text));
                } elseif (strpos($content, 'fastdl.beer') !== false || strpos($content, 'vcloud.beer') !== false || strpos($content, 'filebee.xyz') !== false) {
                    $epFastDl = preg_match('/href=["\'](https:\/\/fastdl\.beer\/embed[^"\']+)["\']/i', $content, $fdm) ? $fdm[1] : null;
                    $epVcloud = preg_match('/href=["\'](https:\/\/vcloud\.beer\/[^"\']+)["\']/i', $content, $vcm) ? $vcm[1] : null;
                    $epFilepress = preg_match('/href=["\'](https:\/\/filebee\.xyz\/file\/[^"\']+)["\']/i', $content, $fpm) ? $fpm[1] : null;

                    if ($epFastDl || $epVcloud || $epFilepress) {
                        $epIdx = count($episodes) + 1;
                        $mainUrl = $epFastDl ?: ($epVcloud ?: $epFilepress);
                        $episodes[] = [
                            'episodeNumber' => $epIdx,
                            'title'         => $currentEpLabel ?: "Episode {$epIdx}",
                            'url'           => $mainUrl,
                            'fastdlUrl'     => $epFastDl,
                            'vcloudUrl'     => $epVcloud,
                            'filepressUrl'  => $epFilepress
                        ];
                        $currentEpLabel = '';
                    }
                }
            }

            if (!empty($episodes)) {
                $out = [
                    'isEpisodeList' => true,
                    'episodes'      => $episodes,
                    'servers'       => []
                ];
                @file_put_contents($cacheFile, json_encode($out));
                return $out;
            }
        }

        // Movie / Single Video
        $fastdlUrl = preg_match('/href=["\'](https:\/\/fastdl\.beer\/embed[^"\']+)["\']/i', $html, $fm) ? $fm[1] : null;
        $vcloudUrl = preg_match('/href=["\'](https:\/\/vcloud\.beer\/[^"\']+)["\']/i', $html, $vm) ? $vm[1] : null;
        $filepressUrl = preg_match('/href=["\'](https:\/\/filebee\.xyz\/file\/[^"\']+)["\']/i', $html, $fpm) ? $fpm[1] : null;

        $allServers = [];

        if ($vcloudUrl) {
            $vdata = self::resolveVCloud($vcloudUrl);
            if ($vdata) {
                if ($vdata['r2Url']) $allServers[] = ['id' => 'r2', 'name' => 'Server 1: Cloudflare R2', 'badge' => 'Direct Ultra Fast Stream', 'url' => $vdata['r2Url'], 'type' => 'stream'];
                if ($vdata['gpdlUrl']) $allServers[] = ['id' => 'gpdl', 'name' => 'Server 2: HubCloud 10Gbps', 'badge' => '10Gbps Speed', 'url' => $vdata['gpdlUrl'], 'type' => 'stream'];
                if ($vdata['pixeldrainUrl']) $allServers[] = ['id' => 'pixeldrain', 'name' => 'Server 3: Pixeldrain', 'badge' => 'Direct Cloud', 'url' => $vdata['pixeldrainUrl'], 'type' => 'stream'];
                if ($vdata['gofileUrl']) $allServers[] = ['id' => 'gofile', 'name' => 'Server 4: Gofile', 'badge' => 'High Speed', 'url' => $vdata['gofileUrl'], 'type' => 'stream'];
            }
        }

        if ($fastdlUrl) {
            $gUrl = self::resolveFastDL($fastdlUrl, $targetUrl);
            if ($gUrl) {
                $allServers[] = ['id' => 'google', 'name' => 'Server: Google UserContent', 'badge' => 'Instant Google Stream', 'url' => $gUrl, 'type' => 'stream'];
            }
        }

        if ($filepressUrl) {
            $allServers[] = ['id' => 'filepress', 'name' => 'Server: Filepress Mirror', 'badge' => 'Direct Mirror', 'url' => $filepressUrl, 'type' => 'stream'];
        }

        $out = [
            'isEpisodeList'    => false,
            'servers'          => $allServers,
            'primaryStreamUrl' => $allServers[0]['url'] ?? ''
        ];
        @file_put_contents($cacheFile, json_encode($out));
        return $out;
    }

    /**
     * Smart Multi-tier matcher between TMDB title and VegaMovies
     */
    public static function matchTmdb(string $title, string $mediaType = 'movie', ?int $season = 1): array
    {
        $raw = trim($title);
        if (empty($raw)) return [];

        $clean = trim(preg_replace('/[:\-_\'\.]+/u', ' ', $raw));
        $clean = preg_replace('/\s+/', ' ', $clean);

        // Tier 1: Exact clean title (for TV, try also with season)
        if ($mediaType === 'tv' && $season && $season > 1) {
            $tSeason = self::search("{$clean} Season {$season}");
            if (!empty($tSeason['hits'])) return $tSeason['hits'];
        }

        $res1 = self::search($clean);
        if (!empty($res1['hits'])) return $res1['hits'];

        // Tier 2: First 2 words
        $words = preg_split('/\s+/', $clean);
        if (count($words) > 2) {
            $q2 = implode(' ', array_slice($words, 0, 2));
            $res2 = self::search($q2);
            if (!empty($res2['hits'])) return $res2['hits'];
        }

        // Tier 3: First word
        if (!empty($words[0]) && mb_strlen($words[0]) > 2) {
            $res3 = self::search($words[0]);
            if (!empty($res3['hits'])) return $res3['hits'];
        }

        return [];
    }

    /**
     * Get stream sources formatted for FlixRaid player from TMDB parameters
     */
    public static function getSourcesForTmdb(string $type, int $tmdbId, ?int $season = 1, ?int $episode = 1, ?string $explicitTitle = ''): array
    {
        global $tmdb;
        $title = $explicitTitle;

        if (empty($title) && isset($tmdb)) {
            $det = $tmdb->details($type, $tmdbId);
            $title = $det['title'] ?? '';
        }

        if (empty($title)) {
            return [];
        }

        $hits = self::matchTmdb($title, $type, $season);
        if (empty($hits)) {
            return [];
        }

        // Pick best matching release (first hit)
        $selectedPost = $hits[0];
        return self::getSourcesForVegaPost($selectedPost['fullUrl'], $type, $season, $episode);
    }

    /**
     * Get sources for a specific Vega post URL
     */
    public static function getSourcesForVegaPost(string $postUrl, string $type = 'movie', ?int $season = 1, ?int $episode = 1, ?string $desiredQuality = ''): array
    {
        $scraped = self::scrapePost($postUrl);
        if (empty($scraped) || !empty($scraped['error'])) {
            return [];
        }

        $qualities = $scraped['qualities'] ?? [];
        $isSeries = $scraped['isSeries'] ?? ($type === 'tv');
        $audioTracks = $scraped['audioTracks'] ?? ['Hindi [ORG / Main]', 'English [Dual Audio]'];

        // If Series: find requested season
        $targetNexdriveUrl = '';
        $episodesList = [];

        if ($isSeries && !empty($scraped['seasons'])) {
            $sNum = $season ?: 1;
            $seasonObj = null;
            foreach ($scraped['seasons'] as $s) {
                if ($s['seasonNumber'] === $sNum) { $seasonObj = $s; break; }
            }
            if (!$seasonObj && !empty($scraped['seasons'])) {
                $seasonObj = $scraped['seasons'][0];
            }

            $sQualities = $seasonObj['qualities'] ?? [];
            if (!empty($sQualities)) {
                $targetQual = $sQualities[0];
                if (!empty($desiredQuality)) {
                    foreach ($sQualities as $q) {
                        if (stripos($q['quality'], $desiredQuality) !== false) { $targetQual = $q; break; }
                    }
                }
                $targetNexdriveUrl = $targetQual['nexdriveUrl'] ?? '';
            }
        } else {
            // Movie
            if (!empty($qualities)) {
                $targetQual = $qualities[0];
                // Prefer 720p or 1080p if available
                foreach ($qualities as $q) {
                    if (!empty($desiredQuality) && stripos($q['quality'], $desiredQuality) !== false) {
                        $targetQual = $q; break;
                    } elseif (empty($desiredQuality) && stripos($q['quality'], '720p') !== false) {
                        $targetQual = $q; break;
                    }
                }
                $targetNexdriveUrl = $targetQual['nexdriveUrl'] ?? '';
            }
        }

        if (empty($targetNexdriveUrl)) {
            return [];
        }

        // Resolve Nexdrive URL to mirrors
        $resolved = self::resolveNexdrive($targetNexdriveUrl);

        // If it was an episode list: resolve the requested episode
        if (!empty($resolved['isEpisodeList']) && !empty($resolved['episodes'])) {
            $episodesList = $resolved['episodes'];
            $epNum = $episode ?: 1;
            $targetEp = null;
            foreach ($episodesList as $ep) {
                if ($ep['episodeNumber'] === $epNum) { $targetEp = $ep; break; }
            }
            if (!$targetEp && !empty($episodesList)) {
                $targetEp = $episodesList[0];
            }

            $epUrl = $targetEp['fastdlUrl'] ?? ($targetEp['vcloudUrl'] ?? ($targetEp['filepressUrl'] ?? $targetEp['url']));
            if (!empty($epUrl)) {
                $resolved = self::resolveNexdrive($epUrl);
            }
        }

        $servers = $resolved['servers'] ?? [];
        $sources = [];

        foreach ($servers as $idx => $srv) {
            $sources[] = [
                'label'    => $srv['name'] . ' (' . ($targetQual['quality'] ?? 'HD') . ')',
                'server'   => $srv['name'],
                'url'      => $srv['url'],
                'kind'     => 'direct',
                'quality'  => $targetQual['quality'] ?? 'HD',
                'lang'     => $scraped['audioInfo'] ?? 'Dual Audio [Hindi + English]',
                'badge'    => $srv['badge'] ?? 'DIRECT STREAM'
            ];
        }

        return [
            'sources'     => $sources,
            'qualities'   => $qualities,
            'audioTracks' => $audioTracks,
            'audioInfo'   => $scraped['audioInfo'] ?? '',
            'isSeries'    => $isSeries,
            'episodes'    => $episodesList,
            'postTitle'   => $scraped['title'] ?? '',
            'postUrl'     => $postUrl
        ];
    }
}
