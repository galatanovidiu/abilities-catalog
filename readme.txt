=== Abilities Catalog ===
Contributors: ovidiu-galatan
Tags: abilities-api, ai, mcp, agents, wp-admin
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Registers wp-admin features as abilities on the WordPress Abilities API, with an optional, off-by-default built-in MCP server.

== Description ==

Abilities Catalog registers a coherent, complete-enough catalog of WordPress wp-admin features as abilities on top of the core Abilities API (WordPress 7.0+).

The catalog is **consumer-agnostic**. It registers abilities and classifies them by risk, and it works standalone. It does not require any particular agent or interface. A consumer decides how to expose the abilities — for example a browser-based AI agent, a server-side MCP (Model Context Protocol) client, or any other code that reads the Abilities API.

The plugin also ships **one optional consumer of its own**: a built-in MCP server that is **off by default**. When enabled, it exposes the catalog as curated domain tools. The catalog behaves exactly the same whether the server is on or off.

= What it registers =

230 ability classes across 21 wp-admin domains: Comments, Connectors, Content, Cron, Dashboard, Fonts, Media, Menus, Network, Plugins, Privacy, Search, Settings, Site Health, Templates, Terms, Themes, Tools, Updates, Users, and Widgets.

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

* It does not expose flat per-ability tools. It exposes one tool per curated domain (each with `list`, `describe`, and `execute` actions) plus a cross-cutting `knowledge` tool that serves file-based OKF concepts (task recipes and authoring guidelines): call it with no `uri` for an index, or with a `uri` for one concept.
* On top of every ability's capability check sits an owner-controlled, deny-by-default **exposure gate**: each ability is disabled until an administrator enables it on the settings page. A disabled ability can still be listed and described, so an agent can learn it, but `execute` is refused. Capability stays the hard guard on every `execute`.
* Enable the server with the `ABILITIES_CATALOG_MCP_ENABLED` constant, the `abilities_catalog_mcp_enabled` option, or the toggle on the settings page at **Settings → MCP Server**.

**Warning — read before connecting an MCP client.** When you connect the MCP server to an MCP client (such as Claude, Gemini, or ChatGPT), the AI acts on your site as you and can make real changes in your name. AI can make mistakes. Back up your site before you enable abilities, and enable only the abilities the agent actually needs.

= Dependencies =

The catalog has no build step and no runtime Composer or npm dependencies — its PSR-4 autoloader is in the plugin. The optional MCP server is the only part that needs Composer dependencies (the adapter and the Jetpack Autoloader); the official release zip bundles them, so the server works out of the box. If the server is enabled but those dependencies are missing, the plugin does not fatal: it shows an admin notice and the catalog keeps working.

= Contributing =

Abilities Catalog is an open source project. Bug reports, suggestions, and questions are welcome on the GitHub issue tracker: https://github.com/galatanovidiu/abilities-catalog/issues. Pull requests are welcome too.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, or extract it into `wp-content/plugins/abilities-catalog`.
2. Activate the plugin through the Plugins screen in WordPress.
3. The abilities register automatically on `wp_abilities_api_init`. A consumer (an Abilities API client) is required to actually use them — or enable the built-in MCP server at **Settings → MCP Server**.

== Connecting an MCP client ==

Turn the server on first (see the FAQ below), then point an MCP client at the endpoint shown on **Settings → MCP Server**. The client signs in as a WordPress user with an Application Password (HTTP Basic authentication). You need three things:

1. The endpoint URL, for example `https://your-site/wp-json/abilities-catalog/v1/mcp`.
2. A WordPress username — the agent acts as this user.
3. An Application Password for that user, created at **Users → Profile → Application Passwords** (this requires the site to run over HTTPS).

Most clients (such as Claude Desktop or Cursor) connect through the `@automattic/mcp-wordpress-remote` proxy. Add it to your MCP client config:

    {
      "mcpServers": {
        "wordpress": {
          "command": "npx",
          "args": [ "-y", "@automattic/mcp-wordpress-remote@latest" ],
          "env": {
            "WP_API_URL": "https://your-site/wp-json/abilities-catalog/v1/mcp",
            "WP_API_USERNAME": "your-username",
            "WP_API_PASSWORD": "your application password"
          }
        }
      }
    }

A client that speaks remote (Streamable HTTP) MCP can call the endpoint directly, authenticating with the header `Authorization: Basic <base64 of "username:application-password">`. Either way the agent acts as the chosen user, so enable only the abilities it needs — and back up your site first.

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

= 0.4.0 =
* Changed: the optional MCP server's cross-cutting `knowledge` tool now reads file-based OKF bundles (markdown with YAML frontmatter) under `includes/knowledge/` instead of bundled PHP recipe classes. Call it with no `uri` for a generated index (live site facts plus every bundle's concepts grouped by type), or with a `uri` (e.g. `core/create-content`) for one concept.
* Changed: the add-on extensibility filter is now `abilities_catalog_mcp_knowledge` and carries scanned `KnowledgeBundle` objects; an add-on scans its own bundle directory with `KnowledgeBundle::fromDirectory()`. Off-by-default and pre-1.0, so no backward-compatibility shim.
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
The optional MCP server's `knowledge` tool now reads file-based OKF bundles (markdown) instead of PHP recipe classes, and its add-on filter is renamed to `abilities_catalog_mcp_knowledge` carrying scanned bundle objects. No change to the catalog, and no change when the MCP server is off. Off-by-default and pre-1.0, so there is no backward-compatibility shim — an add-on that contributed to the old filter must move to the new one.

= 0.3.1 =
Exposes 28 already-registered abilities (Network, Cron, Widgets) through the curated MCP domain tools: a new multisite-only Network domain tool, plus Cron folded into Tools and Widgets into Appearance. No change to the catalog, and no change when the MCP server is off.

= 0.3.0 =
Expands the catalog to 230 abilities across 21 domains (new Cron, Network, and Widgets domains), adds a scalable search-based MCP server, and switches the license to MIT. The bundled MCP adapter's default server now publishes the curated subset; the catalog behaves the same when the server is off.

= 0.2.0 =
Adds an optional, off-by-default built-in MCP server with a per-ability exposure gate and a Settings → MCP Server page, expands the catalog to 160 abilities across 18 domains, and ships correctness fixes. The catalog behaves the same when the server is off.

= 0.1.0 =
Initial release.
