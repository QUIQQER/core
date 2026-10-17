#!/usr/bin/env bash
set -euo pipefail

if [[ "${GITLAB_CI:-}" != "true" ]]; then
    echo "CI preparation must only run inside GitLab CI." >&2
    exit 1
fi

# The testing image starts cron before restoring the installation. Prevent new
# automatic updates and let any existing cron workers finish before Composer.
sudo -n service cron stop

process_root="${1:-/proc}"

if [[ ! -d "$process_root" || ! -r "$process_root" ]]; then
    echo "Cannot inspect cron workers; refusing to modify the CI installation." >&2
    exit 1
fi

has_cron_workers() {
    local process name index
    local -a arguments

    for process in "$process_root"/[0-9]*; do
        if ! { IFS= read -r name < "$process/comm"; } 2>/dev/null; then
            continue
        fi

        if [[ "$name" == "CRON" ]]; then
            return 0
        fi

        if [[ "$name" != php* ]] ||
            ! { mapfile -d '' -t arguments < "$process/cmdline"; } 2>/dev/null; then
            continue
        fi

        for ((index = 0; index + 1 < ${#arguments[@]}; index++)); do
            if [[ "${arguments[index]}" == "/var/www/html/console" &&
                "${arguments[index + 1]}" == "cron" ]]; then
                return 0
            fi
        done
    done

    return 1
}

for attempt in {1..60}; do
    if ! has_cron_workers; then
        exit 0
    fi

    sleep 1
done

echo "Cron workers are still active; refusing to modify the CI installation." >&2
exit 1
