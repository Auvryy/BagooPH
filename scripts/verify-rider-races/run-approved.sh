#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" != '--approved-disposable-postgres' ]]; then
    echo 'Requires explicit authorization for the isolated PostgreSQL exception.' >&2
    exit 2
fi
race_repo=$(git rev-parse --show-toplevel)
cd "$race_repo"
if [[ -n "${DOCKER_HOST:-}" ]] || [[ "$(docker context inspect --format '{{(index .Endpoints "docker").Host}}')" != unix://* ]]; then
    echo 'Refusing a remote Docker endpoint.' >&2
    exit 2
fi
race_suffix=$(python3 -c 'import secrets; print(secrets.token_hex(6))')
export RIDER_RACE_DATABASE="bagoo_rider_race_$race_suffix"
export RIDER_RACE_ARTIFACT_DIR="$race_repo/.codex/native-api/postgres-races/$race_suffix"
export RIDER_RACE_PHP_IMAGE
RIDER_RACE_PHP_IMAGE=$(docker inspect "$(docker compose ps -q app)" --format '{{.Image}}')
mkdir -p "$RIDER_RACE_ARTIFACT_DIR/storage/framework/cache/data" "$RIDER_RACE_ARTIFACT_DIR/storage/framework/views" \
    "$RIDER_RACE_ARTIFACT_DIR/storage/framework/sessions" "$RIDER_RACE_ARTIFACT_DIR/storage/logs" \
    "$RIDER_RACE_ARTIFACT_DIR/storage/app/private" "$RIDER_RACE_ARTIFACT_DIR/storage/app/public"
race_compose=(docker compose -f scripts/verify-rider-races/compose.yaml --project-name "rider-race-$race_suffix")
cleanup() { "${race_compose[@]}" down --volumes --remove-orphans >/dev/null; }
trap cleanup EXIT
"${race_compose[@]}" up --abort-on-container-exit --exit-code-from verifier \
    > "$RIDER_RACE_ARTIFACT_DIR/run.log" 2>&1
