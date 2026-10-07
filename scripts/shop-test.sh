#!/usr/bin/env bash
# Exercise purchases through real shops on a disposable, localhost-only site.
# Usage: scripts/shop-test.sh [--php VERSION] [--wp VERSION] [--ref REF]
#        [--zip FILE] [--keep-log FILE] [--shop-versions SLUG=VERSION,...]
# Defaults: PHP 8.2, latest WordPress and shops, zip built from HEAD.
# Incompatible shops are skipped explicitly; download/activation errors fail.
# Needs Docker, curl, jq and internet access. Never contacts a payment gateway.
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source=scripts/lib/plugin.sh disable=SC1091 # followed with -x
. "$SCRIPT_DIR/lib/plugin.sh"
PHP_VERSION=8.2
WP_VERSION=latest
REF=HEAD
ZIP=""
KEEP_LOG=""
SHOP_VERSIONS=""
TMP_DIR=""
NAME=""
BASE_URL=""
STARTED=0
FAILED=0
SHOPS=()
readonly UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36'

die() {
	local message="$1"
	printf 'shop-test: %s\n' "$message" >&2
	exit 2
	# shellcheck disable=SC2317 # explicit function return; exit terminates the run.
	return 1
}

fail() {
	local message="$1"
	printf '  FAIL %s\n' "$message" >&2
	FAILED=1
	return 0
}

wp_cli() {
	docker run --rm --network "$NAME" -v "$NAME-wp:/var/www/html" -v "$TMP_DIR/zips:/zips:ro" \
		--user 33:33 "wordpress:cli-php$PHP_VERSION" php -d memory_limit=1G /usr/local/bin/wp "$@"
	return $?
}

cleanup() {
	local code=$?
	local attempt id
	trap - EXIT
	if [[ "$STARTED" -eq 1 ]]; then
		if docker exec "$NAME-web" test -f /var/www/html/wp-content/debug.log 2>/dev/null; then
			docker cp "$NAME-web:/var/www/html/wp-content/debug.log" "$TMP_DIR/debug.log" >/dev/null || code=1
			if [[ -n "$KEEP_LOG" ]]; then
				cp "$TMP_DIR/debug.log" "$KEEP_LOG" || code=1
			fi
		fi
		for attempt in 1 2 3; do
			while IFS= read -r id; do
				[[ -z "$id" ]] || docker rm -f "$id" >/dev/null 2>&1 || true
			done < <(docker ps -aq --filter "volume=$NAME-wp")
			docker rm -f "$NAME-web" "$NAME-db" >/dev/null 2>&1 || true
			docker volume rm "$NAME-wp" >/dev/null 2>&1 || true
			docker network rm "$NAME" >/dev/null 2>&1 || true
			if ! docker volume inspect "$NAME-wp" >/dev/null 2>&1 && ! docker network inspect "$NAME" >/dev/null 2>&1 \
				&& ! docker container inspect "$NAME-web" >/dev/null 2>&1 && ! docker container inspect "$NAME-db" >/dev/null 2>&1; then
				printf 'Removed Docker resources: %s\n' "$NAME"
				break
			fi
			if [[ "$attempt" -eq 3 ]]; then
				printf 'shop-test: cleanup failed for %s\n' "$NAME" >&2
				code=1
			fi
			sleep 2
		 done
	fi
	[[ -z "$TMP_DIR" ]] || rm -rf "$TMP_DIR"
	exit "$code"
	# shellcheck disable=SC2317 # explicit function return; exit preserves cleanup failures.
	return 0
}

parse_args() {
	local arg value pin
	while [[ $# -gt 0 ]]; do
		arg="$1"
		value="${2:-}"
		case "$arg" in
		--php | --wp | --ref | --zip | --keep-log | --shop-versions)
			[[ -n "$value" ]] || die "$arg needs a value"
			case "$arg" in
			--php) [[ "$value" =~ ^[0-9]+\.[0-9]+$ ]] || die 'invalid PHP version'; PHP_VERSION="$value" ;;
			--wp) WP_VERSION="$value" ;;
			--ref) REF="$value" ;;
			--zip) [[ -f "$value" ]] || die "no such zip: $value"; ZIP="$(cd "$(dirname "$value")" && pwd)/$(basename "$value")" ;;
			--keep-log) KEEP_LOG="$value" ;;
			--shop-versions) SHOP_VERSIONS="$value" ;;
			*) ;;
			esac
			shift ;;
		-h | --help)
			printf '%s\n' 'Usage: scripts/shop-test.sh [--php VERSION] [--wp VERSION] [--ref REF]' \
				'       [--zip FILE] [--keep-log FILE] [--shop-versions SLUG=VERSION,...]'
			exit 0 ;;
		*) die "unknown argument: $arg" ;;
		esac
		shift
	done
	local pins=()
	IFS=',' read -r -a pins <<<"$SHOP_VERSIONS"
	for pin in ${pins[@]+"${pins[@]}"}; do
		[[ "$pin" =~ ^(woocommerce|easy-digital-downloads|fluent-cart)=[0-9]+(\.[0-9]+)*$ ]] || die "invalid shop pin: $pin"
	done
	return 0
}

