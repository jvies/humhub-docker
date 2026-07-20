FROM dunglas/frankenphp:php8.5 AS builder

ARG HUMHUB_VERSION
ARG VCS_REF


RUN apt-get update && apt-get install -y --no-install-recommends \
    acl \
    ca-certificates \
    curl \
    file \
    git \
    libtree \
    nodejs \
    npm \
    tzdata \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')" && \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")" && \
    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then \
        >&2 echo 'ERROR: Invalid installer checksum'; \
        rm composer-setup.php; \
        exit 1; \
    fi && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    rm composer-setup.php

RUN install-php-extensions \
    apcu \
    bcmath \
    exif \
    gd \
    gmp \
    imagick \
    intl \
    ldap \
    opcache \
    pcntl \
    pdo_mysql \
    pdo_sqlite \
    zip

ENV PHP_POST_MAX_SIZE=16M \
    PHP_UPLOAD_MAX_FILESIZE=10M \
    PHP_MAX_EXECUTION_TIME=60 \
    PHP_MEMORY_LIMIT=1G \
    PHP_TIMEZONE=UTC

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "post_max_size = ${PHP_POST_MAX_SIZE}" >> "$PHP_INI_DIR/conf.d/humhub.ini" && \
    echo "upload_max_filesize = ${PHP_UPLOAD_MAX_FILESIZE}" >> "$PHP_INI_DIR/conf.d/humhub.ini" && \
    echo "max_execution_time = ${PHP_MAX_EXECUTION_TIME}" >> "$PHP_INI_DIR/conf.d/humhub.ini" && \
    echo "memory_limit = ${PHP_MEMORY_LIMIT}" >> "$PHP_INI_DIR/conf.d/humhub.ini" && \
    echo "date.timezone = ${PHP_TIMEZONE}" >> "$PHP_INI_DIR/conf.d/humhub.ini"

# JVI: for debug
RUN echo "display_errors = On" >> "$PHP_INI_DIR/conf.d/humhub.ini" && \
    echo "display_startup_errors = On" >> "$PHP_INI_DIR/conf.d/humhub.ini" && \
    echo "error_reporting = E_ALL" >> "$PHP_INI_DIR/conf.d/humhub.ini"

WORKDIR /usr/src/
ADD https://github.com/humhub/humhub/archive/v${HUMHUB_VERSION}.tar.gz /usr/src/
RUN tar xzf v${HUMHUB_VERSION}.tar.gz && \
    mv humhub-${HUMHUB_VERSION} humhub && \
    rm v${HUMHUB_VERSION}.tar.gz

WORKDIR /usr/src/humhub

RUN composer config --no-plugins allow-plugins.yiisoft/yii2-composer true && \
    composer install --no-ansi --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader && \
    chmod +x protected/yii && \
    chmod +x protected/yii.bat && \
    npm install grunt && \
    npm install -g grunt-cli && \
    grunt build-assets && \
    rm -rf ./node_modules && \
    rm -f protected/config/common.php && \
    echo "v${HUMHUB_VERSION}" > .version

# Prepare distroless bundle
WORKDIR /
RUN mkdir -p /dist/usr/lib /dist/usr/local/bin /dist/usr/local/lib /dist/usr/local/etc /dist/etc /dist/usr/share /dist/etc/frankenphp
RUN cp /usr/local/bin/frankenphp /dist/usr/local/bin/frankenphp
RUN cp -r /usr/local/lib/php /dist/usr/local/lib/php
RUN cp -r /usr/local/etc/php /dist/usr/local/etc/php
RUN cp /etc/caddy/Caddyfile /dist/etc/frankenphp/Caddyfile
RUN cp -r /usr/share/zoneinfo /dist/usr/share/zoneinfo

# Resolve shared libraries
RUN EXT_DIR="$(php -r 'echo ini_get("extension_dir");')" && \
    FRANKENPHP_BIN="/usr/local/bin/frankenphp" && \
    for target in "$FRANKENPHP_BIN" $(find "$EXT_DIR" -maxdepth 2 -type f -name "*.so"); do \
        libtree -pv "$target" | sed 's/.*── \(.*\) \[.*/\1/' | grep -v "^$target" | while IFS= read -r lib; do \
            [ -z "$lib" ] && continue; \
            cp -n "$lib" "/dist/usr/lib/" || true; \
        done; \
    done

# Create user and group files for distroless
RUN groupadd -g 101 humhub && \
    useradd -u 100 -g humhub -d /app/public humhub && \
    grep humhub /etc/passwd > /dist/etc/passwd && \
    grep humhub /etc/group > /dist/etc/group

FROM gcr.io/distroless/base-debian13 AS runner
#FROM debian:13-slim AS runner

ARG HUMHUB_VERSION
ARG VCS_REF
ARG BUILD_DATE

LABEL name="HumHub" version=${HUMHUB_VERSION} variant="frankenphp" \
      org.label-schema.build-date=$BUILD_DATE \
      org.label-schema.name="HumHub" \
      org.label-schema.description="HumHub is a feature rich and highly flexible OpenSource Social Network Kit written in PHP" \
      org.label-schema.url="https://www.humhub.com/" \
      org.label-schema.vcs-ref=$VCS_REF \
      org.label-schema.vcs-url="https://github.com/jvies/humhub-docker" \
      org.label-schema.vendor="HumHub GmbH" \
      org.label-schema.version=${HUMHUB_VERSION} \
      org.label-schema.schema-version="1.0"

# Copy bundled artifacts
COPY --from=builder /dist /

# Copy HumHub
COPY --from=builder --chown=100:101 /usr/src/humhub /app/public

COPY --chown=100:101 base/ /

# Set Library Path
ENV LD_LIBRARY_PATH=/usr/lib

# Run as non-root user
USER humhub

VOLUME /app/public/uploads
VOLUME /app/public/protected/config
VOLUME /app/public/protected/modules

WORKDIR /app/public

EXPOSE 8080

# Point directly to PHP execution of entrypoint.php
ENTRYPOINT ["/usr/local/bin/frankenphp", "php-cli", "/app/entrypoint.php"]

# Command args passed to pcntl_exec / entrypoint.php
CMD ["/usr/local/bin/frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
