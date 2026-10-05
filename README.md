# track-forge
 
 Small PHP website to manage GPX tracks, built on Symfony 7.4 with Doctrine ORM over SQLite.
 
 ## Goal
 
 Provide a lightweight self-hosted web app where you can:
 - upload GPX files
 - browse your uploaded tracks
 - view basic track stats (distance, elevation, total points)
 - view tracks on a map
 - delete tracks you no longer want
 
 Authentication is handled via a numeric login code sent by email.
 
 ## Requirements
 
 - PHP (project targets `^8.4`)
 - Composer
 - SQLite extension (`ext-sqlite3`)
 
 Alternatively, use the provided `docker compose` setup.
 
 ## Configuration
 
 Environment variables are loaded from `.env` (see `.env.dist` for a template).
 
 Key variables:
 - `APP_ENV` (`dev|prod|test`) — **set this to `prod` for any real deployment**, otherwise
   Symfony serves full debug stack traces instead of the app's own error pages
 - `APP_SECRET` — Symfony's general application secret (CSRF tokens, signed values); generate your own
   (`php -r "echo bin2hex(random_bytes(16));"`) and never reuse the committed sample value
 - `SYMFONY_TRUSTED_HOSTS` — regex of `Host` headers the app accepts; anything else gets a
   `400`. This is what stops generated absolute URLs from being Host-header spoofed
 - `SYMFONY_TRUSTED_PROXIES` — set to your reverse proxy's IP/CIDR (or `REMOTE_ADDR`) when
   running behind one, so `X-Forwarded-*` headers are trusted; leave empty if there is none
 - `DATABASE_URL` (SQLite file used by Doctrine)
 - `MAILER_DSN` (Symfony Mailer DSN; the default points at the included Mailpit container)
 - `EMAIL_FROM`
 - `LOGIN_TOLERANCE_TIME` (login code lifetime, in seconds)
 - `PAGINATION_IPP` (tracks per page on the profile list)
 - `ALLOW_COUNTRY_CODE` (restrict access to a single country code; empty disables the check)
 
 ## Quickstart (Docker)

1. Copy env template:

   `cp .env.dist .env`

2. Start containers:

   `make start`

3. Open a shell in the app container:

   `make cli`

4. Install dependencies and copy assets:

   `composer install`

   `composer copy-assets`

5. Create/update the database:

   `bin/console doctrine:migrations:migrate --no-interaction`

6. Open the app:

   `http://localhost/`

Mailpit UI (for catching login-code emails in dev):

`http://localhost:8125/`
 
 ## Running without Docker
 
 1. Copy env template:
 
    `cp .env.dist .env`
 
 2. Install dependencies and copy assets:

   `composer install`

   `composer copy-assets`
 
 3. Ensure writable folders exist:
 
    `var/cache/`
 
    `var/log/`
 
    `var/data/`
 
    `var/database/`
 
 4. Create/update the database:
 
    `bin/console doctrine:migrations:migrate --no-interaction`
 
 5. Point your web server document root to `public/`.
 
 ## Development

- Enable/disable Xdebug in Docker:

  `make xdebug-enable`

  `make xdebug-disable`

- Other useful commands:

  `make help` - Show all available Makefile targets

  `make build` - Build Docker containers

  `make rebuild` - Rebuild containers without cache

  `make stop` - Stop containers

  `make clean` - Stop and remove containers
 
 ## CLI
 
 `bin/console` is the standard Symfony console. Useful commands:
 
 - `bin/console doctrine:migrations:migrate --no-interaction` - apply pending migrations
 - `bin/console doctrine:migrations:status` - show migration state
 - `bin/console debug:router` - list all routes
 - `bin/console cache:clear` - rebuild the cache (run after config changes, and on deploy)
 - `composer copy-assets` - copy `src/Resources/{css,js,images}` into `public/`
 
 In the Docker flow, prefix these with `docker compose exec -u www-data app`, or run them
 from inside `make cli`.
 
 ## Tests
 
 There is currently no automated test suite for the Symfony codebase.
 
 ## License
 
 MIT (see `LICENSE`).
