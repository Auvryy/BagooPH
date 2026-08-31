#!/bin/bash

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
    echo "  composer     Run composer commands inside app container"
    echo "  bash         Open bash shell inside app container"
    echo "  test         Run tests inside app container"
    ;;
esac
