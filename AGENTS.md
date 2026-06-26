# AGENTS.md — abilities-catalog

WordPress plugin (WP 6.9+) that registers wp-admin features as **abilities** on the
core Abilities API, each tagged with a risk classification. Two facts shape how you
work here:

- **The abilities are consumer-agnostic.** The catalog defines them (schemas,
  categories, capability checks, risk annotations); it does not couple to any one
  consumer. Build abilities so any Abilities API client can read them — never document
  the plugin as requiring a specific consumer.
- **The shipped consumer is a search-based MCP server** (`includes/Mcp/`,
  **off by default**) — the part the project is built around and the piece it aims to
  upstream into `wordpress/mcp-adapter`. The abilities themselves are a **stopgap**: as
  WordPress core and other plugins ship official abilities, the duplicates defined here
  get removed to make room for them.

## Commands

Static checks run on the host. Tests run in Docker via `wp-env`.

```bash
composer lint        # phpcs (.phpcs.xml.dist)
composer format      # phpcbf — auto-fix what phpcs can
composer phpstan     # phpstan (phpstan.neon.dist)
```

Tests need Docker running:

```bash
npm run wp-env:test start    # boot the WP 6.9+ test env (.wp-env.test.json, port 8890)
npm run test:php:setup       # composer install inside the container (first run only)
npm run test:php             # full suite (unit + integration)
```

Run one test while iterating (replace the filter):

```bash
npm run wp-env:test -- run cli --env-cwd=wp-content/plugins/abilities-catalog/ \
  vendor/bin/phpunit -c phpunit.xml.dist --no-coverage --filter GetComment
```

If Docker/wp-env is not available locally, run phpcs and phpstan on the host and let
CI run the integration suite (`.github/workflows/test.yml`).

## How an ability is built

- One PHP class per ability, one file, under `includes/Abilities/<Group>/<Domain>/`.
  The core WP catalog is the `Core` group; a non-core add-on gets a sibling group
  (e.g. `Woo/`).
- `Registry` scans `includes/Abilities/` recursively and registers each ability on
  `wp_abilities_api_init` via `wp_register_ability()`. No manifest, no extra build step.
- Ability names are `domain/verb-noun` — e.g. `og-plugins/list-plugins`,
  `og-comments/approve-comment`.
