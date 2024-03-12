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
3. Start the web server:
   ```bash
   symfony serve
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
8. Run `npm run build` or `npm run dev` to build (or build&watch) css/js files. When using Docker run `docker compose run node npm run dev` to watch css/js files.

You should now be able to access the site, create a user, etc.

### Fixtures

You can get test data by running the fixtures:

```bash
bin/console doctrine:fixtures:load
 ```

This will create 100 packages from packagist.org, update them from GitHub,
populate them with fake download stats, and assign a user named `dev`
(with password: `dev`) as their maintainer.

### Search

To use the search in your local development environment, setup an
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
