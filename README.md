# CINEFLIX – PHP Netflix-style Streaming Site

Pure **PHP** (no framework, no database, no API key).

* **Data:** scraped live from [themoviedb.org](https://www.themoviedb.org) — posters, backdrops, logos, details, cast, seasons, episodes, recommendations, people, realtime search
* **Player:** own Netflix-style HTML5 + hls.js player — every movie & episode plays an open-licensed demo stream

## Requirements
* PHP **7.4+** (8.x recommended) with `curl`, `dom` (php-xml), `mbstring`
* `cache/` folder must be writable (`chmod 775 cache`)

## Run locally
```bash
php -S 0.0.0.0:8080 -t cineflix
# open http://localhost:8080
```
Hosting (cPanel / Apache / Nginx): upload the `cineflix` folder contents to `public_html` — done.

## Pages
| File | What it does |
|---|---|
| `index.php` | Hero slider (trending + logos + trailer), Continue Watching, My List, Top 10, 20+ lazy-loaded rows |
| `movies.php` / `tv.php` | Browse by category (Popular, Top Rated, Now Playing…) & genre, infinite scroll |
| `new.php` | New & Popular: Top 10, trending, new episodes, in theaters, coming soon |
| `title.php?type=movie&id=550` | Details, trailer, seasons & episodes, cast, more like this, facts |
| `watch.php?type=tv&id=1399&s=1&e=1` | Netflix-style watch page: player + details, episodes, More Like This |
| `person.php?id=287` | Actor bio, known-for, full filmography |
| `search.php?q=` | Live search (movies, shows, people) |
| `mylist.php` | My List + watch history (stored in browser) |
| `api.php` | AJAX endpoints (suggest, search, browse, rows, season, credits) |

## Config
Edit `includes/config.php` — site name, cache times, `$DEMO_STREAMS` (the video every title plays, first = primary, rest = automatic fallback), `DEMO_INTRO` / `DEMO_CREDITS` markers and the seek step.

## Notes
* Scraped results are cached as JSON in `cache/` (lists 3h, details 24h). If TMDB is unreachable the last cached copy is served.
* Clear cache: delete everything inside `cache/`.

## CINEFLIX Player (Netflix-style)
Own HTML5 + hls.js player (`assets/js/player.js`, `assets/css/player.css`, `assets/js/vendor/hls.min.js`). No embeds, no admin.

**Every movie and episode plays the demo stream** from `$DEMO_STREAMS` (Tears of Steel / Big Buck Bunny – open-licensed Blender films). Swap the URLs in config to use your own licensed HLS/MP4 files.

* **Timeline hover preview** – real video frame thumbnails (hidden low-res HLS decoder, frames cached), also while touch-scrubbing
* Adaptive HLS: **Auto + manual quality**, **audio & subtitle tracks** (de/en/fr/ru/es), Netflix-style subtitles, S/M/L size, remembered language
* Speed 0.5×–2×, volume slider, full screen (landscape lock on mobile), Picture-in-Picture, Media Session lock-screen controls
* **Skip Intro**, **Next Episode** card with countdown at credits, episodes panel with season switch, end screen with recommendations
* "You're watching" pause screen, resume where you left off (Start Over), progress shared with Continue Watching & episode progress bars
* Auto-recovery: network retry, media-error recovery, stall watchdog, fallback to the next demo stream, error screen with Try Again

**PC controls (Netflix web behaviour):**
* Mouse move → controls fade in; 3 s without movement while playing → controls + cursor hide; leaving the player hides them faster
* Single click = play / pause (centre icon animation) · double click = full screen
* Hover the subtitle / speed / quality / episodes icons → pop-up opens above the icon, closes when the mouse leaves
* Hover the volume icon → vertical slider (drag or mouse wheel); ↑/↓ also pops it up
* Paused for 7 s → "You're watching" screen
* Keys: Space/K play, ←/→ ±10 s (spinning 10 s icon), ↑/↓ volume, M mute, F full screen, C subtitles, S skip intro, N next episode, P PiP, &lt; &gt; speed, 0–9 jump, Esc back/close, ? help

**Touch (Netflix app behaviour):**
* Tap = show / hide controls (auto-hide after 3.5 s while playing) · double-tap left/right = ±10 s with ripple (keep tapping to add +10 s)
* Centre ⟲ ▶ ⟳ buttons with spin animation, timeline drag with frame preview (other controls hide while scrubbing)
* Bottom action bar: Speed, Lock, Episodes, Audio & Subtitles, Quality, Next Ep., Full Screen; pinch = fill / fit; screen lock
* Opening Audio & Subtitles or Episodes pauses and resumes on close; on phones (portrait) panels open as bottom sheets
* Autoplay blocked by the browser → plays muted with a "Tap to unmute" pill

**Captions:** Netflix-style white text with shadow, sized from the player height (phone → TV), move above the controls when they are visible; size S/M/L and style (shadow / black box / yellow) with live preview, remembered.

**Skip Intro:** slides in with a fill bar showing the intro progress, hover = white, hides while the "You're watching" screen is on.

**Watch page layout (v3, matches the Netflix reference screens):**

* **Player** = full-screen layer that opens and autoplays on load. ✕ top-right (or Esc / browser Back) closes it → the title pop-up shows with an updated **Resume** bar ("S1:E2 · 16 of 49m").
* **Desktop player:** thin full-width red timeline, frame preview with the time inside the frame and a pointer triangle, centre ⟲10 ▶ ⟳10. Bottom row: play, ±10, volume, `elapsed / total`, centred **S1 • E2** pill + title + green episode name. Right side: next episode (hover card), audio & subtitles, episodes, **CC** quick toggle, ⚙ settings (speed + quality), PiP, fullscreen. The **▶ Next Episode** pill appears together with the controls.
* **Desktop / tablet pop-up:** blurred backdrop, backdrop hero with the logo, progress, white Play/Resume, round **+ My List** and **👍 Rate** buttons, Trailer chip (plays inside the hero).
  * Two columns: match % / year / runtime / HD, rating box + genres, overview, audio languages | Cast, Genres, "This Show is".
  * **Episodes** with a season selector; **More Like This** cards (runtime/episodes badge, match, rating box, year, + button, description; collapsed with a fade + chevron).
  * **About {Title}** table (Director/Creators, Writer, Cast, Genres, This is, Maturity rating, language, network, status).
* **Mobile (< 760px):** full-page title sheet. Hero with a centre ▶, "Trailer" chip and red "New Episodes / Recently Added" tag.
  * "C SERIES" label, title, meta row, audio chip, full-width **Play/Resume** + "x m remaining" bar, grey **Trailer** button.
  * Overview, cast/creator lines, action row (My List · Rate · Share · Details).
  * Tabs **Episodes | More Like This | More Details** (red indicator): episode rows, 3-column poster grid, bordered details table. A floating round ✕ sits at the bottom.
  * Play from the sheet opens the player in full screen + landscape.
* `api.php?action=recinfo&ids=movie:550,tv:1396` lazy-loads poster, runtime, rating and overview for More Like This.
* Ratings are saved in `localStorage.cf_rate`.

## v4 – Netflix hover cards, no title page, fast open

- **No separate title page.** Every card/link opens `watch.php?…&info=1`: a pop-up modal on desktop and the full title sheet on mobile. It does not autoplay; the player starts on **Play**. For TV shows, Play/Resume continues your last-watched episode. `title.php` now just 301-redirects there.
- **Desktop hover card** (`app.js` + `style.css`, `.hp`). Hover a poster for about 0.45s and a card grows smoothly out of it. It shows the backdrop, logo and red "Recently added"/"New Episodes" tag, the Play / + / 👍 / ⌄ buttons, then match %, rating box, runtime or "N Seasons", HD and genres. The data comes from `api.php?action=hover`, which also warms the server cache for the pop-up.
- **Fast open:** Speculation Rules prerender `info=1` links (hover or touch on mobile), and touching a card pre-warms the server cache.
- **Player:** Netflix-style red loading ring with % (manifest → level → buffered), the same ring for buffering, and new ⟲10 / ⟳10 icons. A double-tap seek on phones shows only the ripple (no duplicate centre buttons).
- **Short text:** the synopsis is clamped to 4 lines on desktop and 3 on mobile (tap "more" to expand). More Like This: 4 lines. Episodes: 2 lines. Pause screen: 3 lines.
- **Season selector:** a dark dropdown on desktop. On phones it is a full-screen blurred list with a white ✕, the same as the Netflix app. This works in the details page and in the player's episodes panel.
- **Mobile episodes:** bigger thumbnails (44vw), smaller text.
- **Cast with photos:** About (desktop) / More Details (mobile).

## v5 – smoother home, poster → card morph, clean More Like This

- **More Like This opens the pop-up first**, not the player. Closing a pop-up that was opened from another pop-up returns to the browse page you came from (`sessionStorage cf_from`).
- **Smaller desktop pop-up:** 920px wide (was 1100px).
- **Hover card morph:** the poster itself grows into the card (Web Animations: transform plus clip-path from the poster's exact rectangle, with the poster crossfading into the artwork). The info and one 16:9 artwork are fetched and decoded while the pointer rests, so the card opens complete with no image swapping. It shrinks back into the poster on leave.
- **More Like This thumbnails:** crisp w780 artwork plus the title's logo. No text, runtime or play badge drawn over the image. The runtime moved to the info line.
- **Netflix skip icons:** a ring with the arrowhead at the top and a bold number.
  - Pressing skip swings the arrow and pulses the number.
  - Phone double-tap shows a soft ripple whose icon counts 10 → 20 → 30.
  - Keyboard ←/→ shows a single centre badge (the centre controls hide meanwhile), so icons never appear twice.
- **Home performance:**
  - The hero no longer downloads TMDB "original" images; it is preloaded at w780/w1280. LCP went from 5.6s to 0.5s in tests.
  - Rows use `content-visibility:auto`, and lazy rows load 1400px ahead.
  - Arrow paging moves exactly one page of whole cards with an ease-in-out glide; scroll-snap is off on desktop.
  - Posters fade in once decoded (no pop-in).

## v6 – Netflix pop-up over the home page

- **Desktop pop-up over the page:** clicking any title (cards, hero, hover card, Continue Watching) opens the details/player as a pop-up *on top of* the current page. The home page stays behind it, blurred and dimmed. The URL updates, so the Back button and Esc close it. Refreshing or opening a pop-up link directly shows the same card with the real home page blurred behind it. Mobile keeps the full-page title sheet.
- **Instant pop-up:** while a hover card is open, its pop-up is preloaded in the background, so a click shows it in about 0.1s.
- **Fast hover cards:** they open about 0.2s after the pointer arrives, with no waiting for data. When the pointer enters a row, the visible cards are warmed in advance. Uses the new `api.php?action=hover&lite=1`.
- **Bigger card, smaller type:** the desktop card is 1040px wide. Buttons and text use Netflix sizes (Play 42px/17px, body text 15px).
- **Instant Play:** there is no % loading screen. The stream warms up in the background while the info card is open, then Play starts within about 0.5s. If it is still loading, a small spinner shows.
- **Smooth controls:** controls ease in quickly and fade out slowly. Icons grow with a spring effect on hover. Panels glide in.
- **Netflix timeline and volume:** grey track, red fill and knob, remaining time on the right. The vertical volume pop-up has no % label.
- **Continue Watching:** each card shows clean 16:9 artwork, the title logo and a thin progress line. Logos come from the new `api.php?action=logos` and are cached in `localStorage.cf_logos`.
- **Flat home:** #141414 background, 4px corners, no glows, shadows or glass. Buttons are flat like Netflix's.

### v6.1 – flat player
- The player is flat on desktop and mobile: solid #262626 panels with 4px corners, no shadows, glows or glass blur, a flat Skip Intro button and Next Episode pill, lighter edge scrims and a simple red spinner.
- On a phone held upright, the bottom buttons show an icon above a short label, so they always fit.
- Skip Intro and Next Episode hide while a settings or episodes panel is open.

### v7 – desktop player matched to the Netflix reference screenshots
- **No centre controls on desktop:** ⟲10 / ▶ / ⟳10 appear only in the bottom bar. Skipping on desktop has no animation at all: no seek badge and no spinning arrows.
- **Icons:** flat 2D icons that turn light red on hover; the 10-second buttons stay white. An open control (volume, settings, subtitles, episodes) shows a short bar under its icon.
- **Bottom bar:** play, ⟲10, ⟳10, the speaker icon with a horizontal volume slider that slides open on hover, then the time as `00:00 / 42:10`. Next comes the `S1 • E1` pill with the title and a green episode name. On the right: audio & subtitles, episodes, CC, settings and full screen.
- **Timeline:** thick flat bar with red progress, a white hover segment, and a red knob shown only on hover.
- **Next Episode:** translucent grey button above the timeline.
- **Settings:** one tall panel with Quality, CC (subtitles) and Speed tabs. The active row has a red edge, a grey background and green ▶ text. "Subtitle Settings" opens the audio & subtitle options.
- **Other:** thin red arc spinner; no close ✕ at the top in full screen. Phones and tablets keep their touch layout.

### v7.1 – desktop icons
- Bigger icons (about 53px at 1440px wide, 62px at 1920px) with tighter spacing. The ⟲10 / ⟳10 buttons are about 15% larger than the rest.
- Smooth zoom (×1.28) and a light-red tint on hover, a soft press on click, and the play/pause glyph pops in when it switches.
