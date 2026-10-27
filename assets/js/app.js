/* ==========================================================
   CINEFLIX – front-end (vanilla JS, no dependencies)
   ========================================================== */
(() => {
  'use strict';

  const $  = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const IMG = (window.CF && CF.img) || 'https://image.tmdb.org/t/p/';
  const img = (p, size = 'w342') => (p ? IMG + size + p : '');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  const ICON = {
    play: '<span class="ico"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14z"/></svg></span>',
    plus: '<span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></span>',
    check: '<span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg></span>',
    down: '<span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>',
    close: '<span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></span>',
    user: '<span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" stroke-linecap="round"/></svg></span>',
  };

  /* ---------------- storage ---------------- */
  const store = {
    get(k, d) { try { const v = JSON.parse(localStorage.getItem(k)); return v ?? d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch {} },
  };
  const LIST_KEY = 'cf_list', HIST_KEY = 'cf_history', EPS_KEY = 'cf_eps', PREF_KEY = 'cf_prefs';

  const urls = {
    title: (t, id) => `watch.php?type=${t === 'tv' ? 'tv' : 'movie'}&id=${id}&info=1`,
    watch: (t, id, s, e) => t === 'tv' ? `watch.php?type=tv&id=${id}&s=${s || 1}&e=${e || 1}` : `watch.php?type=movie&id=${id}`,
  };

  /* ---------------- toast ---------------- */
  const toastEl = $('#toast');
  let toastT;
  function toast(msg, ok = true) {
    if (!toastEl) return;
    toastEl.innerHTML = (ok ? ICON.check : '') + '<span>' + esc(msg) + '</span>';
    toastEl.classList.add('show');
    clearTimeout(toastT);
    toastT = setTimeout(() => toastEl.classList.remove('show'), 2200);
  }

  /* ---------------- nav ---------------- */
  const nav = $('#nav');
  const onScroll = () => nav && nav.classList.toggle('scrolled', window.scrollY > 30);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------- live search (navbar) ---------------- */
  (function search() {
    const box = $('#searchBox'), input = $('#searchInput'), panel = $('#suggest');
    if (!box) return;
    const toggle = $('.search-toggle', box), clear = $('.search-clear', box);
    const cache = new Map();
    let ctrl, focusIdx = -1, lastQ = '';

    const open = () => { box.classList.add('open'); input.focus(); };
    const close = () => { if (!input.value) box.classList.remove('open'); panel.hidden = true; focusIdx = -1; };
    if (document.body.classList.contains('search-page')) box.style.display = 'none';

    toggle.addEventListener('click', () => box.classList.contains('open') && input.value ? box.submit() : open());
    clear.addEventListener('click', () => { input.value = ''; box.classList.remove('has-text'); panel.hidden = true; input.focus(); });
    document.addEventListener('click', (e) => { if (!box.contains(e.target)) close(); });
    document.addEventListener('keydown', (e) => {
      if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); open(); }
    });

    const hl = (text, q) => {
      const i = text.toLowerCase().indexOf(q.toLowerCase());
      return i < 0 ? esc(text) : esc(text.slice(0, i)) + '<mark>' + esc(text.slice(i, i + q.length)) + '</mark>' + esc(text.slice(i + q.length));
    };

    function render(data, q) {
      const items = data.items || [], people = data.people || [], vega = data.vega || [];
      if (!items.length && !people.length && !vega.length) {
        panel.innerHTML = `<div class="sg-empty">No results for “${esc(q)}”</div>`;
        return;
      }
      let h = '';

      // 1. VegaMovies Instant Releases Section
      if (vega.length) {
        h += '<h5 style="color:#22c55e;display:flex;align-items:center;gap:6px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg> VegaMovies Releases (Instant Play)</h5>';
        h += vega.map((v) => `
          <a class="sg-item sg-item-vega" href="${esc(v.watchUrl)}" style="border-left: 2px solid #22c55e;">
            ${v.thumbnail ? `<img src="${esc(v.thumbnail)}" alt="" loading="lazy">` : '<div class="sg-ph"></div>'}
            <div class="sg-body">
              <div class="sg-title" style="color:#fff;font-weight:700;">${hl(v.title, q)}</div>
              <div class="sg-sub" style="color:#22c55e;font-size:11px;">Direct Driver Streams &middot; Multi-Audio</div>
            </div>
            <span class="sg-play" style="background:#22c55e;" data-href="${esc(v.watchUrl)}" title="Watch Vega Release">${ICON.play}</span>
          </a>`).join('');
      }

      // 2. TMDB Movies & TV
      if (items.length) {
        h += '<h5>Movies &amp; TV (Cinema Catalog)</h5>';
        h += items.map((it) => `
          <a class="sg-item" href="${esc(it.url)}">
            ${it.poster ? `<img src="${esc(it.poster)}" alt="" loading="lazy">` : '<div class="sg-ph"></div>'}
            <div class="sg-body"><div class="sg-title">${hl(it.title, q)}</div>
              <div class="sg-sub">${it.type === 'tv' ? 'TV Series' : 'Movie'}${it.year ? ' · ' + esc(it.year) : ''}</div></div>
            <span class="sg-play" data-href="${esc(it.watch)}" title="Play">${ICON.play}</span>
          </a>`).join('');
      }
      if (people.length) {
        h += '<h5>People</h5>';
        h += people.map((p) => `
          <a class="sg-item person" href="${esc(p.url)}">
            ${p.profile ? `<img src="${esc(p.profile)}" alt="" loading="lazy">` : '<div class="sg-ph"></div>'}
            <div class="sg-body"><div class="sg-title">${hl(p.name, q)}</div><div class="sg-sub">${esc(p.dept)}${p.known ? ' · ' + esc(p.known) : ''}</div></div>
          </a>`).join('');
      }
      h += `<a class="sg-all" href="search.php?q=${encodeURIComponent(q)}">See all results for “${esc(q)}” →</a>`;
      panel.innerHTML = h;
      focusIdx = -1;
    }

    const run = debounce(async (q) => {
      if (q.length < 2) { panel.hidden = true; return; }
      if (cache.has(q)) { render(cache.get(q), q); panel.hidden = false; return; }
      panel.hidden = false;
      panel.innerHTML = '<div class="sg-loading"><div></div><div></div><div></div></div>';
      ctrl && ctrl.abort();
      ctrl = new AbortController();
      try {
        const r = await fetch('api.php?action=suggest&q=' + encodeURIComponent(q), { signal: ctrl.signal });
        const data = await r.json();
        cache.set(q, data);
        if (q === lastQ) render(data, q);
      } catch (err) {
        if (err.name !== 'AbortError') panel.innerHTML = '<div class="sg-empty">Search is unavailable right now.</div>';
      }
    }, 280);

    input.addEventListener('input', () => {
      const q = input.value.trim();
      lastQ = q;
      box.classList.toggle('has-text', !!input.value);
      run(q);
    });
    input.addEventListener('focus', () => { if (input.value.trim().length >= 2 && panel.innerHTML) panel.hidden = false; });
    input.addEventListener('keydown', (e) => {
      const items = $$('.sg-item', panel);
      if (e.key === 'Escape') { input.blur(); panel.hidden = true; return; }
      if (!items.length || panel.hidden) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        focusIdx = (focusIdx + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach((el, i) => el.classList.toggle('focus', i === focusIdx));
        items[focusIdx].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && focusIdx >= 0) {
        e.preventDefault();
        location.href = items[focusIdx].href;
      }
    });
    panel.addEventListener('click', (e) => {
      const p = e.target.closest('.sg-play');
      if (p) { e.preventDefault(); location.href = p.dataset.href; }
    });
    box.addEventListener('submit', (e) => { if (!input.value.trim()) e.preventDefault(); });
  })();

  /* ---------------- rows: arrows + lazy loading ---------------- */
  // Netflix-style paging: move exactly one "page" of whole cards with a smooth ease-in-out
  function glide(track, dir) {
    const first = track.querySelector('.card, .wcard, .ccard, .pcard');
    const gap = parseFloat(getComputedStyle(track).columnGap) || 10;
    const step = first ? first.getBoundingClientRect().width + gap : track.clientWidth * 0.85;
    const per = Math.max(1, Math.floor((track.clientWidth - 40) / step));
    const max = track.scrollWidth - track.clientWidth;
    const from = track.scrollLeft;
    const to = Math.max(0, Math.min(max, Math.round((from / step) + dir * per) * step));
    if (Math.abs(to - from) < 2) return;
    cancelAnimationFrame(track._glide);
    track.classList.add('gliding');
    const t0 = performance.now(), D = 720;
    const ease = (p) => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2);
    const tick = (n) => {
      const p = Math.min(1, (n - t0) / D);
      track.scrollLeft = from + (to - from) * ease(p);
      if (p < 1) track._glide = requestAnimationFrame(tick);
      else track.classList.remove('gliding');
    };
    track._glide = requestAnimationFrame(tick);
  }
  function initRow(row) {
    const track = $('.row-track', row), L = $('.row-arrow.left', row), R = $('.row-arrow.right', row);
    if (!track || row.dataset.ready) return;
    row.dataset.ready = '1';
    const update = () => {
      if (!L || !R) return;
      L.classList.toggle('hide', track.scrollLeft < 10);
      R.classList.toggle('hide', track.scrollLeft + track.clientWidth >= track.scrollWidth - 10);
    };
    L && L.addEventListener('click', () => glide(track, -1));
    R && R.addEventListener('click', () => glide(track, 1));
    track.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  }
  $$('.row').forEach(initRow);

  const lazyObs = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
    entries.forEach(async (en) => {
      if (!en.isIntersecting) return;
      const row = en.target;
      lazyObs.unobserve(row);
      try {
        const r = await fetch('api.php?action=row&key=' + encodeURIComponent(row.dataset.lazy));
        const d = await r.json();
        if (!d.html) { row.remove(); return; }
        $('.row-track', row).innerHTML = d.html;
        syncList(row);
        delete row.dataset.ready;
        initRow(row);
      } catch { row.remove(); }
    });
  }, { rootMargin: '1400px 0px' }) : null;
  $$('.row[data-lazy]').forEach((r) => lazyObs ? lazyObs.observe(r) : r.remove());

  /* ---------------- hero slider ---------------- */
  (function hero() {
    const hero = $('#hero');
    if (!hero) return;
    const slides = $$('.hero-slide', hero), thumbs = $$('.hero-thumb', hero);
    if (slides.length < 2) return;
    const dur = +hero.dataset.interval || 9000;
    hero.style.setProperty('--dur', dur + 'ms');
    let i = 0, t;
    const go = (n) => {
      slides[i].classList.remove('active'); thumbs[i] && thumbs[i].classList.remove('active');
      i = (n + slides.length) % slides.length;
      slides[i].classList.add('active');
      if (thumbs[i]) { thumbs[i].classList.remove('active'); void thumbs[i].offsetWidth; thumbs[i].classList.add('active'); }
      const im = $('.hero-bg img', slides[i]); im && im.removeAttribute('loading');
      schedule();
    };
    const schedule = () => { clearTimeout(t); t = setTimeout(() => { if (!hero.classList.contains('paused')) go(i + 1); else schedule(); }, dur); };
    thumbs.forEach((b) => b.addEventListener('click', () => go(+b.dataset.go)));
    hero.addEventListener('mouseenter', () => hero.classList.add('paused'));
    hero.addEventListener('mouseleave', () => { hero.classList.remove('paused'); schedule(); });
    document.addEventListener('visibilitychange', () => document.hidden ? clearTimeout(t) : schedule());
    // swipe on touch
    let sx = null;
    hero.addEventListener('touchstart', (e) => (sx = e.touches[0].clientX), { passive: true });
    hero.addEventListener('touchend', (e) => {
      if (sx === null) return;
      const dx = e.changedTouches[0].clientX - sx;
      if (Math.abs(dx) > 50) go(i + (dx < 0 ? 1 : -1));
      sx = null;
    });
    schedule();
  })();

  /* ---------------- My List ---------------- */
  const getList = () => store.get(LIST_KEY, []);
  const inList = (t, id) => getList().some((x) => x.type === t && +x.id === +id);
  function syncList(ctx = document) {
    $$('[data-list-toggle]', ctx).forEach((b) => {
      let it; try { it = JSON.parse(b.dataset.listToggle); } catch { return; }
      const on = inList(it.type, it.id);
      b.classList.toggle('in-list', on);
      const ico = b.querySelector('.ico');
      if (ico) ico.outerHTML = on ? ICON.check : ICON.plus;
      b.title = on ? 'Remove from My List' : 'Add to My List';
    });
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-list-toggle]');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    let it; try { it = JSON.parse(b.dataset.listToggle); } catch { return; }
    let list = getList();
    if (inList(it.type, it.id)) {
      list = list.filter((x) => !(x.type === it.type && +x.id === +it.id));
      toast('Removed from My List', false);
    } else {
      list.unshift({ ...it, added: Date.now() });
      toast('Added to My List');
    }
    store.set(LIST_KEY, list);
    syncList();
    renderMyListRow();
    renderMyListPage();
  });

  function cardHTML(it) {
    const meta = [`<span class="tag">${it.type === 'tv' ? 'Series' : 'Movie'}</span>`];
    if (it.year) meta.push(`<span>${esc(it.year)}</span>`);
    const json = esc(JSON.stringify({ id: it.id, type: it.type, title: it.title, poster: it.poster, backdrop: it.backdrop, year: it.year }));
    return `<div class="card" data-type="${it.type}" data-id="${it.id}"><div class="card-inner">
      <a class="card-img" href="${urls.title(it.type, it.id)}">${it.poster ? `<img loading="lazy" src="${img(it.poster)}" alt="${esc(it.title)}">` : `<div class="noimg"><span>${esc(it.title)}</span></div>`}</a>
      <div class="card-info"><div class="card-actions">
        <a class="cbtn cbtn-play" href="${urls.watch(it.type, it.id)}" title="Play">${ICON.play}</a>
        <button class="cbtn" data-list-toggle="${json}">${ICON.plus}</button>
        <a class="cbtn cbtn-more" href="${urls.title(it.type, it.id)}">${ICON.down}</a></div>
        <h4>${esc(it.title)}</h4><div class="card-meta">${meta.join('')}</div></div></div></div>`;
  }

  function renderMyListRow() {
    const row = $('#myListRow');
    if (!row) return;
    const list = getList();
    row.hidden = !list.length;
    $('.row-track', row).innerHTML = list.slice(0, 30).map(cardHTML).join('');
    syncList(row);
    delete row.dataset.ready; initRow(row);
  }

  let listFilter = 'all';
  function renderMyListPage() {
    const grid = $('#myListGrid');
    if (!grid) return;
    const list = getList().filter((x) => listFilter === 'all' || x.type === listFilter);
    grid.innerHTML = list.map(cardHTML).join('');
    $('#myListEmpty').hidden = list.length > 0;
    syncList(grid);
  }
  $$('#listFilter .chip').forEach((c) => c.addEventListener('click', () => {
    $$('#listFilter .chip').forEach((x) => x.classList.toggle('active', x === c));
    listFilter = c.dataset.f;
    renderMyListPage();
  }));

  /* ---------------- watch history / continue watching ---------------- */
  const getHist = () => store.get(HIST_KEY, {});
  const LOGO_KEY = 'cf_logos';
  function ccLogo(key, title) {
    const l = store.get(LOGO_KEY, {})[key];
    if (l) return `<img src="${esc(l)}" alt="" decoding="async">`;
    return l === '' ? `<b>${esc(title || '')}</b>` : ''; // no logo on TMDB → artwork-style title as a last resort
  }
  function histHTML(h) {
    const pct = h.duration ? Math.max(2, Math.min(100, (h.progress / h.duration) * 100)) : 3;
    const sub = h.type === 'tv' ? `S${h.s}:E${h.e}${h.epTitle ? ' · ' + esc(h.epTitle) : ''}` : (h.year || 'Movie');
    const key = `${h.type}:${h.id}`;
    const pic = h.backdrop ? img(h.backdrop, 'w780') : img(h.poster, 'w342');
    return `<div class="ccard" data-key="${key}">
      <a class="ccard-img" href="${urls.watch(h.type, h.id, h.s, h.e)}" aria-label="Resume ${esc(h.title)} – ${sub}" title="${esc(h.title)} · ${sub}">${pic ? `<img class="ccard-bd${h.backdrop ? '' : ' po'}" src="${pic}" alt="" loading="lazy" decoding="async">` : ''}
        <span class="ccard-logo">${ccLogo(key, h.title)}</span>
        <span class="ccard-bar"><i style="width:${pct}%"></i></span></a>
      <button class="ccard-x" title="Remove from Continue Watching">${ICON.close}</button></div>`;
  }
  // fetch missing title logos once (cached in localStorage) and drop them into the cards with a fade
  let logoBusy = false;
  function loadHistLogos() {
    const cache = store.get(LOGO_KEY, {});
    const need = [...new Set($$('.ccard').map((c) => c.dataset.key))].filter((k) => !(k in cache)).slice(0, 30);
    if (!need.length || logoBusy) return;
    logoBusy = true;
    fetch(`api.php?action=logos&ids=${encodeURIComponent(need.join(','))}`).then((r) => (r.ok ? r.json() : null)).then((j) => {
      if (!j || !j.items) return;
      const c = Object.assign(store.get(LOGO_KEY, {}), j.items);
      const keys = Object.keys(c); if (keys.length > 300) keys.slice(0, keys.length - 300).forEach((k) => delete c[k]);
      store.set(LOGO_KEY, c);
      $$('.ccard').forEach((card) => {
        const k = card.dataset.key, box = $('.ccard-logo', card);
        if (!box || box.childElementCount || !(k in j.items)) return;
        const h = getHist()[k];
        box.innerHTML = ccLogo(k, h && h.title);
        box.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 350, easing: 'ease-out' });
      });
    }).catch(() => {}).finally(() => { logoBusy = false; });
  }
  function renderHistory() {
    ['#continueRow', '#historyRow'].forEach((sel) => {
      const row = $(sel);
      if (!row) return;
      const all = Object.values(getHist()).sort((a, b) => b.updated - a.updated);
      const items = sel === '#continueRow'
        ? all.filter((h) => !(h.type === 'movie' && h.duration && h.progress / h.duration > 0.95)).slice(0, 20)
        : all;
      row.hidden = !items.length;
      $('.row-track', row).innerHTML = items.map(histHTML).join('');
      delete row.dataset.ready; initRow(row);
    });
    loadHistLogos();
  }
  document.addEventListener('click', (e) => {
    const x = e.target.closest('.ccard-x');
    if (x) {
      e.preventDefault();
      const key = x.closest('.ccard').dataset.key;
      const h = getHist(); delete h[key]; store.set(HIST_KEY, h);
      renderHistory();
      return;
    }
    if (e.target.closest('#clearHistory')) {
      if (confirm('Clear your watch history?')) { store.set(HIST_KEY, {}); store.set(EPS_KEY, {}); renderHistory(); }
    }
  });

  // "Play" buttons become "Resume / Continue S2:E4"
  function applyResume() {
    const hist = getHist();
    $$('[data-resume]').forEach((b) => {
      const h = hist[b.dataset.resume];
      if (!h) return;
      const label = b.querySelector('span:last-child');
      b.href = urls.watch(h.type, h.id, h.s, h.e);
      if (label) label.textContent = h.type === 'tv' ? `Continue S${h.s}:E${h.e}` : 'Resume';
      if (b.id === 'playBtn' && h.duration) {
        const bar = $('#resumeBar');
        if (bar) { bar.hidden = false; bar.firstElementChild.style.width = Math.min(100, (h.progress / h.duration) * 100) + '%'; }
      }
    });
  }

  function markEpisodes(ctx = document) {
    const eps = store.get(EPS_KEY, {});
    $$('.episode[data-s]', ctx).forEach((el) => {
      const list = el.closest('.episode-list');
      const show = list && list.dataset.show;
      const p = eps[`${show}:${el.dataset.s}:${el.dataset.e}`];
      if (p) { el.classList.add('watched'); el.querySelector('.ep-progress i').style.width = Math.max(4, p) + '%'; }
    });
  }

  /* ---------------- trailer modal ---------------- */
  const modal = $('#trailerModal'), frameBox = $('#trailerFrame');
  function closeModal() { if (!modal) return; modal.hidden = true; frameBox.innerHTML = ''; document.body.style.overflow = ''; }
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-trailer]');
    if (t) {
      e.preventDefault();
      frameBox.innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(t.dataset.trailer)}?autoplay=1&rel=0&modestbranding=1" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe>`;
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
    }
    if (e.target.closest('[data-close]')) closeModal();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  /* ---------------- share ---------------- */
  document.addEventListener('click', async (e) => {
    if (!e.target.closest('[data-share]')) return;
    const data = { title: document.title, url: location.href };
    try {
      if (navigator.share) await navigator.share(data);
      else { await navigator.clipboard.writeText(location.href); toast('Link copied to clipboard'); }
    } catch {}
  });

  /* ---------------- tabs (title page) ---------------- */
  $$('.tabs .tab').forEach((tab) => tab.addEventListener('click', () => {
    $$('.tabs .tab').forEach((t) => t.classList.toggle('active', t === tab));
    $$('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === 'tab-' + tab.dataset.tab));
  }));

  /* ---------------- season switch ---------------- */
  const seasonSel = $('#seasonSelect');
  if (seasonSel) {
    seasonSel.addEventListener('change', async () => {
      const list = $('#episodeList');
      const mode = seasonSel.dataset.mode || 'title';
      const s = seasonSel.value;
      const e = mode === 'watch' && s === seasonSel.dataset.cur ? seasonSel.dataset.e : 0;
      list.classList.add('loading');
      try {
        const r = await fetch(`api.php?action=season&id=${seasonSel.dataset.show}&s=${s}&mode=${mode}&e=${e}`);
        const d = await r.json();
        list.innerHTML = d.html;
        markEpisodes(list);
        const info = $('#seasonInfo'); if (info) info.innerHTML = '';
        const play = $('#playBtn');
        if (play && !getHist()[play.dataset.resume]) {
          play.href = urls.watch('tv', seasonSel.dataset.show, s, 1);
          play.querySelector('span:last-child').textContent = `Play S${s} E1`;
        }
      } catch { list.innerHTML = '<div class="empty-state small">Could not load episodes.</div>'; }
      list.classList.remove('loading');
    });
  }

  /* ---------------- full cast ---------------- */
  const fullCast = $('#fullCast');
  fullCast && fullCast.addEventListener('click', async () => {
    fullCast.disabled = true;
    fullCast.querySelector('span:last-child').textContent = 'Loading…';
    try {
      const r = await fetch(`api.php?action=credits&type=${fullCast.dataset.type}&id=${fullCast.dataset.id}`);
      const d = await r.json();
      if (d.html) $('#castGrid').innerHTML = d.html;
      if (d.crew) { $('#crewGrid').innerHTML = d.crew; $('#crewWrap').hidden = false; }
      fullCast.parentElement.remove();
    } catch { fullCast.disabled = false; fullCast.querySelector('span:last-child').textContent = 'Try again'; }
  });

  /* ---------------- infinite grid ---------------- */
  function initInfinite() {
    const grid = $('#grid[data-infinite]'), loader = $('#gridLoader');
    if (!grid || !loader || grid.dataset.inf) return;
    grid.dataset.inf = '1';
    let page = +grid.dataset.page || 1, total = +grid.dataset.total || 1, busy = false;
    if (page >= total) { loader.classList.add('done'); return; }
    const obs = new IntersectionObserver(async (en) => {
      if (!en[0].isIntersecting || busy) return;
      busy = true;
      try {
        const r = await fetch(grid.dataset.infinite + '&page=' + (page + 1));
        const d = await r.json();
        page++;
        if (d.html) { grid.insertAdjacentHTML('beforeend', d.html); syncList(grid); }
        if (!d.count || page >= (d.total_pages || total)) { loader.classList.add('done'); obs.disconnect(); }
      } catch { loader.classList.add('done'); obs.disconnect(); }
      busy = false;
    }, { rootMargin: '900px 0px' });
    obs.observe(loader);
  }
  initInfinite();

  /* ---------------- search page (live) ---------------- */
  (function searchPage() {
    const form = $('#bigSearch');
    if (!form) return;
    const input = $('#bigSearchInput'), typeInput = $('#bigSearchType'), spin = $('.big-search-spin', form);
    let ctrl;
    async function load(push = false) {
      const q = input.value.trim(), type = typeInput.value;
      const url = 'search.php?' + new URLSearchParams(q ? { q, type } : {}).toString();
      ctrl && ctrl.abort(); ctrl = new AbortController();
      spin.hidden = false;
      try {
        const html = await (await fetch(url, { signal: ctrl.signal })).text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        $('#searchResults').innerHTML = doc.querySelector('#searchResults').innerHTML;
        const tabs = doc.querySelector('#searchTabs');
        $('#searchTabs').innerHTML = tabs.innerHTML;
        $('#searchTabs').hidden = tabs.hasAttribute('hidden');
        bindTabs();
        history[push ? 'pushState' : 'replaceState'](null, '', url);
        document.title = doc.title;
        syncList($('#searchResults'));
        $$('#searchResults .row-track').forEach((t) => initRow(t.parentElement));
        initInfinite();
      } catch (e) { /* aborted */ }
      spin.hidden = true;
    }
    function bindTabs() {
      $$('#searchTabs .chip').forEach((c) => c.addEventListener('click', () => { typeInput.value = c.dataset.type; load(true); }));
    }
    bindTabs();
    const live = debounce(() => load(false), 450);
    input.addEventListener('input', live);
    form.addEventListener('submit', (e) => { e.preventDefault(); load(true); });
    const v = input.value; input.focus(); input.value = ''; input.value = v;
    window.addEventListener('popstate', (e) => { if (window.cfOvActive || (e.state && e.state.cfOverlay)) return; location.reload(); });
  })();

  /* ---------------- person page ---------------- */
  const bioT = $('#bioToggle');
  bioT && bioT.addEventListener('click', () => { const b = $('#bio'); b.classList.toggle('open'); bioT.textContent = b.classList.contains('open') ? 'Show less' : 'Read more'; });
  $$('#creditTabs .chip').forEach((c) => c.addEventListener('click', () => {
    $$('#creditTabs .chip').forEach((x) => x.classList.toggle('active', x === c));
    $$('.credits-list').forEach((l) => (l.hidden = l.dataset.dept !== c.dataset.dept));
  }));

  /* ---------------- broken images ---------------- */
  document.addEventListener('error', (e) => {
    const t = e.target;
    if (t.tagName !== 'IMG' || t.dataset.failed) return;
    t.dataset.failed = '1';
    const holder = t.closest('.card-img, .pcard-img, .wcard-img');
    if (holder) {
      const ph = document.createElement('div');
      ph.className = 'noimg' + (holder.classList.contains('pcard-img') ? ' person' : '');
      ph.innerHTML = holder.classList.contains('pcard-img') ? ICON.user : `<span>${esc(t.alt || '')}</span>`;
      t.replaceWith(ph);
    } else t.style.visibility = 'hidden';
  }, true);


  /* ---------------- Netflix hover preview card (desktop) ----------------
     Hover a poster → after ~0.4 s the poster itself morphs into the big card (one 16:9 artwork, logo, tag,
     Play / + / 👍 / ⌄, match · rating · seasons · HD, genres). Info + artwork are fetched & decoded while the
     pointer rests, so the card appears complete – no image swapping. Also warms the server cache for the pop-up. */
  (function hoverPreview() {
    const fine = matchMedia('(hover: hover) and (pointer: fine)');
    const EASE = 'cubic-bezier(.2,.8,.25,1)';
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const THUMB = '<span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"><path class="th-o" d="M7.5 10.5 11 3.6c1.4 0 2.3 1.1 2 2.5l-.7 3.4h5.4a2 2 0 0 1 2 2.4l-1.3 6.3a2 2 0 0 1-2 1.6H7.5Zm0 0H4.2v9.3h3.3"/></svg></span>';
    const hp = document.createElement('div');
    hp.className = 'hp';
    hp.setAttribute('aria-hidden', 'true');
    hp.innerHTML = `<a class="hp-img"><img class="hp-bd" alt=""><img class="hp-po" alt=""><span class="hp-logo"></span><span class="hp-tag"></span></a>
      <div class="hp-body">
        <div class="hp-btns">
          <a class="hp-b hp-play" title="Play">${ICON.play}</a>
          <button class="hp-b hp-list" title="Add to My List">${ICON.plus}</button>
          <button class="hp-b hp-rate" title="I like this">${THUMB}</button>
          <a class="hp-b hp-more" title="Episodes &amp; info">${ICON.down}</a>
        </div>
        <div class="hp-meta"></div>
        <div class="hp-genres"></div>
      </div>`;
    document.body.appendChild(hp);
    const hBd = $('.hp-bd', hp), hPo = $('.hp-po', hp), hLogo = $('.hp-logo', hp), hTag = $('.hp-tag', hp),
      hMeta = $('.hp-meta', hp), hGen = $('.hp-genres', hp), hBody = $('.hp-body', hp);
    const infoCache = new Map(), imgCache = new Map();
    let openT = 0, closeT = 0, preT = 0, cur = null, item = null, hoverCard = null, geo = null;
    const enabled = () => fine.matches && innerWidth >= 900;
    const sync = () => document.documentElement.classList.toggle('hp-on', enabled());
    sync(); window.addEventListener('resize', sync);

    const info = (t, id) => {
      const k = t + ':' + id;
      if (!infoCache.has(k)) infoCache.set(k, fetch(`api.php?action=hover&lite=1&type=${t}&id=${id}`).then((r) => (r.ok ? r.json() : null)).catch(() => null));
      return infoCache.get(k);
    };
    // load + decode an image once (never lazy – detached lazy images never load)
    const loadImg = (url) => {
      if (!url) return Promise.resolve(false);
      if (!imgCache.has(url)) imgCache.set(url, new Promise((res) => {
        const im = new Image();
        im.decoding = 'async';
        im.onload = () => (im.decode ? im.decode().catch(() => {}) : Promise.resolve()).then(() => res(true));
        im.onerror = () => res(false);
        im.src = url;
      }));
      return imgCache.get(url);
    };
    function cardItem(card) {
      let it = {};
      try { it = JSON.parse($('[data-list-toggle]', card).dataset.listToggle); } catch { /* none */ }
      it.type = card.dataset.type || it.type; it.id = +(card.dataset.id || it.id);
      return it;
    }
    // everything the card needs, fetched while the pointer rests on the poster
    function prep(card) {
      if (card._hp) return card._hp;
      const it = cardItem(card);
      const dP = info(it.type, it.id);
      const ready = (async () => {
        let bd = it.backdrop ? img(it.backdrop, 'w780') : '';
        const d = await (bd ? dP : dP.then((x) => { bd = (x && x.backdrop) || ''; return x; }));
        const ok = await loadImg(bd);
        if (d && d.logo) await Promise.race([loadImg(d.logo), wait(500)]);
        return { d, bd: ok ? bd : '' };
      })();
      return (card._hp = { it, ready });
    }
    const rateKey = (it) => `${it.type}:${it.id}`;
    function meta(d) {
      const m = [];
      if (d && d.match) m.push(`<span class="hp-match">${d.match}% match</span>`);
      if (d && d.cert) m.push(`<span class="hp-cert">${esc(d.cert)}</span>`);
      if (d && d.type === 'tv' && d.seasons) m.push(`<span>${d.seasons} Season${d.seasons > 1 ? 's' : ''}</span>`);
      else if (d && d.runtime) m.push(`<span>${esc(d.runtime)}</span>`);
      else if (item && item.year) m.push(`<span>${esc(item.year)}</span>`);
      m.push('<span class="hp-hd">HD</span>');
      return m.join('');
    }
    function fillInfo(it, res) {
      const d = res && res.d;
      hLogo.innerHTML = d && d.logo ? `<img src="${esc(d.logo)}" alt="${esc(it.title || '')}">` : `<b>${esc(it.title || '')}</b>`;
      if (d && d.tag) { hTag.textContent = d.tag; hTag.hidden = false; } else { hTag.textContent = ''; hTag.hidden = true; }
      hMeta.innerHTML = meta(d);
      hGen.innerHTML = d ? (d.genres || []).map((g) => `<span>${esc(g)}</span>`).join('<i></i>') : '';
      if (res && res.bd) { hBd.src = res.bd; hBd.hidden = false; }
    }
    function fill(card, it, res) {
      item = it;
      hBd.hidden = true; hBd.removeAttribute('src');
      const pi = $('.card-img img', card);
      hPo.src = (pi && pi.currentSrc) || img(it.poster, 'w342');
      fillInfo(it, res);
      const h = getHist()[`${it.type}:${it.id}`];
      $('.hp-play', hp).href = h && it.type === 'tv' ? urls.watch(it.type, it.id, h.s, h.e) : urls.watch(it.type, it.id);
      $('.hp-img', hp).href = $('.hp-more', hp).href = urls.title(it.type, it.id);
      $('.hp-list', hp).dataset.listToggle = JSON.stringify({ id: it.id, type: it.type, title: it.title, poster: it.poster, backdrop: it.backdrop, year: it.year });
      syncList(hp);
      $('.hp-rate', hp).classList.toggle('on', !!store.get('cf_rate', {})[rateKey(it)]);
    }
    const stopAnims = () => hp.getAnimations({ subtree: true }).forEach((a) => a.cancel());
    function open(card, job, res) {
      const ci = $('.card-img', card) || card;
      const r = ci.getBoundingClientRect();
      if (!r.width || r.bottom < 60 || r.top > innerHeight) return;
      clearTimeout(closeT);
      stopAnims();
      cur = card;
      const W = Math.round(Math.min(460, Math.max(310, r.width > r.height ? r.width * 1.5 : r.width * 2.15)));
      hp.style.width = W + 'px';
      fill(card, job.it, res);
      hp.classList.add('measure');
      const H = hp.offsetHeight, imgH = W * 9 / 16;
      hp.classList.remove('measure');
      const vw = document.documentElement.clientWidth;
      const left = Math.min(Math.max(r.left + r.width / 2 - W / 2, 14), vw - W - 14);
      const top = Math.min(Math.max(r.top + r.height / 2 - imgH / 2 - 26, 72), innerHeight - H - 10);
      // start state = exactly the poster's rectangle (cropped & scaled), end state = full card
      const sc = Math.max(r.width / W, r.height / imgH);
      const cw = r.width / sc, ch = r.height / sc, ix = (W - cw) / 2, iy = (imgH - ch) / 2;
      Object.assign(hPo.style, { left: ix + 'px', top: iy + 'px', width: cw + 'px', height: ch + 'px' });
      geo = {
        from: `translate(${r.left - left - ix * sc}px, ${r.top - top - iy * sc}px) scale(${sc})`,
        clipFrom: `inset(${iy}px ${ix}px ${H - iy - ch}px ${ix}px round ${6 / sc}px)`,
        clipTo: 'inset(0px 0px 0px 0px round 8px)',
      };
      hp.style.left = left + scrollX + 'px';
      hp.style.top = top + scrollY + 'px';
      hp.classList.add('show', 'open');
      hp.animate([{ transform: geo.from, clipPath: geo.clipFrom }, { transform: 'translate(0px, 0px) scale(1)', clipPath: geo.clipTo }],
        { duration: 360, easing: EASE, fill: 'forwards' });
      hBody.animate([{ opacity: 0, transform: 'translateY(-10px)' }, { opacity: 1, transform: 'none' }], { duration: 280, delay: 110, easing: EASE, fill: 'backwards' });
      const fadePoster = () => hPo.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 300, delay: 40, easing: 'ease', fill: 'forwards' });
      clearTimeout(preT);
      preT = setTimeout(() => { if (cur === card && window.cfPreload) window.cfPreload(urls.title(job.it.type, job.it.id)); }, 260);
      if (res && res.bd) fadePoster();
      else {
        hPo.style.opacity = 1;
        job.ready.then((rr) => {
          if (cur !== card || !rr) return;
          fillInfo(job.it, rr);
          [hLogo, hTag, hMeta, hGen].forEach((el) => el.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 240, easing: 'ease-out' }));
          if (rr.bd) fadePoster();
        });
      }
    }
    function close(now) {
      clearTimeout(openT);
      if (!cur) return;
      cur = null; item = null;
      hp.classList.remove('open');
      if (now || !geo) { stopAnims(); hp.classList.remove('show'); return; }
      stopAnims();
      // reverse morph: shrink back into the poster
      const a = hp.animate([{ transform: 'translate(0px, 0px) scale(1)', clipPath: geo.clipTo }, { transform: geo.from, clipPath: geo.clipFrom }],
        { duration: 250, easing: 'cubic-bezier(.4,0,.7,1)', fill: 'forwards' });
      hPo.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 170, easing: 'ease', fill: 'forwards' });
      hBody.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 120, fill: 'forwards' });
      a.onfinish = () => { if (!cur) { hp.classList.remove('show'); stopAnims(); } };
    }
    document.addEventListener('mouseover', (e) => {
      if (!enabled()) return;
      if (hp.contains(e.target)) { clearTimeout(closeT); return; }
      const card = e.target.closest('.row-track .card, .grid .card');
      if (!card) return;
      if (card === cur) { clearTimeout(closeT); return; }
      if (card === hoverCard) return;
      hoverCard = card;
      clearTimeout(openT);
      const job = prep(card);          // start fetching info + artwork right away
      const t0 = performance.now();
      openT = setTimeout(async () => {
        const res = await Promise.race([job.ready, wait(Math.max(0, 230 - (performance.now() - t0)))]);
        if (hoverCard !== card || !card.matches(':hover')) return;
        open(card, job, res);
      }, cur ? 60 : 170);
    });
    document.addEventListener('mouseout', (e) => {
      const to = e.relatedTarget;
      const fromCard = e.target.closest && e.target.closest('.card');
      if (fromCard && (!to || !fromCard.contains(to))) { if (hoverCard === fromCard) hoverCard = null; clearTimeout(openT); }
      if (!cur) return;
      if (to && (hp.contains(to) || cur.contains(to))) return;
      if (hp.contains(e.target) || cur.contains(e.target)) { clearTimeout(closeT); closeT = setTimeout(() => close(), 100); }
    });
    window.cfHoverClose = () => close(true);
    // pointer enters a row → warm the visible cards (info + 16:9 artwork), 2 at a time, so their cards open fully drawn
    const warmQ = [], warmed = new WeakSet();
    let warmRun = 0, warmRow = null;
    function pump() {
      while (warmRun < 2 && warmQ.length) {
        const c = warmQ.shift();
        if (!c.isConnected) continue;
        warmRun++;
        prep(c).ready.finally(() => { warmRun--; pump(); });
      }
    }
    document.addEventListener('mouseover', (e) => {
      if (!enabled()) return;
      const row = e.target.closest && e.target.closest('.row, .grid');
      if (!row || row === warmRow) return;
      warmRow = row;
      warmQ.length = 0;
      const vw = innerWidth;
      $$('.card', row).forEach((c) => {
        if (warmed.has(c) || c._hp) return;
        const r = c.getBoundingClientRect();
        if (r.right < 0 || r.left > vw || r.bottom < 0 || r.top > innerHeight) return;
        warmed.add(c); warmQ.push(c);
      });
      warmQ.splice(8);
      pump();
    }, { passive: true });
    window.addEventListener('scroll', () => { if (cur) close(true); }, { passive: true });
    document.addEventListener('scroll', (e) => { if (cur && e.target !== document && !hp.contains(e.target)) close(true); }, { capture: true, passive: true });
    $('.hp-rate', hp).addEventListener('click', (e) => {
      e.preventDefault(); e.stopPropagation();
      if (!item) return;
      const r = store.get('cf_rate', {}), k = rateKey(item);
      if (r[k]) { delete r[k]; toast('Rating removed', false); } else { r[k] = 1; toast('Rated: I like this'); }
      store.set('cf_rate', r);
      e.currentTarget.classList.toggle('on', !!r[k]);
    });
  })();

  /* ---------------- smooth images: posters fade in when decoded (no pop-in) ---------------- */
  (function smoothImages() {
    const sel = '.card-img img, .wcard-img img, .ccard img, .hero-thumb img';
    const mark = (im) => im.classList.add('ld');
    document.addEventListener('load', (e) => { const t = e.target; if (t.tagName === 'IMG' && t.matches(sel)) mark(t); }, true);
    $$(sel).forEach((im) => { if (im.complete && im.naturalWidth) mark(im); });
    document.documentElement.classList.add('js-img');
  })();

  /* remember the browse page, so closing a pop-up opened from "More Like This" returns here */
  try { sessionStorage.setItem('cf_from', location.pathname.split('/').pop() + location.search); } catch { /* ignore */ }

  /* ---------------- faster opening on touch: warm the pop-up page as soon as a card is touched ---------------- */
  (function warmOnTouch() {
    const done = new Set();
    document.addEventListener('touchstart', (e) => {
      const a = e.target.closest && e.target.closest('a[href*="watch.php"]');
      if (!a || done.has(a.href)) return;
      done.add(a.href);
      const card = a.closest('.card');
      if (card && card.dataset.id) fetch(`api.php?action=hover&type=${card.dataset.type}&id=${card.dataset.id}`, { priority: 'low' }).catch(() => {});
    }, { passive: true });
  })();

  /* ---------------- desktop: titles open as a pop-up over this page (home stays behind, blurred) ---------------- */
  (function overlay() {
    if (window.top !== window) return; // e.g. the blurred home page behind a directly-opened pop-up
    const ok = () => innerWidth >= 760 && matchMedia('(hover: hover) and (pointer: fine)').matches;
    // the pop-up iframe replaces prerendering of the full watch page on desktop
    if (ok()) $$('script[type="speculationrules"]').forEach((x) => x.remove());
    const root = document.documentElement;
    let bg = null, frame = null, pre = null, preSrc = '', lastFocus = null, unlockT = 0;
    const abs = (u) => new URL(u, location.href);
    const embedSrc = (u) => { const x = abs(u); x.searchParams.set('embed', '1'); return x.href; };
    const clean = (u) => { const x = abs(u); x.searchParams.delete('embed'); return x.pathname.split('/').pop() + x.search; };
    function makeFrame(src, hidden) {
      const f = document.createElement('iframe');
      f.className = 'cfov-frame' + (hidden ? ' pre' : '');
      f.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media');
      f.allowFullscreen = true;
      f.title = 'Title details';
      if (hidden) { f.tabIndex = -1; f.setAttribute('aria-hidden', 'true'); }
      f.src = src;
      document.body.appendChild(f); // never re-parent an iframe later (that would reload it)
      return f;
    }
    function ensureBg() {
      if (bg) return bg;
      bg = document.createElement('div');
      bg.className = 'cfov-bg';
      bg.innerHTML = '<i class="cfov-spin"></i>';
      bg.addEventListener('click', () => close());
      document.body.appendChild(bg);
      return bg;
    }
    // warm the pop-up for the card under the pointer (one hidden iframe at a time) → click shows it instantly
    window.cfPreload = (url) => {
      if (!ok() || frame) return;
      const src = embedSrc(url);
      if (pre && preSrc === src) return;
      if (pre) pre.remove();
      pre = makeFrame(src, true); preSrc = src;
    };
    const show = (f) => { try { if (f.contentWindow && f.contentWindow.cfShow) { f.contentWindow.cfShow(); bg && bg.classList.add('ready'); } } catch { /* ignore */ } };
    function open(url, push) {
      const src = embedSrc(url);
      if (window.cfHoverClose) window.cfHoverClose();
      clearTimeout(unlockT);
      if (frame) frame.remove();
      if (pre && preSrc === src) { frame = pre; pre = null; preSrc = ''; } else frame = makeFrame(src, false);
      frame.classList.remove('pre', 'out');
      frame.removeAttribute('aria-hidden'); frame.tabIndex = 0;
      lastFocus = document.activeElement;
      root.style.setProperty('--sbw', (innerWidth - root.clientWidth) + 'px');
      root.classList.add('cfov-lock');
      window.cfOvActive = true;
      const b = ensureBg();
      b.classList.remove('ready');
      void b.offsetWidth;
      b.classList.add('open');
      frame.classList.add('on');
      const f = frame;
      show(f);
      f.addEventListener('load', () => { if (frame === f) { show(f); f.focus(); } });
      requestAnimationFrame(() => f.focus());
      if (push !== false) { try { history.pushState({ cfOverlay: 1 }, '', clean(url)); } catch { /* ignore */ } }
    }
    function teardown() {
      const f = frame;
      if (!f) return;
      frame = null;
      try { const v = f.contentDocument && f.contentDocument.querySelector('video'); if (v) v.pause(); } catch { /* ignore */ }
      f.classList.add('out');
      f.classList.remove('on');
      if (bg) bg.classList.remove('open', 'ready');
      setTimeout(() => f.remove(), 260);
      unlockT = setTimeout(() => { root.classList.remove('cfov-lock'); window.cfOvActive = false; }, 240);
      // the pop-up may have changed progress / My List / ratings
      try { syncList(); renderMyListRow(); renderMyListPage(); renderHistory(); applyResume(); markEpisodes(); } catch { /* ignore */ }
      if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch { /* ignore */ } }
    }
    function close() {
      if (!frame) return;
      if (history.state && history.state.cfOverlay) history.back(); else teardown();
    }
    window.addEventListener('popstate', (e) => {
      const want = !!(e.state && e.state.cfOverlay);
      if (frame && !want) teardown();
      else if (!frame && want && ok()) open(location.href, false);
    });
    window.addEventListener('message', (e) => {
      if (e.origin !== location.origin || !frame || e.source !== frame.contentWindow) return;
      const m = e.data || {};
      if (m.cf === 'close') close();
      else if (m.cf === 'url' && m.url) { try { history.replaceState({ cfOverlay: 1 }, '', clean(m.url)); } catch { /* ignore */ } }
      else if (m.cf === 'ready') { bg && bg.classList.add('ready'); }
    });
    document.addEventListener('keydown', (e) => { if (frame && e.key === 'Escape') { e.preventDefault(); close(); } });
    document.addEventListener('click', (e) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !ok()) return;
      const a = e.target.closest && e.target.closest('a[href*="watch.php"]');
      if (!a || a.target === '_blank' || a.closest('.cfov-frame')) return;
      e.preventDefault();
      open(a.href);
    });
  })();

  /* ---------------- init ---------------- */
  syncList();
  renderMyListRow();
  renderMyListPage();
  renderHistory();
  applyResume();
  markEpisodes();
})();
