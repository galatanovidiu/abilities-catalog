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
- [docs/testing.md](docs/testing.md) — the TDD workflow: how to run PHPUnit in wp-env, the
  red-green-refactor loop, and where tests live. Read before adding or changing an ability;
  new abilities are built test-first.
- `.hyper/loops/` (local, gitignored) — L1/L2/L5 build history.

## Current state

- Registers the full catalog — **160 abilities across 18 domains** (76 read-only, 8 dangerous-tier).
  By build order: the **T1 read** abilities (L2), **T1 safe writes** (L3),
  **T2 standard writes** (L4), the **T3 dangerous tier** (L5, 8 abilities), the **L6
  catalog-gap abilities** (5): `content/create-cpt-item`, `content/update-cpt-item`,
  `menus/delete-menu-item` (permanent — no Trash, `destructive`), `fonts/delete-font-family`,
  `settings/get-option` (read-gated by `Support/ReadableOptionAllowList`), and the **L7
  authoring-context + completeness abilities** (13): six reads
  (`templates/list-block-types`, `list-block-pattern-categories`, `list-synced-patterns`,
  `get-theme-styles`, `list-global-style-variations`, and the new **Search** domain's
  `search/search-content`), four writes (`templates/create-template`,
  `templates/delete-template` (`destructive`), `menus/delete-classic-menu` (`destructive`),
  `menus/delete-navigation` (`destructive`)), and three discovery abilities
  (`templates/lookup-template` (pure core); `plugins/search-directory` and
  `themes/search-directory` — outbound wp.org call, `readonly`, gated on
  `install_plugins`/`install_themes`), and the **L8 per-object completeness abilities** (9):
  post-meta CRUD (`content/get-post-meta`, `content/update-post-meta`,
  `content/delete-post-meta` (`destructive`), `content/list-post-meta-keys`) — all gated to
  registered `show_in_rest` meta keys via `Support/PostMetaKeys`; post↔term assignment
  (`terms/attach-post-terms`, `terms/detach-post-terms`) — resolve existing terms via
  `Support/TermResolver`, never create; the `menus/list-menu-locations` read; and the media
  size abilities (`media/list-image-sizes` read, `media/regenerate-thumbnails` write).
- Abilities are organized into top-level **groups** under `includes/Abilities/<Group>/`. The
  core WP catalog lives in `includes/Abilities/Core/<Domain>/`; non-core add-ons get their own
  sibling group (e.g. `includes/Abilities/Woo/`). One PHP class per ability. The `Registry`
  scans `includes/Abilities/` recursively (any depth) and registers each ability on
  `wp_abilities_api_init`. Categories are contributed **per group** by a class implementing
  `Contracts\CategoryProvider` (the Core group's is `Abilities\Core\CategoryCatalog`); the Registry
  discovers providers in the same scan and registers their categories on
  `wp_abilities_api_categories_init`. Each ability links to its category by slug via
  `args()['category']`. No Composer step, no shared manifest, no shared category file. Adding a
  group means a new folder plus its own `CategoryProvider`; adding a domain or ability to an
  existing group edits only that group's folder.
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
- **Consumer-provided hooks.** The Registry contributes dangerous ability names to an
  `abilities_catalog_dangerous_tools` filter and screen templates to an `abilities_catalog_screen_links`
  filter — hooks a consumer provides; the catalog only populates them when present.
