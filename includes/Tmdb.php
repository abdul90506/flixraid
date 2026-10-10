<?php
/**
 * TMDB website scraper (no API key needed).
 *
 *  - Fetches HTML pages from themoviedb.org with cURL (parallel when batched)
 *  - Parses them with DOMDocument / XPath into clean PHP arrays
 *  - Caches parsed results as JSON files (serves stale cache if TMDB is unreachable)
 */
class Tmdb
{
    private bool  $collecting = false;
    private array $queue      = [];   // url => true  (batch collect phase)
    private array $memory     = [];   // url => html  (prefetched)
    private array $runtime    = [];   // key => data  (per-request memo)

    /* =========================================================
     *  PUBLIC API
     * ========================================================= */

    /** Run several scraper calls with all network requests done in parallel. */
    public function batch(callable $fn)
    {
        $this->collecting = true;
        $this->queue = [];
        try { $fn($this); } catch (\Throwable $e) { /* ignore in collect phase */ }
        $this->collecting = false;
        if ($this->queue) {
            $this->prefetch(array_keys($this->queue));
            $this->queue = [];
        }
        return $fn($this);
    }

    /** Movie / TV list by category (popular, top-rated ...) */
    public function lists(string $type, string $cat = 'popular', int $page = 1): array
    {
        global $CATEGORIES;
        $type = $type === 'tv' ? 'tv' : 'movie';
        $path = $CATEGORIES[$type][$cat][1] ?? $CATEGORIES[$type]['popular'][1];
        $url  = $this->url($path, ['page' => $page > 1 ? $page : null]);
        return $this->cached("list:$type:$cat:$page", TTL_LIST, $url, fn($h) => $this->parsePaged($h, $type)) ?? ['items' => [], 'total_pages' => 0];
    }

    /** Trending (today | this-week), movies + tv mixed. */
    public function trending(string $window = 'today'): array
    {
        $window = $window === 'week' || $window === 'this-week' ? 'this-week' : 'today';
        $url = $this->url('/remote/panel', ['panel' => 'trending_scroller', 'group' => $window]);
        return $this->cached("trending:$window", TTL_LIST, $url, fn($h) => $this->parseCards($h)) ?? [];
    }

    /** Titles by genre. */
    public function genre(string $type, int $gid, int $page = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $url  = $this->url("/genre/$gid/$type", ['page' => $page > 1 ? $page : null]);
        return $this->cached("genre:$type:$gid:$page", TTL_LIST, $url, fn($h) => $this->parsePaged($h, $type)) ?? ['items' => [], 'total_pages' => 0];
    }

    /** Search movies or tv. */
    public function search(string $type, string $q, int $page = 1): array
    {
        $type = in_array($type, ['movie', 'tv', 'person'], true) ? $type : 'movie';
        $q = trim($q);
        if ($q === '') return ['items' => [], 'page' => 1, 'total_pages' => 0, 'total' => 0];
        $url = $this->url("/search/$type", ['query' => $q, 'page' => $page > 1 ? $page : null]);
        $key = "search:$type:" . mb_strtolower($q) . ":$page";
        return $this->cached($key, TTL_SEARCH, $url, function ($h) use ($type) {
            $res = $type === 'person'
                ? ['items' => $this->parsePeopleResults($h), 'total_pages' => 1]
                : $this->parsePaged($h, $type, true);
            $res['total'] = $this->parseSearchCount($h, $type);
            if ($res['total'] > 0) $res['total_pages'] = (int)min(500, ceil($res['total'] / 20));
            return $res;
        }) ?? ['items' => [], 'total_pages' => 0, 'total' => 0];
    }

