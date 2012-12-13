Packagist
=========

Package Repository Website for Composer, see the [about page](http://packagist.org/about) on [packagist.org](http://packagist.org/) for more.

Requirements
------------

- MySQL for the main data store
- Redis for some functionality (favorites, download statistics)
- Solr for search
- git/svn/hg depending on which repositories you want to support

Installation
------------

1. Clone the repository
2. Copy `app/config/parameters.yml.dist` to `app/config/parameters.yml` and edit the relevant values for your setup.
3. Install dependencies: `php composer.phar install`
4. Run `app/console doctrine:schema:create` to setup the DB.
5. Run `app/console assets:install web` to deploy the assets on the web dir.
6. Make a VirtualHost with DocumentRoot pointing to web/

You should now be able to access the site, create a user, etc.

Setting up search
-----------------

The search index uses [Solr](http://lucene.apache.org/solr/) 3.6, so you will have to install that on your server.
If you are running it on a non-standard host or port, you will have to adjust the configuration. See the
[NelmioSolariumBundle](https://github.com/nelmio/NelmioSolariumBundle) for more details.

You will also have to configure Solr. Use the `schema.xml` provided in the doc/ directory for that.

To index packages, just run `app/console packagist:index`. It is recommended to set up a cron job for
this command, and have it run every few minutes.

Day-to-Day Operation
--------------------

There are a few commands you should run periodically (ideally set up a cron job running every minute or so):

    app/console packagist:update --no-debug --env=prod
    app/console packagist:dump --no-debug --env=prod
    app/console packagist:index --no-debug --env=prod

The latter is optional and only required if you are running a solr server.