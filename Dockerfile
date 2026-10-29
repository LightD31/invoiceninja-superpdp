# Invoice Ninja v5 + the SuperPdp module.
#
#   docker build -t invoiceninja-superpdp:5 .
#   docker build --build-arg IN_IMAGE=invoiceninja/invoiceninja-debian:5.13.50 -t invoiceninja-superpdp:5.13.50 .
#
# The official image ships nwidart/laravel-modules and the composer merge
# plugin, so the module only needs to be copied in, enabled, and added to
# the optimized autoloader.
ARG IN_IMAGE=invoiceninja/invoiceninja-debian:5

FROM composer:2 AS composer

FROM ${IN_IMAGE}

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --chown=www-data:www-data Modules/SuperPdp /var/www/html/Modules/SuperPdp

WORKDIR /var/www/html

RUN php -r '$f = "modules_statuses.json"; $s = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; $s["SuperPdp"] = true; file_put_contents($f, json_encode($s, JSON_PRETTY_PRINT).PHP_EOL);' \
 && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev --no-scripts --no-interaction \
 && php -r 'require "vendor/autoload.php"; exit(class_exists(Modules\SuperPdp\Providers\SuperPdpServiceProvider::class) ? 0 : 1);' \
 && chown -R www-data:www-data vendor/composer modules_statuses.json \
 && rm /usr/local/bin/composer
