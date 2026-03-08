ARG HUMHUB_VERSION
ARG VCS_REF
ARG BUILD_DEPS="\
    ca-certificates \
    nodejs \
    npm \
    php84 \
    php84-ctype \
    php84-curl \
    php84-dom \
    php84-exif \
    php84-fileinfo \
    php84-gd \
    php84-iconv \
    php84-intl \
    php84-json \
    php84-ldap \
    php84-mbstring \
    php84-openssl \
    php84-pdo_mysql \
    php84-phar \
    php84-simplexml \
    php84-sodium \
    php84-tokenizer \
    php84-xml \
    php84-xmlreader \
    php84-xmlwriter \
    php84-zip \
    composer \
    tzdata \
    "

FROM docker.io/library/alpine:3.23.3 AS builder

ARG HUMHUB_VERSION
ARG BUILD_DEPS

RUN apk add --no-cache --update $BUILD_DEPS

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
    rm -rf ./node_modules

FROM dunglas/frankenphp:php8.4-alpine AS runner

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

# Install system dependencies
RUN apk add --no-cache \
    ca-certificates \
    curl \
    icu-data-full \
    imagemagick \
    libintl \
    shadow \
    sqlite \
    tzdata

# Install PHP extensions
RUN install-php-extensions \
    apcu \
    bcmath \
    exif \
    gd \
    gmp \
    intl \
    ldap \
    opcache \
    pdo_mysql \
    pdo_sqlite \
    zip \
    imagick

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

# Create user and group
RUN addgroup -g 101 -S humhub && \
    adduser -u 100 -D -S -G humhub humhub && \
	setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp;
RUN	chown -R humhub:humhub /config/caddy /data/caddy

# Copy HumHub
COPY --from=builder --chown=humhub:humhub --chmod=u+rw /usr/src/humhub /app/public
RUN chown humhub:humhub /app/public

USER humhub

COPY --chown=humhub:humhub humhub/ /usr/src/humhub/

RUN rm -f /app/public/protected/config/common.php && \
    echo "v${HUMHUB_VERSION}" > /usr/src/humhub/.version

COPY base/ /
COPY --chmod=+x docker-entrypoint.sh /docker-entrypoint.sh

VOLUME /app/public/uploads
VOLUME /app/public/protected/config
VOLUME /app/public/protected/modules

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
