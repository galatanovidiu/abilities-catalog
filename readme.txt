=== Abilities Catalog ===
Contributors: ovidiu-galatan
Tags: abilities-api, ai, mcp, agents, wp-admin
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Registers wp-admin features as abilities through the WordPress Abilities API. Platform-agnostic: it only registers abilities, no UI or transport.

== Description ==

Abilities Catalog registers a coherent, complete-enough catalog of WordPress wp-admin features as abilities on top of the core Abilities API (WordPress 7.0+).

The plugin is **consumer-agnostic**. It only registers abilities and classifies them by risk. It does not surface them to any agent or interface and does not talk to any browser bridge or transport. A separate consumer decides how to expose them — for example a browser-based AI agent, a server-side MCP (Model Context Protocol) client, or none. The plugin works standalone.

= What it registers =

135 ability classes across 17 wp-admin domains: Comments, Connectors, Content, Dashboard, Fonts, Media, Menus, Plugins, Privacy, Settings, Site Health, Templates, Terms, Themes, Tools, Updates, and Users.

Each ability is one class in one file, discovered by a directory scan and registered with `wp_register_ability()`. Ability names use a domain prefix, for example `plugins/list-plugins` or `comments/approve-comment`.

= Safety model =

Abilities are tiered by risk. The tier is declared in each ability's annotations; it is classification, not enforcement — enforcement is the consumer's job.

* **Read** — no side effects. Always safe to expose.
* **Write** — changes data. A non-destructive write.
* **Destructive** — deletes or trashes data. Annotated `destructive: true`.
* **Dangerous** — installs, updates, or deletes plugins or themes, runs updates, writes options. Annotated `dangerous: true` and guarded by a server-side safety pipeline (filesystem guard, source validation, option allow-list, upgrader lock).

`current_user_can()` is the hard guard in every ability, enforced server-side regardless of any consumer-side gating. A write that omits the `destructive` annotation is treated as unsafe and is not registered.

= No build step =

The plugin uses a no-build PSR-4 autoloader. There are no runtime Composer or npm dependencies.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload Plugin, or extract it into `wp-content/plugins/abilities-catalog`.
2. Activate the plugin through the Plugins screen in WordPress.
3. The abilities register automatically on `wp_abilities_api_init`. A consumer (an Abilities API client) is required to actually use them.

== Frequently Asked Questions ==

= Does this plugin add any admin UI? =

No. It only registers abilities. It has no settings page and no front-end output.

= Do I need anything else to use it? =

Yes. The plugin defines abilities but does not expose them. You need an Abilities API consumer — for example an in-browser AI agent or a server-side MCP client — to surface and run them.

= Is it safe to expose write or dangerous abilities? =

The plugin classifies risk but does not decide what is exposed. Every ability enforces a server-side capability check. Deciding which abilities a given agent or user may run is the consumer's responsibility.

= What are the requirements? =

WordPress 7.0 or later (the Abilities API ships in core) and PHP 8.1 or later.

== Changelog ==

= 0.1.0 =
* Initial release.
* Registers 135 abilities across 17 wp-admin domains (read, write, destructive, and dangerous tiers).
* Server-side safety pipeline for the dangerous tier: filesystem guard, source validation, option allow-list, and upgrader lock.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
