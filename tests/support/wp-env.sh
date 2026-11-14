#!/usr/bin/env bash
# Prepares and serves a disposable WordPress site with this plugin for the browser tests
# (tests/a11y, tests/e2e). Prints an "export ..." line for WPCAP_A11Y_* on stdout.
#
#   eval "$(tests/support/wp-env.sh start)"   # install (if needed) + start `php -S` in the background
#   tests/support/wp-env.sh stop
#
# Env:
#   WPCAP_WP_DIR      WordPress directory to use/create   (default: ${TMPDIR:-/tmp}/wpcap-browser-wp)
#   WPCAP_WP_CORE     existing WordPress core to copy from (otherwise: git clone of WordPress, tag WPCAP_WP_VERSION)
#   WPCAP_WP_VERSION  WordPress tag to clone               (default: 6.8.3)
#   WPCAP_DB_HOST/_USER/_PASS/_NAME  disposable database   (default: 127.0.0.1 / wp / wp / wp_browser)
#   WPCAP_DB_PREFIX   table prefix                         (default: wpbt_)
#   WPCAP_WP_PORT     port of the PHP built-in server      (default: 8890)
# NEVER point this at a production database: it installs a site and creates orders.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HERE/../.." && pwd)"
WP="${WPCAP_WP_DIR:-${TMPDIR:-/tmp}/wpcap-browser-wp}"
PORT="${WPCAP_WP_PORT:-8890}"
URL="http://127.0.0.1:$PORT"
DBH="${WPCAP_DB_HOST:-127.0.0.1}"
DBU="${WPCAP_DB_USER:-wp}"
DBP="${WPCAP_DB_PASS:-wp}"
DBN="${WPCAP_DB_NAME:-wp_browser}"
PFX="${WPCAP_DB_PREFIX:-wpbt_}"
PIDFILE="$WP/.server.pid"

case "${1:-start}" in
stop)
	if [ -f "$PIDFILE" ]; then kill "$(cat "$PIDFILE")" 2>/dev/null || true; fi
	rm -f "$PIDFILE"
	exit 0 ;;
start) ;;
*) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac

if [ ! -f "$WP/wp-load.php" ]; then
	mkdir -p "$WP"
	if [ -n "${WPCAP_WP_CORE:-}" ]; then
		cp -a "$WPCAP_WP_CORE/." "$WP/"
	else
		rmdir "$WP" 2>/dev/null || true
		git clone --quiet --depth 1 --branch "${WPCAP_WP_VERSION:-6.8.3}" https://github.com/WordPress/WordPress.git "$WP" >&2
	fi
	rm -rf "$WP/.git"
fi
if [ ! -f "$WP/wp-config.php" ]; then
	cat > "$WP/wp-config.php" <<PHP
<?php
define( 'DB_NAME', '$DBN' );
define( 'DB_USER', '$DBU' );
define( 'DB_PASSWORD', '$DBP' );
define( 'DB_HOST', '$DBH' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = '$PFX';
define( 'AUTH_KEY', 'browser-tests-1' ); define( 'SECURE_AUTH_KEY', 'browser-tests-2' ); define( 'LOGGED_IN_KEY', 'browser-tests-3' ); define( 'NONCE_KEY', 'browser-tests-4' );
define( 'AUTH_SALT', 'browser-tests-5' ); define( 'SECURE_AUTH_SALT', 'browser-tests-6' ); define( 'LOGGED_IN_SALT', 'browser-tests-7' ); define( 'NONCE_SALT', 'browser-tests-8' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_DISPLAY', false ); define( 'WP_DEBUG_LOG', true );
define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
fi
mkdir -p "$WP/wp-content/plugins" "$WP/wp-content/mu-plugins"
# Test site: never send real mail (fixtures capture wp_mail() through pre_wp_mail themselves), no Gravatar requests.
printf '%s\n' '<?php' "add_filter( 'pre_wp_mail', static fn( \$r ) => null === \$r ? true : \$r, 99 );" "add_filter( 'pre_option_show_avatars', '__return_zero' );" > "$WP/wp-content/mu-plugins/wpcap-test-env.php"
rm -f "$WP/wp-content/mu-plugins/wpcap-test-no-mail.php"
ln -sfn "$PLUGIN" "$WP/wp-content/plugins/pass-captain"
DBH="$DBH" DBU="$DBU" DBP="$DBP" DBN="$DBN" php -r '$m = new mysqli(getenv("DBH"), getenv("DBU"), getenv("DBP")); if ($m->connect_error) { fwrite(STDERR, $m->connect_error . PHP_EOL); exit(1); } $m->query("CREATE DATABASE IF NOT EXISTS `" . str_replace("`", "", getenv("DBN")) . "`");' >&2
php "$HERE/wp-install.php" "$WP" "$URL" >&2

if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then :; else
	PHP_CLI_SERVER_WORKERS="${WPCAP_WP_WORKERS:-4}" nohup php -S "127.0.0.1:$PORT" -t "$WP" > "$WP/.server.log" 2>&1 &
	echo $! > "$PIDFILE"
	for _ in $(seq 1 50); do
		if curl -fsS -o /dev/null "$URL/wp-login.php" 2>/dev/null; then break; fi
		sleep 0.2
	done
fi
echo "export WPCAP_A11Y_WP_PATH='$WP' WPCAP_A11Y_BASE='$URL' WPCAP_A11Y_USER='${WPCAP_A11Y_USER:-admin}' WPCAP_A11Y_PASS='${WPCAP_A11Y_PASS:-admin}'"
