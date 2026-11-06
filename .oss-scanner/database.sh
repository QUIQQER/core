#!/usr/bin/env bash
set -Eeuo pipefail

runtime=/tmp/quiqqer-oss-scanner
socket="$runtime/mysql.sock"
snapshot=/opt/quiqqer-oss-scanner/database.tar.gz

case "${1:-start}" in
    start)
        umask 077
        mkdir -p "$runtime"

        if mariadb-admin --no-defaults --socket="$socket" ping --silent 2>/dev/null; then
            exit 0
        fi

        if [ ! -d "$runtime/mysql/mysql" ]; then
            if [ -f "$snapshot" ]; then
                tar --extract --gzip --file="$snapshot" --directory="$runtime"
            else
                mariadb-install-db --no-defaults --datadir="$runtime/mysql" \
                    --auth-root-authentication-method=normal --skip-test-db \
                    > "$runtime/initialize.log" 2>&1
            fi
        fi

        mariadbd --no-defaults --user="$(id -un)" --datadir="$runtime/mysql" \
            --socket="$socket" --pid-file="$runtime/mysql.pid" \
            --bind-address=127.0.0.1 --port=3306 \
            --innodb-buffer-pool-size=128M --innodb-log-file-size=32M \
            --log-error="$runtime/mysql.log" > "$runtime/mysql.stdout.log" 2>&1 &
        database_pid=$!

        for attempt in {1..60}; do
            if mariadb-admin --no-defaults --socket="$socket" ping --silent 2>/dev/null; then
                exit 0
            fi
            if ! kill -0 "$database_pid" 2>/dev/null; then
                break
            fi
            sleep 1
        done

        cat "$runtime/mysql.log" >&2
        echo 'The local scanner database did not start.' >&2
        exit 1
        ;;
    stop)
        if mariadb-admin --no-defaults --socket="$socket" ping --silent 2>/dev/null; then
            mariadb-admin --no-defaults --socket="$socket" shutdown
        fi
        ;;
    *)
        echo 'Usage: bash .oss-scanner/database.sh start|stop' >&2
        exit 2
        ;;
esac
