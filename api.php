<?php
/**
 * JSON / HTML-fragment API used by the front-end (AJAX).
 *
 *  ?action=suggest&q=           realtime search dropdown
 *  ?action=search&type=&q=&page=  search page (infinite scroll)
 *  ?action=browse&type=&cat=&genre=&page=
 *  ?action=row&key=             lazy home rows
 *  ?action=season&id=&s=&mode=title|watch
 *  ?action=credits&type=&id=
 *  ?action=posters&ids=movie:550,tv:1396   posters for "More Like This" on phones
 *  ?action=hover&type=tv&id=1396         Netflix hover card info (also warms the watch-page cache)
 *  ?action=recinfo&ids=movie:550,tv:1396   poster, runtime, certification, overview, seasons (More Like This cards)
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/rows.php';

$action = q('action');

switch ($action) {

    case 'suggest': {
        $qStr = mb_substr(q('q'), 0, 80);
        if (mb_strlen($qStr) < 2) json_out(['items' => [], 'people' => []]);
        $r = $tmdb->batch(fn(Tmdb $t) => [
            'movie'  => $t->search('movie', $qStr),
            'tv'     => $t->search('tv', $qStr),
            'person' => $t->search('person', $qStr),
        ]);
        // interleave movies & tv, keep TMDB relevance order
        $m = $r['movie']['items'] ?? [];
        $s = $r['tv']['items'] ?? [];
        $mixed = [];
        for ($i = 0; $i < max(count($m), count($s)); $i++) {
            if (isset($m[$i])) $mixed[] = $m[$i];
            if (isset($s[$i])) $mixed[] = $s[$i];
        }
        // exact-ish title matches first
        $needle = mb_strtolower($qStr);
        usort($mixed, function ($a, $b) use ($needle) {
            $sa = mb_strpos(mb_strtolower($a['title']), $needle) === 0 ? 0 : 1;
            $sb = mb_strpos(mb_strtolower($b['title']), $needle) === 0 ? 0 : 1;
            return $sa <=> $sb;
        });
        $items = array_map(fn($i) => [
            'id' => $i['id'], 'type' => $i['type'], 'title' => $i['title'], 'year' => $i['year'],
            'poster' => $i['poster'] ? img($i['poster'], 'w92') : null,
            'url' => title_url($i['type'], $i['id']), 'watch' => watch_url($i['type'], $i['id']),
        ], array_slice($mixed, 0, 8));
        $people = array_map(fn($p) => [
            'id' => $p['id'], 'name' => $p['name'], 'dept' => $p['dept'], 'known' => $p['known'],
            'profile' => $p['profile'] ? img($p['profile'], 'w92') : null, 'url' => person_url($p['id']),
        ], array_slice($r['person']['items'] ?? [], 0, 3));
        json_out([
            'items' => $items, 'people' => $people,
            'counts' => ['movie' => $r['movie']['total'] ?? 0, 'tv' => $r['tv']['total'] ?? 0, 'person' => $r['person']['total'] ?? 0],
        ]);
    }

    case 'search': {
        $type = q('type', 'movie');
        $qStr = mb_substr(q('q'), 0, 80);
        $page = max(1, qi('page', 1));
        $r = search_any($tmdb, $type, $qStr, $page);
        $html = '';
        foreach ($r['items'] as $it) $html .= $type === 'person' ? person_card($it) : card($it);
        json_out(['html' => $html, 'page' => $page, 'total_pages' => $r['total_pages'], 'total' => $r['total'] ?? 0, 'count' => count($r['items'])]);
    }

    case 'browse': {
        $type  = q('type') === 'tv' ? 'tv' : 'movie';
        $cat   = q('cat', 'popular');
        $genre = qi('genre');
        $page  = max(1, min(500, qi('page', 1)));
        $r = $genre ? $tmdb->genre($type, $genre, $page) : $tmdb->lists($type, $cat, $page);
        $html = '';
        foreach ($r['items'] as $it) $html .= card($it);
        json_out(['html' => $html, 'page' => $page, 'total_pages' => $r['total_pages'], 'count' => count($r['items'])]);
    }

    case 'row': {
        $key = q('key');
        json_out(['html' => render_row_items($tmdb, $key)]);
    }

    case 'season': {
        $id   = qi('id');
        $s    = qi('s', 1);
        $mode = q('mode') === 'watch' ? 'watch' : 'title';
        $ep   = qi('e');
        $season = $tmdb->season($id, $s);
        json_out([
            'html'     => episodes_html($id, $season, $mode, $ep),
            'episodes' => array_map(fn($e) => ['number' => $e['number'], 'name' => $e['name']], $season['episodes'] ?? []),
            'full'     => array_map(fn($x) => [
                'number' => (int)$x['number'], 'name' => $x['name'], 'overview' => $x['overview'],
                'runtime' => $x['runtime'], 'still' => $x['still'] ? img($x['still'], 'w300') : '',
                'url' => watch_url('tv', $id, $s, (int)$x['number']),
            ], $season['episodes'] ?? []),
        ]);
    }

    case 'credits': {
        $type = q('type') === 'tv' ? 'tv' : 'movie';
        $id   = qi('id');
        $c = $tmdb->credits($type, $id);
        $html = '';
        foreach ($c['cast'] as $p) $html .= person_card($p);
        $crew = '';
        foreach ($c['crew'] as $p) $crew .= person_card($p);
        json_out(['html' => $html, 'crew' => $crew, 'count' => count($c['cast'])]);
    }

    case 'posters': {
        $keys = [];
        foreach (array_slice(explode(',', (string)q('ids')), 0, 24) as $k) {
            if (preg_match('/^(movie|tv):(\d{1,9})$/', trim($k), $m)) $keys[$m[1] . ':' . $m[2]] = [$m[1], (int)$m[2]];
        }
        $out = $tmdb->batch(function (Tmdb $t) use ($keys) {
            $r = [];
            foreach ($keys as $k => [$ty, $id]) { $d = $t->details($ty, $id); $r[$k] = !empty($d['poster']) ? img($d['poster'], 'w342') : ''; }
            return $r;
        });
        header('Cache-Control: public, max-age=86400');
        json_out(['posters' => $out]);
    }

    case 'hover': {
        $type = q('type') === 'tv' ? 'tv' : 'movie';
        $id   = qi('id');
        if (!$id) json_out(['error' => 'missing id'], 400);
        // same calls as watch.php (info mode) → hovering a card pre-warms the pop-up
        // lite=1 (hover cards, row pre-warming): only what the card shows → fewer TMDB pages per title
        $lite = q('lite') === '1';
        $r = $tmdb->batch(fn(Tmdb $t) => $lite ? [
            'd'       => $t->details($type, $id),
            'logo'    => $t->logo($type, $id),
            'seasons' => $type === 'tv' ? $t->seasons($id) : [],
        ] : [
            'd'       => $t->details($type, $id),
            'logo'    => $t->logo($type, $id),
            'recs'    => $t->recommendations($type, $id),
            'seasons' => $type === 'tv' ? $t->seasons($id) : [],
            'season'  => $type === 'tv' ? $t->season($id, 1) : null,
        ]);
        $d = $r['d'] ?? null;
        if (!$d) json_out(['error' => 'not found'], 404);
        $nSeasons = count(array_filter($r['seasons'] ?? [], fn($x) => (int)$x['number'] > 0));
        $status = $d['facts']['Status'] ?? '';
        $tag = '';
        if ($type === 'tv' && in_array($status, ['Returning Series', 'In Production'], true)) $tag = 'New Episodes';
        elseif ((int)$d['year'] >= (int)date('Y') - 1) $tag = 'Recently added';
        header('Cache-Control: public, max-age=3600');
        json_out([
            'id'       => $id,
            'type'     => $type,
            'title'    => $d['title'],
            'year'     => $d['year'],
            'backdrop' => !empty($d['backdrop']) ? img($d['backdrop'], 'w780') : '',
            'logo'     => !empty($r['logo']) ? 'https://image.tmdb.org/t/p/w300' . $r['logo'] : '',
            'cert'     => $d['certification'] ?? '',
            'runtime'  => $d['runtime'] ?? '',
            'seasons'  => $nSeasons,
            'match'    => !empty($d['rating']) ? (int)round($d['rating'] * 10) : null,
            'genres'   => array_slice(array_column($d['genres'] ?? [], 'name'), 0, 3),
            'tag'      => $tag,
        ]);
    }

    case 'logos': {
        // title logos for the Continue Watching cards: ids=movie:550,tv:1396 (max 30)
        $keys = [];
        foreach (array_slice(explode(',', (string)q('ids')), 0, 30) as $k) {
            if (preg_match('/^(movie|tv):(\d{1,9})$/', trim($k), $m)) $keys[$m[1] . ':' . $m[2]] = [$m[1], (int)$m[2]];
        }
        $out = [];
        if ($keys) {
            $r = $tmdb->batch(function (Tmdb $t) use ($keys) {
                $calls = [];
                foreach ($keys as $k => [$ty, $id]) $calls[$k] = $t->logo($ty, $id);
                return $calls;
            });
            foreach ($keys as $k => $_) $out[$k] = !empty($r[$k]) ? 'https://image.tmdb.org/t/p/w300' . $r[$k] : '';
        }
        header('Cache-Control: public, max-age=86400');
        json_out(['items' => $out]);
    }

    case 'recinfo': {
        $keys = [];
        foreach (array_slice(explode(',', (string)q('ids')), 0, 24) as $k) {
            if (preg_match('/^(movie|tv):(\d{1,9})$/', trim($k), $m)) $keys[$m[1] . ':' . $m[2]] = [$m[1], (int)$m[2]];
        }
        $out = $tmdb->batch(function (Tmdb $t) use ($keys) {
            $r = [];
            foreach ($keys as $k => [$ty, $id]) {
                $d = $t->details($ty, $id);
                if (!$d) { $r[$k] = null; continue; }
                $logo = $t->logo($ty, $id);
                $r[$k] = [
                    'poster'   => !empty($d['poster']) ? img($d['poster'], 'w342') : '',
                    'runtime'  => $d['runtime'] ?? '',
                    'cert'     => $d['certification'] ?? '',
                    'overview' => mb_strimwidth((string)($d['overview'] ?? ''), 0, 190, '…'),
                    'episodes' => $ty === 'tv' ? (int)($d['episodes'] ?? 0) : 0,
                    'logo'     => $logo ? 'https://image.tmdb.org/t/p/w300' . $logo : '',
                ];
            }
            return $r;
        });
        header('Cache-Control: public, max-age=86400');
        json_out(['items' => $out]);
    }

    case 'stream': {
        require_once __DIR__ . '/includes/StreamApi.php';
        $type = q('type') === 'tv' ? 'tv' : 'movie';
        $id   = qi('id');
        $s    = max(1, qi('s', 1));
        $e    = max(1, qi('e', 1));

        $sources = StreamApi::getSources($type, $id, $s, $e);
        $subs    = StreamApi::getSubtitles($type, $id, $s, $e);

        json_out([
            'sources'   => $sources,
            'subtitles' => $subs,
        ]);
    }

    default:
        json_out(['error' => 'Unknown action'], 400);
}
