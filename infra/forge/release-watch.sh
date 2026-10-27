#!/usr/bin/env bash
# Forge daemon for sites with zero-downtime deployments.
# The UniConnect daemons run from `current`, but a running process stays in the release it
# started in. When a deploy switches `current` to a new release, this stops every UniConnect
# process still running an older release; Supervisor restarts it straight away from `current`.
# The API drains for a few seconds first (scripts/run-api.sh), so in-flight requests finish.
# Then it exits, so Supervisor restarts the watcher from the new release too.
# Usage (daemon command): bash /home/forge/<site>/current/infra/forge/release-watch.sh /home/forge/<site>
set -uo pipefail
ROOT="${1:?site root, e.g. /home/forge/uniconnect.on-forge.com}"

sweep() { # stop UniConnect processes outside the active release
    local active="$1" pid dir
    for pid in $(pgrep -u "$(id -u)" -f 'scripts/run-|artisan reverb:start'); do
        dir=$(readlink -f "/proc/$pid/cwd" 2>/dev/null) || continue
        case "$dir" in
            "$ROOT"/releases/*)
                if [ "${dir#"$active"}" = "$dir" ]; then
                    echo "release-watch: stopping pid $pid ($dir)"
                    kill -TERM "$pid" 2>/dev/null
                fi ;;
        esac
    done
}

active=$(readlink -f "$ROOT/current")
sweep "$active"   # catch up on a deploy that happened while the watcher was down
while sleep 2; do
    now=$(readlink -f "$ROOT/current")
    if [ "$now" != "$active" ]; then
        echo "release-watch: current -> $now"
        sweep "$now"
        exit 0
    fi
done
