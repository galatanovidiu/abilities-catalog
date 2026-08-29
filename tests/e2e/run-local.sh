#!/usr/bin/env bash

set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_dir"

wp_env=( "$repo_dir/node_modules/.bin/wp-env" "--config=.wp-env.test.json" )
app_id='00000000-0000-4000-8000-000000000240'

wp_cli() {
	"${wp_env[@]}" run cli -- wp "$@"
}

application_password_uuid() {
	wp_cli eval '$items = WP_Application_Passwords::get_user_application_passwords( 1 ); foreach ( $items as $item ) { if ( ( $item["app_id"] ?? "" ) === "00000000-0000-4000-8000-000000000240" ) { echo $item["uuid"], PHP_EOL; } }' --quiet 2>/dev/null \
		| grep -Eo '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' \
		| tail -n 1
}

cleanup() {
	set +e
	password_uuid="$(application_password_uuid)"
	if [[ -n "$password_uuid" ]]; then
		wp_cli user application-password delete admin "$password_uuid" --quiet >/dev/null 2>&1
	fi
	wp_cli option delete abilities_catalog_mcp_e2e_enabled --quiet >/dev/null 2>&1
	wp_cli option delete abilities_catalog_mcp_exposed_abilities --quiet >/dev/null 2>&1
	wp_cli option delete abilities_catalog_mcp_enabled --quiet >/dev/null 2>&1
}
trap cleanup EXIT INT TERM

cleanup
trap cleanup EXIT INT TERM

exposed='[
	"og-content/list-post-types",
	"og-media/list-image-sizes",
	"og-themes/get-active-theme",
	"og-templates/list-block-types",
	"og-plugins/list-plugins",
	"og-users/get-current-user",
	"og-settings/get-reading",
	"og-cron/list-schedules",
	"og-site-health/get-status",
	"og-updates/list-available-updates",
	"og-dashboard/get-at-a-glance",
	"og-terms/list-taxonomies",
	"og-settings/get-general"
]'

wp_cli option update abilities_catalog_mcp_enabled 1 --quiet >/dev/null
wp_cli option update abilities_catalog_mcp_exposed_abilities "$exposed" --format=json --quiet >/dev/null
wp_cli option update abilities_catalog_mcp_e2e_enabled 1 --quiet >/dev/null

create_output="$(wp_cli user application-password create admin 'Abilities Catalog dual-revision E2E' --app-id="$app_id" --porcelain --quiet 2>/dev/null)"
application_password="$(printf '%s\n' "$create_output" | awk '/^[[:alnum:]]{24}$/ { value = $0 } END { print value }')"
if [[ -z "$application_password" ]]; then
	echo 'Could not create a temporary application password.' >&2
	exit 1
fi

WP_API_USERNAME=admin WP_API_PASSWORD="$application_password" node tests/e2e/mcp-inspector.mjs
