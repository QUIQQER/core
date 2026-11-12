#!/usr/bin/env bash
set -Eeuo pipefail

bash /src/.oss-scanner/database.sh start
cd /src
exec ./tools/phpunit "$@"
