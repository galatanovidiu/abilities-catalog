# Abilities Catalog

**Abilities Catalog gives WordPress agents two things that need each other at
scale: a large catalog of wp-admin abilities, and a search-based MCP server that
lets agents use that catalog without loading it all into context.**

The catalog registers 230+ WordPress core admin operations on the WordPress
Abilities API. Each ability has schemas, category metadata, server-side
capability checks, and risk annotations.

The search MCP server exposes those abilities to agents through one efficient
endpoint. An agent describes the task, gets a small ranked result set, reads the
exact schema for the selected ability, and executes it server-side. The agent
does not need to list hundreds or thousands of tools up front.

Those two parts are equally important in this plugin:

- The **catalog** defines what WordPress can do.
- The **search MCP server** makes that capability practical for agents when the
  catalog grows across core and plugins.

The MCP server is optional and off by default. The catalog still registers on
the Abilities API for any consumer that reads that API. For MCP clients,
however, the search server is the intended scalable surface.

## Quick start

Connect an MCP client to your site in four steps:

1. Install and activate Abilities Catalog.

   Use a release ZIP from the
   [Releases page](https://github.com/galatanovidiu/abilities-catalog/releases)
   and install it like any other WordPress plugin.

   If you are running from a git checkout, run `composer install` in the plugin
   directory before enabling the MCP server. Release ZIPs already include the
   required `vendor/` files.

2. Enable the MCP server. Add this to `wp-config.php`:

   ```php
   define( 'ABILITIES_CATALOG_MCP_ENABLED', true );
   ```

   You can also use the toggle at **Settings -> MCP Server**.

3. Create an Application Password at **Users -> Profile -> Application
   Passwords**. WordPress requires HTTPS to create one.

4. Point your MCP client at:

   ```text
   https://your-site/wp-json/abilities-catalog/v1/mcp-search
   ```

   Authenticate with your WordPress username and the Application Password.

Every ability starts disabled for MCP execution. Enable only the abilities an
agent needs at **Settings -> MCP Server**.

## Requirements

- WordPress 6.9 or later, with the Abilities API in core.
- PHP 8.1 or later.

## What the catalog registers

The core catalog includes 230+ abilities across 21 wp-admin areas:

`Comments`, `Connectors`, `Content`, `Cron`, `Dashboard`, `Fonts`, `Media`,
`Menus`, `Network`, `Plugins`, `Privacy`, `Search`, `Settings`, `SiteHealth`,
`Templates`, `Terms`, `Themes`, `Tools`, `Updates`, `Users`, `Widgets`.

Each ability is one PHP class under `includes/Abilities/Core/<Domain>/`.
`Registry` discovers those classes recursively and registers them with
`wp_register_ability()`.

Ability names use a `domain/verb-noun` shape, for example:

- `og-plugins/list-plugins`
- `og-comments/approve-comment`
- `og-content/create-post`

Each ability declares:

- an input schema and output schema;
- a category;
- a server-side `permission_callback`;
- risk annotations such as `readonly`, `destructive`, `idempotent`, and
  `dangerous`.

## Add-ons

Add-ons register their own abilities on the same Abilities API. When the search
MCP server is enabled, those abilities can appear through the same endpoint as
core abilities. The agent does not need a separate server per plugin.

Current add-ons:

- [abilities-catalog-woo](https://github.com/galatanovidiu/abilities-catalog-woo): WooCommerce store operations.
- [abilities-catalog-yoast-seo](https://github.com/galatanovidiu/abilities-catalog-yoast-seo): Yoast SEO.
- [abilities-catalog-cf7](https://github.com/galatanovidiu/abilities-catalog-cf7): Contact Form 7 forms.

These are separate plugins, not part of the core catalog.

See [Building an add-on](docs/building-add-ons.md) for the extension pattern.

## Why search-based MCP exists

The Abilities API gives WordPress a standard way to describe executable
capabilities. That creates a scale problem for MCP: a real site can have
hundreds of abilities from core, WooCommerce, SEO, forms, memberships, backups,
and custom business plugins.

Listing every ability as an MCP tool does not scale. It spends context before
the agent has done any work, and the cost grows with the total catalog size.

The search MCP server keeps the MCP surface small and fixed. Discovery becomes:

1. Get a compact overview of what the site can do.
2. Search for the task in plain words.
3. Describe one matching ability.
4. Execute that ability.

The result set is bounded. The catalog can grow without turning MCP discovery
into a tool dump.

## The search MCP server

The search server is built on
[`wordpress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) and registers
this endpoint when enabled:

```text
/wp-json/abilities-catalog/v1/mcp-search
```

It exposes five tools:

- **`overview`** - returns a compact capability map: categories, labels,
  descriptions, ability counts, enabled counts, and a few examples per category.
- **`search-abilities`** - searches the live ability registry by plain-language
  task description. Results include ability name, label, description, category,
  compact input signature, safety annotations, and whether the ability is
  enabled for MCP execution.
- **`describe-ability`** - returns the full input/output schema and metadata for
  one exact ability name.
- **`execute-ability`** - runs one exact ability name with arguments under
  `input`.
- **`knowledge`** - serves file-based OKF concept bundles: task recipes,
  authoring guidance, and live site facts for agents.

The `knowledge` tool is experimental. It is this plugin's file-based bridge
until WordPress has an official `wp-knowledge` standard in core. It lets an
agent read task recipes and authoring guidance instead of guessing. Call it with
no `uri` for an index of live site facts and every bundled concept, or pass a
specific `uri` such as `core/create-content` to read one concept.

The usual agent loop is:

```text
overview -> search-abilities -> describe-ability -> execute-ability
```

Discovery shows disabled abilities so an agent can learn what exists. Execution
is refused until a site administrator enables the ability on **Settings -> MCP
Server**.

## Other MCP surfaces

When the MCP server is enabled, this plugin has more than one MCP surface. They
serve different purposes.

### Search server

Endpoint:

```text
/wp-json/abilities-catalog/v1/mcp-search
```

This is the recommended server for agents. It is the scalable surface for large
catalogs and add-ons.

### Curated domain server

Endpoint:

```text
/wp-json/abilities-catalog/v1/mcp
```

This older server exposes one tool per curated domain. Each domain tool supports
`list`, `describe`, and `execute`.

It is useful and readable for the core catalog, but it depends on a maintained
domain taxonomy and becomes less attractive as arbitrary add-ons add hundreds or
thousands of abilities. Prefer the search server for new clients.

### Adapter default server

The bundled adapter also has its own default server. In this plugin, only
curated and owner-enabled abilities are marked public for that default surface.
That keeps the exposure gate intact, but it is still not the preferred large
catalog workflow.

Use the search server when an agent needs to work against the whole catalog
efficiently.

## Connecting a client

Most desktop MCP clients can connect through
[`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote).

Example config:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": [ "-y", "@automattic/mcp-wordpress-remote@latest" ],
      "env": {
        "WP_API_URL": "https://your-site/wp-json/abilities-catalog/v1/mcp-search",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your application password"
      }
    }
  }
}
```

Clients that support remote MCP servers can call the endpoint directly using:

```text
Authorization: Basic <base64 of "username:application-password">
```

The endpoint runs as the authenticated WordPress user. WordPress capability
checks are therefore per-user, just like wp-admin.

## Safety

Safety has two layers:

- the ability's WordPress capability check;
- the MCP exposure gate.

### Capability checks

Capability is the hard guard. Every ability has a server-side
`permission_callback` that calls `current_user_can()`. This runs on every
execution, independent of any MCP client or UI.

### Risk annotations

Risk annotations classify abilities for consumers. They are metadata, not the
permission system.

- **Read**: no side effects. Declares `readonly: true`.
- **Write**: changes data without deleting it. Declares `readonly: false` and
  `destructive: false`.
- **Destructive**: deletes, trashes, or permanently removes data. Declares
  `destructive: true`.
- **Dangerous**: can install, update, or delete plugins and themes, run updates,
  write broad options, or trigger privacy exports. Declares `dangerous: true`
  and runs behind dedicated guards.

The Registry refuses ambiguous write abilities. A write ability must explicitly
set `annotations.destructive` to a boolean.

### MCP exposure gate

The MCP server is off by default. When it is enabled, every ability is still
disabled for MCP execution until an administrator enables it.

Discovery can show disabled abilities. Execution cannot run them.

Two checks run on every MCP execution:

1. The MCP exposure gate must allow that ability.
2. The authenticated WordPress user must pass the ability's capability check.

> [!WARNING]
> An MCP client acts as the authenticated WordPress user. If you enable write,
> destructive, or dangerous abilities, the client can make real changes. Back up
> the site before enabling high-risk abilities, and enable only what the agent
> needs. For example, enabling `og-plugins/install-plugin` lets an authenticated
> administrator install executable code through MCP.

## Dangerous ability guards

Dangerous abilities use additional support code under `includes/Support/`.
Examples include:

- filesystem checks;
- wp.org source validation for plugin and theme installs;
- option allow-lists;
- upgrader locks.

Core update execution and irreversible erase execution are deliberately
excluded.

## Where this is going

This plugin is a working bridge while WordPress core and plugins grow their own
official abilities.

As official abilities appear, duplicates in this catalog should be removed. The
catalog should make room for core and plugin-owned definitions instead of
competing with them.

The search server is also intended as a candidate for upstreaming into
`wordpress/mcp-adapter`, because bounded search is the practical discovery model
for large WordPress ability catalogs.

## Architecture

```text
abilities-catalog/
  abilities-catalog.php          # plugin header, PSR-4 autoloader, bootstrap
  includes/
    Registry.php                 # discovers, categorizes, and registers abilities
    Contracts/                   # ability and category-provider contracts
    Abilities/<Group>/<Domain>/  # one class per ability
    Support/                     # guards for dangerous abilities
    Mcp/                         # optional MCP servers and search index
      Admin/                     # settings page and exposure REST API
  assets/js/                     # no-build React settings app
  docs/                          # user-facing documentation
  tests/phpunit/                 # unit and integration tests
```

`GalatanOvidiu\AbilitiesCatalog\` maps to `includes/`.

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

## Contributing

Abilities Catalog is an open source project. Bug reports, suggestions, and
questions are welcome on the
[GitHub issue tracker](https://github.com/galatanovidiu/abilities-catalog/issues).
Pull requests are welcome too.

## License

MIT. See [LICENSE](LICENSE).
