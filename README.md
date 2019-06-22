Packagist
=========

Package Repository Website for Composer, see the [about page](https://packagist.org/about) on [packagist.org](https://packagist.org/) for more.

**This project is not meant for re-use.**

It is open source to make it easy to contribute. We provide no support if you want to run your own, and will do breaking changes without notice.

Check out [Private Packagist](https://packagist.com/) if you want to host your own packages.

Development
------------

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
   ```
   The composer install will prompt you for the database connection details on first install.
4. Setup the database:
   ```bash
   app/console doctrine:schema:create
   ```
5. Start the web server:
   ```bash
   symfony serve
   ```

You should now be able to access the site, create a user, etc.
