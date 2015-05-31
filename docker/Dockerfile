FROM php:fpm
MAINTAINER Marcelo Andrade <marcelo.andrade.r@gmail.com>

ADD sources.list /etc/apt/
RUN apt-get update -y
RUN DEBIAN_FRONTEND=noninteractive apt-get install -y \
		--no-install-recommends \
		-o Dpkg::Options::="--force-confdef" \
		-o Dpkg::Options::="--force-confold" \
		git-core \
		curl \
		wget \
		supervisor \
		zlib1g-dev \
		nginx \
		mysql-client-5.5 \
		openssh-client
RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer
RUN docker-php-ext-install mbstring
RUN docker-php-ext-install pdo_mysql
RUN docker-php-ext-install pcntl
RUN docker-php-ext-install zip
ENV PATH $PATH:/root/.composer/vendor/bin

RUN wget https://phar.phpunit.de/phpunit.phar && \
	mv phpunit.phar /usr/local/bin/phpunit && \
	chmod u+x /usr/local/bin/phpunit

EXPOSE 80
VOLUME ["/app"]

WORKDIR /app

# Supervisor Configuration
ADD supervisord-php5-fpm.conf /etc/supervisor/conf.d/
ADD supervisord-nginx.conf /etc/supervisor/conf.d/

# Nginx Configuration
RUN rm /etc/nginx/sites-enabled/default
RUN ln -s /app/docker/nginx.conf /etc/nginx/sites-enabled/packagist.conf

# PHP-FPM Configuration
ADD php-fpm.conf /usr/local/etc/
ADD ./project.ini /usr/local/etc/php/conf.d/project.ini

ADD ./my.cnf /root/.my.cnf

# Workaround for boot2docker on mac
RUN usermod -u 1000 www-data

CMD ["supervisord", "-n"]