start_site() {
	local waited=0 port
	STARTED=1
	docker network create "$NAME" >/dev/null
	docker volume create "$NAME-wp" >/dev/null
	docker run -d --name "$NAME-db" --network "$NAME" -e MARIADB_ROOT_PASSWORD=shop-test-only \
		-e MARIADB_DATABASE=wordpress "$(plugin_env DB_IMAGE mariadb:10.6)" >/dev/null
	docker run --rm -v "$NAME-wp:/var/www/html" --user 0:0 "wordpress:cli-php$PHP_VERSION" chown 33:33 /var/www/html
	until docker exec "$NAME-db" mariadb-admin ping -h127.0.0.1 -uroot -pshop-test-only --silent >/dev/null 2>&1; do
		[[ "$waited" -lt 90 ]] || die 'database startup timed out'
		sleep 2
		waited=$((waited + 2))
	done
	wp_cli core download --version="$WP_VERSION" --quiet
	docker run -d --name "$NAME-web" --network "$NAME" -v "$NAME-wp:/var/www/html" \
		-p 127.0.0.1::80 "wordpress:php$PHP_VERSION-apache" >/dev/null
	port="$(docker port "$NAME-web" 80)"
	BASE_URL="http://$port"
	wp_cli config create --dbname=wordpress --dbuser=root --dbpass=shop-test-only --dbhost="$NAME-db" --skip-check --quiet
	wp_cli config set WP_DEBUG true --raw --quiet
	wp_cli config set WP_DEBUG_LOG true --raw --quiet
	wp_cli config set WP_DEBUG_DISPLAY false --raw --quiet
	wp_cli config set DISABLE_WP_CRON true --raw --quiet
	wp_cli core install --title=ShopTest --admin_user=admin --admin_password=shop-test-only \
		--admin_email=admin@example.com --skip-email --quiet --url="$BASE_URL"
	docker exec "$NAME-web" mkdir -p /var/www/html/wp-content/mu-plugins
	docker cp "$SCRIPT_DIR/shop-test-mu.php" "$NAME-web:/var/www/html/wp-content/mu-plugins/shop-test.php" >/dev/null
	wp_cli plugin install "/zips/$PLUGIN_SLUG.zip" --activate --quiet
	wp_cli rewrite structure '/%postname%/' --quiet
	docker exec -u www-data "$NAME-web" sh -c 'printf "%s\n" "RewriteEngine On" "RewriteBase /" \
		"RewriteRule ^index\.php$ - [L]" "RewriteCond %{REQUEST_FILENAME} !-f" "RewriteCond %{REQUEST_FILENAME} !-d" \
		"RewriteRule . /index.php [L]" > /var/www/html/.htaccess'
	printf 'WordPress %s, PHP %s at %s\n' "$(wp_cli core version)" "$PHP_VERSION" "$BASE_URL"
	return 0
}

