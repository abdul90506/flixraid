<?php
/**
 * Flixraid Stream Proxy
 * Bypasses CORS and CDN Referer restrictions for HLS streams (.m3u8 and media segments).
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, DNT, User-Agent, X-Requested-With, If-Modified-Since, Cache-Control, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';
if (!$url || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo 'Invalid stream URL';
    exit;
}

$ref = isset($_GET['ref']) ? trim($_GET['ref']) : 'https://google.com';

// Handle Range requests if provided by browser
$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: ' . $ref,
    'Accept: */*',
];
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

$respHeaders = [];
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_HEADER         => false, // Keep false so body is 100% clean
    CURLOPT_HEADERFUNCTION => function($curl, $headerLine) use (&$respHeaders) {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    }
]);

$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($body === false || $httpCode < 200 || $httpCode >= 400) {
    http_response_code($httpCode ?: 502);
    echo 'Stream fetch error';
    exit;
}

// Determine if this is an M3U8 playlist
$isM3u8 = (stripos($contentType, 'mpegurl') !== false) || 
          (stripos($url, '.m3u8') !== false) || 
          (strpos($body, '#EXTM3U') === 0);

if ($isM3u8) {
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache, no-store');

    $baseUrl = substr($effectiveUrl, 0, strrpos($effectiveUrl, '/') + 1);
    $proxyScript = 'proxy.php';

    // Parse and rewrite M3U8 lines
    $lines = explode("\n", $body);
    $output = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $output[] = $line;
            continue;
        }

        // Tag or comment line
        if ($trimmed[0] === '#') {
            // Check URI="path" attributes in tags like #EXT-X-KEY or #EXT-X-MAP
            if (preg_match('#URI="([^"]+)"#', $line, $m)) {
                $origUri = $m[1];
                $absUri = resolveUrl($origUri, $baseUrl);
                $proxiedUri = $proxyScript . '?url=' . urlencode($absUri) . '&ref=' . urlencode($ref);
                $line = str_replace('URI="' . $origUri . '"', 'URI="' . $proxiedUri . '"', $line);
            }
            $output[] = $line;
            continue;
        }

        // Media segment or sub-playlist URL
        $absUri = resolveUrl($trimmed, $baseUrl);
        $proxied = $proxyScript . '?url=' . urlencode($absUri) . '&ref=' . urlencode($ref);
        $output[] = $proxied;
    }

    echo implode("\n", $output);
    exit;
}

// Media segment (binary .ts / .mp4 / .webp)
if ($contentType) {
    header('Content-Type: ' . $contentType);
}
if (isset($respHeaders['content-range'])) {
    header('Content-Range: ' . $respHeaders['content-range']);
    http_response_code(206);
} else {
    http_response_code($httpCode);
}
header('Content-Length: ' . strlen($body));

echo $body;
exit;

function resolveUrl($rel, $base) {
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    if (strpos($rel, '//') === 0) {
        return 'https:' . $rel;
    }
    if ($rel[0] === '/') {
        $parts = parse_url($base);
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $rel;
    }
    if (strpos($rel, './') === 0) {
        $rel = substr($rel, 2);
    }
    return $base . $rel;
}
