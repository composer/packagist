# Packagist

Package Repository Website for Composer, see the
[about page](https://packagist.org/about) on
[packagist.org](https://packagist.org/) for more.

**This project is not meant for re-use.**

It is open source to make it easy to contribute. We provide no support
if you want to run your own, and will do breaking changes without notice.

Check out [Private Packagist](https://packagist.com/) if you want to
host your own packages.

## Development

These steps are provided for development purposes only.

### Requirements

- **PHP** for the web app
- **NPM** (or Docker) for the frontend build
- **[Symfony CLI](https://symfony.com/download)** to run the web server
- **MySQL** (or Docker) for the main data store
- **Redis** (or Docker) for some functionality (favorites, download statistics)
- **git / svn / hg** depending on which repositories you want to support

### Installation

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   npm install
   ```
   Ensure env vars are set up correctly, you probably need to set `APP_MAILER_FROM_EMAIL`, `APP_MAILER_FROM_NAME` and `APP_DEV_EMAIL_RECIPIENT` in `.env.local`. Set also `MAILER_DSN` if you'd like to receive email.

3. Start the web server:
   ```bash
   symfony serve -d
   ```

4. Start MySQL & Redis:
   ```bash
   docker compose up -d # or somehow run MySQL & Redis on localhost without Docker
   ```
   This mounts the current working directory into the node container and runs npm install and npm run build automatically.

5. Create 2 databases:
    - `packagist` - for the web app
    - `packagist_test` - for running the tests
   ```bash
   bin/console doctrine:database:create
   bin/console doctrine:database:create --env=test
   ```

6. Setup the database schema:
   ```bash
   bin/console doctrine:schema:create
   ```

7. Run a CRON job `bin/console packagist:run-workers` to make sure packages update.

8. Run a CRON job `bin/console packagist:project-transparency-log` (e.g. every minute) to project audit log entries into the public package transparency log. See [Transparency log backfill](#transparency-log-backfill) before enabling this on an existing database.

9. Run `npm run build` or `npm run dev` to build (or build&watch) css/js files. When using Docker run `docker compose run node npm run dev` to watch css/js files.

You should now be able to access the site, create a user, etc.

### Transparency log backfill

`package_transparency_log` is projected from `audit_log` by `packagist:project-transparency-log`, which
only publishes what is in the `package_transparency_log_queue` outbox. A queue row is written at the
same time as the audit record itself, so nothing that predates the queue is ever published on its own
and the cron is safe to enable on a database with existing audit history.

To backfill the transparency log, use the following commands. On a database that already has audit history, run:

```bash
bin/console packagist:seed-transparency-log-queue --dry-run
bin/console packagist:seed-transparency-log-queue
```

Then drain it before enabling the cron:

```bash
bin/console packagist:project-transparency-log --suppress-out-of-order-logging
```

Seeded records are older than whatever is already in the log, so each one would otherwise warn about
being appended out of order. On a first backfill the log is empty and the option changes nothing, but
any drain run is where that warning is expected: a re-seed after making another audit type
projectable, or resuming an interrupted drain, logs one per record without it.

The seed command only ever enqueues package, version and ownership events, which carry their own
package and so backfill exactly as they happened. Account events (2FA, password, email, GitHub link)
carry no package and fan out to whoever maintains the package *at projection time*, so backfilling
them would publish old events against today's maintainer set, and a published entry cannot be
retracted.

Seeding is safe to re-run: it skips anything that already has a `package_transparency_log` entry or a
queue row. Seeded records are appended at the end of `package_transparency_log`, since an append-only
log cannot take insertions, so they appear as recent entries carrying old timestamps.

### Fixtures

You can get test data by running the fixtures:

```bash
bin/console doctrine:fixtures:load --group base
bin/console doctrine:fixtures:load --group downloads --append
 ```

This will create some packages, update them from GitHub, populate them
with fake download stats, and assign a user named `dev` (with password: `dev`)
as their maintainer.

There is also a user `user` (with password: `user`) that has no access if you
need to check readonly views.

Finally there is a user `admin` (with password: `admin`) that has super admin
permissions.

### Search

Search is optional locally: with no Algolia config the rest of the site works
normally, `/search.json` returns an error response and the search page renders
without results. To use the search, setup an
[Algolia Account](https://www.algolia.com/) and configure following keys
in your `.env.local`:

```dotenv
ALGOLIA_APP_ID=
ALGOLIA_ADMIN_KEY=
ALGOLIA_SEARCH_KEY=
ALGOLIA_INDEX_NAME=
```

To setup the search index, run:

```bash
bin/console algolia:configure
bin/console packagist:index
```
