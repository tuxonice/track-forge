# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

pixel-tracks: a small self-hosted PHP app for uploading and browsing GPX tracks (stats, map view, share links). Auth is passwordless via a two-step numeric login code. PHP 8.4, Symfony 7.4 LTS, Doctrine ORM over SQLite.

## Setup

```
cp .env.dist .env
composer install
composer copy-assets      # copies src/Resources/{css,js,images} -> public/
bin/console doctrine:migrations:migrate --no-interaction   # creates/updates var/database/database.sqlite
```

Docker (preferred dev flow): `make start`, then `make cli` to get a shell in the app container (as `www-data`), then run the commands above inside it — or run any of them directly from the host as `docker compose exec -u www-data app <command>`. `make help` lists all targets. Mailpit (catches login-code emails in dev) is at `http://localhost:8125/`.

Writable folders needed outside Docker: `var/cache/`, `var/log/`, `var/data/` (per-user uploaded GPX files), `var/database/` (SQLite file). Web server document root is `public/`.

## Commands

- `bin/console` — Symfony's console entrypoint (run via `docker compose exec -u www-data app bin/console ...` in the Docker flow)
- `bin/console doctrine:migrations:migrate` — applies pending migrations (use `--no-interaction` to skip the confirmation prompt)
- `bin/console doctrine:migrations:status` / `doctrine:migrations:diff` / `doctrine:migrations:generate` — inspect pending migrations / generate a new one from entity-mapping changes / scaffold an empty one
- `bin/console debug:router` — list all registered routes
- `composer copy-assets` — copies `src/Resources/{css,js,images}` into `public/`; re-run after changing anything under `src/Resources/`
- `vendor/bin/simple-phpunit` — runs the test suite (`make tests` in the Docker flow); backed by `symfony/phpunit-bridge`, configured via `phpunit.dist.xml`
- `vendor/bin/phpstan analyse` / `vendor/bin/phpcs` / `vendor/bin/phpcbf` — static analysis / code style check / code style auto-fix (`make phpstan` / `make phpcs` / `make phpcbf`)

A real PHPUnit suite exists under `tests/{Functional,Integration,Unit}`, with GPX fixtures in `tests/Fixtures/gpx/`; `symfony/phpunit-bridge`, `symfony/browser-kit`, and `symfony/css-selector` are dev dependencies. `.env.test` (loaded automatically for `APP_ENV=test`, which `phpunit.dist.xml` sets) isolates the suite from dev: a separate SQLite database (`var/database/database_test.sqlite`), `MAILER_DSN=null://null` (no real mail/Mailpit hits), and a separate upload data subdir (`var/data_test`). Functional tests (`tests/Functional/WebTestCase`) rebuild the schema fresh from entity metadata via Doctrine's `SchemaTool` in `setUp()` — isolated per test and independent of migration history, so no separate `doctrine:migrations:migrate --env=test` step is needed to run tests locally. `phpstan.neon` (level 6, `src/` only) and `phpcs.xml` (PSR12 + a few custom rules, targeting `src/`, `public/index.php`, `bin/console`) are both current and pass cleanly. `.github/workflows/tests.yml` (push to `main`, PRs into `main`) runs `phpcs` → `phpstan` → `doctrine:migrations:migrate --env=test` → `simple-phpunit` and reflects the current codebase, not a legacy pipeline.

## Architecture

**Request lifecycle**: standard Symfony — `public/index.php` boots `App\Kernel` via `vendor/autoload_runtime.php`. Routes are attribute-based (`#[Route]` on controller methods under `src/Controller/`) and auto-imported via `config/routes.yaml`. The security firewall (see Auth below), then the matched controller, handle each request; `src/EventListener/` classes hook into kernel events for cross-cutting behavior (see Cross-cutting below).

