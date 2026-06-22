=== Abilities Catalog ===
Contributors: ovidiu-galatan
Tags: abilities-api, ai, mcp, agents, wp-admin
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Registers wp-admin features as abilities on the WordPress Abilities API, with an optional, off-by-default built-in MCP server.

== Description ==

Abilities Catalog registers a coherent, complete-enough catalog of WordPress wp-admin features as abilities on top of the core Abilities API (WordPress 7.0+).

The catalog is **consumer-agnostic**. It registers abilities and classifies them by risk, and it works standalone. It does not require any particular agent or interface. A consumer decides how to expose the abilities — for example a browser-based AI agent, a server-side MCP (Model Context Protocol) client, or any other code that reads the Abilities API.

The plugin also ships **one optional consumer of its own**: a built-in MCP server that is **off by default**. When enabled, it exposes the catalog as curated domain tools. The catalog behaves exactly the same whether the server is on or off.

= What it registers =

160 ability classes across 18 wp-admin domains: Comments, Connectors, Content, Dashboard, Fonts, Media, Menus, Plugins, Privacy, Search, Settings, Site Health, Templates, Terms, Themes, Tools, Updates, and Users.

Each ability is one class in one file, discovered by a directory scan and registered with `wp_register_ability()`. Ability names use a domain prefix, for example `plugins/list-plugins` or `comments/approve-comment`.

= Safety model =

Abilities are tiered by risk. The tier is declared in each ability's annotations; it is classification, not enforcement — enforcement is the consumer's job.

* **Read** — no side effects. Always safe to expose.
* **Write** — changes data. A non-destructive write.
* **Destructive** — deletes or trashes data. Annotated `destructive: true`.
* **Dangerous** — installs, updates, or deletes plugins or themes, runs updates, writes options. Annotated `dangerous: true` and guarded by a server-side safety pipeline (filesystem guard, source validation, option allow-list, upgrader lock).

`current_user_can()` is the hard guard in every ability, enforced server-side regardless of any consumer-side gating. A write that omits the `destructive` annotation is treated as unsafe and is not registered.

= Optional built-in MCP server =

The plugin can expose the catalog over the Model Context Protocol through a built-in server, built on the official `wordpress/mcp-adapter`. It is **off by default** and the catalog is unaffected when it is off.

* It does not expose flat per-ability tools. It exposes one tool per curated domain (each with `list`, `describe`, and `execute` actions) plus a cross-cutting `skills` tool.
* On top of every ability's capability check sits an owner-controlled, deny-by-default **exposure gate**: each ability is disabled until an administrator enables it on the settings page. A disabled ability can still be listed and described, so an agent can learn it, but `execute` is refused. Capability stays the hard guard on every `execute`.
* Enable the server with the `ABILITIES_CATALOG_MCP_ENABLED` constant, the `abilities_catalog_mcp_enabled` option, or the toggle on the settings page at **Settings → MCP Server**.

= Dependencies =

The catalog has no build step and no runtime Composer or npm dependencies — its PSR-4 autoloader is in the plugin. The optional MCP server is the only part that needs Composer dependencies (the adapter and the Jetpack Autoloader); the official release zip bundles them, so the server works out of the box. If the server is enabled but those dependencies are missing, the plugin does not fatal: it shows an admin notice and the catalog keeps working.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, or extract it into `wp-content/plugins/abilities-catalog`.
2. Activate the plugin through the Plugins screen in WordPress.
3. The abilities register automatically on `wp_abilities_api_init`. A consumer (an Abilities API client) is required to actually use them — or enable the built-in MCP server at **Settings → MCP Server**.

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

WordPress 7.0 or later (the Abilities API ships in core) and PHP 8.1 or later.

== Changelog ==

= 0.2.0 =
* New: an optional, off-by-default built-in MCP server that exposes the catalog as curated domain tools (`list` / `describe` / `execute`) plus a cross-cutting `skills` tool, built on `wordpress/mcp-adapter`.
* New: an owner-controlled, deny-by-default per-ability exposure gate and a **Settings → MCP Server** page (with its exposure REST API) to manage it. A disabled ability can be listed and described but not executed; capability stays the hard guard on every call.
* New: extensibility filters for the domain map, skills, tools, and tool permissions, so consumers can extend the server without editing the plugin.
* Catalog: expanded to 160 abilities across 18 wp-admin domains (adds the Search domain and broader coverage across Content, Terms, Menus, Templates, Settings, Users, and Fonts).
* Fixes: a correctness sweep across abilities — multi-value post meta stored as separate rows, reads no longer mutate state, REST responses no longer leak core download headers, hardened input schemas, and raw serialized block markup returned from navigation reads.
* Internal: renamed the hook and identifier prefix to `abilities_catalog_` and removed consumer-specific wording, so the catalog is fully consumer-agnostic.

= 0.1.0 =
* Initial release.
* Registers 135 abilities across 17 wp-admin domains (read, write, destructive, and dangerous tiers).
* Server-side safety pipeline for the dangerous tier: filesystem guard, source validation, option allow-list, and upgrader lock.

== Upgrade Notice ==

= 0.2.0 =
Adds an optional, off-by-default built-in MCP server with a per-ability exposure gate and a Settings → MCP Server page, expands the catalog to 160 abilities across 18 domains, and ships correctness fixes. No action required for existing consumers; the catalog behaves the same when the server is off.

= 0.1.0 =
Initial release.
