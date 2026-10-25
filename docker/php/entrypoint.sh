#!/bin/sh
set -eu

private_root="/var/www/html/storage/app/private"

# Artisan commands and tests run as root in the container. Repair private upload
# directory ownership before PHP-FPM drops requests to www-data.
for directory in delivery-proofs delivery-attempt-proofs kyc_documents; do
    path="$private_root/$directory"
    mkdir -p "$path"
    chown -R www-data:www-data "$path"
    chmod 700 "$path"
done

exec docker-php-entrypoint "$@"
