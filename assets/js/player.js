/* ============================================================
   CINEFLIX Player – Netflix-style HTML5 / HLS player
   desktop (mouse + keyboard) · touch (phones / tablets) · fullscreen
   ============================================================ */
(() => {
  'use strict';
  const D = window.NFP;
  if (!D) return;

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const root = $('#nfp');
  const video = $('#nfpVideo');
  const iframe = $('#nfpIframe');
  const subsBox = $('#nfpSubs');
  const SKIP = D.skip || 10;
  const store = {
    get(k, d) { try { const v = JSON.parse(localStorage.getItem(k)); return v ?? d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* quota */ } },
  };
  const prefs = store.get('cf_prefs', {});
  const savePrefs = () => store.set('cf_prefs', prefs);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const fmt = (t) => {
    t = Math.max(0, Math.floor(t || 0));
    const h = (t / 3600) | 0, m = ((t % 3600) / 60) | 0, s = t % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
  };
  // desktop clock style: 00:00 / 42:10 (h:mm:ss once it passes an hour)
  const fmt2 = (t) => {
    t = Math.max(0, Math.floor(t || 0));
    const h = (t / 3600) | 0, m = ((t % 3600) / 60) | 0, s = t % 60;
    return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  };
  const CHECK = '<svg class="ck" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  const PLAY = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 3.8v16.4a.8.8 0 0 0 1.2.7l13.4-8.2a.8.8 0 0 0 0-1.4L7.2 3.1A.8.8 0 0 0 6 3.8Z"/></svg>';

  /* =====================================================================
     input mode: touch vs mouse, compact size
     ===================================================================== */
  let touch = matchMedia('(hover: none) and (pointer: coarse)').matches;
  const setTouch = (v) => { if (v !== touch || !root.classList.contains(v ? 'is-touch' : 'is-desk')) { touch = v; root.classList.toggle('is-touch', v); root.classList.toggle('is-desk', !v); requestAnimationFrame(layoutVars); } };
  setTouch(touch);
  window.addEventListener('pointerdown', (e) => setTouch(e.pointerType === 'touch' || e.pointerType === 'pen'), true);
  window.addEventListener('mousemove', (e) => { if (touch && (e.movementX || e.movementY) && !e.sourceCapabilities?.firesTouchEvents && matchMedia('(hover: hover)').matches) setTouch(false); }, true);
  const fsEl = () => document.fullscreenElement || document.webkitFullscreenElement;
  function layoutVars() {
    // subtitle size follows the visible 16:9 picture, not the whole (portrait) viewport
    const h = Math.min(root.clientHeight || 0, (root.clientWidth || 0) * 0.5625);
    if (!h) return;
    const compact = root.classList.contains('is-compact');
    root.style.setProperty('--sub-base', Math.round(clamp(h * (compact ? 0.066 : 0.052), 12, 60)) + 'px');
    const row = $('.nfp-progress-row');
    if (row && !root.classList.contains('is-idle')) {
      const rr = root.getBoundingClientRect(), pr = row.getBoundingClientRect();
      if (pr.height) root.style.setProperty('--bar-h', Math.round(rr.bottom - pr.top + 4) + 'px');
    }
  }
  const sizeCheck = () => { root.classList.toggle('is-compact', root.clientWidth < 600 && !fsEl()); if (!root.classList.contains('is-compact')) root.classList.remove('sheet-open'); requestAnimationFrame(layoutVars); };
  if (window.ResizeObserver) new ResizeObserver(sizeCheck).observe(root); else window.addEventListener('resize', sizeCheck);
  sizeCheck();

  /* =====================================================================
     history (shared with the site: cf_history / cf_eps / cf_list)
     ===================================================================== */
  const HKEY = `${D.type}:${D.id}`;
  function saveHist(progress, duration) {
    const h = store.get('cf_history', {});
    const prev = h[HKEY] || {};
    const sameEp = D.type === 'movie' || (+prev.s === +D.s && +prev.e === +D.e);
    h[HKEY] = {
      type: D.type, id: D.id, s: D.s, e: D.e, title: D.title, epTitle: D.epTitle, poster: D.poster, backdrop: D.backdrop, year: D.year,
      progress: progress ?? (sameEp ? prev.progress || 0 : 0),
      duration: duration ?? (sameEp ? prev.duration || 0 : 0),
      updated: Date.now(),
    };
    store.set('cf_history', h);
    if (D.type === 'tv' && h[HKEY].duration) {
      const eps = store.get('cf_eps', {});
      eps[`${D.id}:${D.s}:${D.e}`] = Math.round((h[HKEY].progress / h[HKEY].duration) * 100);
      store.set('cf_eps', eps);
    }
  }
  function resumePoint() {
    const h = store.get('cf_history', {})[HKEY];
    if (!h || !(h.progress > 20)) return 0;
    if (h.duration && h.progress > h.duration - 60) return 0;
    if (D.type === 'tv' && !(+h.s === +D.s && +h.e === +D.e)) return 0;
    return Math.floor(h.progress);
  }
  let startAt = resumePoint();

  /* =====================================================================
     chrome (auto hide)
     ===================================================================== */
  let idleTimer = 0, pauseInfoTimer = 0, started = false;
  const panelOpen = () => !!$('.nfp-panel.open');
  function wake() {
    const wasIdle = root.classList.contains('is-idle');
    root.classList.remove('is-idle', 'show-pauseinfo');
    if (wasIdle) setTimeout(layoutVars, 360);
    clearTimeout(idleTimer);
    idleTimer = setTimeout(hideChrome, touch ? 3500 : 3000);
    schedulePauseInfo();
  }
  function hideChrome() {
    if (!video.paused && !panelOpen() && !root.classList.contains('is-scrub') && !$('.nfp-vol.open')) root.classList.add('is-idle');
  }
  function schedulePauseInfo() {
    clearTimeout(pauseInfoTimer);
    if (!video.paused || !started || root.classList.contains('is-ended') || !document.body.classList.contains('is-playing')) return;
    pauseInfoTimer = setTimeout(() => {
      if (video.paused && !panelOpen() && !root.classList.contains('is-ended') && !root.classList.contains('is-compact')) root.classList.add('show-pauseinfo', 'is-idle');
    }, 7000);
  }

  const toastEl = $('#nfpToast');
  let toastT;
  function toast(msg) { toastEl.textContent = msg; toastEl.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => toastEl.classList.remove('show'), 1600); }
  function flash(play) {
    if (touch || !root.classList.contains('is-idle')) return; // centre controls already show the state
    const f = $('#nfpFlash');
    f.classList.remove('seek');
    f.innerHTML = $(play ? '#btnPlay .i-play' : '#btnPlay .i-pause').innerHTML;
    f.classList.remove('go'); void f.offsetWidth; f.classList.add('go');
  }

  /* =====================================================================
     panels
     ===================================================================== */
  let resumeAfterPanel = false, hovOpenT = 0, hovCloseT = 0;
  function closePanels() {
    const had = panelOpen();
    $$('.nfp-panel.open').forEach((p) => p.classList.remove('open'));
    root.classList.remove('sheet-open');
    if (had && resumeAfterPanel) { resumeAfterPanel = false; video.play().catch(() => {}); }
  }
  function positionPanel(p, btn) {
    if (touch || !btn) { p.style.right = ''; return; }
    const rr = root.getBoundingClientRect(), br = btn.getBoundingClientRect();
    const pad = parseFloat(getComputedStyle(root).getPropertyValue('--pad')) || 24;
    const maxRight = Math.max(pad * 0.6, rr.width - p.offsetWidth - pad * 0.6);
    p.style.right = clamp(rr.right - (br.left + br.width / 2) - 40, pad * 0.6, maxRight) + 'px';
  }
  function openPanel(name, btn) {
    const p = $('#panel-' + name);
    if (!p) return;
    $$('.nfp-panel.open').forEach((x) => { if (x !== p) x.classList.remove('open'); });
    positionPanel(p, btn);
    if (!p.classList.contains('open')) {
      p.classList.add('open'); p._at = Date.now();
      if (name === 'episodes') renderPanelEpisodes();
    }
    // Netflix mobile pauses while you pick audio / subtitles or browse episodes
    if (touch && (name === 'subs' || name === 'episodes') && !video.paused) { resumeAfterPanel = true; video.pause(); }
    root.classList.toggle('sheet-open', root.classList.contains('is-compact'));
    wake();
  }
  function togglePanel(name, btn) {
    const p = $('#panel-' + name);
    if (!p) return;
    if (p.classList.contains('open')) {
      if (!touch && Date.now() - (p._at || 0) < 700) return; // just opened by hover → a click keeps it open
      closePanels(); wake();
    } else openPanel(name, btn);
  }
  $$('[data-panel]').forEach((b) => {
    b.addEventListener('click', (e) => { e.stopPropagation(); clearTimeout(hovOpenT); togglePanel(b.dataset.panel, b); });
    if (!b.closest('.desk-row')) return;
    b.addEventListener('mouseenter', () => { if (touch) return; clearTimeout(hovCloseT); clearTimeout(hovOpenT); hovOpenT = setTimeout(() => openPanel(b.dataset.panel, b), 150); });
    b.addEventListener('mouseleave', () => { clearTimeout(hovOpenT); if (!touch) { clearTimeout(hovCloseT); hovCloseT = setTimeout(closePanels, 400); } });
  });
  $$('[data-close-panel]').forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); closePanels(); wake(); }));
  $$('.nfp-panel').forEach((p) => {
    p.addEventListener('click', (e) => { e.stopPropagation(); if (e.target === p && touch && !root.classList.contains('is-compact')) closePanels(); });
    p.addEventListener('mouseenter', () => { if (!touch) clearTimeout(hovCloseT); });
    p.addEventListener('mouseleave', () => {
      if (touch) return;
      const a = document.activeElement;
      if (a && a.tagName === 'SELECT' && p.contains(a)) return; // native dropdown open
      clearTimeout(hovCloseT); hovCloseT = setTimeout(closePanels, 400);
    });
  });
  $('#nfpSheetBg').addEventListener('click', (e) => { e.stopPropagation(); closePanels(); wake(); });
  document.addEventListener('click', (e) => { if (!e.target.closest('.nfp-panel, [data-panel], .nfp-sheetbg')) closePanels(); });

  /* =====================================================================
     episodes (player panel + details section)
     ===================================================================== */
  const seasonCache = {};
  if (D.type === 'tv' && D.s != null) seasonCache[D.s] = D.episodes || [];
  function getSeason(season) {
    if (seasonCache[season]) return Promise.resolve(seasonCache[season]);
    return fetch(`api.php?action=season&id=${D.id}&s=${season}&mode=watch`).then((r) => r.json()).then((j) => (seasonCache[season] = j.full || []));
  }
  let panelSeason = D.s;
  function renderPanelEpisodes(season = panelSeason) {
    const list = $('#peList');
    if (!list) return;
    panelSeason = season;
    if (!seasonCache[season]) list.innerHTML = '<div class="pe-loading"><div class="nfp-spinner"></div></div>';
    getSeason(season).then((eps) => {
      if (panelSeason !== season) return;
      const prog = store.get('cf_eps', {});
      list.innerHTML = eps.length ? eps.map((ep) => {
        const cur = +season === +D.s && +ep.number === +D.e;
        const p = prog[`${D.id}:${season}:${ep.number}`] || 0;
        return `<a class="pe-item${cur ? ' cur' : ''}${p ? ' has-bar' : ''}" href="${esc(ep.url)}">
          <span class="n">${ep.number}</span><span class="t">${esc(ep.name)}</span><span class="rt">${esc(ep.runtime || '')}</span>
          <span class="bar"><i style="width:${Math.max(4, p)}%"></i></span>
          <span class="det">${ep.still ? `<img src="${esc(ep.still)}" alt="" loading="lazy">` : ''}<p>${esc(ep.overview || '')}</p></span></a>`;
      }).join('') : '<div class="pe-loading">No episodes found.</div>';
      const cur = $('.pe-item.cur', list);
      if (cur) list.scrollTop = cur.offsetTop - 70;
    }).catch(() => { list.innerHTML = '<div class="pe-loading">Could not load episodes.</div>'; });
  }
  $('#peSeason')?.addEventListener('change', (e) => renderPanelEpisodes(+e.target.value));

  let infoSeason = D.s;
  function renderInfoEpisodes(season = infoSeason) {
    const box = $('#wiEps');
    if (!box) return;
    infoSeason = season;
    if (!seasonCache[season]) box.innerHTML = '<div class="pe-loading"><div class="nfp-spinner"></div></div>';
    getSeason(season).then((eps) => {
      if (infoSeason !== season) return;
      const prog = store.get('cf_eps', {});
      box.innerHTML = eps.length ? eps.map((ep) => {
        const cur = (!D.info || document.body.classList.contains('is-playing')) && +season === +D.s && +ep.number === +D.e;
        const p = prog[`${D.id}:${season}:${ep.number}`] || 0;
        return `<a class="ep${cur ? ' cur' : ''}" href="${esc(ep.url)}">
          <span class="ep-n">${ep.number}</span>
          <span class="ep-img">${ep.still ? `<img src="${esc(ep.still)}" alt="" loading="lazy">` : ''}<span class="ep-pl">${PLAY}</span>${p ? `<span class="ep-bar"><i style="width:${Math.max(4, p)}%"></i></span>` : ''}</span>
          <span class="ep-txt"><span class="ep-tt"><b>${touch || innerWidth < 600 ? ep.number + '. ' : ''}${esc(ep.name)}${cur ? '<span class="ep-now">NOW PLAYING</span>' : ''}</b><span>${esc(ep.runtime || '')}</span></span><span class="ep-sub">E${ep.number} • S${season}${ep.runtime ? ' • ' + esc(ep.runtime) : ''}</span><p>${esc(ep.overview || '')}</p></span></a>`;
      }).join('') : '<div class="pe-loading">No episodes found.</div>';
    }).catch(() => { box.innerHTML = '<div class="pe-loading">Could not load episodes.</div>'; });
  }
  $('#wiSeason')?.addEventListener('change', (e) => renderInfoEpisodes(+e.target.value));
  renderInfoEpisodes();

  /* =====================================================================
     source loading (hls.js / native) + recovery
     ===================================================================== */
  let hls = null, srcIdx = 0, netTries = 0, mediaRecovered = 0, codecSwapped = false, curSpeed = 1;
  let curQuality = D.qualities && D.qualities[0] ? D.qualities[0].quality : '', curAudioIdx = 0;
  let prebooting = false, prebootT = 0, bootReady = false;
  /* v6 desktop pop-up: this page runs inside an iframe over the browse page (watch.php?embed=1) */
  const EMBED = !!D.embed && window.parent !== window;
  function toParent(msg) { try { window.parent.postMessage(msg, location.origin); } catch { /* ignore */ } }
  function embedUrl(href) { const u = new URL(href, location.href); u.searchParams.set('embed', '1'); return u; }
  function cleanUrl(u) { const c = new URL(u.href); c.searchParams.delete('embed'); return c.pathname.split('/').pop() + c.search; }
  // navigate to another watch page (next episode, resume…) — inside the pop-up we swap the iframe and keep the parent URL in sync
  /* direct visit / refresh of a pop-up URL on desktop: load the real home page blurred behind the card */
  function addBehind() {
    if (EMBED || window.parent !== window || innerWidth < 760 || !matchMedia('(pointer: fine)').matches || document.querySelector('.wm-behind')) return;
    let home = 'index.php';
    try { home = sessionStorage.getItem('cf_from') || home; } catch { /* ignore */ }
    const f = document.createElement('iframe');
    f.className = 'wm-behind'; f.tabIndex = -1; f.title = 'Home'; f.setAttribute('aria-hidden', 'true');
    f.onload = () => f.classList.add('ld');
    f.src = home + (home.includes('?') ? '&' : '?') + 'behind=1';
    document.body.prepend(f);
    document.body.classList.add('has-behind');
  }
  function go(href) {
    allowLeave = true;
    if (!EMBED) { location.href = href; return; }
    const u = embedUrl(href);
    toParent({ cf: 'url', url: cleanUrl(u) });
    location.replace(u.href);
  }
  function destroy() {
    if (hls) { try { hls.destroy(); } catch { /* ignore */ } hls = null; }
    video.removeAttribute('src');
    try { video.load(); } catch { /* ignore */ }
  }
  let loadTimeoutT = 0;
  video.addEventListener('playing', () => { clearTimeout(loadTimeoutT); });
  function load(idx, at) {
    const s = D.sources[idx];
    if (!started) {
      root.classList.add('is-loading');
      clearTimeout(loadTimeoutT);
      loadTimeoutT = setTimeout(() => {
        if (!started && root.classList.contains('is-loading')) {
          if (idx + 1 < D.sources.length) {
            console.warn('Source ' + idx + ' timed out, falling back to next source...');
            nextSource();
          } else {
            root.classList.remove('is-loading');
            root.classList.add('need-play');
          }
        }
      }, 9000);
    }
    if (!s) return showError();
    srcIdx = idx; netTries = 0; mediaRecovered = 0; codecSwapped = false;
    const t = at ?? startAt;
    startAt = t;
    hideError();
    destroy();
    buildServers();
    buildAudio();

    // Direct Vega Native HTML5 / MKV / MP4 stream mode
    video.style.display = 'block';
    root.classList.remove('is-embed-mode');

    if (window.Hls && Hls.isSupported() && (s.kind === 'hls' || s.url.includes('.m3u8'))) {
      hls = new Hls({
        enableWorker: true,
        startPosition: t > 0 ? t : -1,
        maxBufferLength: prebooting ? (innerWidth < 760 ? 6 : 10) : 30, maxMaxBufferLength: 90, backBufferLength: 45,
        startFragPrefetch: true, abrEwmaDefaultEstimate: 2_500_000,
        manifestLoadingMaxRetry: 3, levelLoadingMaxRetry: 4, fragLoadingMaxRetry: 6,
        manifestLoadingRetryDelay: 700, levelLoadingRetryDelay: 700, fragLoadingRetryDelay: 500,
        renderTextTracksNatively: true, subtitleDisplay: false,
      });
      hls.on(Hls.Events.MEDIA_ATTACHED, () => hls.loadSource(s.url));
      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        netTries = 0;
        applyQualityPref();
        buildQuality(); buildAudio(); buildSubs(); buildServers();
        if (t > 0) {
          try { video.currentTime = t; } catch { /* ignore */ }
        }
        tryPlay();
      });
      hls.on(Hls.Events.LEVEL_SWITCHED, buildQuality);
      hls.on(Hls.Events.FRAG_LOADED, () => { netTries = 0; });
      hls.on(Hls.Events.AUDIO_TRACKS_UPDATED, buildAudio);
      hls.on(Hls.Events.AUDIO_TRACK_SWITCHED, buildAudio);
      hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, () => { buildSubs(); restoreSubPref(); });
      hls.on(Hls.Events.ERROR, onHlsError);
      hls.attachMedia(video);
    } else {
      video.src = s.url; // Safari / iOS native HLS or MP4
      if (t > 0) video.addEventListener('loadedmetadata', () => { try { video.currentTime = t; } catch { /* ignore */ } }, { once: true });
      video.addEventListener('loadedmetadata', () => { buildQuality(); buildAudio(); buildSubs(); restoreSubPref(); buildServers(); }, { once: true });
      tryPlay();
    }
    video.playbackRate = curSpeed;
  }
  function onHlsError(_, data) {
    if (!data.fatal) return;
    if (data.type === Hls.ErrorTypes.NETWORK_ERROR && netTries < 4) {
      netTries++;
      setTimeout(() => hls && hls.startLoad(video.currentTime || -1), 800 * netTries);
      return;
    }
    if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
      if (mediaRecovered < 2) { mediaRecovered++; hls.recoverMediaError(); return; }
      if (!codecSwapped) { codecSwapped = true; hls.swapAudioCodec(); hls.recoverMediaError(); return; }
    }
    nextSource();
  }
  video.addEventListener('error', () => { if (!hls) nextSource(); });
  function nextSource() {
    const t = video.currentTime || startAt;
    if (srcIdx + 1 < D.sources.length) load(srcIdx + 1, t);
    else showError();
  }
  let autoMuted = false;
  const unmuteBtn = $('#nfpUnmute');
  function blocked() {
    autoMuted = false; unmuteBtn.hidden = true;
    video.muted = !!prefs.muted;
    root.classList.remove('is-loading');
    root.classList.add('need-play', 'is-paused');
  }
  function tryPlay() {
    if (prebooting) return; // warmed up in the background from the info pop-up → wait for the user's Play
    const p = video.play();
    if (p && p.catch) p.catch((err) => {
      if (err && err.name === 'AbortError') return;
      // browser blocked autoplay with sound → start muted (like Netflix previews) and offer "Tap to unmute"
      if (!video.muted) {
        autoMuted = true; video.muted = true;
        video.play().then(() => { unmuteBtn.hidden = false; }).catch(blocked);
      } else blocked();
    });
  }
  function unmute() { autoMuted = false; unmuteBtn.hidden = true; video.muted = false; if (!video.volume) video.volume = 0.5; }
  unmuteBtn.addEventListener('click', (e) => { e.stopPropagation(); unmute(); wake(); });
  function showError(msg) {
    root.classList.add('is-error'); root.classList.remove('is-loading', 'is-buffering');
    if (msg) $('#nfpErrorMsg').textContent = msg;
    $('#nfpError').hidden = false;
  }
  function hideError() { root.classList.remove('is-error'); $('#nfpError').hidden = true; }
  $('#errRetry').addEventListener('click', () => { root.classList.add('is-loading'); load(0, video.currentTime || startAt); });

  // stall watchdog
  let lastT = -1, stallSince = 0;
  setInterval(() => {
    if (video.paused || video.ended || !started) { stallSince = 0; return; }
    if (video.currentTime === lastT) {
      if (!stallSince) stallSince = Date.now();
      const stuck = Date.now() - stallSince;
      if (stuck > 12000 && stuck < 13100) {
        if (hls) hls.startLoad(video.currentTime);
        else { const t = video.currentTime; video.load(); video.currentTime = t; tryPlay(); }
      }
      if (stuck > 30000) { stallSince = 0; nextSource(); }
    } else { stallSince = 0; lastT = video.currentTime; }
  }, 1000);

  /* =====================================================================
     video state
     ===================================================================== */
  video.addEventListener('playing', () => {
    root.classList.remove('is-loading', 'is-buffering', 'need-play', 'is-paused', 'show-pauseinfo');
    if (!started) {
      started = true;
      if (startAt > 5) showResume(startAt);
      saveHist();
      setTimeout(initPreview, 2500);
    }
    wake();
  });
  video.addEventListener('play', () => { root.classList.remove('is-paused', 'need-play', 'show-pauseinfo', 'is-ended'); $('#nfpEnd').hidden = true; wake(); });
  video.addEventListener('pause', () => { root.classList.add('is-paused'); wake(); saveNow(); });
  video.addEventListener('waiting', () => root.classList.add('is-buffering'));
  video.addEventListener('canplay', () => root.classList.remove('is-buffering'));
  video.addEventListener('seeked', () => root.classList.remove('is-buffering'));
  video.addEventListener('ended', onEnded);

  /* =====================================================================
     progress bar, scrubbing, frame preview
     ===================================================================== */
  const elPlayed = $('#nfpPlayed'), elBuf = $('#nfpBuffered'), elThumb = $('#nfpThumb'), elRem = $('#nfpRemaining'), elTime = $('#nfpTime');
  const elProg = $('#nfpProgress'), elHover = $('#nfpHoverBar');
  const pvBox = $('#nfpPreview'), pvFrame = $('.pv-frame', pvBox), pvCanvas = $('#pvCanvas'), pvTime = $('#pvTime'), pvArrow = $('.pv-arrow', pvBox);
  const pvCtx = pvCanvas.getContext('2d');
  if (D.bg) pvFrame.style.backgroundImage = `url("${D.bg}")`;
  let scrubbing = false, scrubT = 0, lastSave = 0;

  function draw() {
    const d = video.duration || 0, t = scrubbing ? scrubT : video.currentTime;
    const pct = d ? (t / d) * 100 : 0;
    elPlayed.style.width = pct + '%';
    elThumb.style.left = pct + '%';
    elRem.textContent = d ? fmt(d - t) : '0:00';
    elTime.textContent = fmt2(t) + '  /  ' + fmt2(d);
    let end = 0;
    const b = video.buffered;
    for (let i = 0; i < b.length; i++) if (b.start(i) <= video.currentTime + 0.5 && b.end(i) >= video.currentTime) end = b.end(i);
    elBuf.style.width = d ? (end / d) * 100 + '%' : '0';
  }
  video.addEventListener('timeupdate', () => {
    if (!scrubbing) draw();
    renderSubs();
    checkMarkers();
    if (started && Date.now() - lastSave > 5000) saveNow();
  });
  video.addEventListener('progress', draw);
  video.addEventListener('durationchange', draw);
  function saveNow() {
    if (!started || !video.duration || !isFinite(video.duration)) return;
    lastSave = Date.now();
    saveHist(video.currentTime, video.duration);
  }
  window.addEventListener('pagehide', saveNow);
  document.addEventListener('visibilitychange', () => { if (document.hidden) saveNow(); });

  const ratioAt = (x) => { const r = elProg.getBoundingClientRect(); return clamp((x - r.left) / r.width, 0, 1); };
  function hoverAt(x) {
    const r = elProg.getBoundingClientRect(), ratio = ratioAt(x), d = video.duration || 0;
    elHover.style.width = ratio * 100 + '%';
    const half = pvBox.offsetWidth / 2;
    const want = ratio * r.width, at = clamp(want, half - r.left + 6, r.width - half + (innerWidth - r.right) - 6);
    pvBox.style.left = at + 'px';
    if (pvArrow) pvArrow.style.transform = `translateX(${clamp(want - at, -half + 10, half - 10)}px)`;
    pvTime.textContent = fmt(ratio * d);
    if (d) requestPreview(ratio * d);
  }
  elProg.addEventListener('pointermove', (e) => { hoverAt(e.clientX); if (scrubbing) { scrubT = ratioAt(e.clientX) * (video.duration || 0); draw(); } });
  elProg.addEventListener('pointerenter', () => initPreview());
  elProg.addEventListener('pointerdown', (e) => {
    if (!video.duration) return;
    e.stopPropagation();
    scrubbing = true; root.classList.add('is-scrub');
    clearTimeout(idleTimer);
    elProg.setPointerCapture(e.pointerId);
    scrubT = ratioAt(e.clientX) * video.duration; hoverAt(e.clientX); draw();
  });
  const endScrub = (e) => {
    if (!scrubbing) return;
    scrubbing = false; root.classList.remove('is-scrub');
    try { elProg.releasePointerCapture(e.pointerId); } catch { /* ignore */ }
    seekTo(scrubT);
    wake();
  };
  elProg.addEventListener('pointerup', endScrub);
  elProg.addEventListener('pointercancel', endScrub);
  elProg.addEventListener('click', (e) => e.stopPropagation());

  /* frame preview: a hidden low-quality hls instance seeks to the hovered time and the frame is drawn on a canvas.
     Frames are cached per 5-second bucket so moving back over the bar is instant. */
  let pv = null, pvHls = null, pvBusy = false, pvPending = null, pvWant = null, pvBusyTimer = 0;
  const thumbs = new Map();
  const BUCKET = 5;
  function initPreview() {
    if (pv || !D.sources[srcIdx]) return;
    pv = document.createElement('video');
    pv.muted = true; pv.playsInline = true; pv.preload = 'auto'; pv.crossOrigin = 'anonymous';
    pv.setAttribute('aria-hidden', 'true');
    const url = D.sources[srcIdx].url;
    if (window.Hls && Hls.isSupported()) {
      pvHls = new Hls({ enableWorker: true, startLevel: 0, autoStartLoad: true, maxBufferLength: 3, maxMaxBufferLength: 6, backBufferLength: 0,
        maxBufferSize: 4 * 1000 * 1000, capLevelToPlayerSize: false, startFragPrefetch: false, fragLoadingMaxRetry: 2, abrEwmaDefaultEstimate: 400000 });
      pvHls.on(Hls.Events.MANIFEST_PARSED, () => {
        // smallest level that is still at least ~300px wide
        const lv = pvHls.levels.map((l, i) => ({ i, w: l.width || 0, b: l.bitrate })).sort((a, b) => a.b - b.b);
        const pick = lv.find((l) => l.w >= 300) || lv[lv.length - 1] || { i: 0 };
        pvHls.currentLevel = pick.i; pvHls.autoLevelCapping = pick.i;
      });
      pvHls.on(Hls.Events.ERROR, (_, d) => { if (d.fatal) { try { pvHls.destroy(); } catch { /* ignore */ } pvHls = null; } });
      pvHls.loadSource(url);
      pvHls.attachMedia(pv);
    } else {
      pv.src = url;
    }
    pv.addEventListener('seeked', () => {
      clearTimeout(pvBusyTimer);
      try {
        const w = 320, h = Math.round(w * ((pv.videoHeight || 9) / (pv.videoWidth || 16)));
        const c = document.createElement('canvas'); c.width = w; c.height = h;
        c.getContext('2d').drawImage(pv, 0, 0, w, h);
        const key = Math.round(pv.currentTime / BUCKET);
        thumbs.set(key, c);
        if (pvWant === key) paintThumb(c);
      } catch { /* tainted / not ready */ }
      pvBusy = false;
      if (pvPending !== null) { const k = pvPending; pvPending = null; seekPreview(k); }
    });
  }
  function paintThumb(c) {
    pvCanvas.width = c.width; pvCanvas.height = c.height;
    pvCtx.drawImage(c, 0, 0);
    pvFrame.classList.add('has-frame'); pvFrame.classList.remove('loading');
  }
  function seekPreview(key) {
    if (!pv || thumbs.has(key)) { if (thumbs.has(key) && pvWant === key) paintThumb(thumbs.get(key)); return; }
    pvBusy = true;
    try { pv.currentTime = key * BUCKET + 0.5; } catch { pvBusy = false; }
    clearTimeout(pvBusyTimer);
    pvBusyTimer = setTimeout(() => { pvBusy = false; if (pvPending !== null) { const k = pvPending; pvPending = null; seekPreview(k); } }, 4000);
  }
  function requestPreview(t) {
    const key = Math.round(t / BUCKET);
    pvWant = key;
    const c = thumbs.get(key);
    if (c) { paintThumb(c); return; }
    // nearest cached frame as placeholder
    let best = null, bd = 1e9;
    thumbs.forEach((v, k) => { const dd = Math.abs(k - key); if (dd < bd) { bd = dd; best = v; } });
    if (best && bd <= 6) paintThumb(best);
    else if (D.bg) {
      // Show crisp title backdrop if frame is still buffering
      pvFrame.style.backgroundImage = `url("${D.bg}")`;
      pvFrame.classList.remove('has-frame');
    } else {
      pvFrame.classList.remove('has-frame');
    }
    pvFrame.classList.add('loading');
    if (!pv) initPreview();
    if (pvBusy) pvPending = key; else seekPreview(key);
  }

  function seekTo(t) {
    if (!video.duration) return;
    video.currentTime = clamp(t, 0, video.duration - 0.25);
    draw();
    if (root.classList.contains('is-ended')) { root.classList.remove('is-ended'); $('#nfpEnd').hidden = true; }
  }
  let skipAccum = 0, skipAccumT = 0, skipDir = 0;
  function spinIcon(el) {
    const svg = el && (el.tagName === 'svg' ? el : el.querySelector('svg'));
    if (!svg) return;
    svg.classList.remove('spin'); void svg.getBoundingClientRect(); svg.classList.add('spin');
  }
  function skip(sec, src) {
    seekTo(video.currentTime + sec);
    const dir = Math.sign(sec);
    const now = Date.now();
    skipAccum = dir === skipDir && now - skipAccumT < 1000 ? skipAccum + Math.abs(sec) : Math.abs(sec);
    skipDir = dir; skipAccumT = now;
    if (touch && src !== 'btn') {
      const rip = $(sec < 0 ? '#rippleL' : '#rippleR');
      $(sec < 0 ? '#rippleLText' : '#rippleRText').textContent = skipAccum + ' seconds';
      const n = $('.sk-n', rip); if (n) n.textContent = skipAccum;
      rip.classList.add('show'); rip.classList.remove('bump'); void rip.offsetWidth; rip.classList.add('bump');
      // only the ripple should be visible – no duplicate centre ⟲10 / ⟳10 buttons
      root.classList.add('is-rippling', 'is-idle');
      clearTimeout(rip._t); rip._t = setTimeout(() => { rip.classList.remove('show', 'bump'); root.classList.remove('is-rippling'); }, 700);
    } else if (!touch && src === 'key') {
      const f = $('#nfpFlash');
      f.innerHTML = $(sec < 0 ? '#btnRew' : '#btnFwd').innerHTML;
      const fn = $('.sk-n', f); if (fn) fn.textContent = skipAccum;
      // only the centre badge – hide the centre ⟲10 ▶ ⟳10 controls while it shows (no duplicate icons)
      root.classList.add('is-flashing');
      clearTimeout(f._t); f._t = setTimeout(() => root.classList.remove('is-flashing'), 700);
      f.classList.add('seek'); f.classList.remove('go'); void f.offsetWidth; f.classList.add('go');
      spinIcon(f); spinIcon($(sec < 0 ? '#btnRew' : '#btnFwd'));
    }
  }

  /* =====================================================================
     transport
     ===================================================================== */
  function togglePlay(quiet) {
    resumeAfterPanel = false;
    if (video.paused || video.ended) { video.play().catch(() => {}); if (!quiet) flash(true); }
    else { video.pause(); if (!quiet) flash(false); }
  }
  $('#btnPlay').addEventListener('click', (e) => { e.stopPropagation(); togglePlay(); });
  $('#cPlay').addEventListener('click', (e) => { e.stopPropagation(); togglePlay(); wake(); });
  $('#nfpBigPlay').addEventListener('click', (e) => { e.stopPropagation(); root.classList.remove('need-play'); video.play().catch(() => {}); });
  ['#btnRew', '#cRew'].forEach((id) => $(id).addEventListener('click', (e) => { e.stopPropagation(); skip(-SKIP, 'btn'); spinIcon(e.currentTarget); wake(); }));
  ['#btnFwd', '#cFwd'].forEach((id) => $(id).addEventListener('click', (e) => { e.stopPropagation(); skip(SKIP, 'btn'); spinIcon(e.currentTarget); wake(); }));

  // click / tap layer
  const clickLayer = $('#nfpClick');
  let lastTap = 0, lastTapX = 0, tapTimer = 0, seekMode = false, seekModeT = 0;
  clickLayer.addEventListener('click', (e) => {
    if (panelOpen()) { closePanels(); wake(); return; }
    if (!touch) { if (autoMuted) { unmute(); wake(); return; } togglePlay(); wake(); return; }
    const r = root.getBoundingClientRect(), x = (e.clientX - r.left) / r.width;
    const now = Date.now();
    const side = x < 0.38 ? -1 : x > 0.62 ? 1 : 0;
    // double-tap (or keep tapping right after a double-tap) on a side → seek
    if (side && (now - lastTap < 320 || (seekMode && now - seekModeT < 800))) {
      clearTimeout(tapTimer);
      seekMode = true; seekModeT = now;
      skip(side * SKIP);
      lastTap = 0;
      return;
    }
    seekMode = false;
    lastTap = now; lastTapX = x;
    clearTimeout(tapTimer);
    tapTimer = setTimeout(() => {
      if (root.classList.contains('is-idle') || root.classList.contains('show-pauseinfo')) wake();
      else { clearTimeout(idleTimer); clearTimeout(pauseInfoTimer); root.classList.add('is-idle'); }
    }, side ? 300 : 0);
  });
  clickLayer.addEventListener('dblclick', () => { if (!touch) { toggleFs(); togglePlay(true); } });

  // pinch to zoom (fill / fit)
  let pinch0 = 0;
  root.addEventListener('touchstart', (e) => { if (e.touches.length === 2) pinch0 = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY); }, { passive: true });
  root.addEventListener('touchmove', (e) => {
    if (e.touches.length !== 2 || !pinch0) return;
    const d = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
    if (d / pinch0 > 1.2 && !root.classList.contains('is-fill')) { root.classList.add('is-fill'); toast('Zoomed to fill'); pinch0 = 0; }
    else if (d / pinch0 < 0.83 && root.classList.contains('is-fill')) { root.classList.remove('is-fill'); toast('Original'); pinch0 = 0; }
  }, { passive: true });

  /* =====================================================================
     volume
     ===================================================================== */
  const volWrap = $('#nfpVol'), volSlider = $('#volSlider'), volFill = $('#volFill');
  video.volume = clamp(prefs.volume ?? 1, 0, 1);
  video.muted = !!prefs.muted;
  function drawVol() {
    const v = video.muted ? 0 : video.volume;
    if (root.classList.contains('is-desk')) { volFill.style.width = v * 100 + '%'; volFill.style.height = ''; }
    else { volFill.style.height = v * 100 + '%'; volFill.style.width = ''; }
    root.classList.toggle('is-muted', video.muted || video.volume === 0);
    root.classList.toggle('vol-low', v > 0 && v < 0.5);
  }
  video.addEventListener('volumechange', () => {
    drawVol();
    if (autoMuted && !video.muted) { autoMuted = false; unmuteBtn.hidden = true; }
    if (autoMuted) return;
    prefs.volume = video.volume; prefs.muted = video.muted; savePrefs();
  });
  function volPeek() { if (touch) return; volWrap.classList.add('open'); clearTimeout(volWrap._t); volWrap._t = setTimeout(() => { if (!volDrag) volWrap.classList.remove('open'); }, 1300); }
  drawVol();
  function setVol(v) { video.volume = clamp(v, 0, 1); video.muted = video.volume === 0; }
  $('#btnMute').addEventListener('click', (e) => { e.stopPropagation(); if (autoMuted) { unmute(); return; } video.muted = !video.muted; if (!video.muted && video.volume === 0) video.volume = 0.5; });
  let volDrag = false;
  // desktop: horizontal slider next to the speaker (Netflix) · touch/tablet: vertical pop-up
  const volAt = (e) => {
    const r = volSlider.getBoundingClientRect();
    return r.width > r.height ? clamp((e.clientX - r.left) / r.width, 0, 1) : clamp(1 - (e.clientY - r.top) / r.height, 0, 1);
  };
  volSlider.addEventListener('pointerdown', (e) => { e.stopPropagation(); volDrag = true; volWrap.classList.add('open'); volSlider.setPointerCapture(e.pointerId); setVol(volAt(e)); });
  volSlider.addEventListener('pointermove', (e) => { if (volDrag) setVol(volAt(e)); });
  volWrap.addEventListener('mouseenter', drawVol);
  const volEnd = () => { volDrag = false; volWrap.classList.remove('open'); };
  volSlider.addEventListener('pointerup', volEnd);
  volSlider.addEventListener('pointercancel', volEnd);
  volWrap.addEventListener('wheel', (e) => { e.preventDefault(); setVol(video.volume + (e.deltaY < 0 ? 0.05 : -0.05)); }, { passive: false });

  /* =====================================================================
     fullscreen / pip / lock
     ===================================================================== */
  function toggleFs() {
    if (fsEl()) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
    const req = root.requestFullscreen || root.webkitRequestFullscreen;
    if (req) {
      Promise.resolve(req.call(root, { navigationUI: 'hide' })).then(() => {
        if (touch) { try { screen.orientation.lock('landscape').catch(() => {}); } catch { /* ignore */ } }
      }).catch(() => { if (video.webkitEnterFullscreen) video.webkitEnterFullscreen(); });
    } else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen(); // iPhone
  }
  const onFs = () => {
    root.classList.toggle('is-fs', !!fsEl());
    sizeCheck();
    if (!fsEl()) { try { screen.orientation.unlock(); } catch { /* ignore */ } }
  };
  document.addEventListener('fullscreenchange', onFs);
  document.addEventListener('webkitfullscreenchange', onFs);
  $('#btnFs').addEventListener('click', (e) => { e.stopPropagation(); toggleFs(); });
  $('#actFs').addEventListener('click', (e) => { e.stopPropagation(); toggleFs(); });
  // phones: rotate to landscape while playing → go full screen
  try {
    screen.orientation?.addEventListener('change', () => {
      if (!touch || !started || !isPlaying()) return;
      if (screen.orientation.type.startsWith('landscape') && !fsEl() && !video.paused) toggleFs();
    });
  } catch { /* ignore */ }

  const pipBtn = $('#btnPip');
  if (document.pictureInPictureEnabled && !video.disablePictureInPicture) {
    pipBtn.hidden = false;
    pipBtn.addEventListener('click', (e) => { e.stopPropagation(); togglePip(); });
  }
  function togglePip() {
    if (!document.pictureInPictureEnabled) return;
    if (document.pictureInPictureElement) document.exitPictureInPicture().catch(() => {});
    else video.requestPictureInPicture().catch(() => {});
  }

  const lockEl = $('#nfpLock');
  let lockT = 0;
  $('#actLock').addEventListener('click', (e) => {
    e.stopPropagation();
    closePanels();
    root.classList.add('is-locked'); lockEl.hidden = false; showUnlock();
  });
  function showUnlock() { lockEl.classList.add('show'); clearTimeout(lockT); lockT = setTimeout(() => lockEl.classList.remove('show'), 2500); }
  lockEl.addEventListener('click', (e) => { e.stopPropagation(); if (!e.target.closest('#nfpUnlock')) showUnlock(); });
  $('#nfpUnlock').addEventListener('click', (e) => {
    e.stopPropagation();
    if (!lockEl.classList.contains('show')) { showUnlock(); return; }
    root.classList.remove('is-locked'); lockEl.hidden = true; wake();
  });

  /* =====================================================================
     speed
     ===================================================================== */
  const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
  function buildSpeed() {
    $('#speedList').innerHTML = SPEEDS.map((s) => `<button class="sp-dot${s === curSpeed ? ' on' : ''}" data-speed="${s}"><i></i><span>${s}x${s === 1 ? ' (Normal)' : ''}</span></button>`).join('');
    $('#actSpeed').textContent = curSpeed + 'x';
  }
  function setSpeed(s) { curSpeed = s; video.playbackRate = s; buildSpeed(); }
  $('#speedList').addEventListener('click', (e) => { const b = e.target.closest('[data-speed]'); if (b) { setSpeed(+b.dataset.speed); toast(`Speed ${b.dataset.speed}x`); } });
  video.addEventListener('ratechange', () => { if (video.playbackRate !== curSpeed) video.playbackRate = curSpeed; });
  buildSpeed();

  /* =====================================================================
     quality
     ===================================================================== */
  const cls = (h, w) => { const x = Math.max(w || 0, (h || 0) * 16 / 9); return x >= 3200 ? 2160 : x >= 2200 ? 1440 : x >= 1600 ? 1080 : x >= 1100 ? 720 : x >= 750 ? 480 : x >= 560 ? 360 : x ? 240 : 0; };
  const label = (h, w) => { const c = cls(h, w); return c === 2160 ? '4K' : c ? c + 'p' : ''; };
  const badgeFor = (h, w) => { const c = cls(h, w); return c >= 2160 ? '4K' : c >= 1080 ? 'HD' : ''; };
  const setBadge = (t) => $$('.qBadge').forEach((b) => { b.textContent = t; });
  function applyQualityPref() {
    if (!hls || !prefs.quality || prefs.quality === 'auto') return;
    const i = hls.levels.findIndex((l) => cls(l.height, l.width) === +prefs.quality);
    if (i >= 0) { hls.startLevel = i; hls.currentLevel = i; }
  }
  function buildQuality() {
    const ul = $('#qualityList');
    if (!ul) return;
    let html = '';
    if (D.qualities && D.qualities.length > 0) {
      html = D.qualities.map((q, idx) => {
        const isCur = (curQuality ? curQuality === q.quality : idx === 0);
        const sizeStr = q.size ? `<small style="margin-left:6px;opacity:0.8;">${esc(q.size)}</small>` : '';
        const hevcBadge = q.isHevc ? '<span style="font-size:10px;background:#10b981;color:#fff;padding:1px 4px;border-radius:3px;margin-left:4px;">HEVC</span>' : '';
        return `<li data-vega-q="${esc(q.quality)}" class="${isCur ? 'on' : ''}">${CHECK}${esc(q.quality)} ${hevcBadge}${sizeStr}</li>`;
      }).join('');
      ul.innerHTML = html;
      const activeQ = curQuality || (D.qualities[0] && D.qualities[0].quality) || 'HD';
      setBadge(activeQ);
      return;
    }
    if (hls && hls.levels.length) {
      const seen = new Map();
      hls.levels.forEach((l, i) => { const k = cls(l.height, l.width) || l.bitrate; if (!seen.has(k) || hls.levels[seen.get(k)].bitrate < l.bitrate) seen.set(k, i); });
      const idxs = Array.from(seen.values()).sort((a, b) => (hls.levels[b].height - hls.levels[a].height) || (hls.levels[b].bitrate - hls.levels[a].bitrate));
      const auto = hls.autoLevelEnabled;
      const cur = hls.levels[hls.currentLevel];
      html += `<li data-q="-1" class="${auto ? 'on' : ''}">${CHECK}Auto${auto && cur ? ` <small>${label(cur.height, cur.width) || Math.round(cur.bitrate / 1000) + ' kbps'}</small>` : ''}</li>`;
      idxs.forEach((i) => {
        const l = hls.levels[i];
        html += `<li data-q="${i}" class="${!auto && hls.currentLevel === i ? 'on' : ''}">${CHECK}${label(l.height, l.width) || 'Level ' + (i + 1)} <small>${(l.bitrate / 1e6).toFixed(1)} Mbps</small></li>`;
      });
      setBadge(cur ? badgeFor(cur.height, cur.width) : '');
    } else {
      const h = video.videoHeight;
      html += `<li class="on">${CHECK}${h ? label(h, video.videoWidth) : 'HD Direct'}</li>`;
      setBadge(badgeFor(h, video.videoWidth) || 'HD');
    }
    ul.innerHTML = html;
  }
  $('#qualityList').addEventListener('click', (e) => {
    const li = e.target.closest('li[data-vega-q], li[data-q]');
    if (!li) return;
    if (li.dataset.vegaQ) {
      const qual = li.dataset.vegaQ;
      if (qual === curQuality) return;
      curQuality = qual;
      toast('Switching Quality: ' + qual);
      const curT = video.currentTime || 0;
      root.classList.add('is-loading');
      fetch(`api.php?action=stream&type=${D.type}&id=${D.id}&s=${D.s || 1}&e=${D.e || 1}&quality=${encodeURIComponent(qual)}&url=${encodeURIComponent(D.vegaUrl || '')}`)
        .then(r => r.json())
        .then(data => {
          if (data.sources && data.sources.length) {
            D.sources = data.sources;
            load(0, curT);
            buildServers();
            buildQuality();
            buildAudio();
          } else {
            root.classList.remove('is-loading');
            toast('Quality stream not ready');
          }
        })
        .catch(() => {
          root.classList.remove('is-loading');
          toast('Failed to switch quality');
        });
      if (touch) setTimeout(closePanels, 250);
      return;
    }
    if (hls && li.dataset.q !== undefined) {
      const i = +li.dataset.q;
      if (i < 0) {
        hls.currentLevel = -1;
        prefs.quality = 'auto';
        toast('Quality: Auto (Adaptive)');
      } else {
        const l = hls.levels[i];
        hls.currentLevel = i;
        hls.loadLevel = i;
        prefs.quality = cls(l.height, l.width);
        toast('Quality: ' + label(l.height, l.width));
      }
      savePrefs();
      buildQuality();
      if (touch) setTimeout(closePanels, 250);
    }
  });
  video.addEventListener('loadedmetadata', buildQuality);

  /* =====================================================================
     servers (Direct Vega Driver Switcher)
     ===================================================================== */
  function buildServers() {
    const uls = [$('#serverList'), $('#serverListDirect')].filter(Boolean);
    if (!uls.length || !D.sources) return;

    const curSource = D.sources[srcIdx] || D.sources[0];
    const srvBadge = $('.srvBadge');
    if (srvBadge) {
      srvBadge.textContent = 'DIRECT';
      srvBadge.style.background = '#22c55e';
    }
    const actServer = $('#actServer');
    if (actServer && curSource) {
      actServer.textContent = curSource.server || 'Server';
    }

    let html = D.sources.map((s, idx) => {
      const isCur = (srcIdx === idx);
      const badge = '<span style="font-size:11px;background:#22c55e;color:#fff;padding:2px 6px;border-radius:4px;margin-left:8px;font-weight:700;">DIRECT</span>';
      const name = s.label || s.server || ('Server ' + (idx + 1));
      const subInfo = (s.lang && !name.includes(s.lang)) ? `<small style="display:block;opacity:0.7;font-size:12px;margin-top:2px;">${esc(s.lang)}</small>` : '';
      return `<li data-srv="${idx}" class="${isCur ? 'on' : ''}">${CHECK}<div style="flex:1;"><b>${esc(name)}</b> ${badge}${subInfo}</div></li>`;
    }).join('');

    uls.forEach(ul => { ul.innerHTML = html; });
    buildVegaReleases();
  }

  function buildVegaReleases() {
    const wrap = $('#vegaReleasesWrap');
    const ul = $('#vegaReleasesList');
    if (!wrap || !ul) return;
    if (!D.vegaReleases || D.vegaReleases.length <= 1) {
      wrap.hidden = true;
      return;
    }
    wrap.hidden = false;
    ul.innerHTML = D.vegaReleases.map((rel) => {
      const isCur = D.vegaUrl && (D.vegaUrl === rel.fullUrl || D.vegaUrl.includes(rel.permalink));
      return `<li data-vega-url="${esc(rel.fullUrl)}" style="cursor:pointer;padding:8px 10px;border-radius:8px;background:${isCur ? 'rgba(34,197,94,0.18)' : 'rgba(255,255,255,0.06)'};border:${isCur ? '1px solid #22c55e' : '1px solid rgba(255,255,255,0.08)'};display:flex;align-items:center;gap:10px;">
        <span style="font-size:12px;color:#fff;font-weight:${isCur ? '700' : '500'};flex:1;line-height:1.3;">${esc(rel.title)}</span>
        <span style="font-size:11px;color:#22c55e;font-weight:700;">${isCur ? 'PLAYING' : 'SELECT'}</span>
      </li>`;
    }).join('');
  }

  $('#vegaReleasesList')?.addEventListener('click', (e) => {
    const li = e.target.closest('li[data-vega-url]');
    if (!li) return;
    const url = li.dataset.vegaUrl;
    if (url === D.vegaUrl) return;
    toast('Loading Release from VegaMovies...');
    location.href = `watch.php?url=${encodeURIComponent(url)}`;
  });

  function handleServerClick(e) {
    const li = e.target.closest('li[data-srv]');
    if (!li || !D.sources) return;
    const idx = +li.dataset.srv;
    if (idx !== srcIdx) {
      const s = D.sources[idx];
      toast('Switching: ' + (s.label || s.server || 'Server'));
      const curT = video.currentTime || 0;
      load(idx, curT);
      buildServers();
      buildAudio();
      closePanels();
    }
  }

  $('#serverList')?.addEventListener('click', handleServerClick);
  $('#serverListDirect')?.addEventListener('click', handleServerClick);

  /* =====================================================================
     audio tracks
     ===================================================================== */
  function buildAudio() {
    const ul = $('#audioList');
    const ulSettings = $('#settingAudioList');
    let html = '';

    if (D.audioTracks && D.audioTracks.length > 0) {
      html = D.audioTracks.map((trk, i) => {
        const isCurrent = (curAudioIdx === i);
        return `<li data-vega-audio="${i}" class="${isCurrent ? 'on' : ''}">${CHECK}${esc(trk)}</li>`;
      }).join('');
    } else if (D.sources && D.sources.length > 1) {
      html = D.sources.map((s, idx) => {
        const isCurrent = (srcIdx === idx);
        const name = s.label || s.lang || ('Audio Track ' + (idx + 1));
        return `<li data-src-idx="${idx}" class="${isCurrent ? 'on' : ''}">${CHECK}${esc(name)}</li>`;
      }).join('');
    } else if (hls && hls.audioTracks && hls.audioTracks.length > 1) {
      const tracks = hls.audioTracks.map((t, i) => ({ i, name: t.name || t.lang || 'Track ' + (i + 1), on: hls.audioTrack === i }));
      const seen = new Set();
      const filtered = tracks.filter((t) => (t.on || !seen.has(t.name)) && seen.add(t.name));
      html = filtered.map((t) => `<li data-a="${t.i}" class="${t.on ? 'on' : ''}">${CHECK}${esc(t.name)}</li>`).join('');
    } else if (video.audioTracks && video.audioTracks.length > 1) {
      const tracks = Array.from(video.audioTracks).map((t, i) => ({ i, name: t.label || t.language || 'Track ' + (i + 1), on: t.enabled }));
      html = tracks.map((t) => `<li data-a="${t.i}" class="${t.on ? 'on' : ''}">${CHECK}${esc(t.name)}</li>`).join('');
    } else {
      const defName = (D.sources && D.sources[0] && (D.sources[0].label || D.sources[0].lang)) || 'Original / Default';
      html = `<li class="on">${CHECK}${esc(defName)}</li>`;
    }

    if (ul) ul.innerHTML = html;
    if (ulSettings) ulSettings.innerHTML = html;
  }

  function handleAudioSelection(e) {
    const li = e.target.closest('[data-vega-audio], [data-src-idx], [data-a]');
    if (!li) return;
    if (li.dataset.vegaAudio !== undefined) {
      curAudioIdx = +li.dataset.vegaAudio;
      toast('Audio: ' + D.audioTracks[curAudioIdx]);
      if (video.audioTracks && video.audioTracks.length > curAudioIdx) {
        Array.from(video.audioTracks).forEach((t, j) => { t.enabled = (j === curAudioIdx); });
      }
      buildAudio();
      if (touch) setTimeout(closePanels, 250);
      return;
    }
    if (li.dataset.srcIdx !== undefined) {
      const idx = +li.dataset.srcIdx;
      if (idx !== srcIdx) {
        const curT = video.currentTime || 0;
        toast('Switching Audio: ' + (D.sources[idx].label || D.sources[idx].lang));
        load(idx, curT);
        buildServers();
        buildAudio();
      }
      return;
    }
    if (li.dataset.a !== undefined) {
      const i = +li.dataset.a;
      if (hls) hls.audioTrack = i;
      else if (video.audioTracks) Array.from(video.audioTracks).forEach((t, j) => { t.enabled = j === i; });
      buildAudio();
      buildServers();
    }
  }

  $('#audioList')?.addEventListener('click', handleAudioSelection);
  $('#settingAudioList')?.addEventListener('click', handleAudioSelection);

  /* =====================================================================
     subtitles (custom Netflix-style renderer)
     ===================================================================== */
  let sub = { kind: 'off' }, lastSubHtml = '';
  let extCues = [], extSubLoading = false;

  function parseTime(s) {
    if (!s) return 0;
    s = s.trim().replace(',', '.');
    const parts = s.split(':');
    if (parts.length === 3) {
      return (parseFloat(parts[0]) || 0) * 3600 + (parseFloat(parts[1]) || 0) * 60 + (parseFloat(parts[2]) || 0);
    } else if (parts.length === 2) {
      return (parseFloat(parts[0]) || 0) * 60 + (parseFloat(parts[1]) || 0);
    }
    return parseFloat(s) || 0;
  }

  function parseSrtOrVtt(text) {
    const cues = [];
    const normalized = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    const blocks = normalized.split(/\n\n+/);
    for (const block of blocks) {
      const lines = block.trim().split('\n');
      if (!lines.length) continue;
      let timeLineIdx = -1;
      for (let i = 0; i < lines.length; i++) {
        if (lines[i].includes('-->')) { timeLineIdx = i; break; }
      }
      if (timeLineIdx === -1) continue;
      const parts = lines[timeLineIdx].split('-->');
      if (parts.length !== 2) continue;
      const startStr = parts[0].trim().split(/\s+/)[0];
      const endStr = parts[1].trim().split(/\s+/)[0];
      const start = parseTime(startStr);
      const end = parseTime(endStr);
      const textLines = lines.slice(timeLineIdx + 1).join('\n').trim();
      if (textLines && end > start) cues.push({ start, end, text: textLines });
    }
    return cues;
  }

  function loadExternalSub(url) {
    extSubLoading = true;
    extCues = [];
    fetch(url)
      .then((r) => r.text())
      .then((t) => {
        extCues = parseSrtOrVtt(t);
        extSubLoading = false;
        renderSubs();
      })
      .catch(() => {
        extSubLoading = false;
      });
  }

  function subOptions() {
    const out = [];
    if (hls && hls.subtitleTracks) hls.subtitleTracks.forEach((t, i) => out.push({ kind: 'hls', i, name: t.name || t.lang || 'Track ' + (i + 1), lang: (t.lang || '').toLowerCase() }));
    else if (!hls) Array.from(video.textTracks || []).forEach((t, i) => { if (/subtitles|captions/.test(t.kind)) out.push({ kind: 'native', i, name: t.label || t.language || 'Track ' + (i + 1), lang: (t.language || '').toLowerCase() }); });
    if (D.subtitles && Array.isArray(D.subtitles)) {
      D.subtitles.forEach((s, i) => {
        out.push({ kind: 'external', i, name: s.label || s.lang, lang: (s.lang || '').toLowerCase(), url: s.url });
      });
    }
    return out;
  }
  function buildSubs() {
    const opts = subOptions();
    const html = `<li data-sk="off" class="${sub.kind === 'off' ? 'on' : ''}">${CHECK}Off</li>` +
      opts.map((o) => `<li data-sk="${o.kind}" data-si="${o.i}" class="${sub.kind === o.kind && sub.i === o.i ? 'on' : ''}">${CHECK}${esc(o.name)}</li>`).join('');
    $('#subList').innerHTML = html;
    const l2 = $('#subList2'); if (l2) l2.innerHTML = html;
  }
  function restoreSubPref() {
    if (sub.kind !== 'off' || !prefs.subLang) return;
    const o = subOptions().find((x) => x.lang && (x.lang === prefs.subLang || x.lang.startsWith(prefs.subLang)));
    if (o) setSub(o.kind, o.i, true);
  }
  function setSub(kind, i, silent) {
    sub = kind === 'off' ? { kind: 'off' } : { kind, i: +i };
    lastSubHtml = ''; subsBox.innerHTML = '';
    extCues = [];
    if (hls) { hls.subtitleTrack = kind === 'hls' ? +i : -1; hls.subtitleDisplay = false; }
    Array.from(video.textTracks || []).forEach((t, j) => {
      if (kind === 'native') t.mode = j === +i ? 'hidden' : 'disabled';
      else if (kind !== 'hls') t.mode = 'disabled';
    });
    const opt = subOptions().find((o) => o.kind === kind && o.i === +i);
    if (kind === 'external' && opt && opt.url) {
      loadExternalSub(opt.url);
    }
    if (!silent) {
      prefs.subLang = kind === 'off' ? '' : (opt && opt.lang) || ''; savePrefs();
      toast(kind === 'off' ? 'Subtitles off' : 'Subtitles: ' + (opt ? opt.name : ''));
    }
    buildSubs();
    $('#btnCC')?.classList.toggle('on', sub.kind !== 'off');
    renderSubs();
  }
  $('#btnCC')?.addEventListener('click', (e) => { e.stopPropagation(); cycleSubs(); wake(); });
  $('#subList').addEventListener('click', (e) => { const li = e.target.closest('[data-sk]'); if (li) setSub(li.dataset.sk, li.dataset.si); });
  $('#subList2')?.addEventListener('click', (e) => { e.stopPropagation(); const li = e.target.closest('[data-sk]'); if (li) setSub(li.dataset.sk, li.dataset.si); });
  // settings panel tabs (desktop): Quality · Subtitles · Speed
  const stPanel = $('#panel-settings');
  stPanel && stPanel.addEventListener('click', (e) => {
    const t = e.target.closest('.st-tab');
    if (t) {
      e.stopPropagation();
      $$('.st-tab', stPanel).forEach((x) => x.classList.toggle('on', x === t));
      $$('[data-pane]', stPanel).forEach((p) => p.classList.toggle('on', p.dataset.pane === t.dataset.st));
      return;
    }
    if (e.target.closest('.st-subset')) { e.stopPropagation(); openPanel('subs', $('.nfp-right [data-panel="subs"]')); }
  });
  function cleanCue(t) {
    return esc(t.replace(/<(?!\/?(i|b|u)>)[^>]+>/g, '').replace(/\{\\[^}]*\}/g, ''))
      .replace(/&lt;(\/?)(i|b|u)&gt;/g, '<$1$2>').split('\n').map((l) => `<span>${l}</span>`).join('<br>');
  }
  function activeCueTexts() {
    if (sub.kind === 'off') return [];
    if (sub.kind === 'external') {
      const cur = video.currentTime || 0;
      return extCues.filter((c) => cur >= c.start && cur <= c.end).map((c) => c.text);
    }
    const all = Array.from(video.textTracks || []);
    let tr;
    if (sub.kind === 'native') {
      tr = all[sub.i];
    } else {
      const want = hls && hls.subtitleTracks && hls.subtitleTracks[sub.i];
      if (!want) return [];
      const subsOnly = all.filter((x) => /subtitles|captions/.test(x.kind));
      tr = subsOnly.find((x) => x.label === want.name && (!want.lang || !x.language || x.language === want.lang)) || subsOnly.find((x) => x.label === want.name);
    }
    if (!tr || !tr.activeCues) return [];
    return Array.from(tr.activeCues).map((c) => c.text || '');
  }
  function renderSubs() {
    if (sub.kind === 'off') { if (lastSubHtml && lastSubHtml !== '__sample__') { subsBox.innerHTML = ''; lastSubHtml = ''; } return; }
    const html = activeCueTexts().map(cleanCue).join('<br>');
    if (html !== lastSubHtml) { subsBox.innerHTML = html; lastSubHtml = html; }
  }
  video.addEventListener('timeupdate', renderSubs);
  video.addEventListener('seeked', renderSubs);
  const sizeBtns = $$('#subSize button');
  function setSize(sz) { root.classList.remove('sub-s', 'sub-l'); if (sz !== 'm') root.classList.add('sub-' + sz); sizeBtns.forEach((b) => b.classList.toggle('on', b.dataset.size === sz)); prefs.subSize = sz; savePrefs(); }
  let sampleT = 0;
  function sampleSub() {
    if (sub.kind !== 'off' && lastSubHtml) return;
    subsBox.innerHTML = '<span>This is how subtitles will look.</span>'; lastSubHtml = '__sample__';
    clearTimeout(sampleT); sampleT = setTimeout(() => { if (lastSubHtml === '__sample__') { subsBox.innerHTML = ''; lastSubHtml = ''; } }, 2200);
  }
  sizeBtns.forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); setSize(b.dataset.size); sampleSub(); }));
  setSize(prefs.subSize || 'm');
  const styleBtns = $$('#subStyle button');
  function setSubStyle(st) {
    root.classList.remove('subst-box', 'subst-yellow');
    if (st && st !== 'shadow') root.classList.add('subst-' + st);
    styleBtns.forEach((b) => b.classList.toggle('on', b.dataset.style === (st || 'shadow')));
    prefs.subStyle = st || 'shadow'; savePrefs();
  }
  styleBtns.forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); setSubStyle(b.dataset.style); sampleSub(); }));
  setSubStyle(prefs.subStyle || 'shadow');
  function cycleSubs() {
    const opts = subOptions();
    if (!opts.length) { toast('No subtitles available'); return; }
    if (sub.kind === 'off') {
      const o = opts.find((x) => prefs.lastSubLang && x.lang === prefs.lastSubLang) || opts.find((x) => /^en/.test(x.lang)) || opts[0];
      setSub(o.kind, o.i);
    } else { prefs.lastSubLang = prefs.subLang; setSub('off'); }
  }

  /* =====================================================================
     skip intro / next episode / end screen
     ===================================================================== */
  const skipBtn = $('#nfpSkip'), upNext = $('#nfpUpNext');
  let upNextDismissed = false, upTimer = 0, creditsToast = false;
  function checkMarkers() {
    const t = video.currentTime, d = video.duration || 0;
    if (D.intro && d > D.intro[1]) setSkip(t >= D.intro[0] && t < D.intro[1] - 1, t);
    if (upNext && d > 60 && !upNextDismissed) {
      const at = D.credits && D.credits < d - 5 ? D.credits : d - 25;
      if (t >= at && upNext.hidden && !video.paused) showUpNext();
      else if (t < at - 2 && !upNext.hidden) hideUpNext();
    }
    if (!D.next && D.credits && d > D.credits && t >= D.credits && !creditsToast && !video.paused) { creditsToast = true; toast('Credits'); }
  }
  const skFill = $('#skFill');
  function setSkip(show, t) {
    if (show) {
      if (skipBtn.hidden || skipBtn.classList.contains('leaving')) { skipBtn.classList.remove('leaving'); skipBtn.hidden = false; root.classList.add('has-skip'); }
      skFill.style.width = clamp((t - D.intro[0]) / (D.intro[1] - 1 - D.intro[0]), 0, 1) * 100 + '%';
    } else if (!skipBtn.hidden && !skipBtn.classList.contains('leaving')) {
      skipBtn.classList.add('leaving');
      root.classList.remove('has-skip');
      setTimeout(() => { if (skipBtn.classList.contains('leaving')) { skipBtn.hidden = true; skipBtn.classList.remove('leaving'); } }, 280);
    }
  }
  skipBtn.addEventListener('click', (e) => { e.stopPropagation(); if (D.intro) seekTo(D.intro[1]); setSkip(false); });
  function showUpNext() {
    if (!upNext) return;
    upNext.hidden = false; root.classList.add('has-upnext');
    const fill = $('#unFill');
    if (prefs.autoNext === false) { fill.style.width = '0'; return; }
    const total = 10000, t0 = Date.now();
    clearInterval(upTimer);
    upTimer = setInterval(() => {
      const p = (Date.now() - t0) / total;
      fill.style.width = Math.min(100, p * 100) + '%';
      if (p >= 1) { clearInterval(upTimer); goNext(); }
    }, 100);
  }
  function hideUpNext() { if (upNext) { upNext.hidden = true; clearInterval(upTimer); root.classList.remove('has-upnext'); } }
  $('#unCredits')?.addEventListener('click', (e) => { e.stopPropagation(); upNextDismissed = true; hideUpNext(); });
  upNext?.addEventListener('click', (e) => e.stopPropagation());
  function goNext() { if (D.next) { saveNow(); go(D.next.url); } }
  $('#btnNext')?.addEventListener('click', (e) => { e.preventDefault(); goNext(); });
  $('#unGo')?.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); goNext(); });
  $('#nfpNextPill')?.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); goNext(); });
  $('#actNext')?.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); goNext(); });

  function onEnded() {
    saveHist(video.duration, video.duration);
    if (D.next) { if (prefs.autoNext !== false) goNext(); else showUpNext(); return; }
    root.classList.add('is-ended'); root.classList.remove('is-idle');
    $('#endGrid').innerHTML = (D.recs || []).map((r) => `<a class="end-card" href="${esc(r.url)}">${r.img ? `<img src="${esc(r.img)}" alt="" loading="lazy">` : ''}<b>${esc(r.title)}</b></a>`).join('');
    $('#nfpEnd').hidden = false;
  }
  $('#endReplay').addEventListener('click', () => { $('#nfpEnd').hidden = true; root.classList.remove('is-ended'); video.currentTime = 0; video.play().catch(() => {}); });

  function showResume(t) {
    const box = $('#nfpResume');
    $('#nfpResumeText').textContent = 'Resumed from ' + fmt(t);
    box.hidden = false;
    clearTimeout(box._t); box._t = setTimeout(() => { box.hidden = true; }, 6000);
  }
  $('#nfpStartOver').addEventListener('click', (e) => { e.stopPropagation(); seekTo(0); $('#nfpResume').hidden = true; video.play().catch(() => {}); });

  /* =====================================================================
     help + keyboard
     ===================================================================== */
  const help = $('#nfpHelp');
  $('#helpBtn')?.addEventListener('click', (e) => { e.stopPropagation(); help.hidden = !help.hidden; });
  $('#helpClose').addEventListener('click', () => { help.hidden = true; });
  help.addEventListener('click', (e) => { if (e.target === help) help.hidden = true; });

  const isPlaying = () => document.body.classList.contains('is-playing');
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (/input|select|textarea/i.test(document.activeElement.tagName)) return;
    const k = e.key;
    // details modal open → Esc closes it, everything else scrolls the page normally
    if (!isPlaying()) { if (k === 'Escape') { e.preventDefault(); leaveModal(); } return; }
    if (root.classList.contains('is-locked')) return;
    let handled = true;
    switch (k) {
      case ' ': case 'k': case 'K': togglePlay(); break;
      case 'ArrowLeft': case 'j': case 'J': skip(-SKIP, 'key'); break;
      case 'ArrowRight': case 'l': case 'L': skip(SKIP, 'key'); break;
      case 'ArrowUp': if (autoMuted) unmute(); setVol(video.volume + 0.1); video.muted = false; volPeek(); break;
      case 'ArrowDown': setVol(video.volume - 0.1); volPeek(); break;
      case 'm': case 'M': if (autoMuted) unmute(); else video.muted = !video.muted; volPeek(); break;
      case 'f': case 'F': toggleFs(); break;
      case 'c': case 'C': cycleSubs(); break;
      case 's': case 'S': if (!skipBtn.hidden) skipBtn.click(); else handled = false; break;
      case 'n': case 'N': if (D.next) goNext(); else handled = false; break;
      case 'p': case 'P': togglePip(); break;
      case '<': case ',': { const i = SPEEDS.indexOf(curSpeed); if (i > 0) { setSpeed(SPEEDS[i - 1]); toast(`Speed ${curSpeed}x`); } break; }
      case '>': case '.': { const i = SPEEDS.indexOf(curSpeed); if (i < SPEEDS.length - 1) { setSpeed(SPEEDS[i + 1]); toast(`Speed ${curSpeed}x`); } break; }
      case '?': help.hidden = !help.hidden; break;
      case 'Escape':
        if (!help.hidden) help.hidden = true;
        else if (panelOpen()) closePanels();
        else if (!fsEl()) closePlayer();
        break;
      default:
        if (/^[0-9]$/.test(k) && video.duration) seekTo((video.duration * +k) / 10);
        else handled = false;
    }
    if (handled) { e.preventDefault(); if (![' ', 'k', 'K', 'ArrowUp', 'ArrowDown', 'm', 'M'].includes(k) || video.paused) wake(); }
  });

  root.addEventListener('mousemove', (e) => { if (!touch && (e.movementX || e.movementY)) wake(); });
  root.addEventListener('mouseleave', () => { if (!touch && !video.paused) { clearTimeout(idleTimer); idleTimer = setTimeout(hideChrome, 800); } });
  $$('.nfp-bottom, .nfp-top, .nfp-panel').forEach((el) => {
    el.addEventListener('mouseenter', () => { if (!touch) clearTimeout(idleTimer); });
    el.addEventListener('pointerdown', () => { clearTimeout(idleTimer); });
    el.addEventListener('pointerup', () => wake());
  });
  $$('.nfp-bottom, .nfp-top').forEach((el) => el.addEventListener('click', (e) => e.stopPropagation()));
  $('#nfpClose').addEventListener('click', (e) => { e.stopPropagation(); closePlayer(); });
  $('#errBack').addEventListener('click', (e) => { e.stopPropagation(); closePlayer(); });
  $('#endInfo').addEventListener('click', (e) => { e.stopPropagation(); closePlayer(); });

  /* =====================================================================
     media session (lock screen / headset controls)
     ===================================================================== */
  if ('mediaSession' in navigator) {
    try {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: D.type === 'tv' ? `S${D.s}:E${D.e} ${D.epTitle || ''}` : D.title,
        artist: D.type === 'tv' ? D.title : String(D.year || ''),
        album: 'CINEFLIX',
        artwork: D.bg ? [{ src: D.bg, sizes: '1280x720', type: 'image/jpeg' }] : [],
      });
      navigator.mediaSession.setActionHandler('play', () => video.play());
      navigator.mediaSession.setActionHandler('pause', () => video.pause());
      navigator.mediaSession.setActionHandler('seekbackward', () => skip(-SKIP));
      navigator.mediaSession.setActionHandler('seekforward', () => skip(SKIP));
      if (D.next) navigator.mediaSession.setActionHandler('nexttrack', goNext);
    } catch { /* unsupported */ }
  }

  /* =====================================================================
     details section: play / my list / share
     ===================================================================== */
  const body = document.body;
  const mqMobile = matchMedia('(max-width: 759px)');
  let booted = false, resumeHref = '', playerOpened = false;
  const toMin = (x) => Math.max(1, Math.round(x / 60));
  function refreshResume() {
    const h = store.get('cf_history', {})[HKEY];
    let pct = 0, txt = '', rem = '', lbl = 'Play';
    // info mode (opened from a card): Play / Resume continues the last watched episode
    resumeHref = '';
    if (D.info && !playerOpened && D.type === 'tv' && h && h.s && h.e && (+h.s !== +D.s || +h.e !== +D.e)) {
      resumeHref = `watch.php?type=tv&id=${D.id}&s=${h.s}&e=${h.e}`;
      lbl = 'Resume';
      if (h.duration && h.progress > 20 && h.progress < h.duration - 60) {
        pct = (h.progress / h.duration) * 100;
        txt = `S${h.s}:E${h.e} · ${toMin(h.progress)} of ${toMin(h.duration)}m`;
        rem = `S${h.s}:E${h.e} · ${toMin(h.duration - h.progress)}m left`;
      } else lbl = `Play S${h.s}:E${h.e}`;
    } else if (h && h.duration && h.progress > 20 && h.progress < h.duration - 60 && (D.type === 'movie' || (+h.s === +D.s && +h.e === +D.e))) {
      pct = (h.progress / h.duration) * 100;
      txt = (D.type === 'tv' ? `S${D.s}:E${D.e} · ` : '') + `${toMin(h.progress)} of ${toMin(h.duration)}m`;
      rem = `${toMin(h.duration - h.progress)}m remaining`;
      lbl = 'Resume';
    }
    const set = (box, fill, text, t) => { const el = $(box); if (!el) return; el.hidden = !pct; $(fill).style.width = pct + '%'; $(text).textContent = t; };
    set('#wmProg', '#wmProgFill', '#wmProgText', txt);
    set('#wmProgM', '#wmProgFillM', '#wmProgTextM', rem);
    ['#wiPlayLabel', '#wiPlayLabelM'].forEach((id) => { const x = $(id); if (x) x.textContent = lbl; });
  }
  function boot() {
    booted = true;
    if (!D.sources || !D.sources.length) { showError('No video source found for this title.'); return; }
    if (!window.Hls && !video.canPlayType('application/vnd.apple.mpegurl')) { showError('Your browser cannot play this stream.'); return; }
    load(0, startAt);
  }
  /* v6: warm the stream up in the background while the info pop-up is open, so Play starts instantly
     (no loading screen) — manifest + first few seconds only, never autoplays */
  function preboot() {
    if (booted || playerOpened || resumeHref || !D.info || document.hidden) return;
    if (navigator.connection && navigator.connection.saveData) return;
    prebooting = true;
    boot();
  }
  function schedulePreboot(ms) { clearTimeout(prebootT); prebootT = setTimeout(preboot, ms); }
  /* open the full-screen player layer (from the modal's Play / Resume) */
  function openPlayer(gesture) {
    if (resumeHref) { go(resumeHref); return; }
    playerOpened = true;
    clearTimeout(prebootT);
    if (prebooting) { prebooting = false; if (hls) hls.config.maxBufferLength = 30; }
    stopTrailer();

    // 1. Instantly switch to playing mode so controls & Netflix spinner show without any delay
    body.classList.add('is-playing');
    root.classList.remove('need-play', 'is-ended');
    root.classList.add('is-loading');
    $('#nfpEnd').hidden = true;
    if (gesture) { try { history.pushState({ cfPlayer: 1 }, ''); } catch { /* ignore */ } }

    // 2. If sources are already present, start playback immediately
    if (D.sources && D.sources.length) {
      if (!booted) boot();
      else {
        const rp = resumePoint();
        if (video.ended || (rp && Math.abs(video.currentTime - rp) > 30)) seekTo(rp);
        video.play().catch(() => root.classList.add('need-play'));
      }
    } else {
      // 3. Ultra-fast dynamic stream resolution from API
      const sParam = D.s ? `&s=${D.s}` : '';
      const eParam = D.e ? `&e=${D.e}` : '';
      fetch(`api.php?action=stream&type=${D.type}&id=${D.id}${sParam}${eParam}`)
        .then(r => r.json())
        .then(res => {
          if (res && res.sources && res.sources.length) {
            D.sources = res.sources;
            if (res.subtitles) D.subtitles = res.subtitles;
            boot();
          } else {
            showError('No playable stream found.');
          }
        })
        .catch(() => showError('Failed to load streaming links.'));
    }

    // phones: playing from the details sheet goes straight to landscape full screen (like the Netflix app)
    if (gesture && touch && Math.min(innerWidth, innerHeight) < 600 && !fsEl() && (root.requestFullscreen || root.webkitRequestFullscreen)) toggleFs();
    sizeCheck(); wake();
    root.focus({ preventScroll: true });
  }
  /* close the player → back to the details modal with updated Resume progress */
  /* anti-redirect and anti-popup defense for embed players */
  let allowLeave = false;
  window.addEventListener('beforeunload', (e) => {
    if (root.classList.contains('is-embed-mode') && !allowLeave) {
      e.preventDefault();
      e.returnValue = '';
      return '';
    }
  });
  window.addEventListener('blur', () => {
    if (root.classList.contains('is-embed-mode')) {
      setTimeout(() => { try { window.focus(); } catch (e) {} }, 100);
    }
  });
  try {
    const origOpen = window.open;
    window.open = function(url, target, features) {
      if (root.classList.contains('is-embed-mode')) {
        console.warn('Blocked unauthorized popup/window.open attempt in embed mode:', url);
        return null;
      }
      return origOpen.apply(this, arguments);
    };
  } catch (e) { /* ignore */ }

  /* close the player → back to the details modal with updated Resume progress */
  function closePlayer(fromPop) {
    allowLeave = true;
    if (!isPlaying()) return;
    // pop-up over the browse page that went straight to playback → closing the player closes the whole pop-up
    if (EMBED && !D.info && !fromPop) { saveNow(); video.pause(); if (fsEl()) { try { (document.exitFullscreen || document.webkitExitFullscreen).call(document); } catch { /* ignore */ } } toParent({ cf: 'close' }); return; }
    saveNow();
    video.pause();
    closePanels(); help.hidden = true; hideUpNext();
    clearTimeout(pauseInfoTimer); root.classList.remove('show-pauseinfo');
    if (fsEl()) { try { (document.exitFullscreen || document.webkitExitFullscreen).call(document); } catch { /* ignore */ } }
    body.classList.remove('is-playing');
    if (!EMBED) setTimeout(addBehind, 400);
    refreshResume();
    if (!fromPop && history.state && history.state.cfPlayer) history.back();
  }
  window.addEventListener('popstate', (e) => {
    const want = !!(e.state && e.state.cfPlayer);
    if (isPlaying() && !want) closePlayer(true);
    else if (!isPlaying() && want) openPlayer(false);
  });
  function leaveModal() {
    allowLeave = true;
    saveNow();
    if (EMBED) { stopTrailer(); toParent({ cf: 'close' }); return; }
    let ref = '';
    let fromPopup = false;
    try { const u = new URL(document.referrer); if (u.origin === location.origin) { if (/watch\.php$/.test(u.pathname)) fromPopup = true; else ref = document.referrer; } } catch { /* no referrer */ }
    let home = 'index.php';
    try { home = sessionStorage.getItem('cf_from') || home; } catch { /* ignore */ }
    if (ref && history.length > 1) history.back();
    else location.href = fromPopup ? home : (ref || home);
  }
  $('#wmClose').addEventListener('click', leaveModal);
  $('#wmFClose').addEventListener('click', leaveModal);
  // click on the dimmed backdrop (outside the card) closes the modal on desktop
  $('#wm').addEventListener('click', (e) => { if (e.target === e.currentTarget && !mqMobile.matches) leaveModal(); });

  ['#wiPlay', '#wiPlayM', '#wmHeroPlay'].forEach((id) => $(id)?.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); openPlayer(true); }));
  $('#wmHero')?.addEventListener('click', (e) => {
    if (e.target.closest('#wmTrailerChip, #wmTrailerBox, .wm-chip-trailer, .wm-round, .wm-close, #wmClose')) return;
    openPlayer(true);
  });

  /* trailer inside the hero (YouTube, same as the title page) */
  const trailerId = $('#wmTrailerChip')?.dataset.yt;
  function playTrailer() {
    if (!trailerId) return;
    $('#wmTrailerBox').innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(trailerId)}?autoplay=1&rel=0&modestbranding=1&playsinline=1" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen title="Trailer"></iframe><button class="wm-trailer-x" aria-label="Close trailer">${$('#wmClose').innerHTML}</button>`;
    $('#wmHero').classList.add('has-trailer');
    $('.wm-trailer-x').addEventListener('click', (e) => { e.stopPropagation(); stopTrailer(); });
    if (mqMobile.matches) window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function stopTrailer() { const b = $('#wmTrailerBox'); if (b) b.innerHTML = ''; $('#wmHero')?.classList.remove('has-trailer'); }
  ['#wmTrailerChip', '#wiTrailerM'].forEach((id) => $(id)?.addEventListener('click', (e) => { e.preventDefault(); playTrailer(); }));

  /* my list / rate / share */
  const getList = () => store.get('cf_list', []);
  const inList = (it) => getList().some((x) => +x.id === +it.id && x.type === it.type);
  function toggleList(it) {
    let l = getList();
    const had = l.some((x) => +x.id === +it.id && x.type === it.type);
    l = had ? l.filter((x) => !(+x.id === +it.id && x.type === it.type)) : [{ ...it, added: Date.now() }, ...l];
    store.set('cf_list', l);
    wiToast(had ? 'Removed from My List' : 'Added to My List');
    return !had;
  }
  const wiToastEl = $('#wiToast');
  let wiT;
  function wiToast(m) { wiToastEl.textContent = m; wiToastEl.classList.add('show'); clearTimeout(wiT); wiT = setTimeout(() => wiToastEl.classList.remove('show'), 1800); }
  const listBtns = $$('#wiList, #wiListM');
  const syncList = () => { const on = inList(D.item); listBtns.forEach((b) => { b.classList.toggle('on', on); b.title = on ? 'Remove from My List' : 'Add to My List'; }); };
  listBtns.forEach((b) => b.addEventListener('click', () => { toggleList(D.item); syncList(); }));
  syncList();
  $$('.mlt-add[data-item]').forEach((b) => {
    let it; try { it = JSON.parse(b.dataset.item); } catch { return; }
    b.classList.toggle('on', inList(it));
    b.addEventListener('click', (e) => { e.preventDefault(); b.classList.toggle('on', toggleList(it)); });
  });
  const rateBtns = $$('#wiRate, #wiRateM');
  const syncRate = () => { const on = !!store.get('cf_rate', {})[HKEY]; rateBtns.forEach((b) => b.classList.toggle('on', on)); };
  rateBtns.forEach((b) => b.addEventListener('click', () => {
    const r = store.get('cf_rate', {});
    if (r[HKEY]) { delete r[HKEY]; wiToast('Rating removed'); } else { r[HKEY] = 1; wiToast('Rated: I like this'); }
    store.set('cf_rate', r); syncRate();
  }));
  syncRate();
  $('#wiShare')?.addEventListener('click', () => {
    const url = location.origin + location.pathname.replace(/watch\.php$/, '') + D.titleUrl;
    if (navigator.share) navigator.share({ title: D.title, url }).catch(() => {});
    else navigator.clipboard?.writeText(url).then(() => wiToast('Link copied'), () => wiToast(url));
  });

  /* mobile tabs: Episodes | More Like This | More Details */
  function selectTab(t) {
    if (!$(`#wmTabs [data-tab="${t}"]`)) return;
    $$('#wmTabs [data-tab]').forEach((x) => x.classList.toggle('on', x.dataset.tab === t));
    $$('.wm-pane').forEach((p) => p.classList.toggle('on', p.dataset.pane === t));
  }
  $$('#wmTabs [data-tab]').forEach((b) => b.addEventListener('click', () => selectTab(b.dataset.tab)));
  $$('[data-goto]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    if (mqMobile.matches) { selectTab('details'); $('#wmTabs').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    else $('#about').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));

  /* More Like This: collapsed to ~2 rows on desktop with a chevron */
  const mltWrap = $('#mltWrap');
  function mltMeasure() {
    if (!mltWrap) return;
    const grid = $('#mltGrid'), cards = $$('.mlt', grid);
    const cols = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length || 3;
    if (mqMobile.matches || cards.length <= cols * 3) { mltWrap.classList.add('no-collapse'); return; }
    mltWrap.classList.remove('no-collapse');
    const c = cards[cols * 2];
    mltWrap.style.setProperty('--mlt-h', (c.offsetTop + Math.min(150, c.offsetHeight * 0.45)) + 'px');
  }
  $('#mltToggle')?.addEventListener('click', () => {
    const col = mltWrap.classList.toggle('collapsed');
    $('#mltToggle').setAttribute('aria-label', col ? 'Show more' : 'Show less');
    if (col) $('#more').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  window.addEventListener('resize', () => requestAnimationFrame(mltMeasure));
  if (mqMobile.addEventListener) mqMobile.addEventListener('change', mltMeasure);
  window.addEventListener('load', mltMeasure);
  mltMeasure();

  /* More Like This details (poster, runtime, rating, description) – TMDB recommendations only carry a backdrop */
  let recAsked = false;
  function loadRecInfo() {
    const cards = $$('.mlt[data-pk]');
    if (recAsked || !cards.length) return;
    recAsked = true;
    fetch('api.php?action=recinfo&ids=' + encodeURIComponent(cards.map((c) => c.dataset.pk).join(','))).then((r) => r.json()).then((j) => {
      cards.forEach((c) => {
        const it = j.items && j.items[c.dataset.pk];
        if (!it) return;
        const rt = $('.mlt-rt', c);
        if (it.runtime) rt.textContent = it.runtime; else if (it.episodes) rt.textContent = it.episodes + ' Episodes';
        if (it.cert) { const ce = $('.mlt-cert', c); ce.textContent = it.cert; ce.hidden = false; }
        const len = $('.mlt-len', c);
        if (len) len.textContent = it.runtime || (it.episodes ? it.episodes + ' Episodes' : '');
        const lg = $('.mlt-logo', c);
        if (lg && it.logo && !mqMobile.matches) {
          const im = new Image(); im.alt = ''; im.decoding = 'async'; im.src = it.logo;
          im.onload = () => { lg.appendChild(im); requestAnimationFrame(() => lg.classList.add('on')); };
        }
        if (it.overview) $('.mlt-desc', c).textContent = it.overview;
        const ph = $('.mlt-po-t', c);
        if (ph && it.poster) {
          const im = new Image(); im.className = 'mlt-po'; im.alt = ''; im.decoding = 'async'; im.src = it.poster;
          im.onload = () => ph.replaceWith(im);
        }
      });
      mltMeasure();
    }).catch(() => { recAsked = false; });
  }
  setTimeout(loadRecInfo, 700);
  refreshResume();


  /* =====================================================================
     Netflix-style season selector (desktop dropdown · phone full-screen list)
     – enhances the native <select>, which stays the source of truth
     ===================================================================== */
  function seasonPicker(sel) {
    if (!sel || sel.options.length < 1) return;
    const wrap = sel.parentElement;
    wrap.classList.add('sp-enh');
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'sp-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    const lbl = () => { const o = sel.options[sel.selectedIndex]; return o ? o.textContent.replace(/\s*\(\d+ Episodes\)$/, '') : ''; };
    btn.innerHTML = `<span>${esc(lbl())}</span><svg viewBox="0 0 24 24" width="16" height="16"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
    wrap.appendChild(btn);
    const items = () => Array.from(sel.options).map((o) => `<button type="button" role="option" class="sp-it${o.selected ? ' on' : ''}" data-v="${esc(o.value)}" aria-selected="${o.selected}">${esc(o.textContent)}</button>`).join('');
    let pop = null;
    const phone = () => matchMedia('(max-width: 759px)').matches || (touch && Math.min(innerWidth, innerHeight) < 600);
    function close() {
      if (!pop) return;
      const p = pop; pop = null;
      p.classList.remove('open'); btn.classList.remove('open');
      setTimeout(() => p.remove(), 220);
    }
    function pick(v) {
      if (String(sel.value) !== String(v)) { sel.value = v; sel.dispatchEvent(new Event('change', { bubbles: true })); }
      btn.firstElementChild.textContent = lbl();
      close();
    }
    function open() {
      if (pop) { close(); return; }
      if (phone()) {
        pop = document.createElement('div');
        pop.className = 'sp-full';
        pop.innerHTML = `<div class="sp-list" role="listbox">${items()}</div><button type="button" class="sp-x" aria-label="Close"><svg viewBox="0 0 24 24" width="26" height="26"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg></button>`;
        (sel.closest('#nfp') || document.body).appendChild(pop);
        pop.addEventListener('click', (e) => { e.stopPropagation(); const it = e.target.closest('.sp-it'); if (it) pick(it.dataset.v); else if (e.target.closest('.sp-x') || e.target === pop) close(); });
      } else {
        pop = document.createElement('div');
        pop.className = 'sp-drop';
        pop.setAttribute('role', 'listbox');
        pop.innerHTML = items();
        wrap.appendChild(pop);
        pop.addEventListener('click', (e) => { e.stopPropagation(); const it = e.target.closest('.sp-it'); if (it) pick(it.dataset.v); });
      }
      btn.classList.add('open');
      requestAnimationFrame(() => {
        if (!pop) return;
        pop.classList.add('open');
        const on = pop.querySelector('.sp-it.on');
        if (on) on.scrollIntoView({ block: 'center' });
      });
    }
    btn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); open(); });
    sel.addEventListener('change', () => { btn.firstElementChild.textContent = lbl(); });
    document.addEventListener('click', (e) => { if (pop && !pop.contains(e.target) && !btn.contains(e.target)) close(); }, true);
    document.addEventListener('keydown', (e) => { if (pop && e.key === 'Escape') { e.stopPropagation(); close(); } }, true);
  }
  seasonPicker($('#wiSeason'));
  seasonPicker($('#peSeason'));

  /* long synopsis: clamp (4 lines desktop / 3 phone) – tap to expand */
  const ov = $('#wmOverview');
  if (ov) {
    const chk = () => ov.classList.toggle('can-more', ov.classList.contains('open') || ov.scrollHeight > ov.clientHeight + 2);
    requestAnimationFrame(chk); window.addEventListener('resize', chk);
    ov.addEventListener('click', () => { if (ov.classList.contains('can-more')) { ov.classList.toggle('open'); ov.title = ov.classList.contains('open') ? '' : 'Show more'; } });
  }

  /* pages prerendered from a card hover/tap: run the pop-up entry animation when the page is actually shown */
  if (document.prerendering) {
    document.documentElement.classList.add('is-prerender');
    document.addEventListener('prerenderingchange', () => {
      document.documentElement.classList.remove('is-prerender');
      refreshResume();
      if (!D.info) { boot(); wake(); }
    }, { once: true });
  }

  /* =====================================================================
     go
     ===================================================================== */
  try { history.replaceState({ cfModal: 1 }, ''); } catch { /* ignore */ }
  bootReady = true;
  if (EMBED) {
    // links inside the pop-up: other titles swap the pop-up in place; anything else (person, browse…) leaves to the top page
    document.addEventListener('click', (e) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = e.target.closest && e.target.closest('a[href]');
      if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
      const raw = a.getAttribute('href') || '';
      if (!raw || raw[0] === '#' || /^javascript:/i.test(raw)) return;
      let u; try { u = new URL(a.href); } catch { return; }
      if (u.origin !== location.origin) return;
      e.preventDefault();
      if (/watch\.php$/.test(u.pathname)) { saveNow(); go(u.href); }
      else window.top.location.href = u.href;
    });
    // the parent page reveals us (pop-up entry animation) — possibly long after we were preloaded in a hidden iframe
    let shown = false;
    window.cfShow = () => {
      if (shown) return; shown = true;
      body.classList.add('shown');
      if (D.info) schedulePreboot(1100);
      else if (bootReady && !booted) { boot(); wake(); root.focus({ preventScroll: true }); }
    };
    try { if (window.frameElement && window.frameElement.classList.contains('on')) window.cfShow(); } catch { /* ignore */ }
    toParent({ cf: 'ready' });
  } else if (D.info && !document.prerendering) {
    schedulePreboot(1300);
    if (document.readyState === 'complete') setTimeout(addBehind, 300); else addEventListener('load', () => setTimeout(addBehind, 300), { once: true });
  } else if (D.info) {
    document.addEventListener('prerenderingchange', () => schedulePreboot(1300), { once: true });
  }
  if (D.info) {
    // opened from a card: show the details pop-up / title sheet; the player boots on Play
    try {
      const u = new URL(D.sources?.[0]?.url || '', location.href);
      if (u.origin !== location.origin) { const l = document.createElement('link'); l.rel = 'preconnect'; l.href = u.origin; l.crossOrigin = ''; document.head.appendChild(l); }
    } catch { /* ignore */ }
  } else if (!document.prerendering && !booted && !(EMBED && !body.classList.contains('shown'))) {
    boot();
    wake();
    root.focus({ preventScroll: true });
  }
})();
