#!/bin/sh

set -eu

cd /opt/trypost

timestamp=$(date -u +%Y-%m-%dT%H-%M-%SZ)
compose="docker compose --env-file .image"
db_username=$(sed -n 's/^DB_USERNAME=//p' .image)
db_database=$(sed -n 's/^DB_DATABASE=//p' .image)

$compose exec -T pgsql \
    pg_dump -U "$db_username" -d "$db_database" -Fc \
    | aws s3 cp - "s3://${BACKUP_BUCKET}/database/${timestamp}.dump"

tar -C /var/lib/docker/volumes/trypost_storage/_data -czf - . \
    | aws s3 cp - "s3://${BACKUP_BUCKET}/storage/${timestamp}.tar.gz"