- Each group declares its categories in a class implementing
  `Contracts\CategoryProvider` (Core's is `Abilities\Core\CategoryCatalog`), registered
  on `wp_abilities_api_categories_init`. An ability links to its category by slug in
  `args()['category']`.
- `site` is the default scope; declare `meta.abilities_catalog.scope` only for a
  non-site ability (network / user identity / install-wide). On multisite the policy
  decorator injects an optional `blog_id` into `site` abilities only.
- **Wrap, don't reimplement.** `execute_callback` wraps a core function or an internal
  REST route (`rest_do_request`) and returns `WP_Error` on failure. Do not reimplement
  core behavior.
- Adding a group = new folder + its own `CategoryProvider`. Adding a domain or ability
  to an existing group edits only that folder.

Build abilities test-first. A test mirrors its class path:
`includes/Abilities/Core/Comments/GetComment.php` →
`tests/phpunit/Integration/Abilities/Comments/GetCommentTest.php`.
`tests/phpunit/Integration/RegistryTest.php` is a standing guard — every ability must
register and every write must be classified. Keep it green.

## Schema gotchas (these make an ability *execute*, not just register)

The cause of most "registers but won't run" bugs:

- An empty schema must serialize as a JSON object — cast `(object) array()`, never
  `array()` (which encodes to `[]`).
- A no-input ability still needs the canonical no-input input schema; an all-optional
  schema needs a default.
- Object outputs must be cast so they serialize as objects, not arrays.
- A net-new read that calls admin-only code must `require` the needed
  `wp-admin/includes/*.php` first.

## Safety model (do not weaken)

- **Capability is the hard guard.** Every ability's `permission_callback` enforces its
  capability server-side with `current_user_can()`, independent of any consumer-side
  gating. Non-negotiable. A write that omits its risk annotation is treated as unsafe
  and is not registered (RegistryTest enforces this).
- **Classification, not enforcement.** Abilities tag `readonly` / `destructive` /
  `idempotent` / `dangerous` in `meta.annotations`. How those are surfaced is the
  consumer's concern. Never document this plugin as requiring a specific consumer.
- **Dangerous tier** (plugin/theme install·update·delete, option writes, update runs,
  privacy export) runs behind the guards in `includes/Support/`: filesystem guard,
  source validation (wp.org slugs only), option allow-list (deny-by-default), upgrader
  lock. Core update and irreversible erase execution are deliberately excluded.
- The Registry contributes dangerous ability names to the
  `abilities_catalog_dangerous_tools` filter and screen templates to
  `abilities_catalog_screen_links` — hooks a consumer provides; the catalog only fills
  them when present.
- Standard WordPress rules apply (nonces, sanitize-in / escape-out, `$wpdb->prepare`);
  secrets come from constants or env, never committed.

## Optional MCP server (`includes/Mcp/`)

Off by default; the catalog is unaffected when off. Enabled by the
`ABILITIES_CATALOG_MCP_ENABLED` constant or the `abilities_catalog_mcp_enabled` option
(toggle at **Settings → MCP Server**). Built on `wordpress/mcp-adapter`, loaded via the
Jetpack Autoloader from `vendor/` (git-ignored; a release build ships it). If enabled
without `vendor/`, the plugin shows an admin notice and keeps working — it does not
fatal.

- Two servers boot on the adapter, each a separate consumer of the one ability
  registry, neither exposing flat per-ability tools:
  - **Search server** (`Mcp\SearchServer`, route `mcp-search`) — the **primary** one,
    built for scale and the piece headed upstream. Five bounded tools backed by
    `Mcp\AbilityIndex`: `overview` (capability map), `search-abilities` (ranked keyword
    retrieval), `describe-ability`, `execute-ability` — plus the shared `knowledge`
    tool. Discovery cost tracks the result set, not the catalog size.
  - **Curated domain server** (`Mcp\Server`, route `mcp`) — legacy alternative. One
    tool per hand-curated domain (`list` / `describe` / `execute`, mapped by
    `Mcp\DomainMap`) plus `knowledge`. Boots first; `SearchServer` boots after it.
- The `knowledge` tool (built once via `Mcp\KnowledgeToolFactory`, shared by both
  servers) reads file-based **OKF** bundles (markdown + YAML frontmatter) under
  `includes/knowledge/`: a no-arg call returns a generated index (live site facts +
  every bundle's concepts grouped by type), a `{uri}` call returns one concept.
- On top of the capability guard sits an owner-controlled **exposure gate**
  (`Mcp\ExposurePolicy`, deny-by-default): every ability is disabled until enabled on
  the settings page. `execute` refuses a disabled ability; discovery
  (`search-abilities` / `describe-ability`, or `list` / `describe` on the curated
  server) still shows it so an agent can learn it. Capability stays the hard guard on
  every `execute`.
- The settings page and its exposure REST route (`abilities-catalog/v1/exposure`,
  `manage_options`) register whenever the Abilities API is present, independent of the
  server enable flag. The page is a no-build React app on core
  `wp-element` / `wp-components`.
- Extensible without editing this plugin: filters `abilities_catalog_mcp_domain_map`,
  `abilities_catalog_mcp_knowledge` (carries scanned `KnowledgeBundle` objects),
  `abilities_catalog_mcp_tools`, `abilities_catalog_mcp_tool_permission`.

## Architecture

```
abilities-catalog.php              # plugin header + no-build PSR-4 autoloader + bootstrap
includes/
  Registry.php                     # discovers, categorizes, registers abilities
  Contracts/                       # Ability + CategoryProvider contracts
  Abilities/<Group>/<Domain>/      # one class per ability
  Support/                         # safety pipeline for the dangerous tier
  Mcp/                             # optional, off-by-default MCP server (loads vendor/ only when on)
    Admin/                         # settings page + exposure REST API (always in wp-admin)
assets/js/                         # no-build React settings app
tests/phpunit/{Unit,Integration}/  # Unit = no DB; Integration = real WordPress
docs/                              # user-facing documentation
```

`GalatanOvidiu\AbilitiesCatalog\` maps to `includes/`. The catalog has no build step;
only the optional MCP server pulls `vendor/` (via the Jetpack Autoloader) when enabled.
