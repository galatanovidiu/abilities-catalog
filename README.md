# Abilities Catalog

Abilities Catalog registers wp-admin functionality on the WordPress core
Abilities API.

It is a catalog first: 162 core abilities, each with schemas, category metadata,
server-side capability checks, and risk annotations. It does not choose which
agent, UI, or integration may surface them.

Any consumer that reads the Abilities API can use the catalog. The plugin also
ships one optional consumer of its own: a built-in MCP server, built on
[`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter), off by
default, that exposes enabled abilities as curated domain tools.

## Requirements

- WordPress 7.0 or later, with the Abilities API in core.
- PHP 8.1 or later.
- Optional MCP server only: Composer dependencies for `wordpress/mcp-adapter`.
  The catalog itself has no Composer or build step.

## What It Registers

The core catalog currently includes 162 abilities across 18 wp-admin areas:

`Comments`, `Connectors`, `Content`, `Dashboard`, `Fonts`, `Media`, `Menus`,
`Plugins`, `Privacy`, `Search`, `Settings`, `SiteHealth`, `Templates`, `Terms`,
`Themes`, `Tools`, `Updates`, `Users`.

Each ability is one PHP class under `includes/Abilities/Core/<Domain>/`.
`Registry` discovers those classes recursively and registers them with
`wp_register_ability()`.

Ability names use a `domain/verb-noun` shape, for example
`plugins/list-plugins` or `comments/approve-comment`.

## Safety Model

Capability checks are the hard guard. Every ability has a server-side
`permission_callback` that calls `current_user_can()`, independent of any
consumer settings or UI.

Risk annotations are classification metadata for consumers. The catalog uses
them to reject ambiguous writes, publish dangerous-tool metadata through filters,
and help consumers show safer controls.

- **Read**: no side effects. Declares `readonly: true`.
- **Write**: changes data without deleting it. Declares `readonly: false` and
  `destructive: false`.
- **Destructive**: deletes, trashes, or permanently removes data. Declares
  `destructive: true`.
- **Dangerous**: can install, update, or delete plugins and themes, run updates,
  write broad options, or trigger privacy exports. Declares `dangerous: true`
  and uses dedicated guards such as source validation, filesystem checks, option
  allow-lists, and upgrader locks.

The Registry refuses any write ability that does not explicitly set
`annotations.destructive` to a boolean. Destructive and dangerous abilities can
register; how they are exposed is a consumer decision.

## MCP Server (Optional)

The plugin can expose the catalog over the [Model Context
Protocol](https://modelcontextprotocol.io/) through a built-in server. The server
is **off by default** and built on
[`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter).

The server does not expose one MCP tool per ability. It exposes one tool per
curated domain, plus a cross-cutting `skills` tool for task recipes.

Each domain tool supports three actions: `list`, `describe`, and `execute`.
Execution happens server-side through `wp_get_ability()`, so the target ability's
own capability check still runs.

The curated domains are `content`, `media`, `appearance`, `design`, `plugins`,
`users`, `settings`, `tools`, `site-health`, `updates`, and `dashboard`.

### Enable It

Define a constant in `wp-config.php`:

```php
define( 'ABILITIES_CATALOG_MCP_ENABLED', true );
```

You can also set the `abilities_catalog_mcp_enabled` option, or use the toggle at
**Settings -> MCP Server**. The constant wins over the option, and the settings
toggle is locked when the constant is defined.

### Install the Adapter

The MCP server needs Composer dependencies: the adapter and the Jetpack
Autoloader. They are not committed to the repository because `vendor/` is
ignored. Release builds include them.

From the plugin directory:

```bash
composer install          # development
composer install --no-dev # release build
```

If the server is enabled but `vendor/` is missing, the plugin does not fatal. It
shows an admin notice, logs under `WP_DEBUG`, and leaves the catalog running.

### Endpoint

When enabled, the server registers one POST endpoint:

```text
/wp-json/abilities-catalog/v1/mcp
```

The adapter uses MCP's HTTP transport shape: JSON-RPC over POST with MCP session
headers. GET/SSE streaming is not implemented by the adapter yet and currently
returns 405. Authenticate with standard WordPress REST authentication: an
Application Password for a remote agent, or cookie and nonce for same-origin use.

The endpoint runs as the authenticated user. Capability checks are therefore
per-user, just like other WordPress admin actions.

### Exposure Gate

The MCP server has a separate per-ability exposure gate. Every ability is
disabled by default for execution over MCP.

A connected agent can still `list` and `describe` disabled abilities, so it can
learn what exists and what schema each ability expects. `execute` is refused
until an administrator enables that ability.

Use **Settings -> MCP Server** to enable abilities. The page groups abilities by
domain and shows read, write, destructive, and dangerous badges.

Two guards run on every MCP `execute`: the MCP exposure gate and the ability's
own capability check. A disabled ability is refused even for an administrator;
an enabled ability still requires the right WordPress capability.

### Security Note

Enabling a write or dangerous ability gives a network client reach to that
ability. For example, enabling `plugins/install-plugin` lets an authenticated
administrator install executable code through MCP.

The server is off by default, every ability is disabled by default for MCP
execution, and capability checks still run on every call. Enable only the
abilities an agent actually needs.

## Architecture

```text
abilities-catalog/
  abilities-catalog.php          # plugin header, PSR-4 autoloader, bootstrap
  includes/
    Registry.php                 # discovers, categorizes, and registers abilities
    Contracts/                   # ability and category-provider contracts
    Support/                     # guards for the dangerous tier
    Abilities/<Group>/<Domain>/  # one class per ability
    Mcp/                         # optional, off-by-default MCP server
      Admin/                     # settings page and exposure REST API
  assets/js/                     # no-build React settings app
```

`GalatanOvidiu\AbilitiesCatalog\` maps to `includes/`. The catalog has no build
step. Only the optional MCP server needs `vendor/`, loaded through the Jetpack
Autoloader when the server is enabled.

## Development

Static checks run on the host:

```bash
composer lint
composer phpstan
```

Tests run in Docker through `wp-env`:

```bash
npm run wp-env:test start
npm run test:php:setup
npm run test:php
```

## License

GPL-2.0-or-later.
