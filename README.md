Packagist
=========

Package Repository Website for Composer, see the [about page](http://packagist.org/about) on [packagist.org](http://packagist.org/) for more.

Installation
------------

- Clone the repository
- Run `bin/vendors install` to get all the vendors.
- Copy `app/config/parameters.yml.dist` to `app/config/parameters.yml` and edit the relevant values for your setup.
- Run `app/console doctrine:schema:create` to setup the DB.
- Run `app/console assets:install web` to deploy the assets on the web dir.
- Make a VirtualHost with DocumentRoot pointing to web/
- You should now be able to access the site, create a user, etc.
