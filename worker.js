/**
 * Cloudflare Worker for Flixraid Ultra-Fast Video Streaming Proxy
 * Proxies HLS (.m3u8 playlists, master variants, .ts/.mp4 video segments)
 * Handles CORS, Referer spoofing, byte-ranges, and in-flight M3U8 URL rewriting.
 */

addEventListener('fetch', event => {
  event.respondWith(handleRequest(event.request));
});

async function handleRequest(request) {
  const url = new URL(request.url);

  // Handle CORS preflight OPTIONS
  if (request.method === 'OPTIONS') {
    return new Response(null, {
      status: 204,
      headers: corsHeaders()
    });
  }

  const targetUrl = url.searchParams.get('url');
  if (!targetUrl || !/^https?:\/\//i.test(targetUrl)) {
    return new Response(JSON.stringify({
      status: 'active',
      service: 'Flixraid Cloudflare Stream Worker',
      usage: '?url=<target_stream_url>&ref=<optional_referer>'
    }, null, 2), {
      status: 200,
      headers: {
        'Content-Type': 'application/json',
        ...corsHeaders()
      }
    });
  }

  const referer = url.searchParams.get('ref') || 'https://google.com';

  // Forward Range headers for seeking/fast buffering
  const forwardHeaders = new Headers();
  forwardHeaders.set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
  forwardHeaders.set('Referer', referer);
  forwardHeaders.set('Origin', new URL(referer).origin);
  forwardHeaders.set('Accept', '*/*');

  const range = request.headers.get('Range');
  if (range) {
    forwardHeaders.set('Range', range);
  }

  try {
    const response = await fetch(targetUrl, {
      method: request.method,
      headers: forwardHeaders,
      redirect: 'follow'
    });

    const contentType = response.headers.get('Content-Type') || '';
    const isM3u8 = contentType.includes('mpegurl') || 
                   contentType.includes('application/x-mpegURL') || 
                   targetUrl.includes('.m3u8') || 
                   url.pathname.endsWith('.m3u8');

    // If it is an M3U8 playlist, rewrite URLs to proxy through this worker
    if (isM3u8) {
      const text = await response.text();
      const baseUrl = response.url.substring(0, response.url.lastIndexOf('/') + 1);
      const workerBase = url.origin + url.pathname;

      const lines = text.split('\n');
      const rewritten = lines.map(line => {
        const trimmed = line.trim();
        if (!trimmed) return line;

        if (trimmed.startsWith('#')) {
          // Handle URI="..." inside tags like #EXT-X-KEY or #EXT-X-MAP
          return line.replace(/URI="([^"]+)"/g, (match, uri) => {
            const abs = resolveUrl(uri, baseUrl);
            return `URI="${workerBase}?url=${encodeURIComponent(abs)}&ref=${encodeURIComponent(referer)}"`;
          });
        }

        // Segment or sub-variant URL
        const abs = resolveUrl(trimmed, baseUrl);
        return `${workerBase}?url=${encodeURIComponent(abs)}&ref=${encodeURIComponent(referer)}`;
      }).join('\n');

      const headers = new Headers(corsHeaders());
      headers.set('Content-Type', 'application/vnd.apple.mpegurl');
      headers.set('Cache-Control', 'no-cache, no-store');

      return new Response(rewritten, {
        status: 200,
        headers
      });
    }

    // Binary segment / TS chunk stream response with streaming pass-through
    const headers = new Headers(response.headers);
    Object.entries(corsHeaders()).forEach(([k, v]) => headers.set(k, v));
    headers.set('Cache-Control', 'public, max-age=14400'); // Cache video chunks on Cloudflare Edge

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers
    });
  } catch (err) {
    return new Response('Stream Proxy Error: ' + err.message, {
      status: 502,
      headers: corsHeaders()
    });
  }
}

function resolveUrl(rel, base) {
  if (/^https?:\/\//i.test(rel)) return rel;
  if (rel.startsWith('//')) return 'https:' + rel;
  if (rel.startsWith('/')) {
    const u = new URL(base);
    return u.origin + rel;
  }
  return base + rel;
}

function corsHeaders() {
  return {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, HEAD, OPTIONS',
    'Access-Control-Allow-Headers': 'Range, DNT, User-Agent, X-Requested-With, If-Modified-Since, Cache-Control, Content-Type',
    'Access-Control-Expose-Headers': 'Content-Length, Content-Range, Accept-Ranges'
  };
}
