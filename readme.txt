=== Abilities Catalog ===
Contributors: ovidiu-galatan
Tags: abilities-api, ai, mcp, agents, wp-admin
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A catalog of wp-admin abilities on the WordPress Abilities API, plus an optional search-based MCP server so agents can use it at scale.

== Description ==

Abilities Catalog gives WordPress agents two things that need each other at scale: a large catalog of wp-admin abilities, and a search-based MCP (Model Context Protocol) server that lets agents use that catalog without loading it all into context.

The catalog registers 230+ WordPress core admin operations on the WordPress Abilities API (WordPress 6.9+). Each ability has input and output schemas, category metadata, a server-side capability check, and risk annotations.

The search MCP server exposes those abilities to agents through one efficient endpoint. An agent describes the task, gets a small ranked result set, reads the exact schema for the selected ability, and executes it server-side. The agent does not need to list hundreds or thousands of tools up front.

Both parts are equally important:

* The **catalog** defines what WordPress can do.
* The **search MCP server** makes that capability practical for agents when the catalog grows across core and plugins.

The MCP server is optional and off by default. The catalog still registers on the Abilities API for any consumer that reads that API, so it is **consumer-agnostic** and works standalone. For MCP clients, the search server is the intended scalable surface.

= What the catalog registers =

231 ability classes across 21 wp-admin domains: Comments, Connectors, Content, Cron, Dashboard, Fonts, Media, Menus, Network, Plugins, Privacy, Search, Settings, Site Health, Templates, Terms, Themes, Tools, Updates, Users, and Widgets.

Each ability is one PHP class under `includes/Abilities/Core/<Domain>/`. `Registry` discovers those classes recursively and registers them with `wp_register_ability()`. Ability names use a `domain/verb-noun` shape, for example `og-plugins/list-plugins`, `og-comments/approve-comment`, or `og-content/create-post`.

Each ability declares an input schema and output schema, a category, a server-side `permission_callback`, and risk annotations such as `readonly`, `destructive`, `idempotent`, and `dangerous`.

= Why search-based MCP exists =

The Abilities API gives WordPress a standard way to describe executable capabilities. That creates a scale problem for MCP: a real site can have hundreds of abilities from core, WooCommerce, SEO, forms, memberships, backups, and custom business plugins.

Listing every ability as an MCP tool does not scale. It spends context before the agent has done any work, and the cost grows with the total catalog size.

The search MCP server keeps the MCP surface small and fixed. Discovery becomes: get a compact overview of what the site can do, search for the task in plain words, describe one matching ability, then execute it. The result set is bounded, so the catalog can grow without turning MCP discovery into a tool dump.

= The search MCP server =

The search server is built on the official `wordpress/mcp-adapter` and registers this endpoint when enabled:

    /wp-json/abilities-catalog/v1/mcp-search

It exposes five tools:

* **overview** — returns a compact capability map: categories, labels, descriptions, ability counts, enabled counts, and a few examples per category.
* **search-abilities** — searches the live ability registry by plain-language task description. Results include the ability name, label, description, category, a compact input signature, safety annotations, and whether the ability is enabled for MCP execution.
* **describe-ability** — returns the full input/output schema and metadata for one exact ability name.
* **execute-ability** — runs one exact ability name with arguments under `input`.
* **knowledge** — serves file-based OKF concept bundles: task recipes, authoring guidance, and live site facts for agents. It is experimental, a file-based bridge until WordPress has an official `wp-knowledge` standard in core.

The usual agent loop is `overview` -> `search-abilities` -> `describe-ability` -> `execute-ability`. Discovery shows disabled abilities so an agent can learn what exists; execution is refused until a site administrator enables the ability on **Settings → MCP Server**.

= Other MCP surfaces =

When the MCP server is enabled, this plugin has more than one MCP surface:

* **Search server** (`/wp-json/abilities-catalog/v1/mcp-search`) — the recommended, scalable surface for large catalogs and add-ons.
* **Curated domain server** (`/wp-json/abilities-catalog/v1/mcp`) — an older server that exposes one tool per curated domain, each with `list`, `describe`, and `execute`. Readable for the core catalog, but it depends on a maintained domain taxonomy and is less attractive as add-ons add thousands of abilities. Prefer the search server for new clients.
* **Adapter default server** — the bundled adapter's own default surface; only curated, owner-enabled abilities are marked public there, keeping the exposure gate intact.

