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

Setting up search
-----------------

The search index uses [Solr](http://lucene.apache.org/solr/), so you will have to install that on your server.
If you are running it on a non-standard host or port, you will have to adjust the configuration. See the
[NelmioSolariumBundle](https://github.com/nelmio/NelmioSolariumBundle) for more details.

You will also have to configure Solr. The standard `schema.xml` already covers most fields like `title` and
`description`. The following need to be added though:

    <fields>
        ...

        <field name="tags" type="text_general" indexed="true" stored="true" multiValued="true"/>

        ....
    </fields>

To index packages, just run `app/console packagist:index`. It is recommended to set up a cron job for
this command, and have it run every few minutes.
