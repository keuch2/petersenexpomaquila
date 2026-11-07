# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Microsite for the Petersen Industria stand at Expo Maquila 2026 (Paraguay). Visitors register, spin a prize wheel (ruleta), and enter a final raffle (sorteo). Staff use a web admin and a projector-ready raffle screen. All UI text is in Rioplatense Spanish (voseo: "Registrate", "Girá"). Keep that tone.

Stack: vanilla PHP 8.4 + SQLite (PDO) + vanilla HTML/CSS/JS. There is no build step, no Composer/npm and no test suite. `_demo/index.html` is the original single-file Claude-artifact demo (localStorage-based). The design and the client-side wheel/raffle animations come from it. Keep it as a visual reference only.

## Running locally

Served by Homebrew Apache (docroot `/opt/homebrew/var/www/`, port 80, AllowOverride All) at `http://localhost/petersenexpomaquila/`. Use the Homebrew stack only, never XAMPP or MAMP. Apache runs as the user, so `data/` and `uploads/` are writable.

- Lint: `for f in *.php admin/*.php inc/*.php; do php -l $f; done` and `node --check assets/*.js`.
- The DB (`data/app.sqlite`) is created and seeded automatically on the first request. To reset everything, delete `data/app.sqlite*` and `data/admin-inicial.txt`, delete everything in `uploads/` except `.htaccess`, then load any page. The new admin password is written to `data/admin-inicial.txt` (blocked from the web).
- Git: this folder sits inside the Homebrew checkout and is ignored by its `.gitignore`. It has no repository of its own. Don't commit it to Homebrew.

## Architecture

- `inc/bootstrap.php`: required by every entry point. It sets up the session (`piexpo` cookie), security headers, `base_url()` (computed from the filesystem, so the app works in any subfolder), `h()`, CSRF helpers, `rate_limit()`/`hit()` (backed by the `hits` table), `current_admin()`/`require_admin_page()`, `phone_key()`, and the `RUBROS`/`DESAFIOS` lists.
- `inc/db.php`: `db()` opens the PDO connection with WAL and `busy_timeout`. It runs `CREATE TABLE IF NOT EXISTS` migrations on every request and seeds once, guarded by `settings.seeded`. The tables are `admins`, `participants`, `prizes` (`type` is `ruleta` or `sorteo`), `draws`, `brands`, `settings` (site `content` JSON and the `rev` counter) and `hits`. Timestamps are Unix **milliseconds** (`now_ms()`), because the JS `fmtDate()` expects ms.
- `api.php`: the single JSON endpoint, `?action=...`.
  - Public actions: `state`, `register`, `spin`. Every other action requires an admin session. Every POST requires the `X-CSRF` header, whose token comes from `<meta name="csrf">`.
  - Errors are thrown as `ApiError(message, status, extra)`.
  - Every write calls `bump()`. The admin polls `admin_state?rev=X` every 5 s and gets `{same:true}` when nothing changed.
- **Server is authoritative**: anyone on the public web can register.
  - `spin` and `draw` pick results with `random_int` inside `tx()` (`BEGIN IMMEDIATE`), so stock can't be oversold.
  - The client only animates toward the result the server returns.
  - Dedup is by `phone_key` (strips `595` and leading zeros) and by email. A registered-but-not-spun person can continue only if both phone and email match.
  - Never move prize selection to the client.
- Pages: `index.php` (public site, content server-rendered; `#kiosco` hash = auto-reset after 25 s and `device=kiosco`), `admin/login.php` (username + password), `admin/index.php` (tabs: resumen, participantes, ganadores, sorteos, premios, admins, contenido; rendered by `assets/admin.js` with HTML strings — escape with `esc()`), `sorteo.php` (admin-only raffle screen, `assets/sorteo.js`).
- Prize stock rules:
  - `awarded` counts units given out.
  - Voiding a draw, or deleting/resetting a ruleta winner that hasn't collected the prize, gives the unit back.
  - A prize that already has winners can't be deleted, only deactivated.
- Admin safety rules (enforced in `api.php`): an admin can't delete or deactivate themselves, and there must always be at least one active admin.
- Uploads: PNG/JPG/WEBP only (checked with `finfo` + `getimagesize`), up to 8 MB, saved with random names in `uploads/`. That folder's `.htaccess` blocks scripts and SVG. `media_url()`/`media()` turn stored relative paths (`logos/…`, `uploads/…`) into URLs.
- Access control: `.htaccess` files block `data/`, `inc/`, and `*.md/*.sqlite/*.txt`.

## Conventions

- JS is the dense, demo-derived style (`$`, `esc`, `toast`, `api()`, `confirmTap()` in `assets/common.js`). Match it and keep edits small.
- CSS lives in `assets/app.css` (extracted from the demo, with additions at the end). Brand palette: blue `#004479`, yellow `#f3b80a`, light blue `#9cc4ea`. Fonts: Archivo, IBM Plex Sans and IBM Plex Mono, loaded from Google Fonts.
- Every query uses prepared statements. Output is escaped with `h()` in PHP and `esc()` in JS.
