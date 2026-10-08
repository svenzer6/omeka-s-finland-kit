#!/bin/sh
# Prepare Omeka S on container start, then hand over to Apache.
#  1. write config/database.ini from the environment
#  2. wait for the database
#  3. install Omeka S (when OMEKA_ADMIN_EMAIL / OMEKA_ADMIN_PASSWORD are set)
#  4. install/upgrade/activate the pinned modules
#  5. seed the bilingual demo when KIT_DEMO=1 and it does not exist yet
set -eu

OMEKA=/var/www/html
KIT=/opt/kit

: "${OMEKA_DB_HOST:=db}"
: "${OMEKA_DB_NAME:=omeka}"
: "${OMEKA_DB_USER:=omeka}"
: "${OMEKA_DB_PASSWORD:?Set OMEKA_DB_PASSWORD}"

cat > "$OMEKA/config/database.ini" <<EOF
user     = "$OMEKA_DB_USER"
password = "$OMEKA_DB_PASSWORD"
dbname   = "$OMEKA_DB_NAME"
host     = "$OMEKA_DB_HOST"
EOF
chown www-data:www-data "$OMEKA/config/database.ini"
chmod 640 "$OMEKA/config/database.ini"

echo "kit: waiting for database $OMEKA_DB_HOST..."
php -r '
for ($i = 0; $i < 60; $i++) {
    try {
        new PDO("mysql:host=" . getenv("OMEKA_DB_HOST") . ";dbname=" . getenv("OMEKA_DB_NAME"),
            getenv("OMEKA_DB_USER"), getenv("OMEKA_DB_PASSWORD"));
        exit(0);
    } catch (Throwable $e) {
        sleep(2);
    }
}
fwrite(STDERR, "Database not reachable\n");
exit(1);
'

run_php() {
    php -d memory_limit=1G "$@"
}

if [ -n "${OMEKA_ADMIN_EMAIL:-}" ] && [ -n "${OMEKA_ADMIN_PASSWORD:-}" ]; then
    run_php "$KIT/scripts/install_omeka.php" "$OMEKA"
    run_php "$KIT/scripts/install_modules.php" "$OMEKA"
    if [ "${KIT_DEMO:-0}" = "1" ]; then
        # The seed script refuses to run twice unless --reset is given.
        run_php "$KIT/scripts/seed_demo.php" "$OMEKA" || echo "kit: demo already present, skipped"
    fi
else
    echo "kit: OMEKA_ADMIN_EMAIL/OMEKA_ADMIN_PASSWORD not set, use the web installer at /install"
fi

chown -R www-data:www-data "$OMEKA/files"
exec "$@"
