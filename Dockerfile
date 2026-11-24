ARG BASE_IMAGE=php:8.4.28-fpm-alpine

FROM ${BASE_IMAGE} AS builder
WORKDIR /app/data

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apk update && apk upgrade --no-cache

# phpredis comes from the GitHub release tarball with a pinned checksum:
# pecl.php.net answered HTTP 500 under load on 2026-10-21.
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad
RUN apk add --no-cache \
        git \
        curl \
        mariadb-client \
        libzip-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        icu-dev \
        oniguruma-dev \
        libxml2-dev \
        gmp-dev \
        ${PHPIZE_DEPS} \
    && mkdir -p /usr/src/php/ext/redis \
    && curl -fsSL -o /tmp/phpredis.tar.gz https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz \
    && echo "${PHPREDIS_SHA256}  /tmp/phpredis.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/phpredis.tar.gz --strip-components=1 -C /usr/src/php/ext/redis \
    && rm /tmp/phpredis.tar.gz \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        zip \
        opcache \
        pdo \
        pdo_mysql \
        mbstring \
        xml \
        exif \
        gmp \
        redis \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer --version=2.10.1 \
    && docker-php-source delete \
    && apk del --no-cache ${PHPIZE_DEPS}

COPY . .
RUN test ! -e private \
    && test ! -e web/sites/default/files \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && composer clear-cache \
    && test ! -e private \
    && test ! -e web/sites/default/files \
    && rm -rf web/core/modules/package_manager/tests

FROM ${BASE_IMAGE}
WORKDIR /app/data

RUN apk update && apk upgrade --no-cache \
    && apk add --no-cache \
        bash \
        ca-certificates \
        curl \
        freetype \
        icu-libs \
        icu-data-full \
        libjpeg-turbo \
        libpng \
        libxml2 \
        libzip \
        mariadb-client \
        msmtp \
        oniguruma \
        gmp \
        wget \
    && ln -sf /usr/bin/msmtp /usr/sbin/sendmail

COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d/docker-php-ext-* /usr/local/etc/php/conf.d/
# FIT-Connect reply decryption (RSA 4096) is unusably slow without native
# big-integer math: the JOSE library falls back to pure PHP.
RUN php -m | grep -qx gmp
COPY --from=builder /app/data ./

COPY docker/settings.php /app/data/web/sites/default/settings.php
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh

RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=60'; \
        echo 'opcache.enable_cli=1'; \
        echo 'memory_limit=512M'; \
        echo 'max_execution_time=600'; \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'display_errors=Off'; \
        echo 'display_startup_errors=Off'; \
        echo 'log_errors=On'; \
        echo 'expose_php=Off'; \
        echo 'sendmail_path=/usr/bin/msmtp -t -C /tmp/msmtprc'; \
    } > "$PHP_INI_DIR/conf.d/beantragt-production.ini"

RUN test ! -e private \
    && test ! -e web/sites/default/files \
    && adduser -D -u 1000 app \
    && mkdir -p web/sites/default/files config/sync /app/data/private /tmp/drupal \
    && touch web/sites/default/services.yml \
    && chown app:app web/sites/default/services.yml config/sync \
    && chown -R app:app web/sites/default/files /app/data/private /tmp/drupal \
    && printf "[www]\nuser = app\ngroup = app\n" > /usr/local/etc/php-fpm.d/zz-app-user.conf \
    && ln -sf /app/data/vendor/bin/drush /usr/local/bin/drush \
    && chmod +x /docker-entrypoint.sh

RUN set -eux; \
    ARCH="$(uname -m)"; \
    case "$ARCH" in \
        x86_64) BINARY="supercronic-linux-amd64"; SHA256="cc232108374e8e2b537045f2d5eaa1229f619db314c3ba6a14d38b35dc0aa123" ;; \
        aarch64) BINARY="supercronic-linux-arm64"; SHA256="4ae22576a78223d8ccdf5f3b3660a5cbb5288416bf0ca96afc4a36d195932b94" ;; \
        *) echo "Unsupported arch: $ARCH" && exit 1 ;; \
    esac; \
    wget -q -O /usr/local/bin/supercronic "https://github.com/aptible/supercronic/releases/download/v0.2.51/$BINARY"; \
    echo "$SHA256  /usr/local/bin/supercronic" | sha256sum -c -; \
    chmod +x /usr/local/bin/supercronic; \
    # Full cron every 5 minutes. Approved transfers, attachment analyses and
    # FIT-Connect replies must not wait for it: light jobs drain the dispatch
    # and the attachment analysis queues every minute and fetch replies every
    # two minutes. supercronic never starts a job while its previous run is
    # still active. Bulk item, finalize and unpack lanes run every minute
    # where the batch intake module is part of the build; bulk retention is
    # included in the full cron hook.
    printf '%s\n' \
        "*/5 * * * * cd /app/data && /app/data/vendor/bin/drush cron 2>&1 | tee -a /proc/1/fd/1" \
        "* * * * * cd /app/data && /app/data/vendor/bin/drush queue:run beantragt_delivery_dispatch --time-limit=45 2>&1 | tee -a /proc/1/fd/1" \
        "* * * * * cd /app/data && /app/data/vendor/bin/drush queue:run beantragt_application_attachment_analysis --time-limit=45 2>&1 | tee -a /proc/1/fd/1" \
        "*/2 * * * * cd /app/data && /app/data/vendor/bin/drush beantragt:delivery:replies:poll --json 2>&1 | tee -a /proc/1/fd/1" \
        "* * * * * cd /app/data && { [ ! -d web/modules/custom/beantragt_bulk ] || /app/data/vendor/bin/drush beantragt:bulk:work --queue=item --lane=1 --time-limit=55 2>&1 | tee -a /proc/1/fd/1; }" \
        "* * * * * cd /app/data && { [ ! -d web/modules/custom/beantragt_bulk ] || /app/data/vendor/bin/drush beantragt:bulk:work --queue=finalize --time-limit=55 2>&1 | tee -a /proc/1/fd/1; }" \
        "* * * * * cd /app/data && { [ ! -d web/modules/custom/beantragt_bulk ] || /app/data/vendor/bin/drush beantragt:bulk:work --queue=draft --time-limit=55 2>&1 | tee -a /proc/1/fd/1; }" \
        "* * * * * cd /app/data && { [ ! -d web/modules/custom/beantragt_bulk ] || /app/data/vendor/bin/drush beantragt:bulk:work --queue=decision --time-limit=55 2>&1 | tee -a /proc/1/fd/1; }" \
        "* * * * * cd /app/data && { [ ! -d web/modules/custom/beantragt_bulk ] || /app/data/vendor/bin/drush beantragt:bulk:work --queue=unpack --time-limit=55 2>&1 | tee -a /proc/1/fd/1; }" \
        > /etc/crontab

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=3s --start-period=1m --retries=3 \
    CMD php-fpm -t && kill -0 1 || exit 1

USER app

LABEL org.opencontainers.image.source="https://github.com/civicpatches/beantragt"
LABEL org.opencontainers.image.description="beantragt.ai Drupal application"

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["php-fpm"]