= Add-ons =

Add-ons register their own abilities on the same Abilities API. When the search MCP server is enabled, those abilities appear through the same endpoint as core abilities, so an agent does not need a separate server per plugin. Current add-ons (separate plugins, not part of the core catalog):

* abilities-catalog-woo — WooCommerce store operations: https://github.com/galatanovidiu/abilities-catalog-woo
* abilities-catalog-yoast-seo — Yoast SEO: https://github.com/galatanovidiu/abilities-catalog-yoast-seo
* abilities-catalog-cf7 — Contact Form 7 forms: https://github.com/galatanovidiu/abilities-catalog-cf7

See the "Building an add-on" guide (`docs/building-add-ons.md`) for the extension pattern.

= Safety =

Safety has two layers: the ability's WordPress capability check, and the MCP exposure gate.

**Capability checks.** Capability is the hard guard. Every ability has a server-side `permission_callback` that calls `current_user_can()`. This runs on every execution, independent of any MCP client or UI.

**Risk annotations.** Risk annotations classify abilities for consumers — they are metadata, not the permission system.

* **Read** — no side effects. Declares `readonly: true`.
* **Write** — changes data without deleting it. Declares `readonly: false` and `destructive: false`.
* **Destructive** — deletes, trashes, or permanently removes data. Declares `destructive: true`.
* **Dangerous** — can install, update, or delete plugins and themes, run updates, write broad options, or trigger privacy exports. Declares `dangerous: true` and runs behind dedicated guards.

The Registry refuses ambiguous write abilities: a write ability must explicitly set `annotations.destructive` to a boolean.

**MCP exposure gate.** The MCP server is off by default. When enabled, every ability is still disabled for MCP execution until an administrator enables it on **Settings → MCP Server**. Discovery can show disabled abilities; execution cannot run them. Two checks run on every MCP execution: the exposure gate must allow that ability, and the authenticated WordPress user must pass the ability's capability check.

**Warning — read before connecting an MCP client.** An MCP client acts as the authenticated WordPress user. If you enable write, destructive, or dangerous abilities, the client can make real changes in your name, and AI can make mistakes. Back up the site before enabling high-risk abilities, and enable only what the agent needs. For example, enabling `og-plugins/install-plugin` lets an authenticated administrator install executable code through MCP.

= Dangerous ability guards =

Dangerous abilities use additional support code under `includes/Support/`: filesystem checks, wp.org source validation for plugin and theme installs, option allow-lists, and upgrader locks. Core update execution and irreversible erase execution are deliberately excluded.

= Dependencies =

The catalog has no build step and no runtime Composer or npm dependencies — its PSR-4 autoloader is in the plugin. The optional MCP server is the only part that needs Composer dependencies (the adapter and the Jetpack Autoloader); the official release zip bundles them, so the server works out of the box. If the server is enabled but those dependencies are missing, the plugin does not fatal: it shows an admin notice and the catalog keeps working.

= Where this is going =

This plugin is a working bridge while WordPress core and plugins grow their own official abilities. As official abilities appear, duplicates in this catalog should be removed to make room for core and plugin-owned definitions. The search server is also intended as a candidate for upstreaming into `wordpress/mcp-adapter`, because bounded search is the practical discovery model for large WordPress ability catalogs.

= Requirements =

WordPress 6.9 or later (the Abilities API ships in core) and PHP 8.1 or later.

= Contributing =

Abilities Catalog is an open source project. Bug reports, suggestions, and questions are welcome on the GitHub issue tracker: https://github.com/galatanovidiu/abilities-catalog/issues. Pull requests are welcome too.

== Installation ==

