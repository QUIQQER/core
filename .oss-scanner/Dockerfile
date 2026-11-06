FROM quiqqer/testing:v1-php8.5

USER root

# Use the CI test runtime with a disposable database on loopback. Do not start
# the image's entrypoint, cron, or automatic package updates during the audit.
ENV GITLAB_CI=true \
    QUIQQER_RESTORE_ON_START=0 \
    QUIQQER_DB_VENDOR=mariadb \
    QUIQQER_DB_DRIVER=mysql \
    QUIQQER_DB_HOST=127.0.0.1 \
    QUIQQER_DB_PORT=3306 \
    QUIQQER_DB_NAME=quiqqer \
    QUIQQER_DB_USER=quiqqer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    XDEBUG_MODE=off

ENTRYPOINT []
CMD ["/bin/bash"]

# Match the web-style environment assumed by the Composer regression tests:
# getenv() remains available; $_ENV is populated explicitly by the application.
RUN printf 'variables_order=GPCS\n' > /usr/local/etc/php/conf.d/oss-scanner.ini \
    && printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && apt-get update \
    && apt-get install --yes --no-install-recommends mariadb-server \
    && rm -rf /var/lib/apt/lists/*

# Keep the physical package location expected by the test bootstrap. /src will
# point to that same checkout, so edits made by the scanner also affect tests.
COPY . /opt/quiqqer-core

RUN php /opt/quiqqer-core/.oss-scanner/prepare-composer.php \
    && php /var/www/html/var/composer/composer.phar update \
        quiqqer/core quiqqer/rest quiqqer/oauth-server \
        --with-all-dependencies --minimal-changes \
        --working-dir=/var/www/html/var/composer \
        --no-interaction --no-progress --no-scripts --prefer-dist \
    && rm -rf /var/www/html/packages/quiqqer/core \
    && mv /opt/quiqqer-core /var/www/html/packages/quiqqer/core \
    && ln -s /var/www/html/packages/quiqqer/core /src \
    && php /var/www/html/var/composer/composer.phar dump-autoload \
        --working-dir=/var/www/html/var/composer --no-interaction --no-scripts \
    && mkdir -p /src/tools \
    && curl --fail --location --silent --show-error --retry 3 \
        --connect-timeout 10 --max-time 120 \
        https://update.quiqqer.com/phar-mirror/phpunit/10/phpunit.phar \
        --output /src/tools/phpunit \
    && chmod +x /src/tools/phpunit

WORKDIR /src

RUN bash .oss-scanner/prepare-database.sh

RUN --network=none bash .oss-scanner/run-tests.sh --filter PrintTemplateSecurityTest \
    && bash .oss-scanner/database.sh stop \
    && rm -rf /tmp/quiqqer-oss-scanner