    /** Movie / TV details (main page). */
    public function details(string $type, int $id): ?array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $url  = $this->url("/$type/$id");
        return $this->cached("details:$type:$id", TTL_DETAIL, $url, fn($h) => $this->parseDetails($h, $type, $id));
    }

    /** "More like this" recommendations. */
    public function recommendations(string $type, int $id): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $url  = $this->url("/$type/$id/remote/recommendations", ['translate' => 'false']);
        return $this->cached("recs:$type:$id", TTL_DETAIL, $url, fn($h) => $this->parseRecommendations($h, $type), true) ?? [];
    }

    /** Title logo (transparent PNG) – used on hero banners. */
    public function logo(string $type, int $id): ?string
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $url  = $this->url("/$type/$id/images/logos", ['image_language' => 'en']);
        $r = $this->cached("logo:$type:$id", TTL_DETAIL * 3, $url, function ($h) {
            if (preg_match('#/t/p/[^/"\s]+(/[A-Za-z0-9_\-]+\.(?:png|svg))#', $h, $m)) return ['path' => $m[1]];
            return ['path' => null];
        }, true);
        return $r['path'] ?? null;
    }

    /** Full cast & crew. */
    public function credits(string $type, int $id): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $url  = $this->url("/$type/$id/cast");
        return $this->cached("credits:$type:$id", TTL_DETAIL, $url, fn($h) => $this->parseCredits($h)) ?? ['cast' => [], 'crew' => []];
    }

    /** List of seasons of a tv show. */
    public function seasons(int $id): array
    {
        $url = $this->url("/tv/$id/seasons");
        return $this->cached("seasons:$id", TTL_SEASON, $url, fn($h) => $this->parseSeasons($h)) ?? [];
    }

    /** Episodes of a season. */
    public function season(int $id, int $season): ?array
    {
        $url = $this->url("/tv/$id/season/$season");
        return $this->cached("season:$id:$season", TTL_SEASON, $url, fn($h) => $this->parseSeason($h, $season));
    }

    /** Person profile + credits. */
    public function person(int $id): ?array
    {
        $url = $this->url("/person/$id");
        return $this->cached("person:$id", TTL_DETAIL, $url, fn($h) => $this->parsePerson($h, $id));
    }

    /* =========================================================
     *  IMAGES
     * ========================================================= */

    /** Extract "/abc.jpg" from any TMDB image URL. */
    public static function imgPath(?string $url): ?string
    {
        if (!$url) return null;
        if (preg_match('#/t/p/[^/]+(/[A-Za-z0-9_\-]+\.(?:jpg|jpeg|png|svg|webp))#i', $url, $m)) return $m[1];
        if (preg_match('#^/[A-Za-z0-9_\-]+\.(?:jpg|jpeg|png|svg|webp)$#i', $url)) return $url;
        return null;
    }

    public static function img(?string $path, string $size = 'w342'): string
    {
        if (!$path) return '';
        return TMDB_IMG . $size . $path;
    }

    /* =========================================================
     *  CACHE + HTTP
     * ========================================================= */

    private function url(string $path, array $params = []): string
    {
        $params = array_filter($params, fn($v) => $v !== null && $v !== '');
        $params['language'] = TMDB_LANG;
        return TMDB_SITE . $path . '?' . http_build_query($params);
    }

    private function cacheFile(string $key): string
    {
        $h = md5($key);
        return CACHE_DIR . '/' . substr($h, 0, 2) . '/' . $h . '.json';
    }

    /**
     * Core: fresh cache -> return | collect mode -> queue url | fetch + parse + store.
     * On failure the stale cache (if any) is returned.
     */
    private function cached(string $key, int $ttl, string $url, callable $parser, bool $allowEmpty = false)
    {
        if (array_key_exists($key, $this->runtime)) return $this->runtime[$key];

        $file  = $this->cacheFile($key);
        $stale = null;
        if (is_file($file)) {
            $data = json_decode((string)@file_get_contents($file), true);
            if ($data !== null) {
                if (filemtime($file) + $ttl > time()) {
                    return $this->runtime[$key] = $data;
                }
                $stale = $data;
            }
        }

        if ($this->collecting) {           // batch phase 1: just remember url
            $this->queue[$url] = true;
            return $stale;
        }

        $html = $this->memory[$url] ?? $this->get($url);
        $data = null;
        if ($html) {
            try { $data = $parser($html); } catch (\Throwable $e) { $data = null; }
        }

        $ok = $data !== null && ($allowEmpty || !self::isEmpty($data));
        if ($ok) {
            if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
            @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            return $this->runtime[$key] = $data;
        }
        return $this->runtime[$key] = $stale;
    }

    private static function isEmpty($d): bool
    {
        if (empty($d)) return true;
        if (is_array($d) && array_key_exists('items', $d) && empty($d['items'])) return true;
        return false;
    }

    private function curlHandle(string $url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => HTTP_UA,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        return $ch;
    }

    private function get(string $url): ?string
    {
        $ch   = $this->curlHandle($url);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }

    /** Parallel download (curl_multi) into memory. */
    private function prefetch(array $urls): void
    {
        $urls = array_values(array_filter($urls, fn($u) => !isset($this->memory[$u])));
        foreach (array_chunk($urls, HTTP_CONCURRENCY) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $u) {
                $ch = $this->curlHandle($u);
                curl_multi_add_handle($mh, $ch);
                $handles[$u] = $ch;
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $u => $ch) {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body = curl_multi_getcontent($ch);
                if ($code >= 200 && $code < 300 && $body) $this->memory[$u] = $body;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
    }

    /* =========================================================
     *  DOM HELPERS
     * ========================================================= */

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        return new \DOMXPath($dom);
    }

    private static function cls(string $c): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
    }

    private function node(\DOMXPath $xp, string $q, $ctx = null): ?\DOMNode
    {
        $r = $ctx ? $xp->query($q, $ctx) : $xp->query($q);
        return ($r && $r->length) ? $r->item(0) : null;
    }

    private function text(\DOMXPath $xp, string $q, $ctx = null): string
    {
        $n = $this->node($xp, $q, $ctx);
        return $n ? self::clean($n->textContent) : '';
    }

    private function attr(\DOMXPath $xp, string $q, string $attr, $ctx = null): string
    {
        $n = $this->node($xp, $q, $ctx);
        return ($n instanceof \DOMElement) ? trim($n->getAttribute($attr)) : '';
    }

    private static function clean(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function idFromHref(string $href, string $kind = '(movie|tv)'): ?array
    {
        if (preg_match('#^(?:https?://[^/]+)?/' . $kind . '/(\d+)#', $href, $m)) {
            return count($m) === 3 ? [$m[1], (int)$m[2]] : [$kind, (int)$m[1]];
        }
        return null;
    }

    private static function yearOf(string $s): string
    {
        return preg_match('/(19|20)\d{2}/', $s, $m) ? $m[0] : '';
    }

    /* =========================================================
     *  PARSERS
     * ========================================================= */

    /** Generic TMDB "media card" parser (lists, trending, search, genre pages). */
    private function parseCards(string $html, ?string $forceType = null, ?string $scope = null): array
    {
        $xp = $this->xpath($html);
        $items = [];
        $seen  = [];
        $root  = $scope ? $this->node($xp, $scope) : null;
        if ($scope && !$root) return [];
        $q = './/*[@data-object-id][not(ancestor::*[@data-object-id])]';
        $cards = $root ? $xp->query($q, $root) : $xp->query(substr($q, 1));
        foreach ($cards as $card) {
            $link = null;
            foreach ($xp->query('.//a[@href]', $card) as $a) {
                if (self::idFromHref($a->getAttribute('href'))) { $link = $a; break; }
            }
            if (!$link) continue;
            if ($link->getAttribute('data-media-adult') === 'true') continue;
            [$type, $id] = self::idFromHref($link->getAttribute('href'));
            if ($forceType && $type !== $forceType) continue;
            if (isset($seen[$type . $id])) continue;
            $seen[$type . $id] = true;

            $title = $this->text($xp, './/h2', $card);
            $img   = $this->node($xp, './/img', $card);
            if ($title === '' && $img) $title = self::clean($img->getAttribute('alt'));
            $date  = $this->text($xp, './/*[' . self::cls('release_date') . ']', $card);
            $over  = $this->text($xp, './/p', $card);

            $items[] = [
                'id'       => $id,
                'type'     => $type,
                'title'    => $title,
                'poster'   => $img ? self::imgPath($img->getAttribute('src')) : null,
                'date'     => $date,
                'year'     => self::yearOf($date),
                'overview' => $over,
            ];
        }
        return $items;
    }

    /** Cards + pagination info. */
    private function parsePaged(string $html, string $type, bool $isSearch = false): array
    {
        $items = $this->parseCards($html, $type, $isSearch ? '//div[@id="' . $type . '_results"]' : null);
        $max = 1;
        if (preg_match_all('#[?&](?:amp;)?page=(\d+)#', $html, $m)) $max = max(array_map('intval', $m[1]));
        // TMDB list/genre pages use "load more" (up to 500 pages on their side)
        if (!$isSearch && count($items) >= 20) $max = 500;
        return ['items' => $items, 'total_pages' => $max];
    }

    private function parseSearchCount(string $html, string $type): int
    {
        if (preg_match('#data-href="/search/' . $type . '\?[^"]*"[^>]*>[^<]*</a>\s*<span>([\d,]+)</span>#', $html, $m)) {
            return (int)str_replace(',', '', $m[1]);
        }
        return 0;
    }

    private function parsePeopleResults(string $html): array
    {
        $xp = $this->xpath($html);
        $out = [];
        foreach ($xp->query('//div[' . self::cls('profile') . ' and ' . self::cls('list_item') . ']') as $n) {
            $a = $this->node($xp, './/p[' . self::cls('name') . ']/a', $n);
            if (!$a) continue;
            $ref = self::idFromHref($a->getAttribute('href'), 'person');
            if (!$ref) continue;
            $known = [];
            foreach ($xp->query('.//p[' . self::cls('sub') . ']/a', $n) as $k) $known[] = self::clean($k->textContent);
            $out[] = [
                'id'      => $ref[1],
                'type'    => 'person',
                'name'    => self::clean($a->textContent),
                'profile' => self::imgPath($this->attr($xp, './/img', 'src', $n)),
                'dept'    => $this->text($xp, './/p[' . self::cls('sub') . ']/span', $n),
                'known'   => implode(', ', $known),
            ];
        }
        return $out;
    }

    private function parseDetails(string $html, string $type, int $id): ?array
    {
        $xp = $this->xpath($html);

        // ---- JSON-LD (very stable structured data)
        $ld = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
            $raw = preg_replace('#/\*\s*<!\[CDATA\[\s*\*/|/\*\s*\]\]>\s*\*/#', '', $s->textContent);
            $j = json_decode(trim($raw), true);
            if (is_array($j) && in_array($j['@type'] ?? '', ['Movie', 'TVSeries'], true)) { $ld = $j; break; }
        }

        $header = $this->node($xp, '//section[@id="original_header"]');
        $title  = $ld['name'] ?? $this->text($xp, './/h2/a', $header);
        if (!$title) return null;

        // backdrop from inline CSS
        $backdrop = null;
        if (preg_match("#url\('([^']*/t/p/w1920_and_h800[^']*)'\)#", $html, $m)) $backdrop = self::imgPath($m[1]);

        $poster = self::imgPath($this->attr($xp, './/img[' . self::cls('poster') . ']', 'src', $header))
               ?: self::imgPath($ld['image'] ?? '');

        $genres = [];
        foreach ($xp->query('.//span[' . self::cls('genres') . ']/a', $header) as $g) {
            $gid = preg_match('#/genre/(\d+)#', $g->getAttribute('href'), $gm) ? (int)$gm[1] : 0;
            $genres[] = ['id' => $gid, 'name' => self::clean($g->textContent)];
        }

        $yearTag = $this->text($xp, './/span[' . self::cls('release_date') . ']', $header);
        $score   = $this->attr($xp, '//div[' . self::cls('user_score_chart') . ']', 'data-percent');
        $rating  = $ld['aggregateRating']['ratingValue'] ?? ($score !== '' ? round(((float)$score) / 10, 1) : null);

        // trailer (first YouTube play_trailer link)
        $trailer = '';
        foreach ($xp->query('//a[' . self::cls('play_trailer') . '][@data-site="YouTube"]') as $t) {
            if ($t->getAttribute('data-id')) { $trailer = $t->getAttribute('data-id'); break; }
        }

        // crew in header (director, writer / creator)
        $crew = [];
        foreach ($xp->query('//ol[' . self::cls('people') . ' and ' . self::cls('no_image') . ']/li') as $li) {
            $a = $this->node($xp, './/a', $li);
            $ref = $a ? self::idFromHref($a->getAttribute('href'), 'person') : null;
            $crew[] = [
                'id'   => $ref[1] ?? 0,
                'name' => $a ? self::clean($a->textContent) : '',
                'job'  => $this->text($xp, './/p[' . self::cls('character') . ']', $li),
            ];
        }

        // top billed cast
        $cast = [];
        foreach ($xp->query('//section[' . self::cls('top_billed') . ']//li[' . self::cls('card') . ']') as $li) {
            $a = $this->node($xp, './/p/a', $li);
            if (!$a) continue;
            $ref = self::idFromHref($a->getAttribute('href'), 'person');
            $cast[] = [
                'id'        => $ref[1] ?? 0,
                'name'      => self::clean($a->textContent),
                'character' => $this->text($xp, './/p[' . self::cls('character') . ']', $li),
                'profile'   => self::imgPath($this->attr($xp, './/img', 'src', $li)),
            ];
        }

        // facts (Status, Original Language, Budget, Revenue, Type ...)
        $facts = [];
        foreach ($xp->query('//section[' . self::cls('facts') . ']/p') as $p) {
            $label = $this->text($xp, './strong', $p);
            if ($label === '') continue;
            $val = trim(str_replace($label, '', self::clean($p->textContent)));
            if ($val !== '' && $val !== '-') $facts[$label] = $val;
        }

        $networks = [];
        foreach ($xp->query('//ul[' . self::cls('networks') . ']//img') as $img) {
            $networks[] = [
                'name' => trim(preg_replace('/^See more TV shows from |\.\.\.$/', '', $img->getAttribute('alt'))),
                'logo' => self::imgPath($img->getAttribute('src')),
            ];
        }

        $keywords = [];
        foreach ($xp->query('//section[' . self::cls('keywords') . ']//li/a') as $k) $keywords[] = self::clean($k->textContent);

        $overview = $this->text($xp, '//div[' . self::cls('header_info') . ']//div[' . self::cls('overview') . ']');
        if ($overview === '' || stripos($overview, "don't have an overview") !== false) $overview = $ld['description'] ?? $overview;

        return [
            'id'            => $id,
            'type'          => $type,
            'title'         => self::clean($title),
            'year'          => self::yearOf($yearTag) ?: self::yearOf($ld['startDate'] ?? ($ld['releasedEvent'][0]['startDate'] ?? '')),
            'tagline'       => $this->text($xp, '//h3[' . self::cls('tagline') . ']'),
            'overview'      => $overview,
            'poster'        => $poster,
            'backdrop'      => $backdrop,
            'certification' => $this->text($xp, './/span[' . self::cls('certification') . ']', $header),
            'release'       => $this->text($xp, './/span[' . self::cls('release') . ']', $header),
            'runtime'       => $this->text($xp, './/span[' . self::cls('runtime') . ']', $header),
            'genres'        => $genres,
            'rating'        => $rating !== null ? (float)$rating : null,
            'votes'         => (int)($ld['aggregateRating']['ratingCount'] ?? 0),
            'trailer'       => $trailer,
            'crew'          => $crew,
            'cast'          => $cast,
            'facts'         => $facts,
            'networks'      => $networks,
            'keywords'      => array_slice($keywords, 0, 15),
            'episodes'      => (int)($ld['numberOfEpisodes'] ?? 0),
            'start'         => substr($ld['startDate'] ?? '', 0, 10),
            'end'           => substr($ld['endDate'] ?? '', 0, 10),
            'country'       => $ld['countryOfOrigin'][0]['name'] ?? '',
        ];
    }

    private function parseRecommendations(string $html, string $type): array
    {
        $xp = $this->xpath('<div>' . $html . '</div>');
        $out = [];
        foreach ($xp->query('//div[' . self::cls('mini_card') . ']') as $n) {
            $a = $this->node($xp, './/a[' . self::cls('title') . ']', $n) ?: $this->node($xp, './/a', $n);
            if (!$a) continue;
            $ref = self::idFromHref($a->getAttribute('href'));
            if (!$ref) continue;
            $vote = $this->text($xp, './/*[' . self::cls('vote_average') . ']', $n);
            $date = $this->text($xp, './/*[' . self::cls('release_date') . ']', $n);
            $out[] = [
                'id'       => $ref[1],
                'type'     => $ref[0],
                'title'    => self::clean($a->getAttribute('title') ?: $a->textContent),
                'backdrop' => self::imgPath($this->attr($xp, './/img', 'src', $n)),
                'poster'   => null,
                'date'     => $date,
                'year'     => self::yearOf($date),
                'rating'   => $vote !== '' ? round(((int)$vote) / 10, 1) : null,
            ];
        }
        return $out;
    }

    private function parseCredits(string $html): array
    {
        $xp = $this->xpath($html);
        $res = ['cast' => [], 'crew' => []];
        $lists = $xp->query('//ol[' . self::cls('people') . ' and ' . self::cls('credits') . ']');
        foreach ($lists as $i => $ol) {
            $bucket = $i === 0 ? 'cast' : 'crew';
            foreach ($xp->query('./li', $ol) as $li) {
                $a = $this->node($xp, './/div[' . self::cls('info') . ']//a', $li) ?: $this->node($xp, './/p/a', $li);
                if (!$a) continue;
                $ref = self::idFromHref($a->getAttribute('href'), 'person');
                $res[$bucket][] = [
                    'id'        => $ref[1] ?? 0,
                    'name'      => self::clean($a->textContent),
                    'character' => $this->text($xp, './/p[' . self::cls('character') . ']', $li),
                    'profile'   => self::imgPath($this->attr($xp, './/img', 'src', $li)),
                ];
            }
        }
        $res['crew'] = array_slice($res['crew'], 0, 60);
        return $res;
    }

    private function parseSeasons(string $html): array
    {
        $xp = $this->xpath($html);
        $out = [];
        foreach ($xp->query('//div[' . self::cls('season_wrapper') . ']//div[' . self::cls('season') . ']') as $n) {
            $a = $this->node($xp, './/h2/a', $n);
            if (!$a || !preg_match('#/season/(\d+)#', $a->getAttribute('href'), $m)) continue;
            $h4 = $this->text($xp, './/h4', $n);
            $ps = [];
            foreach ($xp->query('.//div[' . self::cls('season_overview') . ']/p', $n) as $p) $ps[] = self::clean($p->textContent);
            $out[] = [
                'number'   => (int)$m[1],
                'name'     => self::clean($a->textContent),
                'poster'   => self::imgPath($this->attr($xp, './/img', 'src', $n)),
                'year'     => self::yearOf($h4),
                'episodes' => preg_match('/(\d+)\s+Episodes?/i', $h4, $em) ? (int)$em[1] : 0,
                'rating'   => preg_match('/(\d+)\s*%/', $h4, $rm) ? round(((int)$rm[1]) / 10, 1) : null,
                'overview' => count($ps) > 1 ? end($ps) : ($ps[0] ?? ''),
            ];
        }
        return $out;
    }

    private function parseSeason(string $html, int $season): ?array
    {
        $xp = $this->xpath($html);
        $eps = [];
        foreach ($xp->query('//div[' . self::cls('episode_list') . ']/div[' . self::cls('card') . ']') as $c) {
            $a = $this->node($xp, './/a[@data-episode-number]', $c);
            if (!$a) continue;
            $num = (int)$a->getAttribute('data-episode-number');
            $rating = $this->text($xp, './/div[' . self::cls('rating') . ']', $c);
            $eps[] = [
                'number'   => $num,
                'name'     => $this->text($xp, './/div[' . self::cls('episode_title') . ']//h3', $c) ?: "Episode $num",
                'still'    => self::imgPath($this->attr($xp, './/img', 'src', $c)),
                'date'     => $this->text($xp, './/span[' . self::cls('date') . ']', $c),
                'runtime'  => $this->text($xp, './/span[' . self::cls('runtime') . ']', $c),
                'rating'   => preg_match('/(\d+)/', $rating, $rm) ? round(((int)$rm[1]) / 10, 1) : null,
                'overview' => $this->text($xp, './/div[' . self::cls('overview') . ']/p', $c),
            ];
        }
        if (!$eps) return null;
        return [
            'number'   => $season,
            'name'     => $this->text($xp, '//section[' . self::cls('header') . ']//h2/a') ?: "Season $season",
            'poster'   => self::imgPath($this->attr($xp, '//section[' . self::cls('header') . ']//img[' . self::cls('poster') . ']', 'src')),
            'episodes' => $eps,
        ];
    }

    private function parsePerson(string $html, int $id): ?array
    {
        $xp = $this->xpath($html);
        $ld = [];
        foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
            $raw = preg_replace('#/\*\s*<!\[CDATA\[\s*\*/|/\*\s*\]\]>\s*\*/#', '', $s->textContent);
            $j = json_decode(trim($raw), true);
            if (is_array($j) && ($j['@type'] ?? '') === 'Person') { $ld = $j; break; }
        }
        $name = $ld['name'] ?? $this->text($xp, '//h2[' . self::cls('title') . ']');
        if (!$name) return null;

        $bio = [];
        foreach ($xp->query('//div[' . self::cls('biography') . ']//div[' . self::cls('text') . ']/p') as $p) {
            $t = self::clean($p->textContent);
            if ($t !== '') $bio[] = $t;
        }
        $facts = [];
        foreach ($xp->query('//section[' . self::cls('facts') . ']/p') as $p) {
            $label = $this->text($xp, './strong', $p);
            if ($label === '' || $label === 'Also Known As') continue;
            $val = trim(str_replace($label, '', self::clean($p->textContent)));
            if ($val !== '' && $val !== '-') $facts[$label] = $val;
        }

        $known = [];
        foreach ($xp->query('//div[@id="known_for"]//li') as $li) {
            $a = $this->node($xp, './/a[@href]', $li);
            $ref = $a ? self::idFromHref($a->getAttribute('href')) : null;
            if (!$ref) continue;
            $known[] = [
                'id' => $ref[1], 'type' => $ref[0],
                'title'  => $this->text($xp, './/p//bdi', $li) ?: self::clean($this->attr($xp, './/img', 'alt', $li)),
                'poster' => self::imgPath($this->attr($xp, './/img', 'src', $li)),
                'year' => '', 'date' => '',
            ];
        }

        // filmography, grouped by department heading
        $credits = [];
        foreach ($xp->query('//table[' . self::cls('credits') . ']') as $table) {
            $dept = 'Acting';
            $h = $table->previousSibling;
            while ($h && !($h instanceof \DOMElement)) $h = $h->previousSibling;
            if ($h && strtolower($h->nodeName) === 'h3') $dept = self::clean($h->textContent);
            foreach ($xp->query('.//table[' . self::cls('credit_group') . ']//tr', $table) as $tr) {
                $a = $this->node($xp, './/a[' . self::cls('tooltip') . ']', $tr);
                $ref = $a ? self::idFromHref($a->getAttribute('href')) : null;
                if (!$ref) continue;
                $year = $this->text($xp, './td[' . self::cls('year') . ']', $tr);
                $credits[$dept][] = [
                    'id' => $ref[1], 'type' => $ref[0],
                    'title' => self::clean($a->textContent),
                    'year'  => self::yearOf($year),
                    'role'  => $this->text($xp, './/span[' . self::cls('character') . ']', $tr)
                              ?: trim(preg_replace('/^\.\.\.\s*/', '', $this->text($xp, './/span[' . self::cls('group') . ']', $tr))),
                ];
            }
        }

        return [
            'id'      => $id,
            'name'    => self::clean($name),
            'profile' => self::imgPath($this->attr($xp, '//div[' . self::cls('image_content') . ']//img[' . self::cls('profile') . ']', 'src'))
                       ?: self::imgPath($ld['image'] ?? ''),
            'bio'     => $bio ?: (isset($ld['description']) ? preg_split('/\n+/', $ld['description']) : []),
            'birth'   => $ld['birthDate'] ?? '',
            'death'   => $ld['deathDate'] ?? '',
            'facts'   => $facts,
            'known'   => $known,
            'credits' => $credits,
        ];
    }
}
