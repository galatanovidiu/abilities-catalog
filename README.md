# Abilities Catalog

A WordPress plugin that registers wp-admin features as abilities through the
[Abilities API](https://developer.wordpress.org/) (WordPress 7.0+).

The catalog is **consumer-agnostic**: it registers abilities and their risk
classification, and works standalone. A consumer decides how to expose them — for
example the [WebMCP adapter](https://github.com/galatanovidiu/webmcp-adapter)
(browser, in-page AI agents), the
[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) (server-side
MCP), or any other code that reads the Abilities API.

The plugin also ships **one optional consumer of its own**: a built-in MCP server
that is **off by default**. When enabled it exposes the catalog as curated domain
tools (see [MCP server](#mcp-server-optional)). The catalog behaves exactly the
same whether the server is on or off.

## Requirements

- WordPress 7.0 or later (Abilities API in core)
- PHP 8.1 or later
- For the optional MCP server only: a `composer install` (or the release build) to
  fetch the adapter — see [MCP server](#mcp-server-optional). The catalog itself
  needs no Composer step.

## What it registers

160 abilities across 18 wp-admin domains:

`Comments`, `Connectors`, `Content`, `Dashboard`, `Fonts`, `Media`, `Menus`,
`Plugins`, `Privacy`, `Search`, `Settings`, `SiteHealth`, `Templates`, `Terms`,
`Themes`, `Tools`, `Updates`, `Users`.

Each ability is one class, one file, discovered by a directory scan and registered
with `wp_register_ability()`. Ability names use a domain prefix, for example
`plugins/list-plugins` or `comments/approve-comment`.

## Safety model

Abilities are tiered by risk. The tier is declared in each ability's annotations,
not enforced here — enforcement is the consumer's job:

- **Read** — no side effects. Always safe to expose.
- **Write** — changes data. A non-destructive write.
- **Destructive** — deletes or trashes data. Annotated `destructive: true`.
- **Dangerous** — installs/updates/deletes plugins or themes, runs updates, writes
  options. Annotated `dangerous: true` and guarded by a safety pipeline in
  [`includes/Support/`](includes/Support/) (filesystem guard, source validation,
  option allow-list, upgrader lock).

`current_user_can()` is the hard guard in every ability. A write that omits the
`destructive` annotation is treated as unsafe and is not registered.

## MCP server (optional)

The plugin can expose the catalog over the [Model Context
Protocol](https://modelcontextprotocol.io/) (MCP, the standard AI agents use to
call tools) through a built-in server. It is **off by default** and built on the
official [`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter).

It does **not** expose 160 flat tools. It exposes **one tool per curated domain**
(11 domain tools), each with three actions — `list`, `describe`, `execute` — plus a
cross-cutting **`skills`** tool that serves lazy, agent-invocable task recipes (for
example, how to author coherent Gutenberg content). Abilities are invoked
server-side, inside a domain tool's handler, via `wp_get_ability()`.

### Enable it

Either define a constant in `wp-config.php` (the zero-UI path):

```php
define( 'ABILITIES_CATALOG_MCP_ENABLED', true );
```

…or set the option `abilities_catalog_mcp_enabled` to a truthy value, or flip the
toggle on the settings page at **Settings → MCP Server**. The constant wins over the
option (the settings toggle is shown locked when the constant is defined). The server
is off until one of these is set.

### Install the adapter

The MCP server needs its Composer dependencies (the adapter and the Jetpack
Autoloader). They are **not** bundled in the repository (`vendor/` is
git-ignored); a release build ships them. From the plugin directory:

```bash
composer install          # development
composer install --no-dev # what the release build runs
```

If the server is enabled but `vendor/` is missing, the plugin does not fatal: it
shows an admin notice and logs under `WP_DEBUG`, and the catalog keeps working.

### Endpoint

When enabled, the server registers a single POST endpoint:

```
/wp-json/abilities-catalog/v1/mcp
```

Transport is Streamable HTTP (POST only). Authenticate with standard WordPress REST
auth — an **Application Password** for a remote agent, or cookie + nonce
same-origin. The endpoint runs **as the authenticated user**, so capability gating
is per-user.

### Exposure (the per-ability gate)

The server is a **gate, not a firehose**. Every ability is **disabled by default**:
a connected agent can still `list` and `describe` it — so it learns the ability
exists and how to call it — but `execute` is refused until an administrator enables
it. Enable abilities on **Settings → MCP Server**, where they are grouped by domain
with read / write / destructive / dangerous badges; each toggle saves on the spot.
The `execute` error for a disabled ability names it and links back to that page.

Two independent guards run on every `execute`: this exposure gate, and the ability's
own `permission_callback` (the capability check). A disabled ability is refused even
for an administrator; an enabled one still enforces its capability — capability stays
the hard guard.

### Security note

Enabling a write or dangerous ability grants network reach to it: an administrator's
Application Password can then drive an enabled `plugins/install` (which is
RCE-equivalent) over the network with no human in the loop. The defenses are layered
— the server is **off by default**, every ability is **disabled by default**,
capability stays the hard guard on each call, and the settings page surfaces each
ability's risk tier before you enable it. Enable only what an agent genuinely needs.

## Architecture

```
abilities-catalog/
  abilities-catalog.php          # plugin header + no-build PSR-4 autoloader + bootstrap
  includes/
    Registry.php                 # discovers, categorizes, and registers abilities
    Contracts/                   # the one-class-per-ability + category-provider contracts
    Support/                     # safety pipeline for the dangerous tier
    Abilities/<Group>/<Domain>/  # one class per ability
    Mcp/                         # the optional, off-by-default MCP server (loads vendor/ only when on)
      Admin/                     # the settings page + exposure REST API (always available in wp-admin)
  assets/js/                     # the no-build React settings app (core wp-element/wp-components)
```

`GalatanOvidiu\AbilitiesCatalog\` maps to `includes/`. The catalog has no Composer
step and no build step; only the optional MCP server pulls `vendor/` (via the
Jetpack Autoloader) when it is enabled.

## License

GPL-2.0-or-later.
