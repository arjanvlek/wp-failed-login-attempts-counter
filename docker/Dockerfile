# PHP CLI image for running the plugin's PHPUnit test suite.
FROM php:8.3-cli

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		ca-certificates \
		git \
		mariadb-client \
		subversion \
		unzip \
	&& rm -rf /var/lib/apt/lists/* \
	&& docker-php-ext-install mysqli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
