#!/usr/bin/env bash
# Disposable, local-only WordPress with SEO Pro Stats demo data.
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source=scripts/lib/plugin.sh disable=SC1091
. "$SCRIPT_DIR/lib/plugin.sh"
readonly NAME="seoprostats-demo-site"
readonly LABEL="seoprostats.demo-site=1"
REF="HEAD"
LIVE_SEARCH=0
POST_PATH=""
ACTION=""
TMP_DIR=""
STARTED=0
KEEP_SITE=0
BASE_URL=""
ADMIN_PASSWORD=""
DB_PASSWORD=""
POST_ID=""

die() {
	local message="$1"
	printf 'demo-site: %s\n' "$message" >&2
	return 1
}

usage() {
	printf '%s\n' 'Usage: scripts/demo-site.sh up [--ref REF] [--live-search] [--post /blog/SLUG/]' \
		'       scripts/demo-site.sh down' \
		'Builds the committed ref (default HEAD), not uncommitted files. Requires Docker and openssl.'
	return 0
}

parse_args() {
	[[ $# -gt 0 ]] || die 'choose up or down (see --help)'
	ACTION="$1"
	shift
	case "$ACTION" in
	-h | --help) usage; exit 0 ;;
	up | down) ;;
	*) die "unknown command: $ACTION" ;;
	esac
	while [[ $# -gt 0 ]]; do
		[[ "$ACTION" = up ]] || die 'down takes no options'
		case "$1" in
		--ref | --post)
			[[ $# -ge 2 && -n "$2" ]] || die "$1 needs a value"
			case "$1" in
			--ref) REF="$2" ;;
			--post) POST_PATH="$2" ;;
			esac
			shift 2
			;;
		--live-search) LIVE_SEARCH=1; shift ;;
		*) die "unknown option: $1" ;;
		esac
	done
	[[ -z "$POST_PATH" || "$POST_PATH" =~ ^/blog/[a-z0-9]+(-[a-z0-9]+)*/$ ]] || die '--post must be /blog/SLUG/ with a lower-case slug'
	return 0
}

# Only resources labelled by this helper are removed, never a shared site.
down() {
	local id
	while IFS= read -r id; do
		[[ -z "$id" ]] || docker rm -f "$id" >/dev/null || return 1
	done < <(docker ps -aq --filter "label=$LABEL")
	if docker volume inspect "$NAME-wp" >/dev/null 2>&1; then
		[[ "$(docker volume inspect --format '{{index .Labels "seoprostats.demo-site"}}' "$NAME-wp")" = 1 ]] || { die 'refusing to remove an unlabelled volume'; return 1; }
		docker volume rm "$NAME-wp" >/dev/null || return 1
	fi
	if docker network inspect "$NAME" >/dev/null 2>&1; then
		[[ "$(docker network inspect --format '{{index .Labels "seoprostats.demo-site"}}' "$NAME")" = 1 ]] || { die 'refusing to remove an unlabelled network'; return 1; }
		docker network rm "$NAME" >/dev/null || return 1
	fi
	printf 'Demo site removed.\n'
	return 0
}

cleanup() {
	local status="$?"
	trap - EXIT INT TERM
	if [[ "$STARTED" -eq 1 && "$KEEP_SITE" -eq 0 ]]; then
		down || status=1
	fi
	if [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]]; then
		rm -rf "$TMP_DIR"
	fi
	return "$status"
}

wp_cli() {
	docker run --rm --name "$NAME-cli" --label "$LABEL" --network "$NAME" \
		-v "$NAME-wp:/var/www/html" -v "$TMP_DIR:/zips:ro" --user 33:33 \
		-e DEMO_POST_PATH="$POST_PATH" \
		wordpress:cli-php8.3 php -d memory_limit=1G /usr/local/bin/wp "$@"
	return $?
}

