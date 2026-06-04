# Implementing abilities — the `abilities-catalog` plugin

The catalog ([ability-catalog.md](ability-catalog.md)) is the plan; this is how the plan
is built. This plugin registers abilities and nothing else — it is consumer-agnostic. One
PHP class per ability; a consumer (an in-browser agent, a server-side MCP client, or none)
reads the registered abilities separately and is not required for this plugin to work.

The first build (loop L2) implemented every **T1 read** ability from the catalog
(62 abilities across 17 domains). Later loops added T1 safe writes (L3), T2 standard writes
(L4), and the **T3 dangerous tier** (L5, 8 abilities — see below). Loop **L6** closed five
catalog-planned-but-unbuilt gaps: `content/create-cpt-item`, `content/update-cpt-item`,
`menus/delete-menu-item` (permanent — menu items have no Trash), `fonts/delete-font-family`,
and `settings/get-option` (read-gated by `Support/ReadableOptionAllowList`). Loop **L7** added
13 authoring-context + completeness abilities — six reads (block types, block-pattern
categories, synced patterns, theme styles, style variations, and a new **Search** domain),
four writes (`templates/create-template`/`delete-template`, `menus/delete-classic-menu`,
`menus/delete-navigation`), and three discovery abilities (`templates/lookup-template` plus
`plugins/`/`themes/search-directory`, which make an outbound wp.org call and gate on the
install capability).

## Structure

```
abilities-catalog/
  abilities-catalog.php            # plugin header + no-build PSR-4 autoloader + bootstrap
  includes/
    Contracts/Ability.php         # interface: name(), args()
    Categories.php                # central category catalog (slug => label/description)
    Registry.php                  # discovers ability classes, registers them, guards
    Support/AdminIncludes.php     # loads wp-admin/includes/* on demand (for net-new reads)
    Abilities/<Domain>/<Class>.php # one class per ability
```

