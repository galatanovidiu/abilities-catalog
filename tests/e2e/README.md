# MCP Inspector dual-revision E2E

This check connects the official MCP Inspector CLI to all three MCP endpoints:

- the bounded search server;
- the curated domain server; and
- the bundled adapter's default public-ability server.

Every endpoint is exercised in both protocol eras:

- `protocolEra: legacy` exercises MCP `2025-11-25` through initialization and
  its session-backed requests.
- `protocolEra: modern` pins MCP `2026-07-28` and exercises the sessionless
  `server/discover` flow.

The matrix checks exact negotiation, tool discovery and calls, bounded search and
no-match recovery, every curated domain list, public-ability discovery, schema
description, resource discovery and reads, the empty resource-template contract,
prompt discovery and retrieval, server identity, and advertised tool/resource/
prompt capabilities.

Twelve read-only core abilities are described and executed through the search,
curated, and default-server paths. They cover content, media, appearance, design,
plugins, users, settings, cron/tools, Site Health, updates, dashboard, and
taxonomies. No write ability is executed.

The repository pins `@modelcontextprotocol/inspector` to `2.4.0`.

## Prerequisites

1. Install the repository dependencies with `npm install` and `composer install`.
2. Run WordPress with this plugin active and its MCP server enabled.
3. Enable these read abilities on **Settings → MCP Server**:

   - `og-content/list-post-types`
   - `og-media/list-image-sizes`
   - `og-themes/get-active-theme`
   - `og-templates/list-block-types`
   - `og-plugins/list-plugins`
   - `og-users/get-current-user`
   - `og-settings/get-reading`
   - `og-cron/list-schedules`
   - `og-site-health/get-status`
   - `og-updates/list-available-updates`
   - `og-dashboard/get-at-a-glance`
   - `og-terms/list-taxonomies`

4. Create a WordPress application password for an administrator.

The default wp-env endpoints are:

```text
search:  http://localhost:8890/?rest_route=/abilities-catalog/v1/mcp-search
curated: http://localhost:8890/?rest_route=/abilities-catalog/v1/mcp
default: http://localhost:8890/?rest_route=/mcp/mcp-adapter-default-server
```

## Run

Pass the application-password credential without storing it in the repository:

```bash
WP_API_USERNAME=admin WP_API_PASSWORD='<application-password>' npm run test:mcp:e2e
```

For another environment, set `MCP_BASE_URL`, or override individual endpoints
with `MCP_SEARCH_ENDPOINT`, `MCP_CURATED_ENDPOINT`, and `MCP_DEFAULT_ENDPOINT`.
`MCP_ENDPOINT` remains an alias for the search endpoint. To use a prebuilt
authorization value, set `MCP_AUTH_HEADER` instead of the username and password:

```bash
MCP_BASE_URL='https://example.test' \
MCP_AUTH_HEADER='Bearer <token>' \
npm run test:mcp:e2e
```

The runner creates a mode-`0600` temporary Inspector configuration, deletes it
after the run, and never prints the authorization value. It runs independent
read-only calls with concurrency four; the target should be an isolated test site.