start_site() {
	local waited=0
	DB_PASSWORD="$(openssl rand -hex 24)"
	ADMIN_PASSWORD="$(openssl rand -hex 24)"
	# A fixed network name also acts as an atomic reservation for concurrent up.
	docker network create --label "$LABEL" "$NAME" >/dev/null
	STARTED=1
	docker volume create --label "$LABEL" "$NAME-wp" >/dev/null
	docker run -d --name "$NAME-db" --label "$LABEL" --network "$NAME" \
		-e MARIADB_ROOT_PASSWORD="$DB_PASSWORD" -e MARIADB_DATABASE=wordpress \
		"$(plugin_env DB_IMAGE mariadb:10.6)" >/dev/null
	docker run --rm --name "$NAME-cli" --label "$LABEL" -v "$NAME-wp:/var/www/html" \
		--user 0:0 wordpress:cli-php8.3 chown 33:33 /var/www/html
	until docker exec "$NAME-db" mariadb-admin ping -h127.0.0.1 -uroot -p"$DB_PASSWORD" --silent >/dev/null 2>&1; do
		[[ "$waited" -lt 90 ]] || die 'the database did not start'
		sleep 2
		waited=$((waited + 2))
	done
	wp_cli core download --quiet
	docker run -d --name "$NAME-web" --label "$LABEL" --network "$NAME" \
		-v "$NAME-wp:/var/www/html" -p 127.0.0.1::80 wordpress:php8.3-apache >/dev/null
	BASE_URL="http://$(docker port "$NAME-web" 80)" # NOSONAR: disposable loopback-only HTTP.
	wp_cli config create --dbname=wordpress --dbuser=root --dbpass="$DB_PASSWORD" --dbhost="$NAME-db" --skip-check --quiet
	wp_cli config set WP_DEBUG true --raw --quiet
	wp_cli config set WP_DEBUG_LOG true --raw --quiet
	wp_cli config set WP_DEBUG_DISPLAY false --raw --quiet
	wp_cli config set DISABLE_WP_CRON true --raw --quiet
	wp_cli core install --title='SEO Pro Stats demo' --admin_user=admin --admin_password="$ADMIN_PASSWORD" \
		--admin_email=admin@example.com --skip-email --quiet --url="$BASE_URL"
	wp_cli rewrite structure '/blog/%postname%/' --quiet
	docker exec -u www-data "$NAME-web" sh -c 'printf "%s\n" "# BEGIN WordPress" "RewriteEngine On" "RewriteBase /" \
		"RewriteRule ^index\.php$ - [L]" "RewriteCond %{REQUEST_FILENAME} !-f" "RewriteCond %{REQUEST_FILENAME} !-d" \
		"RewriteRule . /index.php [L]" "# END WordPress" >/var/www/html/.htaccess'
	# The web process needs the same generous resources as the CLI seed.
	docker exec "$NAME-web" sh -c 'printf "%s\n" "memory_limit=768M" "opcache.memory_consumption=1024" \
		"opcache.interned_strings_buffer=64" "opcache.max_accelerated_files=50000" >/usr/local/etc/php/conf.d/demo.ini'
	docker exec "$NAME-web" apache2ctl graceful
	wp_cli plugin install "/zips/$PLUGIN_SLUG.zip" --activate --quiet
	wp_cli user meta update 1 wp_persisted_preferences '{"core/edit-post":{"welcomeGuide":false}}' --format=json --quiet
	return 0
}