`Automattic\AbilitiesCatalog\` maps to `includes/`. There is **no Composer step** and **no
shared manifest**: `Registry` scans `includes/Abilities/*/*.php`, instantiates every class
implementing `Ability`, and registers each ability on `wp_abilities_api_init`. Categories
are defined centrally in `Categories.php` and registered on
`wp_abilities_api_categories_init`; an ability links to its category by slug through
`args()['category']`. To add a domain you add files under its own folder **and** add one
category entry to `Categories.php` (the only shared file a new domain touches).

## Adding a read ability

Copy [`Abilities/Content/GetPost.php`](../includes/Abilities/Content/GetPost.php)
— the canonical template — and follow it:

1. `declare(strict_types=1)`, namespace `Automattic\AbilitiesCatalog\Abilities\<Domain>`,
   `if (!defined('ABSPATH')) { exit; }`, one `final class` implementing `Ability`.
2. For a net-new domain, add one entry to `Categories.php` keyed by slug
   (`'slug' => ['slug' => ..., 'label' => ..., 'description' => ...]`). Existing
   domains already have an entry — reuse the slug.
3. `args()` returns the `wp_register_ability` array: `label`, `description`,
   `category` (= slug), `input_schema`, `output_schema`, `execute_callback`,
   `permission_callback`, and `meta` with `annotations.readonly = true` and
   `show_in_rest = true`.
4. `permission_callback` encodes the **catalog capability exactly** (never weaker). For
   object-level caps read the id from the input: `current_user_can('read_post', $id)`.
   This is the hard guard; `rest_do_request` re-checks underneath as defense-in-depth.
5. `execute_callback` wraps a core function or an internal REST route
   (`rest_do_request`) — do not reimplement WordPress logic. Return a `WP_Error` on
   failure; otherwise an array matching `output_schema`.

The Registry refuses any ability whose `meta.annotations.readonly` is not strictly `true`
unless it is registered through the write path, so a write ability cannot ship by accident.

## Schema rules that are easy to get wrong

An ability can register and list yet still **fail when executed**, because the input and
output are validated against the declared JSON Schema and the runtime marshals the arguments.
A strict validator (such as a browser consumer's AJV) is less forgiving than `wp eval`, so a
schema that passes server-side can still fail for a consumer.
Full detail and the debugging method are in [schema-constraints.md](schema-constraints.md).
The short rules:

- **No-input ability:** declare `'input_schema' => array()` (empty) and make the callbacks
  accept zero arguments: `execute($input = null)`, `hasPermission($input = null)`. This
  matches core's `get-environment-info`. A non-empty object schema with no properties fails.
- **All-optional list ability:** give one property a `default` (e.g. a `context` param with
  `default => 'view'`). Without any default, an empty `{}` call is mis-marshalled and fails.
  Do not default a real filter — it changes results.
- **Empty object output:** cast to `(object)` so it serializes as `{}`, not `[]`
  (an empty PHP `array()` becomes a JSON array and fails a `type: object` schema).
- Empty `properties`/`required` arrays in schemas are auto-repaired by the Registry
  normalizer, but prefer not to emit them.
- **Net-new reads** that call `wp-admin/includes/*` functions must load them first via
  `AdminIncludes::load(...)` — REST requests do not load admin includes (CLI does, so it
  passes in `wp eval` and fails only over REST).

## Adding a dangerous (T3) ability

The T3 dangerous tier (plugin/theme install·update·delete, `updates/run-update`,
`settings/update-option`, `privacy/generate-export`) runs irreversible or code-on-disk
operations. It sits behind a safety pipeline on top of the write + destructive gates.

**Annotations.** All 8 carry `readonly: false, destructive: true, idempotent: false,
dangerous: true` in `meta.annotations`. Capability is still the hard server-side guard. The
`dangerous` annotation is how a consumer detects that an ability needs the strictest gate.

**Exposure is the consumer's duty.** The catalog does not surface a dangerous ability anywhere;
it only classifies it. A consumer must require its own write + destructive + a separate
per-ability dangerous opt-in, plus a per-call human confirmation, before acting (the concrete
mechanism for the in-browser consumer is in the
[webmcp-adapter architecture.md](https://github.com/galatanovidiu/webmcp-adapter/blob/trunk/docs/architecture.md)).
Capability remains the hard server-side guard underneath, regardless of any consumer gate.

**Consumer discovery by name.** Some consumers cannot read custom annotation keys (for example
a browser client whose ability store keeps only `readonly`/`destructive`/`idempotent`), so they
cannot see `annotations.dangerous`. For those consumers the Registry contributes every dangerous
ability name to the `webmcp_dangerous_tools` filter — a hook a consumer provides and the catalog
populates when present. When you add a dangerous ability, add its name to
`Registry::contributeDangerousTools()` so any name-gating consumer treats it as dangerous, not as
a plain destructive write. (The hook name carries a consumer prefix for historical reasons; a
neutral rename is tracked separately.)

**Server-side guards (`includes/Support/`).** Use these instead of calling core upgraders or
`update_option` directly:

- `FilesystemGuard::ensureDirect()` — direct-or-fail. Pre-initializes `WP_Filesystem` so core
  never prompts for FS credentials. Returns a generic 503 on non-direct filesystems (no
  credential leak).
- `SourceValidator::slug()` — accepts a wp.org directory slug only (`^[a-z0-9-]+$`). No ZIP,
  URL, path, or file-editor source, ever. This is what keeps "no arbitrary source" true.
- `OptionAllowList` — deny-by-default for `settings/update-option`. Allow-list: blogname,
  blogdescription, timezone_string, gmt_offset, date_format, time_format, start_of_week,
  blog_public, posts_per_page. Refuses siteurl/home/active_plugins/template/stylesheet/role
  maps/db_version/etc.
- `UpgraderLock` — wraps `WP_Upgrader::create_lock`/`release_lock`; prevents concurrent
  updates.
- `UpgradeRunner::withLock()` / `skin()` — the run pattern: FS guard → lock → quiet
  `Automatic_Upgrader_Skin` run → `finally` release.

**Invariants to keep true.**

- **Core update is excluded** from `updates/run-update` (timeout/brick risk) — plugin, theme,
  and translation only.
- **`privacy/run-erase` is not built** — erase execution is irreversible and batched; it stays
  human-only in wp-admin.
- **No arbitrary code = no arbitrary source.** Updates still run plugin/theme-author upgrade
  code (DB migrations etc.) by design — that is inherent to updating. The guard is on the
  source, not the code that runs.
- **Maintenance mode honesty.** The runner does best-effort cleanup on the live request path.
  On a killed worker mid-update the only backstop is core's 10-minute `.maintenance`
  auto-expiry. Do not claim the runner prevents a stuck window.

## Verifying

Server-side registration is all this plugin needs — abilities-catalog is standalone and
does not depend on any consumer:

```bash
wp eval 'do_action("wp_abilities_api_categories_init"); do_action("wp_abilities_api_init"); foreach (wp_get_abilities() as $a) { echo $a->get_name()."\n"; }'
```

Registration alone is not enough: an ability can register yet **fail when executed**.
`execute()` runs the full path — input validation, permission check, the callback, and
output validation against the schema — which is where most mistakes surface. Run one end to
end (pass `--user` so the permission check has a real user):

```bash
wp --user=admin eval 'do_action("wp_abilities_api_categories_init"); do_action("wp_abilities_api_init"); $a = wp_get_ability("<namespace>/<name>"); $r = $a->execute(["..." => "..."]); echo is_wp_error($r) ? "WP_Error: " . $r->get_error_message() : wp_json_encode($r);'
```

Always verify execution, not just registration.
