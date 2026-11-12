#!/usr/bin/env bash
set -Eeuo pipefail

bash /src/.oss-scanner/database.sh start
trap 'bash /src/.oss-scanner/database.sh stop' EXIT

# These are disposable CI fixture credentials, used only inside the image.
mariadb --no-defaults --socket=/tmp/quiqqer-oss-scanner/mysql.sock <<'SQL'
CREATE DATABASE quiqqer;
CREATE USER 'quiqqer'@'127.0.0.1' IDENTIFIED BY 'quiqqer';
CREATE USER 'quiqqer'@'localhost' IDENTIFIED BY 'quiqqer';
GRANT ALL PRIVILEGES ON quiqqer.* TO 'quiqqer'@'127.0.0.1';
GRANT ALL PRIVILEGES ON quiqqer.* TO 'quiqqer'@'localhost';
SQL

# Reuse the same prepared data/configuration as GitLab CI, then apply the
# checkout's schemas, permissions, events and translations to that installation.
quiqqer-ci-restore-system
cd /var/www/html
./console setup

bash /src/.oss-scanner/database.sh stop
trap - EXIT
mkdir -p /opt/quiqqer-oss-scanner
tar --create --gzip --file=/opt/quiqqer-oss-scanner/database.tar.gz \
    --directory=/tmp/quiqqer-oss-scanner mysql
rm -rf /tmp/quiqqer-oss-scanner