seed_site() {
	printf 'Making 400 days of demo data; this usually takes about 4.5 minutes.\n'
	wp_cli seoprostats demo make
	if [[ "$LIVE_SEARCH" -eq 1 ]]; then
		printf 'Copying demo search data into this disposable site\x27s live tables...\n'
		# shellcheck disable=SC2016 # PHP variables, not shell variables.
		wp_cli eval '
			global $wpdb;
			SEOProStats_Schema::use_set("live");
			foreach (array("dict", "gsc_pairs", "gsc_pages", "gsc_queries", "gsc_totals", "imports") as $name) {
				$live = SEOProStats_Schema::table($name);
				$demo = $wpdb->prefix . "seoprostats_demo_" . $name;
				foreach (array($wpdb->prepare("DELETE FROM %i", $live), $wpdb->prepare("INSERT INTO %i SELECT * FROM %i", $live, $demo)) as $sql) {
					if (false === $wpdb->query($sql)) { WP_CLI::error($wpdb->last_error); }
				}
			}'
	fi
	if [[ -n "$POST_PATH" ]]; then
		# Use the demo page text and focus, without installing an SEO plugin or
		# altering browser authentication. The filter exists only in this site.
		# shellcheck disable=SC2016 # PHP variables, not shell variables.
		POST_ID="$(wp_cli eval '
			$path = getenv("DEMO_POST_PATH");
			$text = SEOProStats_Demo::text($path);
			if (!$text) { WP_CLI::error("No demo page text for " . $path); }
			$id = wp_insert_post(array("post_type" => "post", "post_status" => "publish", "post_name" => basename($path), "post_title" => $text["title"], "post_content" => $text["content"]), true);
			if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
			update_post_meta($id, "seoprostats_demo_focus", $text["focus"]);
			wp_mkdir_p(WPMU_PLUGIN_DIR);
			$code = "<?php\nadd_filter(\"seoprostats_focus_keywords\", function (\$focus, \$id) { return get_post_meta(\$id, \"seoprostats_demo_focus\", true) ?: \$focus; }, 10, 2);\n";
			if (false === file_put_contents(WPMU_PLUGIN_DIR . "/demo-focus.php", $code)) { WP_CLI::error("Could not write demo focus filter"); }
			echo $id;')"
	fi
	return 0
}

main() {
	parse_args "$@"
	command -v docker >/dev/null || die 'Docker is required'
	docker info >/dev/null 2>&1 || die 'Docker is not running'
	if [[ "$ACTION" = down ]]; then
		down
		return 0
	fi
	if docker network inspect "$NAME" >/dev/null 2>&1 || docker volume inspect "$NAME-wp" >/dev/null 2>&1 \
		|| [[ -n "$(docker ps -aq --filter "name=^/$NAME-")" ]]; then
		die 'a demo site already exists; run scripts/demo-site.sh down first'
	fi
	command -v openssl >/dev/null || die 'openssl is required'
	plugin_identity "$REF"
	TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/seoprostats-demo-site.XXXXXX")"
	trap cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	chmod 755 "$TMP_DIR"
	printf 'Building %s from %s...\n' "$PLUGIN_NAME" "$REF"
	"$SCRIPT_DIR/build-release.sh" --ref "$REF" --out "$TMP_DIR" --quiet >/dev/null
	local built=("$TMP_DIR/$PLUGIN_SLUG"-*.zip)
	[[ -f "${built[0]}" ]] || die 'the build made no GitHub zip'
	cp "${built[0]}" "$TMP_DIR/$PLUGIN_SLUG.zip"
	chmod 644 "$TMP_DIR/$PLUGIN_SLUG.zip"
	start_site
	seed_site
	KEEP_SITE=1
	printf '\nURL: %s\nAdmin: %s/wp-admin/\nLogin: admin\nPassword: %s\nUser ID: 1\n' "$BASE_URL" "$BASE_URL" "$ADMIN_PASSWORD"
	if [[ -n "$POST_ID" ]]; then
		printf 'Post ID: %s\nEditor: %s/wp-admin/post.php?post=%s&action=edit\nCoverage: %s/wp-json/seoprostats/v1/coverage?post=%s\n' "$POST_ID" "$BASE_URL" "$POST_ID" "$BASE_URL" "$POST_ID"
	fi
	printf 'Statistics: %s/wp-admin/admin.php?page=seoprostats-dashboard#/search?report=opportunities\nStop: scripts/demo-site.sh down\n' "$BASE_URL"
	return 0
}

main "$@"
