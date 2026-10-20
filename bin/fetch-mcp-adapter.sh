#!/usr/bin/env bash
#
# Fetches the MCP Adapter plugin release that development, static analysis and the
# test environments use.
#
# The plugin is a soft runtime dependency: the shipped catalog never bundles it, so
# nothing in composer.json pulls it in. Development still needs a real copy — wp-env
# mounts it as an active plugin, PHPUnit boots it, and phpstan scans it for symbols —
# and this script is the one place that pins which release that is.
#
# Usage: bin/fetch-mcp-adapter.sh            # install (no-op when already current)
#        MCP_ADAPTER_VERSION=0.7.1 bin/fetch-mcp-adapter.sh

set -euo pipefail

version="${MCP_ADAPTER_VERSION:-0.7.1}"
repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dest="$repo_dir/tests/plugins/mcp-adapter"
url="https://downloads.wordpress.org/plugin/mcp-adapter.${version}.zip"

installed=""
if [[ -f "$dest/mcp-adapter.php" ]]; then
	installed="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$dest/mcp-adapter.php" | head -n1 | tr -d '[:space:]')"
fi

if [[ "$installed" == "$version" ]]; then
	echo "MCP Adapter ${version} already present at tests/plugins/mcp-adapter"
	exit 0
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

echo "Fetching MCP Adapter ${version} from ${url}"
curl --fail --silent --show-error --location --output "$tmp/mcp-adapter.zip" "$url"
unzip -q "$tmp/mcp-adapter.zip" -d "$tmp/unzipped"

if [[ ! -f "$tmp/unzipped/mcp-adapter/mcp-adapter.php" ]]; then
	echo "Unexpected archive layout: mcp-adapter/mcp-adapter.php not found" >&2
	exit 1
fi

mkdir -p "$(dirname "$dest")"
if [[ -e "$dest" && ! -w "$dest" ]]; then
	echo "Cannot replace $dest (a wp-env start before the first fetch leaves an empty mount directory there); remove it and re-run." >&2
	exit 1
fi
rm -rf "$dest"
mv "$tmp/unzipped/mcp-adapter" "$dest"
echo "Installed MCP Adapter ${version} at tests/plugins/mcp-adapter"