**Routing**: declared with `#[Route(...)]` attributes directly on controller methods in `src/Controller/*.php` — there's no central routes file to edit. Run `bin/console debug:router` to see the full list. The app is localized (English + Portuguese, `enabled_locales` in `config/packages/framework.yaml`); 7 of the user-facing GET routes below use Symfony's built-in i18n routing — a locale-keyed `path` array (e.g. `#[Route(path: ['en' => '/en/tracks/', 'pt' => '/pt/percursos/'], name: 'app_profile')]`) registers one route per locale internally (`app_profile.en`/`app_profile.pt`), and `path()`/`redirectToRoute()`/`generate()` calls by the bare route name resolve to whichever locale variant matches the current request automatically. Technical/action routes (POST endpoints, the login/logout callbacks) are deliberately left single and unprefixed. Controllers extend Symfony's `AbstractController`; one class per feature area:
- `HomeController` — `/` (unprefixed; for an already-authenticated visitor, redirects to the localized profile page, picking a locale from `Accept-Language`; an unauthenticated visitor never reaches this controller at all — `access_control` intercepts first and `LoginEntryPoint` redirects straight to the login page) and `/en/tracks/` / `/pt/percursos/` (+ `/{page}` variants, paginated track list)
- `AboutController` — `/en/about` / `/pt/sobre` (GET, `PUBLIC_ACCESS`; renders a static per-locale template — `Default/about.en.html.twig` / `Default/about.pt.html.twig`, picked directly by request locale — rather than the single-template-plus-`|trans` pattern used elsewhere)
- `LoginController` — `/en/login` / `/pt/entrar` (GET: email form, POST: rate-limited code request + email send) and `/en/login/verify` / `/pt/entrar/verificar` (GET: code form, POST: verify + log in)
- `LogoutController` — `/logout` (unprefixed), same pattern (Symfony's logout listener intercepts it)
- `UploadController` — `/track/upload` (unprefixed POST, GPX file upload + validation)
- `TrackController` — `/en/track/info/{trackKey}` / `/pt/percurso/info/{trackKey}` (GET) and `/track/delete` (unprefixed POST)
- `MapController` — `/en/map/{trackKey}` / `/pt/mapa/{trackKey}` (GET, full-screen Leaflet map view) and `/en/track/view/{trackKey}` / `/pt/percurso/ver/{trackKey}` (GET, the same map embedded in the normal site template alongside an elevation-profile chart and a stats card)

**Auth**: no passwords — a two-step numeric login code, keyed on the `User` entity's `email` property. `LoginController::sendCode` rate-limits by IP and by email (via the `login_code_by_ip`/`login_code_by_email` limiters configured in `config/packages/framework.yaml`), looks up or creates a `User`, persists the requester's current locale onto it (`User::locale` — used for the `/track/upload`/`/track/delete` routes' flash messages later, since those stay deliberately unlocalized), generates a 6-digit code via `random_int()`, hashes it with `password_hash()` into `User::loginCodeHash` (with `loginCodeExpiresAt`/`loginCodeAttempts`), stores the pending user's id in the session (`pending_login_user_id`), and emails the code. `LoginController::verifyCode` checks the submitted code against the hash (`password_verify()`), enforces a 5-minute expiry and a 5-attempt cap (past either, the code is invalidated and a new one must be requested), and on success calls `Security::login($user)` before redirecting to the profile. `Security::login()` requires the firewall to have exactly one authenticator registered, which is what `App\Security\LoginCodeAuthenticator` exists for — it never handles a request directly (its `supports()` always returns `false`); Symfony's `authenticateUser()` path calls only its inherited `createToken()` (from `AbstractAuthenticator`) and its `onAuthenticationSuccess()`. `App\Security\LoginEntryPoint` redirects unauthenticated access attempts to the localized login page. `access_control` in `security.yaml` grants `PUBLIC_ACCESS` to both locale variants of the login and verify paths and of the About page (end-anchored, to avoid matching unintended longer paths) and requires full authentication for everything else; `#[IsGranted('PUBLIC_ACCESS')]` on the controller actions is defense-in-depth only — `access_control`'s `kernel.request`-time check is what actually enforces this, since it runs before controller attributes are evaluated.

**Data layer**: Doctrine ORM. Entities (`src/Entity/User.php`, `src/Entity/Track.php`) are attribute-mapped; `User` implements `UserInterface` for Security. Repositories (`src/Repository/`) extend `ServiceEntityRepository` and add query-builder methods (e.g. `TrackRepository::findPageForUser`, `countForUser`, `findOneByKey`). No hand-written SQL, no DTOs/transfer-object generation step — inject `EntityManagerInterface` or a repository and work with entities directly.

**Migrations**: standard `doctrine/doctrine-migrations-bundle`, files in `migrations/` (configured in `config/packages/doctrine_migrations.yaml`), named `Version<timestamp>.php` and generated/applied via `bin/console doctrine:migrations:*`. Note: `bin/console doctrine:database:create` does not work against this SQLite setup (doctrine/dbal 4.4 + doctrine-bundle 3.3 throw `... is not supported by platform`) — running `doctrine:migrations:migrate` directly creates `var/database/database.sqlite` as a side effect of connecting, so that command alone is sufficient to stand up a fresh database.

**GPX handling**: `UploadController` validates the uploaded file (`App\Validator\XmlValidator` against `src/Schemas/gpx.xsd`, then `App\Service\GpxValidator` for size/MIME/GPX-namespace/track-content checks), stores it via `App\Service\FileUploaderService` under `var/data/profile-{userId}/`, and parses it with `sibyx/phpgpx` through `App\Gps\GpsTrack` to compute stats (distance/elevation/point count) persisted on the `Track` entity. `MapController` re-parses the stored file via `GpsTrack` to render the Leaflet map.

**Cross-cutting**: `src/EventListener/SecurityHeadersListener` (kernel.response) sets `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and a `Content-Security-Policy` on every main-request response. `src/EventListener/CountryRestrictionListener` (kernel.request, priority 10) looks up the client IP's country via `App\Service\IpApiService` (cached in `cache.app`) and returns a 403 if it doesn't match the `ALLOW_COUNTRY_CODE` env var (no-op when that var is empty). `src/EventListener/LocaleTemplateGlobalsListener` (kernel.request) exposes the current route's locale-suffix-stripped name and its route parameters as Twig globals (`app_route_name`/`app_route_params`), used by the navbar's language switcher to link to the same page in the other locale. `App\Pagination\Paginator` backs the profile track list page-number UI. Mail is sent via Symfony Mailer (`MAILER_DSN` env var, Mailpit in dev), translated per the recipient's locale. Twig templates live in `templates/` (e.g. `templates/Default/track.html.twig`, error pages under `templates/Error/`). Translations live in `translations/messages.{en,pt}.yaml` (`enabled_locales: ['en', 'pt']`, `default_locale: en`, `set_locale_from_accept_language: true` in `config/packages/framework.yaml`/`translation.yaml`).

**Config**: filesystem paths and small app-specific settings (email sender, pagination page size, GPX schema path, upload data path, allowed country code) are bound as autowired scalar parameters in `config/services.yaml`'s `_defaults.bind`, sourced from env vars declared in `.env.dist`. `.env`/`.env.dev`/`.env.test` layer over it per Symfony's usual env-file precedence.
