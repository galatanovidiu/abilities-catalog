# Abilities Catalog

A WordPress plugin that registers wp-admin features as abilities through the
[Abilities API](https://developer.wordpress.org/) (WordPress 7.0+).

The plugin is platform-agnostic. It only registers abilities. It does not talk to
any browser bridge or transport. A separate consumer decides how to expose them —
for example the [WebMCP adapter](https://github.com/galatanovidiu/webmcp-adapter)
(browser, in-page AI agents) or the
[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) (server-side
MCP) — or any other code that reads the Abilities API.

## Requirements

- WordPress 7.0 or later (Abilities API in core)
- PHP 8.1 or later

## What it registers

135 ability classes across 17 wp-admin domains:

`Comments`, `Connectors`, `Content`, `Dashboard`, `Fonts`, `Media`, `Menus`,
`Plugins`, `Privacy`, `Settings`, `SiteHealth`, `Templates`, `Terms`, `Themes`,
`Tools`, `Updates`, `Users`.

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

## Architecture

```
abilities-catalog/
  abilities-catalog.php          # plugin header + no-build PSR-4 autoloader + bootstrap
  includes/
    Registry.php                 # discovers, categorizes, and registers abilities
    Contracts/Ability.php        # the one-class-per-ability contract
    Support/                     # safety pipeline for the dangerous tier
    Abilities/<Domain>/          # one class per ability
```

`Automattic\AbilitiesCatalog\` maps to `includes/`. There is no Composer step and
no build step.

## License

GPL-2.0-or-later.
