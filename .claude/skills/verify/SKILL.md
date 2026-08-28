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
