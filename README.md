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
- **[Symfony CLI](https://symfony.com/download)** to run the web server
- **MySQL** for the main data store
- **Redis** for some functionality (favorites, download statistics)
- **git / svn / hg** depending on which repositories you want to support

### Installation

1. Clone the repository
2. Create 2 databases:
    - `packagist` - for the web app
    - `packagist_test` - for running the tests
3. Install dependencies:
   ```bash
   composer install
   npm install
   ```
   The `composer install` command will prompt you for the database connection
   details on first install. The connection url is stored in the `.env` files.
4. Setup the database:
   ```bash
   bin/console doctrine:schema:create
   ```
5. Start the web server:
   ```bash
   symfony serve
   ```
6. Start Redis:
   ```bash
   docker compose up -d
   ```
7. Run a CRON job `bin/console packagist:run-workers` to make sure packages update.
7. Run `npm run build` or `npm run dev` to build (or build&watch) css/js files.

You should now be able to access the site, create a user, etc.

### Fixtures

You can get test data by running the fixtures:

```bash
bin/console doctrine:fixtures:load
 ```

This will create 100 packages from packagist.org, update them from GitHub,
populate them with fake download stats, and assign a user named `dev`
(with password: `dev`) as their maintainer.