1. Install and activate Abilities Catalog. Use a release ZIP from the Releases page (https://github.com/galatanovidiu/abilities-catalog/releases) and install it like any other WordPress plugin, or extract it into `wp-content/plugins/abilities-catalog`. If you are running from a git checkout, run `composer install` in the plugin directory before enabling the MCP server; release ZIPs already include the required `vendor/` files.
2. The abilities register automatically on `wp_abilities_api_init`. A consumer of the Abilities API is required to actually use them — either an external consumer, or the built-in MCP server.
3. Enable the MCP server (optional). Add `define( 'ABILITIES_CATALOG_MCP_ENABLED', true );` to `wp-config.php`, or use the toggle at **Settings → MCP Server**.
4. Create an Application Password at **Users → Profile → Application Passwords** (WordPress requires HTTPS to create one).
5. Point your MCP client at `https://your-site/wp-json/abilities-catalog/v1/mcp-search` and authenticate with your WordPress username and the Application Password.

Every ability starts disabled for MCP execution. Enable only the abilities an agent needs at **Settings → MCP Server**.

== Connecting an MCP client ==

Turn the server on first (see the FAQ), then point an MCP client at the search endpoint. The client signs in as a WordPress user with an Application Password (HTTP Basic authentication). You need the endpoint URL (for example `https://your-site/wp-json/abilities-catalog/v1/mcp-search`), a WordPress username (the agent acts as this user), and an Application Password for that user.

Most desktop clients (such as Claude Desktop or Cursor) connect through the `@automattic/mcp-wordpress-remote` proxy. Add it to your MCP client config:

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

A client that speaks remote (Streamable HTTP) MCP can call the endpoint directly, authenticating with the header `Authorization: Basic <base64 of "username:application-password">`. The endpoint runs as the authenticated WordPress user, so capability checks are per-user, just like wp-admin. Enable only the abilities the agent needs — and back up your site first.

== Frequently Asked Questions ==

= Does this plugin add any admin UI? =

One page: **Settings → MCP Server**, which controls the optional built-in MCP server and the per-ability exposure gate. It registers whenever the Abilities API is present, independent of whether the server is enabled. The plugin adds no front-end output.

= Do I need anything else to use it? =

To use the abilities you need a consumer of the Abilities API. You can use the built-in MCP server (off by default — enable it at **Settings → MCP Server**), or any external consumer such as an in-browser AI agent or a separate server-side MCP client.

= How do I turn on the MCP server? =

Define the `ABILITIES_CATALOG_MCP_ENABLED` constant in `wp-config.php`, set the `abilities_catalog_mcp_enabled` option to a truthy value, or flip the toggle on **Settings → MCP Server**. The server is off until one of these is set. Every ability stays disabled for `execute` until you enable it on that page.

= Is it safe to expose write or dangerous abilities? =

The plugin classifies risk but does not decide what is exposed. Every ability enforces a server-side capability check. With the MCP server, an ability is also disabled by default and must be enabled by an administrator. Deciding which abilities a given agent or user may run is the operator's responsibility — enable only what an agent genuinely needs.

= What are the requirements? =

WordPress 6.9 or later (the Abilities API ships in core) and PHP 8.1 or later.

== Changelog ==

= 0.4.0 =
* Breaking: every core ability name is now prefixed with `og-` (e.g. `plugins/list-plugins` is now `og-plugins/list-plugins`), and the Core categories are namespaced `og-core-*`. This frees the unprefixed names for official WordPress and third-party abilities. Pre-1.0, so there is no alias shim — consumers that stored ability names, including the MCP exposure allow-list, must re-select the abilities under their new names.
* New: multisite support. A policy decorator injects an optional `blog_id` into `site`-scoped abilities on multisite so a network admin can target a specific site, a new `og-users/list-my-sites` ability discovers the sites a user can act on, and non-site abilities declare their scope so the decorator leaves them alone. The catalog is now 231 abilities.
* Changed: the optional MCP server's cross-cutting `knowledge` tool now reads file-based OKF bundles (markdown with YAML frontmatter) under `includes/knowledge/` instead of bundled PHP recipe classes. Call it with no `uri` for a generated index (live site facts plus every bundle's concepts grouped by type), or with a `uri` (e.g. `og-core/create-content`) for one concept.
* Changed: the add-on extensibility filter is now `abilities_catalog_mcp_knowledge` and carries scanned `KnowledgeBundle` objects; an add-on scans its own bundle directory with `KnowledgeBundle::fromDirectory()`. Off-by-default and pre-1.0, so no backward-compatibility shim.
* Changed: better blind search in the MCP search server — tokens are stemmed and weighted by IDF, content abilities carry search keywords so content queries rank them, and search is oriented with category examples plus a no-match map. Unknown-ability errors now name the domain's owned prefixes to steer recovery.
* Changed: the **Settings → MCP Server** exposure page groups abilities by category with an improved UX, and the observability handler is now filterable.
* Fixes: the `og-content/get-post` read exposes `featured_media`, and search-server list results are wrapped consistently.
* Note: shipped knowledge concepts are English-only (the files are not translated).

