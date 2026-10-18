#!/bin/bash

set -e

# Bagoo Project CLI Helper

cmd=$1
shift

case "$cmd" in
  start|up)
    docker compose up -d
    echo "Bagoo is running at: http://localhost:8000"
    ;;
  stop|down)
    docker compose down
    ;;
  build)
    docker compose build
    ;;
  restart)
    docker compose restart
    ;;
  logs)
    docker compose logs -f "$@"
    ;;
  artisan)
    docker compose exec app php artisan "$@"
    ;;
  migrate)
    docker compose exec app php artisan migrate "$@"
    ;;
  seed)
    docker compose exec app php artisan db:seed "$@"
    ;;
  fresh)
    docker compose exec app php artisan migrate:fresh --seed "$@"
    ;;
  npm)
    docker compose exec app npm "$@"
    ;;
  deploy)
    docker compose stop scheduler
    docker compose build app
    docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
    docker compose run --rm --no-deps app npm ci
    docker compose run --rm --no-deps -e "NODE_OPTIONS=${NODE_OPTIONS:---max-old-space-size=1024}" app npm run build
    docker compose up -d db
    docker compose up -d app web
    docker compose exec app php artisan migrate --force
    docker compose exec app php artisan optimize:clear
    docker compose exec app php artisan config:cache
    docker compose exec app php artisan view:cache
    docker compose up -d --no-deps scheduler
    echo "Deployment checks passed. Frontend assets, Laravel caches and the scheduler are ready."
    ;;
  verify)
    docker compose exec app sh -lc 'test -f public/build/manifest.json || { echo "Missing public/build/manifest.json. Run ./bagoo.sh deploy." >&2; exit 1; }'
    docker compose exec app php artisan migrate:status --no-ansi --pending=1
    if [ -z "$(docker compose ps --status running -q scheduler)" ]; then
      echo "The scheduler is not running. Run ./bagoo.sh deploy before verifying." >&2
      exit 1
    fi
    docker compose exec -T scheduler php artisan schedule:list --no-ansi
    echo "Deployment verification passed."
    ;;
  composer)
    docker compose exec app composer "$@"
    ;;
  bash)
    docker compose exec app bash
    ;;
  test)
    docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app php artisan test "$@"
    ;;
  *)
    echo "Bagoo CLI Helper"
    echo "Usage: ./bagoo.sh [command]"
    echo ""
    echo "Commands:"
    echo "  start|up     Start Docker containers in background"
    echo "  stop|down    Stop Docker containers"
    echo "  build        Rebuild Docker images"
    echo "  logs         Tail container logs"
    echo "  artisan      Run php artisan commands inside app container"
    echo "  migrate      Run database migrations"
    echo "  seed         Run database seeders"
    echo "  fresh        Run fresh migrations and seed demo data"
    echo "  npm          Run npm commands inside app container"
    echo "  deploy       Build assets, migrate, warm caches and start the scheduler"
    echo "  verify       Confirm assets, migrations and the running scheduler"
    echo "  composer     Run composer commands inside app container"
    echo "  bash         Open bash shell inside app container"
    echo "  test         Run tests inside app container"
    ;;
esac
