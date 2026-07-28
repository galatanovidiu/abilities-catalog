# End-to-end test: mcp-adapter PR #260

Throwaway branch (`test/meta-annotations-e2e`) that exercises
[WordPress/mcp-adapter#260](https://github.com/WordPress/mcp-adapter/pull/260) —
"Normalize `_meta` and annotations on every DTO the adapter emits" — against a real
WordPress install, using this catalog as the ability source.

Not intended to merge.

## What is wired

| Package | Source | Ref |
| --- | --- | --- |
| `wordpress/mcp-adapter` | `../mcp-adapter` (composer path repo) | `fix/preserve-resource-contents-meta` @ `c438ba8` |
| `wordpress/php-mcp-schema` | `../php-mcp-schema` (composer path repo) | `fix/open-type-passthrough` @ `e817949` |

Both path repositories use `"symlink": false`. Docker bind-mounts do not follow a
symlink whose target sits outside the mount, so a symlinked `vendor/wordpress/*`
would be a dangling path inside the wp-env container. Mirroring copies the files in,
at the cost of a `composer update` after each upstream edit.

The schema branch is **not** a prerequisite for the adapter branch — it changes
`Result`-family DTOs and `ToolInput/OutputSchema`, while `TextResourceContents` has
modelled `_meta` since the released 0.1.2. Both are wired to prove they do not
collide.

## Running it

```bash
npx wp-env start --config=.wp-env.probe.json   # http://localhost:8888
```

`.wp-env.probe.json` sets three things that the test does not work without:

- `JETPACK_AUTOLOAD_DEV: true` — Jetpack's `Version_Selector::is_version_update_required()`
  refuses a `dev-` version outright unless this is defined, so any sibling plugin
  vendoring `mcp-adapter v0.5.0` wins and the branch code never loads. Silent.
- `WP_ENVIRONMENT_TYPE: "local"` — `wp_is_application_passwords_available()` is false
  under `development`, so every authenticated REST call answers 401.
- `ABILITIES_CATALOG_META_PROBE: true` — gates the probe server below.

Endpoints, all behind an admin application password:

- `/?rest_route=/abilities-catalog/v1/mcp-probe` — the probe server
- `/?rest_route=/abilities-catalog/v1/mcp-search` — the real search server
- `/?rest_route=/abilities-catalog/v1/mcp` — the real curated domain server

## Why a separate probe server

Every one of this catalog's existing surfaces reaches an ability through a
`discover` / `execute` meta-tool. Those never enter `ResourcesHandler::read()`,
`PromptsHandler::get()`, or the typed-content-block branches of `ToolsHandler` —
which is the whole of what PR #260 changes. `Mcp\Probe\ProbeServer` registers its
abilities *directly* as tools, resources and prompts so those handlers are actually
in the request path.

It also passes `ErrorLogMcpErrorHandler` rather than the null default: half of what
the PR adds is a warning logged when a value is dropped, and `NullMcpErrorHandler`
discards exactly those.

## Results

All 11 scenarios from the PR's testing instructions pass.

| # | Scenario | Outcome |
| --- | --- | --- |
| 1 | `_meta` object on resource contents | Present on `contents[0]._meta` |
| 2 | List-shaped `_meta` | Key absent, not emitted as a JSON array; warning logged |
| 3 | Blob-only first item | One `BlobResourceContents` per item, each taking the resource's own URI |
| 4a | Descriptor `_meta` object | Emitted on the tool in `tools/list` |
| 4b | Descriptor `_meta` list | Key omitted |
| 5 | Nested embedded resource | `_meta` on both levels, `annotations` on the block |
| 6 | Flat embedded resource | `_meta` on the contents, `annotations` on the block |
| 7 | Image with sibling keys | Both reach the image content block |
| 8 | Result with no `type` | Left as tool data in `structuredContent`; not lifted onto the block |
| 9a | `readOnlyHint: '1'` | Registers, emits `readOnlyHint: true` |
| 9b | `priority: '0.5'` on a result | Emits the float `0.5` |
| 10 | Prompt message the DTOs refuse | Degraded to a text block, siblings untouched, warning logged |
| 11 | `composer test` / `lint` / `phpstan` | 1119 tests OK, no lint errors, no PHPStan errors |

Logged warnings carry the object they came from:

```
[WARNING] Invalid _meta on resource contents, dropping it | Context: {"uri":"ui:\/\/probe\/bad-meta"}
[WARNING] Invalid embedded resource contents in prompt message, degrading to text | Context: {"prompt_name":"probe-meta-prompt-invalid-embedded"}
```

### Through the real servers

`og-settings/get-general` was converted into an MCP App UI resource — a nested
embedded-resource block with `ui://` contents, `_meta.ui`, and content annotations.
Both `_meta` levels and the annotations survive intact through the search server's
`execute-ability` **and** through the curated `settings` domain tool, so the feature
works behind this catalog's real wrappers, not only on the direct path. Its
`output_schema` was emptied, since the old schema described the flat settings object
this no longer returns.

## Findings not caused by the PR

Both reproduce on `trunk`; neither is a regression. Worth reporting anyway.

**1. Testing instruction 7 cannot be followed as written.** `ToolsHandler`'s image
branch is guarded on `$result['results']`, holding **raw bytes**, which it
base64-encodes itself. A `data` key holding base64 — the MCP wire shape, and the
natural reading of "return a `type: image` result" — misses the branch and falls
through to the generic text path with no diagnostic. The instruction should name the
key.

**2. `mcp.annotations` silently drops every tool annotation.**
`RegisterAbilityAsMcpResource::get_mcp_meta()` deprecates top-level `annotations` in
favour of `mcp.annotations`, and logs that notice. But
`RegisterAbilityAsMcpTool::build_tool_data()` reads `$ability_meta['annotations']`
directly and has no `mcp.annotations` branch at all. A tool author who follows the
deprecation loses every annotation without a word — including `readOnlyHint`, which
is safety-relevant. Hit live during this run; it produced a false pass until the
source was checked. `ProbeAbilities::ability()` therefore places annotations per
component type.

## Files

- `includes/Mcp/Probe/ProbeAbilities.php` — one ability per scenario
- `includes/Mcp/Probe/ProbeServer.php` — the gated probe server
- `.wp-env.probe.json` — env constants the test depends on
- `includes/Abilities/Core/Settings/GetGeneral.php` — real ability turned MCP App UI resource
- `composer.json` — path repositories and branch constraints
- `.mcp.json` — probe server entry beside the search server