install_shops() {
	local shop version pin metadata url
	local pins=()
	IFS=',' read -r -a pins <<<"$SHOP_VERSIONS"
	for shop in woocommerce easy-digital-downloads fluent-cart; do
		version=latest
		for pin in ${pins[@]+"${pins[@]}"}; do
			[[ "${pin%%=*}" != "$shop" ]] || version="${pin#*=}"
		done
		# Inspect the downloaded version's headers before installation. Older
		# WP-CLI images have no --ignore-requirements flag; installing an
		# incompatible shop would fail before we could report a clean skip.
		# shellcheck disable=SC2016 # PHP variables, not shell expansion.
		url="$(wp_cli eval 'require_once ABSPATH . "wp-admin/includes/plugin-install.php"; $info = plugins_api("plugin_information", array("slug" => "'"$shop"'", "fields" => array("versions" => true))); if (is_wp_error($info)) { WP_CLI::error($info->get_error_message()); } $version = "'"$version"'"; $url = $version === "latest" ? $info->download_link : ($info->versions[$version] ?? ""); if (!$url) { WP_CLI::error("Shop version not found"); } echo $url;')"
		[[ "$url" == https://downloads.wordpress.org/plugin/* ]] || die "unexpected download host for $shop"
		curl -fSs --max-time 120 "$url" -o "$TMP_DIR/zips/$shop.zip"
		mkdir "$TMP_DIR/zips/$shop-source"
		unzip -q "$TMP_DIR/zips/$shop.zip" -d "$TMP_DIR/zips/$shop-source"
		chmod -R a+rX "$TMP_DIR/zips/$shop-source"
		# shellcheck disable=SC2016 # Only the fixed, validated shop slug is interpolated.
		metadata="$(wp_cli eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; $file = "/zips/'"$shop"'-source/'"$shop"'/'"$shop"'.php"; if (!is_file($file)) { WP_CLI::error("Shop main file missing"); } $data = get_plugin_data($file, false, false); $data["compatible"] = is_wp_version_compatible($data["RequiresWP"]) && is_php_version_compatible($data["RequiresPHP"]); echo wp_json_encode($data);')"
		printf '%s %s\n' "$shop" "$(jq -r .Version <<<"$metadata")"
		if [[ "$(jq -r .compatible <<<"$metadata")" != true ]]; then
			printf 'SKIP %s: requires WordPress %s / PHP %s\n' "$shop" "$(jq -r .RequiresWP <<<"$metadata")" "$(jq -r .RequiresPHP <<<"$metadata")"
			continue
		fi
		chmod 644 "$TMP_DIR/zips/$shop.zip"
		wp_cli plugin install "/zips/$shop.zip" --activate --quiet
		SHOPS+=("$shop")
	done
	wp_cli eval 'seoprostats_shop_test_setup();'
	return 0
}

# Save the response and status separately; HTTP errors never pass silently.
request() {
	local path="$1"
	local expected="$2"
	shift 2
	local status
	status="$(curl -sS --max-time 60 -A "$UA" -e "$BASE_URL/checkout/" \
		-o "$TMP_DIR/response" -w '%{http_code}' "$@" "$BASE_URL$path")" || die "request failed: $path"
	[[ "$status" == "$expected" ]] || die "$path answered $status, expected $expected: $(<"$TMP_DIR/response")"
	return 0
}

visit() {
	request '/wp-json/seoprostats/v1/collect' 204 -H 'Content-Type: application/json' \
		--data '{"h":"127.0.0.1","e":[{"t":"pv","p":"1111222233334444","u":"/?utm_source=shop-test&utm_medium=email&utm_campaign=purchases"}]}'
	request '/wp-json/seoprostats/v1/collect' 204 -H 'Content-Type: application/json' \
		--data '{"h":"127.0.0.1","e":[{"t":"pv","p":"5555666677778888","u":"/checkout/"}]}'
	wp_cli seoprostats process
	return 0
}

woo() {
	local product nonce method order
	product="$(wp_cli eval 'echo get_option("seoprostats_shop_test_product");')"
	for method in cod bacs; do
		request '/wp-json/wc/store/v1/cart' 200 -D "$TMP_DIR/headers" -c "$TMP_DIR/cookies" -b "$TMP_DIR/cookies"
		nonce="$(awk 'tolower($1)=="nonce:" {gsub("\r", "", $2); print $2}' "$TMP_DIR/headers")"
		[[ -n "$nonce" ]] || die 'Store API did not send a Nonce'
		request '/wp-json/wc/store/v1/cart/add-item' 201 -c "$TMP_DIR/cookies" -b "$TMP_DIR/cookies" \
			-H "Nonce: $nonce" -H 'Content-Type: application/json' --data "{\"id\":$product,\"quantity\":2}"
		request '/wp-json/wc/store/v1/checkout' 200 -c "$TMP_DIR/cookies" -b "$TMP_DIR/cookies" \
			-H "Nonce: $nonce" -H 'Content-Type: application/json' \
			--data "{\"billing_address\":{\"first_name\":\"Shop\",\"last_name\":\"Test\",\"address_1\":\"1 Test Road\",\"city\":\"New York\",\"state\":\"NY\",\"postcode\":\"10001\",\"country\":\"US\",\"email\":\"shop@example.com\"},\"payment_method\":\"$method\"}"
		order="$(jq -er '.order_id' "$TMP_DIR/response")"
		[[ "$order" =~ ^[1-9][0-9]*$ ]] || die 'Store API did not return an order ID'
		if [[ "$method" == bacs ]]; then
			# shellcheck disable=SC2016 # Numeric ID above, PHP variables stay literal.
			wp_cli eval '$order = wc_get_order('"$order"'); if ($order->get_status() !== "on-hold" || !$order->get_meta("_seoprostats_visit") || $order->get_meta("_seoprostats_recorded")) { WP_CLI::error("FAIL WooCommerce: unpaid bank transfer must retain its visit, not a purchase"); }'
			wp_cli wc shop_order update "$order" --status=completed --user=1 --porcelain
			wp_cli wc shop_order update "$order" --status=processing --user=1 --porcelain
		fi
	done
	wp_cli wc shop_order create --status=completed --line_items="[{\"product_id\":$product,\"quantity\":2}]" --user=1 --porcelain
	return 0
}

shop_requests() {
	local shop order
	for shop in ${SHOPS[@]+"${SHOPS[@]}"}; do
		case "$shop" in
		woocommerce) woo ;;
		easy-digital-downloads)
			request '/?spst_test=edd' 200
			order="$(jq -er .order_id "$TMP_DIR/response")"
			request "/?spst_test=edd_pay&order_id=$order" 200 -A 'ShopTest payment callback'
			request "/?spst_test=edd_pay&order_id=$order" 200 -A 'ShopTest payment callback' ;;
		fluent-cart) request '/?spst_test=fluentcart' 200 ;;
		esac
	done
	return 0
}

