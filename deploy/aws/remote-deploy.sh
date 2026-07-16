#!/bin/bash

set -euo pipefail

if [ "$#" -lt 3 ]; then
    echo "usage: remote-deploy.sh IMAGE_URI SECRET_ID BACKUP_BUCKET [DATABASE_BACKUP_KEY] [STORAGE_BACKUP_KEY]" >&2
    exit 1
fi

image_uri=$1
secret_id=$2
backup_bucket=$3
database_backup_key=${4:-}
storage_backup_key=${5:-}

cd /opt/trypost

aws secretsmanager get-secret-value \
    --secret-id "$secret_id" \
    --query SecretString \
    --output text > .env.new
chmod 600 .env.new
mv .env.new .env

{
    printf 'IMAGE_URI=%s\n' "$image_uri"
    sed -n '/^APP_DOMAIN=/p; /^DB_DATABASE=/p; /^DB_USERNAME=/p; /^DB_PASSWORD=/p' .env
} > .image
printf 'BACKUP_BUCKET=%s\n' "$backup_bucket" > .backup
chmod 600 .image .backup

db_username=$(sed -n 's/^DB_USERNAME=//p' .image)
db_database=$(sed -n 's/^DB_DATABASE=//p' .image)

aws ecr get-login-password --region us-east-1 \
    | docker login --username AWS --password-stdin "${image_uri%%/*}"

docker compose --env-file .image pull
docker compose --env-file .image up -d pgsql redis

if [ ! -f .database-restored ] && [ -n "$database_backup_key" ]; then
    aws s3 cp "s3://${backup_bucket}/${database_backup_key}" /tmp/trypost.dump
    docker compose --env-file .image exec -T pgsql \
        pg_restore \
            -U "$db_username" \
            -d "$db_database" \
            --clean \
            --if-exists \
            --no-owner \
            --exit-on-error < /tmp/trypost.dump
    rm /tmp/trypost.dump
    touch .database-restored
fi

if [ ! -f .storage-restored ] && [ -n "$storage_backup_key" ]; then
    aws s3 cp "s3://${backup_bucket}/${storage_backup_key}" /tmp/trypost-storage.tar.gz
    docker volume create trypost_storage >/dev/null
    storage_volume=$(docker volume inspect trypost_storage --format '{{.Mountpoint}}')
    tar -xzf /tmp/trypost-storage.tar.gz -C "$storage_volume"
    rm /tmp/trypost-storage.tar.gz
    touch .storage-restored
fi

docker compose --env-file .image run --rm --no-deps --entrypoint php app \
    artisan migrate --force
docker compose --env-file .image up -d --remove-orphans
docker compose --env-file .image exec -T app curl -fsS http://127.0.0.1/up >/dev/null

install -m 0755 backup.sh /opt/trypost/backup.sh
install -m 0644 trypost-backup.service /etc/systemd/system/trypost-backup.service
install -m 0644 trypost-backup.timer /etc/systemd/system/trypost-backup.timer
systemctl daemon-reload
systemctl enable --now trypost-backup.timer

printf '%s\n' "$image_uri" > REVISION
docker image prune -f >/dev/null

echo "deployed $image_uri"
