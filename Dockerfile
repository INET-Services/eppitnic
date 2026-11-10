# Builds and serves eppitnic's REST API from one image: nginx in front,
# php-fpm behind it over a unix socket, both under tini. See docs/DOCKER.md
# for how the pieces (this file, compose.yaml, docker/) fit together, and
# for the measurements behind a few choices below that would otherwise look
# arbitrary.

# One stage for the PHP platform, shared by the two below, so that the image
# resolving the dependencies is the image that will run them. Building in the
# composer image instead means `composer install` validates composer.json
# against a platform that is not the runtime's -- it has no pdo_mysql, so the
# build fails outright, and --ignore-platform-req would only silence the check
# until the next extension is added.
#
# pdo_mysql and intl are the extensions this base image lacks. pdo_mysql,
# verified against real usage: the DSN is always mysql: (Config,
# Setup\DatabaseCredentials) and SchemaInstaller's `SHOW TABLES` is MySQL-only.
# intl formats amounts per region.lc_monetary: musl ships no locale data, ICU
# does -- icu-data-full, since icu-libs alone brings English only and ICU then
# quietly falls back to en_US. The alpine images ship no compiler, so
# $PHPIZE_DEPS and icu-dev go in and come straight back out -- build tooling.
FROM php:8.5-fpm-alpine AS base
RUN apk add --no-cache icu-libs icu-data-full \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install pdo_mysql intl \
    && apk del .build-deps

FROM base AS builder
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
# the full tree, not just composer.json/.lock -- --optimize-autoloader scans
# src/ to build its class map, so it needs the real source present, and
# .dockerignore already keeps vendor/, tests/ and the rest of the build
# context down to what this actually uses
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && php docker/notices.php /app > /app/THIRD-PARTY-NOTICES.txt

# For the About screen (src/Support/About.php), from deploy/release-images.sh.
# Always written, so the runtime stage has a file to copy and a stale one from
# the build context never ships; `{}` means unknown.
ARG COMMIT=
ARG BUILT=
RUN if [ -n "$COMMIT" ]; then \
        printf '{"commit":"%s","built":"%s"}\n' "$COMMIT" "$BUILT" > /app/BUILD.json; \
    else \
        echo '{}' > /app/BUILD.json; \
    fi

FROM base AS runtime

# nginx: this application's own front controller, not the base image's.
# tini: a minimal init as PID 1, so `docker stop` reaches start-web.sh as a
#   real signal instead of only stopping tini itself.
# su-exec: drops from root to www-data before running one-shot CLI verbs
#   (see the eppitnic-cli-* compose services) -- config.php and selftest notes
#   then end up owned the same as the web/cron processes that read them back,
#   rather than root-owned and unreadable to the www-data worker that needs
#   them next.
#
# No docker-php-ext-enable opcache here: on this base image OPcache is
# compiled into the core binary, not a separate loadable .so -- there is
# nothing to enable. docker/php.ini's opcache.* settings are what actually
# turn it on.
# The base image's own default pool config -- www.conf/www.conf.default on
# older tags, docker.conf + zz-docker.conf on this one (the "zz" prefix sorts
# it after eppitnic.conf, so its [www] pool -- with no `user` directive --
# is still there to break startup even though ours is correctly configured;
# php-fpm refuses to start at all if *any* pool is missing `user` while
# running as root). Removing all four names covers both generations.
RUN apk add --no-cache nginx tini su-exec \
    && rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf.default \
             /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
    && rm -f /etc/nginx/http.d/default.conf \
    && mkdir -p /run/php

WORKDIR /app

# xsd/ is never read at runtime -- only its filenames appear, as
# xsi:schemaLocation string literals in src/Epp/XmlBuilder.php -- so it's
# left out here rather than copied and then ignored.
COPY --from=builder /app/bin            ./bin
COPY --from=builder /app/config         ./config
COPY --from=builder /app/public         ./public
COPY --from=builder /app/src            ./src
COPY --from=builder /app/vendor         ./vendor
COPY --from=builder /app/composer.json  ./composer.json
COPY --from=builder /app/BUILD.json     ./BUILD.json

# The packages' own license files (the LGPL one among them) stay in vendor/;
# the notices file lists them all.
COPY LICENSE                                   /usr/share/eppitnic/LICENSE
COPY --from=builder /app/THIRD-PARTY-NOTICES.txt /usr/share/eppitnic/THIRD-PARTY-NOTICES.txt

RUN ln -s /app/bin/eppitnic /usr/local/bin/eppitnic \
    && chmod +x /app/bin/eppitnic

COPY docker/php.ini                  /usr/local/etc/php/conf.d/eppitnic.ini
COPY docker/php-fpm.d/eppitnic.conf  /usr/local/etc/php-fpm.d/eppitnic.conf
COPY docker/nginx.conf               /etc/nginx/http.d/default.conf
COPY docker/crontab                  /etc/crontabs/root
COPY docker/entrypoint.sh            /docker/entrypoint.sh
COPY docker/start-web.sh             /docker/start-web.sh
RUN chmod +x /docker/entrypoint.sh /docker/start-web.sh \
    && chmod 600 /etc/crontabs/root

# The container-side halves of the two per-instance paths (see
# src/Setup/ConfigFile.php and src/Selftest/Leftovers.php) are fixed by the
# image, not by the instance: what actually varies between instances is the
# host side of the /data bind mount and the published port, both set in
# compose.yaml/compose.multi.yaml.sample. Override these with `-e` for a
# different in-container layout; no instance should need to.
ENV EPPITNIC_CONFIG_DIR=/data/config \
    EPPITNIC_VAR_DIR=/data/var

# VERSION, COMMIT and BUILT come from deploy/release-images.sh.
ARG VERSION=dev
ARG COMMIT=
ARG BUILT=
LABEL org.opencontainers.image.title="eppitnic" \
      org.opencontainers.image.version="$VERSION" \
      org.opencontainers.image.revision="$COMMIT" \
      org.opencontainers.image.created="$BUILT" \
      org.opencontainers.image.licenses="BSD-3-Clause" \
      org.opencontainers.image.source="https://github.com/INET-Services/eppitnic"

EXPOSE 80

ENTRYPOINT ["/sbin/tini", "--", "/docker/entrypoint.sh"]
CMD ["/docker/start-web.sh"]
