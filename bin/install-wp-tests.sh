#!/usr/bin/env bash
#
# Install WordPress test suite for plugin testing.
# Usage: ./bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#

if [ $# -lt 3 ]; then
    echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

set -ex

if [ "$WP_VERSION" = "latest" ]; then
    WP_VERSION=$(curl -s https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
fi

# Set up WP test suite (download via GitHub tarball — no svn required)
if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    mkdir -p "$WP_TESTS_DIR"

    TMPDIR=$(mktemp -d)
    curl -sL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" -o "$TMPDIR/wp-dev.tar.gz"
    tar -xzf "$TMPDIR/wp-dev.tar.gz" -C "$TMPDIR"

    WP_DEV_DIR="$TMPDIR/wordpress-develop-${WP_VERSION}"
    cp -r "$WP_DEV_DIR/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
    cp -r "$WP_DEV_DIR/tests/phpunit/data" "$WP_TESTS_DIR/data"
    rm -rf "$TMPDIR"
fi

# Use existing WP core if available, otherwise download
if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
    mkdir -p "$WP_CORE_DIR"
    curl -sL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wordpress.tar.gz
    tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
fi

# Create wp-tests-config.php
cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
EOF

# Create test database if it doesn't exist
RESULT=$(mysql -u "$DB_USER" --password="$DB_PASS" -h "$DB_HOST" -e "SHOW DATABASES LIKE '$DB_NAME'" --batch --skip-column-names 2>/dev/null)
if [ "$RESULT" != "$DB_NAME" ]; then
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null
fi