= 0.3.1 =
* New: the optional MCP server now exposes the Network, Cron, and Widgets abilities through its curated domain tools — Network as its own (multisite-only) domain tool, with Cron folded into Tools and Widgets into Appearance. These 28 abilities shipped in 0.3.0 but were reachable through no domain tool until now.

= 0.3.0 =
* New: a scalable, search-based MCP server (`overview` / `search` / `describe` / `execute`) for navigating large catalogs, and the bundled adapter default server now publishes the curated ability subset instead of being suppressed.
* New: six cross-cutting task recipes spanning the domain tools, plus discovery guidance that points agents at `list` / `describe` (and at the exact schema on invalid input) instead of guessing ability names or inputs.
* New: register an add-on domain tool through the `abilities_catalog_mcp_domains` filter, so add-ons extend the MCP server without editing this plugin.
* Catalog: expanded to 230 abilities across 21 wp-admin domains (adds the Cron, Network, and Widgets domains; adds meta read/write, transient and object-cache, cron scheduling, multisite network read/write, roles & capabilities lookups, template parts, rewrite rules, sitemaps, taxonomy, theme mods, and user sessions).
* Changed: license switched from GPL-2.0-or-later to MIT.
* Fixes: gate multisite network transient/cache operations on `manage_network_options`; avoid a Plugin Check `plugin_updater_detected` false positive.
* Docs: guide for building catalog add-ons, and how to connect an MCP client.

= 0.2.0 =
* New: an optional, off-by-default built-in MCP server that exposes the catalog as curated domain tools (`list` / `describe` / `execute`) plus a cross-cutting `knowledge` tool, built on `wordpress/mcp-adapter`.
* New: an owner-controlled, deny-by-default per-ability exposure gate and a **Settings → MCP Server** page (with its exposure REST API) to manage it. A disabled ability can be listed and described but not executed; capability stays the hard guard on every call.
* New: extensibility filters for the domain map, knowledge, tools, and tool permissions, so consumers can extend the server without editing the plugin.
* Catalog: expanded to 160 abilities across 18 wp-admin domains (adds the Search domain and broader coverage across Content, Terms, Menus, Templates, Settings, Users, and Fonts).
* Fixes: a correctness sweep across abilities — multi-value post meta stored as separate rows, reads no longer mutate state, REST responses no longer leak core download headers, hardened input schemas, and raw serialized block markup returned from navigation reads.
* Internal: renamed the hook and identifier prefix to `abilities_catalog_` and removed consumer-specific wording, so the catalog is fully consumer-agnostic.

= 0.1.0 =
* Initial release.
* Registers 135 abilities across 17 wp-admin domains (read, write, destructive, and dangerous tiers).
* Server-side safety pipeline for the dangerous tier: filesystem guard, source validation, option allow-list, and upgrader lock.

== Upgrade Notice ==

= 0.4.0 =
Breaking: core ability names are now `og-`-prefixed (e.g. `og-plugins/list-plugins`) and Core categories are `og-core-*`, freeing the plain names for official abilities. Pre-1.0, so there is no alias — re-select your abilities in the MCP exposure list after upgrading, as the stored names changed. Adds multisite support (an optional `blog_id` on site abilities, the new `og-users/list-my-sites` ability). The MCP `knowledge` tool now reads markdown OKF bundles and its add-on filter is renamed to `abilities_catalog_mcp_knowledge`.

= 0.3.1 =
Exposes 28 already-registered abilities (Network, Cron, Widgets) through the curated MCP domain tools: a new multisite-only Network domain tool, plus Cron folded into Tools and Widgets into Appearance. No change to the catalog, and no change when the MCP server is off.

= 0.3.0 =
Expands the catalog to 230 abilities across 21 domains (new Cron, Network, and Widgets domains), adds a scalable search-based MCP server, and switches the license to MIT. The bundled MCP adapter's default server now publishes the curated subset; the catalog behaves the same when the server is off.

= 0.2.0 =
Adds an optional, off-by-default built-in MCP server with a per-ability exposure gate and a Settings → MCP Server page, expands the catalog to 160 abilities across 18 domains, and ships correctness fixes. The catalog behaves the same when the server is off.

= 0.1.0 =
Initial release.
