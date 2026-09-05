---
name: verify
description: How to build/launch/drive this app (Laravel + Livewire on Docker Sail) for runtime verification.
---

# Verify recipe for stock_auto_order

Web app surface: Livewire full-page screens served by `php artisan serve`
inside Docker Sail, driven via Playwright MCP against `http://localhost`.

## Launch

App is already running under Docker Sail (`docker compose up -d`, service
name `laravel.test`). No separate launch step needed — just hit
`http://localhost` directly with Playwright.

Run artisan/composer/tinker commands via:

```
docker compose exec laravel.test php artisan <command>
docker compose exec laravel.test ./vendor/bin/pint --dirty
```

## Rebuild frontend assets before verifying any Blade/CSS change

There is no Vite dev server in `compose.yaml` — assets are static, built
once via `npm run build`. Tailwind v4 is JIT: it only emits CSS for
utility classes it sees in Blade files **at build time**. If you add a
Tailwind class to a Blade file that wasn't already used elsewhere in the
project, the compiled CSS won't contain it until you rebuild — the page
will render with that class silently doing nothing (no error, just
broken layout). `php artisan test` cannot catch this (Livewire's
`->html()` doesn't load compiled CSS). Rebuild before every visual check:

```
docker compose exec laravel.test bash -c "cd /var/www/html && npm run build"
```

See `docs/ai-context/known-pitfalls.md` ("Tailwind CSS v4") for the
incident this was learned from.

## Login (every Playwright session needs this — no persisted auth)

Seeded user: `test@example.com` / `password` (from `database/seeders/DatabaseSeeder.php`).
If missing (`php artisan tinker --execute="App\Models\User::where('email','test@example.com')->exists()"`
returns false), restore with `docker compose exec laravel.test php artisan db:seed --class=DatabaseSeeder --force`.

Navigate to `/login`, fill email/password fields, then submit. **`.click()`
on the login button is unreliable** (see Known gotcha below) — the robust
way is to fill the fields with `browser_type`, then:

```js
() => { const form = document.querySelector('form'); form.requestSubmit ? form.requestSubmit() : form.submit(); return 'submitted'; }
```
via `browser_evaluate`, then `browser_wait_for` ~2s and check the URL
redirected away from `/login`.

## Known gotcha: `.click()` silently no-ops

Across many verification sessions, Playwright's `.click()` on buttons
inside this app (login submit, logout, Livewire `wire:submit` buttons)
intermittently does nothing — no navigation, no network request, no
console error. This is a Playwright-side timing flakiness, not an app
bug: confirmed by inspecting the button's DOM state right after a failed
click (not disabled, not covered by another element, `elementFromPoint`
resolves to the button itself — i.e. nothing is actually wrong with the
markup/wiring), then simply retrying the exact same `.click()` on the
exact same element, which succeeds. Same markup, same wire:submit
binding, inconsistent result — that pattern points at the click
synthesis itself, not the app.

**Diagnose**: check `browser_network_requests` after the click — if no
new POST fired, the click didn't register.

**First response: just retry the same `.click()` once or twice.** This
resolves it most of the time and requires no DOM/JS workaround.

**If retries don't help**: dispatch native submit via `browser_evaluate`
(see Login above).

**Workaround for Livewire component methods** (`wire:submit` buttons that
still won't fire): call the component method directly, bypassing the
DOM entirely:

```js
() => {
  const wireEl = document.querySelector('[wire\\:id]');
  const wireId = wireEl.getAttribute('wire:id');
  const c = window.Livewire.find(wireId);
  c.set('someProperty', 'value');   // if you need to set state first
  c.call('someMethod');             // invokes the same server-side method a real click would
  return 'called';
}
```

This exercises the exact same server-side code path as a real click
(same AJAX commit, same render) — it's a legitimate way to confirm
component behavior when the click event itself won't land, not a way to
bypass verification.

## Preferred fallback when Playwright MCP won't connect: run Playwright directly inside the Sail container

The Playwright MCP server has repeatedly failed to connect (`CONNECT_TIMEOUT`)
across sessions. The host shell running Claude Code has no browser/Node
either. But **the Sail container itself has `node`/`npx` and can download
a real headless Chromium**, which gives you an actual browser — real
scrolling, real layout, real screenshots — not just the static-HTML
inspection the curl fallback below provides. Prefer this whenever you need
to verify anything CSS/layout/interaction-related (`overflow`, `sticky`,
scroll behavior, JS-driven UI) — the curl fallback literally cannot detect
these (see the `position: sticky` incident in `known-pitfalls.md`, found
only after building this and looking at a real screenshot).

Setup (once per container lifetime — do this in a scratch dir under
`storage/app/`, e.g. `storage/app/pw-scratch/`, since it's inside the
mounted volume and screenshots land on the host filesystem too):

```
docker compose exec laravel.test bash -c "mkdir -p storage/app/pw-scratch && cd storage/app/pw-scratch && npm init -y && npm install playwright@1.63.0 && npx playwright install chromium"
```

This downloads ~300MB of browser binaries — takes a minute, needs
internet access from the container (worked in practice). Then drive it
with a plain Node script (`chromium.launch({ args: ['--no-sandbox'] })`,
same login dance as below via `page.evaluate` + `form.requestSubmit()`,
`page.goto('http://localhost/signals')`, `page.screenshot({ path: '...png' })`).
Run via `docker compose exec laravel.test bash -c "cd storage/app/pw-scratch && node your-script.mjs"`.
Read the resulting `.png` directly with the Read tool (the mount means
`storage/app/pw-scratch/foo.png` in the container is
`storage/app/pw-scratch/foo.png` in the repo on the host).

**Clean up afterward**: `docker compose exec laravel.test rm -rf storage/app/pw-scratch`
— this directory is not gitignored (only specific `storage/` subpaths are)
and the browser binaries + node_modules are large; don't let them show up
in `git status`. Copy any screenshots you want to keep out first (e.g. to
your own scratchpad dir) before deleting.

This is also useful for isolating a suspected CSS bug in a minimal
throwaway `.html` file (`page.goto('file:///path/to/test.html')`) before
touching the real app's Blade files — much faster than a guess-rebuild-ask-user
loop when the underlying CSS mechanism is unclear.

## Fallback when even that isn't available: real HTTP login via curl

If `npx playwright install chromium` fails (no internet from the
container, disk space, etc.), you're limited to inspecting the real
server-rendered HTML — no visual/layout verification is possible this
way, so say so explicitly rather than claiming a layout is fixed. The
login page is a Livewire component (`wire:submit`), not a plain form
post, so a normal curl form-POST to `/login` won't authenticate.
Reproduce the real Livewire AJAX update call instead:

1. `GET /login` with a cookie jar, extract from the HTML: the
   `<meta name="csrf-token" content="...">` value, the `wire:snapshot="..."`
   attribute (HTML-entity-encoded JSON — `html.unescape()` it), and the
   `data-update-uri="..."` attribute on the Livewire script tag.
2. `POST` that update URI (same cookie jar) with JSON body:
   `{"_token": <csrf>, "components": [{"snapshot": <the raw snapshot string, unescaped>, "updates": {"email": "test@example.com", "password": "password"}, "calls": [{"path": "", "method": "login", "params": []}]}]}`,
   headers `Content-Type: application/json`, `X-CSRF-TOKEN: <csrf>`,
   `X-Livewire: true`, `Accept: application/json`.
3. Response JSON's `effects.redirect` confirms success (e.g. `/holdings`).
   The cookie jar now holds a real authenticated session — reuse it with
   plain `curl -b cookies.txt http://localhost/<route>` to pull the exact
   production-rendered HTML of any page.

This gets you the real server-rendered HTML (same code path a browser
hits) but **not** a visual screenshot — CSS layout/overflow issues need
either an actual browser or careful manual reasoning about the classes
in the fetched HTML (e.g. `grep` the compiled CSS bundle at
`public/build/assets/app-*.css` for the utility classes the Blade
actually uses — see the Tailwind rebuild note above for why this matters).
Tell the user plainly when you've had to fall back to this instead of a
real screenshot.

## Auth-state check without full page load

`GET /api/holdings` returns 200 JSON when authenticated, redirects/401
when not — faster than snapshotting the nav bar to confirm session state.

## Seeding real data for a happy-path check

Dev DB holdings can be empty (has happened repeatedly — a shared dev DB,
possibly reset by a concurrent session). To verify a screen's populated
state, seed minimal fixtures via `php artisan tinker --execute="..."`
(create `Holding` + `HoldingSnapshot`/`Snapshot`/`FundamentalIndicator`/
`TechnicalIndicator` etc. as needed — mirror the shapes in
`tests/Feature/*Test.php` fixture helpers). **Clean up afterward** — this
is the real personal-use dev DB, not a disposable test DB. Delete
everything you created, by id, in reverse dependency order.

## Full suite (for pre-verification sanity only — NOT part of verify itself)

```
docker compose exec laravel.test php artisan test
```
Takes ~2-3 minutes. Note: a concurrent Claude Code session sometimes runs
tests against the same DB simultaneously, causing spurious `RefreshDatabase`
contention failures — if failures look unrelated to your change, re-run
alone, or `php artisan migrate:fresh --env=testing --force` first.
