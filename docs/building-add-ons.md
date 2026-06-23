# Building an Abilities Catalog add-on

**An add-on is a normal WordPress plugin that registers its own abilities on the
core Abilities API, and — only when the catalog is present — plugs into the
catalog's MCP server through public filters. It never edits a catalog file.**

This guide is the build recipe. The reference implementation is
[abilities-catalog-cf7](https://github.com/galatanovidiu/abilities-catalog-cf7)
(Contact Form 7 forms as abilities). Read this top to bottom, then copy that
plugin's `includes/Contracts/` and `includes/Registry.php` to start — they are
infrastructure you change only by swapping the namespace.

## What you are building, and the one rule

You ship a standalone plugin. It does two things:

1. **Always:** registers `your-domain/verb-noun` abilities on the core Abilities
   API (WordPress 7.0+). Any consumer — the catalog, a custom REST client, a
   different MCP server — can then call them.
2. **Optionally:** when the Abilities Catalog and its MCP server are active,
   contributes a curated MCP *domain tool* and *skills* through the catalog's
   public filters.

**The rule: zero coupling.** Do not add the catalog as a dependency. Do not
reference its classes. Do not edit its files. The catalog is optional; your
add-on must work standalone on the bare Abilities API. The MCP filters you hook
simply do not fire when the catalog is absent, so the integration code is inert
on its own. CF7 proves the shape: install it without the catalog and the
`cf7/*` abilities still register and run.

## File layout

Mirror CF7. Replace `Cf7` / `cf7` with your group and domain.

```
your-addon.php                              # plugin header + PSR-4 autoloader + bootstrap
includes/
  Contracts/
    Ability.php                             # copy verbatim (swap namespace)
    ConditionalAbility.php                  # copy verbatim (swap namespace)
    CategoryProvider.php                    # copy verbatim (swap namespace)
  Registry.php                              # copy verbatim (swap namespace + text domain)
  Abilities/
    <Group>/
      CategoryCatalog.php                   # your group's categories
      <Domain>/
        ListThings.php                      # one ability = one class = one file
        GetThing.php
        CreateThing.php
        ...
  Support/                                  # optional: shared schema/helpers, dependency facade
  Mcp/
    Integration.php                         # optional: the MCP filter hooks
    Skills/
      DoTheCommonTask.php                   # optional: a recipe
tests/phpunit/Integration/...              # tests mirror the ability class path
```

`Registry` discovers everything under `includes/Abilities/` by a recursive scan.
There is no manifest. To add an ability you drop one file; you never edit a
shared list.

## Step 1 — the plugin file

Header, a no-build PSR-4 autoloader, and a `plugins_loaded` bootstrap. Copy this,
change the names. (Trimmed from `abilities-catalog-cf7.php`.)

```php
<?php
/**
 * Plugin Name: Abilities Catalog — Your Thing
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Requires Plugins: your-dependency-slug   // only if you wrap another plugin
 * License: MIT
 */

declare(strict_types=1);

namespace YourVendor\AbilitiesCatalogYourThing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YOUR_ADDON_DIR', plugin_dir_path( __FILE__ ) );

// No-build PSR-4 autoloader: namespace root -> includes/.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = YOUR_ADDON_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// Abilities register on the bare Abilities API — works without the catalog.
		( new Registry() )->register();

		// No Abilities API (pre-WP 7.0) means nothing to expose; bail before the MCP hooks.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Optional: plug into the catalog's MCP server. No-ops when the catalog is absent.
		Mcp\Integration::register();
	}
);
```

Notes:

- **`Requires Plugins`** — set it only if your abilities wrap another plugin
  (CF7 sets `contact-form-7`). WordPress then keeps your add-on from activating
  without that plugin. This is the static guard; the runtime guard is
  `ConditionalAbility` (Step 4).
- **No Composer at runtime.** Composer is dev-only (lint, phpstan, tests). The
  autoloader above is all the runtime needs.

## Step 2 — copy the contracts and Registry

Copy these four files from CF7 unchanged except for the namespace (and the text
domain in `Registry`'s `_doing_it_wrong` message):

- `Contracts/Ability.php` — `name(): string` + `args(): array`.
- `Contracts/ConditionalAbility.php` — adds `isAvailable(): bool`.
- `Contracts/CategoryProvider.php` — `categories(): array`.
- `Registry.php` — discovery, the annotation guard, schema normalization, and
  the two adapter-map filters.

You do not need to understand the Registry internals to use it, but three of its
behaviors shape how you write abilities:

- **Annotation guard (hard gate).** A read-only ability registers. A *write*
  registers only if it explicitly sets a boolean `annotations.destructive`. A
  write that omits `destructive` is treated as unsafe and refused with
  `_doing_it_wrong()`. This is why every write below carries an explicit
  `destructive` flag.
- **Schema normalization.** The Registry repairs two PHP→JSON quirks for you
  (see Step 6), so an empty `properties` or `required` will not silently break
  validation.
- **Conditional registration.** A `ConditionalAbility` registers only when its
  `isAvailable()` is true (Step 4).

## Step 3 — declare your group's categories

One `CategoryProvider` per group. Each ability points at a category slug.
Category slugs are **global** to the Abilities API — pick a slug nobody else
will reuse for a different meaning.

```php
final class CategoryCatalog implements CategoryProvider {
	public function categories(): array {
		return array(
			'your-domain' => array(
				'slug'        => 'your-domain',
				'label'       => __( 'Your Thing', 'your-addon' ),
				'description' => __( 'Abilities that read and write your things.', 'your-addon' ),
			),
		);
	}
}
```

`categories()` runs on the `wp_abilities_api_categories_init` hook, so its
`__()` calls have translations ready. If your group only exists when a
dependency is active, gate it the same way the abilities are gated (CF7 returns
`array()` from `categories()` when CF7 is inactive).

## Step 4 — write an ability

One class, one file, under `includes/Abilities/<Group>/<Domain>/`. Implement
`Ability` (always available) or `ConditionalAbility` (gated on a dependency).

`name()` is `domain/verb-noun`, kebab-case. `args()` returns the full
`wp_register_ability()` argument array. **Wrap core or an internal REST route —
do not reimplement behavior.** CF7's abilities dispatch CF7's own REST routes via
`rest_do_request()` so CF7's validation and capability checks run underneath.

Skeleton (read ability, always available):

```php
final class ListThings implements Ability {

	public function name(): string {
		return 'your-domain/list-things';
	}

	public function args(): array {
		return array(
			'label'               => __( 'List Things', 'your-addon' ),
			'description'         => __( 'Lists your things. Returns id, title, and edit_link for each.', 'your-addon' ),
			'category'            => 'your-domain',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => (object) array(),   // empty -> object, never array()
				'additionalProperties' => false,
			),
			'output_schema'       => array( /* describe what you return */ ),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array( 'readonly' => true ),
				'show_in_rest' => true,
			),
		);
	}

	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );   // the real capability for this action
	}

	public function execute( $input ) {
		// Wrap core / an internal REST route. Return WP_Error on failure.
	}
}
```

For a **write**, set the annotations explicitly:

```php
'meta' => array(
	'annotations'  => array(
		'readonly'    => false,
		'destructive' => false,   // true for a permanent delete / irreversible action
		'idempotent'  => false,
	),
	'show_in_rest' => true,
),
```

A **destructive** write (permanent delete, etc.) sets `'destructive' => true`. A
genuinely high-risk write (installs, option writes, irreversible bulk actions)
can also set `'dangerous' => true` — that lists it in the adapter's per-ability
opt-in (Step 7). The `permission_callback` is always the hard authorization
guard: enforce the real capability server-side with `current_user_can()`,
independent of any consumer gating.

**`ConditionalAbility` — wrapping an optional dependency.** If your ability
needs another plugin's functions, implement `ConditionalAbility` and report
availability without touching the dependency's symbols beyond detecting them:

```php
final class CreateThing implements ConditionalAbility {

	public function name(): string { return 'your-domain/create-thing'; }

	public function isAvailable(): bool {
		return function_exists( 'their_plugin_create' );   // detect only, never call here
	}

	public function args(): array { /* ... */ }
}
```

The Registry checks `isAvailable()` at registration time (after `plugins_loaded`),
never at file load. When false, the ability is **absent** from the Abilities API
— not registered-and-denying. Keep all `class_exists()` / `function_exists()`
detection here; do real work only in `execute()`.

Tip: funnel all access to a wrapped dependency through one `Support/` facade
(CF7's `Cf7Plugin` is the only file that touches `wpcf7_*` symbols). It owns the
`isAvailable()` check, a typed "unavailable" error for the defensive path, and
any reads the dependency's REST routes don't expose.

## Step 5 — describe what each ability returns and errors with

Define an `output_schema` so a consumer knows the result shape. Return a
`WP_Error` on failure (CF7 converts a failed internal REST response into a
`WP_Error` via a small `Support/RestError` helper). Do not `die()` / `exit()` in
a class — throw or return `WP_Error`.

## Step 6 — the schema gotchas (these make an ability *run*, not just register)

The Registry normalizes most of this for you, but write your schemas so they are
correct before normalization:

- An **empty** `properties` must serialize as a JSON object. Use
  `(object) array()`, never `array()` (which encodes to `[]` and fails the
  client-side validator). The Registry will coerce a stray empty `properties` to
  `{}`, but be explicit.
- Do **not** ship `'required' => array()`. An empty `required` is invalid JSON
  Schema; the Registry drops it for you, so just omit `required` when nothing is
  required.
- Object **outputs** must be cast so they serialize as objects, not arrays.
- A net-new read that calls admin-only code must `require` the needed
  `wp-admin/includes/*.php` first.

## Step 7 — plug into the catalog's MCP server (optional)

When the catalog and its MCP server are active, you contribute a curated **domain
tool** (one tool per domain, with `list` / `describe` / `execute`) and optional
**skills**. All of it is filter-based — the catalog provides the hooks, you fill
them. Put it in `Mcp/Integration.php` and call `Integration::register()` from the
bootstrap. The filters never fire when the catalog is absent, so this code is
safe standalone.

```php
final class Integration {

	private const ABILITIES = array(
		'your-domain/list-things',
		'your-domain/get-thing',
		'your-domain/create-thing',
		// ... in the order an agent should see them
	);

	public static function register(): void {
		add_filter( 'abilities_catalog_mcp_domains', array( self::class, 'contributeDomain' ) );
		add_filter( 'abilities_catalog_mcp_skills',  array( self::class, 'contributeSkill' ) );
	}

	public static function contributeDomain( array $domains ): array {
		$domains['your-domain'] = array(
			'description' => __( 'Manage your things — list, read, create, update, delete.', 'your-addon' ),
			'abilities'   => self::ABILITIES,
		);
		return $domains;   // preserve existing entries — add, don't replace
	}

	public static function contributeSkill( array $skills ): array {
		$skills[ DoTheCommonTask::ID ] = array(
			'title'       => DoTheCommonTask::title(),
			'when_to_use' => DoTheCommonTask::whenToUse(),
			'body'        => array( DoTheCommonTask::class, 'body' ),   // callable -> built only on `get`
		);
		return $skills;
	}
}
```

If your abilities are conditional, gate these contributions on the same
condition (CF7 returns the array unchanged when CF7 is inactive, so no empty tool
or dangling skill appears).

### The filters you can use

| Filter | When to use | Payload |
|---|---|---|
| `abilities_catalog_mcp_domains` | Open a **new** domain tool over your own abilities. The usual choice. | `slug => [ 'description' => string, 'abilities' => string[] ]`. A slug that collides with a curated core domain is ignored. |
| `abilities_catalog_mcp_skills` | Add a cross-cutting **recipe** (a short procedural playbook chaining tools). | `id => [ 'title', 'when_to_use', 'body ]`. `body` is a string or a **callable** returning a string — use a callable so the text costs no context until a `get` resolves it. |
| `abilities_catalog_mcp_domain_map` | Drop your ability into an **existing core** domain instead of opening your own. | `domain-slug => string[]` (exact ability names). Preserve the curated names already there. |
| `abilities_catalog_mcp_tools` | Low-level: register a raw MCP tool. Rarely needed; prefer a domain. | the tools array |
| `abilities_catalog_mcp_tool_permission` | Override who may call the MCP tools. | a callable returning bool |

Two more filters are read by the catalog **adapter** (the consumer that surfaces
abilities in the browser). Contribute to them from your `Registry` exactly as the
catalog's Registry does — CF7 copies this verbatim:

| Filter | What it does |
|---|---|
| `abilities_catalog_dangerous_tools` | Lists each ability whose `meta.annotations.dangerous` is `true`, keyed by name → label, for the per-ability opt-in on the Settings page. |
| `abilities_catalog_screen_links` | Maps a write ability to a `meta.screen` wp-admin URL template, so a logged write deep-links to the screen it touched. |

## Step 8 — test it

Tests are integration-only and run inside `wp-env` (Docker WordPress), not on the
host. A test mirrors its ability's class path:

```
includes/Abilities/Cf7/Forms/CreateForm.php
  -> tests/phpunit/Integration/Abilities/Forms/CreateFormTest.php
```

Keep a standing `RegistryTest`-style guard that asserts every ability registers
and every write carries its safety annotation. Build test-first: write the test,
then the ability.

```bash
composer lint        # phpcs, on the host
composer phpstan     # static analysis, on the host
npm run wp-env start && npm run test:php   # integration suite, in Docker
```

## Checklist (human or agent)

- [ ] Standalone plugin. No catalog class referenced, no catalog file edited.
- [ ] No-build PSR-4 autoloader; runtime needs no Composer.
- [ ] `Requires Plugins` set if you wrap another plugin.
- [ ] Contracts + Registry copied; only namespace/text-domain changed.
- [ ] One `CategoryProvider`; every ability's `category` slug exists in it.
- [ ] Each ability: one class, one file, `domain/verb-noun` name, wraps core /
      REST (no reimplementation).
- [ ] Every write sets an explicit boolean `annotations.destructive`. Permanent
      / irreversible writes set it `true`; high-risk ones also set `dangerous`.
- [ ] `permission_callback` enforces the real capability with
      `current_user_can()` — the hard guard.
- [ ] Optional-dependency abilities implement `ConditionalAbility`; detection
      only in `isAvailable()`.
- [ ] Empty `properties` is `(object) array()`; no `'required' => array()`.
- [ ] MCP `Integration` (if any) contributes via filters, preserves existing
      entries, and gates on the same condition as the abilities.
- [ ] Tests mirror the class path; lint, phpstan, and the wp-env suite pass.
</content>
</invoke>