thrivecart() {
	local expected="$1"
	local order="$2"
	local mode="$3"
	local page="$4"
	local event="$5"
	request '/wp-json/seoprostats/v1/thrivecart' 200 --data-urlencode 'thrivecart_secret=shop-test-only' \
		--data-urlencode "event=$event" --data-urlencode "mode=$mode" --data-urlencode "order_id=$order" \
		--data-urlencode 'currency=USD' --data-urlencode 'order[total]=99700' --data-urlencode "passthrough[spst]=$page"
	jq -e --arg expected "$expected" '.result == $expected' "$TMP_DIR/response" >/dev/null || die "ThriveCart expected $expected: $(<"$TMP_DIR/response")"
	printf '  ThriveCart %s\n' "$expected"
	return 0
}

check_results() {
	local doctor
	wp_cli seoprostats process
	wp_cli eval 'seoprostats_shop_test_assert();' || fail 'purchase counts, amounts, currencies or visit joins'
	doctor="$(wp_cli seoprostats doctor --format=json)"
	jq -e 'any(.[]; .check == "purchases" and (.detail | contains("1 ThriveCart orders without a known page load")))' <<<"$doctor" >/dev/null || fail 'doctor: expected 1 ThriveCart order not joined'
	# Read through PHP so the fixture can group notice + backtrace lines and
	# allow only WooCommerce's independently caused translation notice.
	wp_cli eval 'seoprostats_shop_test_log();' || fail 'PHP debug log'
	return 0
}

main() {
	parse_args "$@"
	local tool zip
	for tool in docker curl jq unzip; do command -v "$tool" >/dev/null || die "needs $tool"; done
	docker info >/dev/null 2>&1 || die 'Docker is not running'
	plugin_identity "$REF" || die 'cannot identify plugin'
	NAME="$PLUGIN_SLUG-shop-$$"
	TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/$NAME.XXXXXX")"
	trap cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	mkdir "$TMP_DIR/zips"
	chmod 755 "$TMP_DIR" "$TMP_DIR/zips"
	zip="$ZIP"
	if [[ -z "$zip" ]]; then
		"$SCRIPT_DIR/build-release.sh" --ref "$REF" --out "$TMP_DIR/build" --quiet >/dev/null
		local built=("$TMP_DIR"/build/"$PLUGIN_SLUG"-*.zip)
		zip="${built[0]}"
	fi
	cp "$zip" "$TMP_DIR/zips/$PLUGIN_SLUG.zip"
	chmod 644 "$TMP_DIR/zips/$PLUGIN_SLUG.zip"
	start_site
	install_shops
	visit
	shop_requests
	request '/wp-json/seoprostats/v1/thrivecart' 200 --head
	request '/?rest_route=/seoprostats/v1/thrivecart' 200
	request '/wp-json/seoprostats/v1/thrivecart' 403 --data 'thrivecart_secret=wrong&event=order.success'
	thrivecart recorded live-1 live 5555666677778888 order.success
	thrivecart duplicate live-1 live 5555666677778888 order.success
	thrivecart test test-1 test 5555666677778888 order.success
	thrivecart not-joined unknown-1 live aaaa0000bbbb0000 order.success
	thrivecart ignored refund-1 live 5555666677778888 order.refund
	check_results
	[[ "$FAILED" -eq 0 ]] || return 1
	printf 'Shop test passed (WordPress %s, PHP %s).\n' "$WP_VERSION" "$PHP_VERSION"
	return 0
}

main "$@"
