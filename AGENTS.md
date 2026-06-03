# AGENTS.md — abilities-catalog

## Project goal

Register a coherent, complete-enough catalog of WordPress **abilities** for core WP 7.0 wp-admin,
on top of the core Abilities API. This plugin is **consumer-agnostic**: it defines abilities and
their risk classification. It does not surface them to any agent or UI — that is a consumer's job
(an in-browser agent, a server-side MCP client, or none). It works standalone.

## Docs

- [docs/ability-catalog.md](docs/ability-catalog.md) — the build plan: every core WP 7.0 wp-admin
  ability, grouped by domain, classified (read / write / destructive / dangerous), tiered into a
  build order, with the gating *principle* (the consumer must gate writes). Read before adding tools.
- [docs/ability-implementation.md](docs/ability-implementation.md) — how abilities are built: the
  per-ability class pattern, the Registry, and the dangerous-tier safety pipeline. Read before
  adding abilities.
- [docs/schema-constraints.md](docs/schema-constraints.md) — input/output schema rules that make an
  ability execute (not just register). Read before authoring or debugging an ability's schema.
- `.hyper/loops/` (local, gitignored) — L1/L2/L5 build history.

## Current state

- Registers all catalog **T1 read** abilities (62, across 17 domains), **T1 safe writes** (L3),
  **T2 standard writes** (L4), the **T3 dangerous tier** (L5, 8 abilities), and the **L6
  catalog-gap abilities** (5): `content/create-cpt-item`, `content/update-cpt-item`,
  `menus/delete-menu-item` (permanent — no Trash, `destructive`), `fonts/delete-font-family`,
  `settings/get-option` (read-gated by `Support/ReadableOptionAllowList`).
- One PHP class per ability under `includes/Abilities/<Domain>/`. The `Registry` scans them,
  registers each category once on `wp_abilities_api_categories_init` and each ability on
  `wp_abilities_api_init`. No Composer step, no shared manifest — adding a domain edits only its
  own folder.
- **T3 dangerous tier** runs behind the server-side safety pipeline in `includes/Support/`:
  `FilesystemGuard` (direct-or-fail), `SourceValidator` (wp.org-slug-only source), `OptionAllowList`
  (deny-by-default for `settings/update-option`), `UpgraderLock`, `UpgradeRunner`. Scope: plugin/theme
  install·update·delete, `updates/run-update` (plugin/theme/translation only — **core update excluded**),
  `settings/update-option` (allow-list), `privacy/generate-export`.
- **Deferred (deliberate):** core update; `privacy/run-erase` execution (irreversible + batched,
  human-only in wp-admin).
- Verified server-side (consumer-agnostic):
  `wp --user=admin eval '... wp_get_ability("content/get-post")->execute(["id"=>1]) ...'`.

## Key facts to not get wrong

- **Capability is the hard guard.** Every ability's `permission_callback` enforces the catalog
  capability server-side, regardless of any consumer-side gating. This is non-negotiable.
- **Classification, not enforcement.** The catalog marks `readonly` / `destructive` / `idempotent`
  / `dangerous` in `meta.annotations`. Whether and how those are surfaced is the consumer's concern.
  Never document this plugin as requiring a specific consumer.
- **Wrap, don't reimplement.** `execute_callback` wraps a core function or an internal REST route
  (`rest_do_request`); return a `WP_Error` on failure.
- **Schema gotchas are real** — empty `array()` → `[]`, no-input pattern, all-optional default,
  object-output cast, admin includes for net-new reads. See docs/schema-constraints.md.
- **Consumer-provided hooks.** The Registry contributes dangerous ability names to a
  `webmcp_dangerous_tools` filter and screen templates to a `webmcp_screen_links` filter — hooks a
  consumer provides; the catalog only populates them when present. The names carry a consumer prefix
  for historical reasons; a neutral rename is tracked (backlog B9).
