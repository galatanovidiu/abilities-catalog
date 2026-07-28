# How WordPress/mcp-adapter#260 was tested end to end

A record of an end-to-end test of [WordPress/mcp-adapter#260](https://github.com/WordPress/mcp-adapter/pull/260)
— "Normalize `_meta` and annotations on every DTO the adapter emits" — run against a real
WordPress install with a real MCP client, rather than against the unit suite alone.

Everything below is reproducible from public branches. The harness lives in
[galatanovidiu/abilities-catalog#90](https://github.com/galatanovidiu/abilities-catalog/pull/90)
on the throwaway branch `test/meta-annotations-e2e`, **which is not intended to merge** —
it exists only to host this test.

## Result

All 11 of the PR's own testing scenarios pass, plus the MCP Apps and image-resource paths.
No regressions found. The issues that surfaced during the run all reproduce on `trunk` or are
documentation-only; none were introduced by this PR.

| Check | Result |
| --- | --- |
| Adapter unit suite (`npm run test:php`) | 1119 tests, 4162 assertions, OK |
| `composer lint` | No errors |
| `composer phpstan` | No errors |
| Wire scenarios 1–10 (see matrix below) | 13/13 pass |
| MCP App (SEP-1865) in Claude Desktop | Renders a live interactive posts table |
| Blob image resource in Claude Desktop | Renders in the resource picker |

Versions under test:

| Package | Repo | Ref |
| --- | --- | --- |
| `wordpress/mcp-adapter` | WordPress/mcp-adapter | `fix/preserve-resource-contents-meta` @ `c438ba8` |
| `wordpress/php-mcp-schema` | WordPress/php-mcp-schema | `fix/open-type-passthrough` @ `e817949` |
| harness | galatanovidiu/abilities-catalog | `test/meta-annotations-e2e` |

The schema branch is **not** a prerequisite for the adapter branch. It changes `Result`-family
DTOs and `ToolInput/OutputSchema`, while `TextResourceContents` has modelled `_meta` since the
released 0.1.2. It is wired in only to prove the two do not collide.

## Why a dedicated probe server was needed

This is the part worth copying if you want to test the PR against your own plugin.

The catalog's normal MCP servers reach every ability through a `discover` / `execute`
meta-tool, which re-packages the result before it reaches the wire. That path **never enters**
`ResourcesHandler::read()`, `PromptsHandler::get()`, or the typed-content-block branches of
`ToolsHandler` — which is the entirety of what PR #260 changes. Testing through a meta-tool
wrapper produces a green run that proves nothing.

So the harness adds `Mcp\Probe\ProbeServer`, which registers each probe ability **directly**
as a tool, resource or prompt, on its own REST route, gated behind a constant. That puts the
changed handlers in the request path.

It also passes `ErrorLogMcpErrorHandler` instead of the default `NullMcpErrorHandler`. Half of
what this PR adds is a *warning logged when a value is dropped*, and the null handler discards
exactly those — so with the default you cannot tell "correctly omitted with a diagnostic" from
"silently lost".

## Reproducing it

### 1. Clone the three repos side by side

`composer.json` on the harness branch uses **path** repositories pointing at `../mcp-adapter`
and `../php-mcp-schema`, so the layout matters:

```bash
git clone https://github.com/WordPress/mcp-adapter.git
git clone https://github.com/WordPress/php-mcp-schema.git
git clone https://github.com/galatanovidiu/abilities-catalog.git

git -C mcp-adapter     checkout fix/preserve-resource-contents-meta
git -C php-mcp-schema  checkout fix/open-type-passthrough
git -C abilities-catalog checkout test/meta-annotations-e2e

cd abilities-catalog && composer install
```

Both path repos set `"symlink": false`. That is deliberate: Docker bind-mounts do not follow a
symlink whose target sits outside the mount, so a symlinked `vendor/wordpress/*` is a dangling
path inside the wp-env container. The cost is that after editing the adapter, `composer update`
is a **no-op** — the commit ref has not changed, so the lock does not change. Re-mirror instead:

```bash
rm -rf vendor/wordpress/mcp-adapter && composer install
```

### 2. Start the environment

```bash
npx wp-env start --config=.wp-env.probe.json     # http://localhost:8888
```

Three settings in that config are load-bearing. Each one costs an hour if you omit it, because
all three fail *silently*:

| Setting | Why it is required |
| --- | --- |
| `JETPACK_AUTOLOAD_DEV: true` | Jetpack's `Version_Selector::is_version_update_required()` never selects a `dev-` version unless this is defined. Without it, any sibling plugin vendoring a released `mcp-adapter` wins and you test the released code while believing you tested the branch. |
| `WP_ENVIRONMENT_TYPE: "local"` | `wp_is_application_passwords_available()` returns false under `development`, so every authenticated REST call answers 401 with a misleading `rest_not_logged_in`. |
| `ABILITIES_CATALOG_META_PROBE: true` | Gates the probe server. Without it none of the probe abilities or routes exist. |

### 3. Create credentials

```bash
npx wp-env run cli --config=.wp-env.probe.json -- \
  wp user application-password create admin mcp-probe --porcelain
```

### 4. Run the scenarios

`tools/pr-260/mcp.py` is a dependency-free MCP client. It exists because plain `curl` cannot do
the `initialize` → `Mcp-Session-Id` handshake that Streamable HTTP requires.

```bash
export MCP_PASS='<the application password>'

python3 tools/pr-260/verify.py        # runs all 13 checks, prints PASS/FAIL per scenario

# or drive it by hand
python3 tools/pr-260/mcp.py tools/list
python3 tools/pr-260/mcp.py resources/list
python3 tools/pr-260/mcp.py read   "probe://blob"
python3 tools/pr-260/mcp.py call   probe-meta-tool-image
python3 tools/pr-260/mcp.py prompt probe-meta-prompt-invalid-embedded
```

Read the adapter's own warnings — the half of the PR that is *not* visible on the wire:

```bash
npx wp-env run cli --config=.wp-env.probe.json -- \
  wp eval 'echo @file_get_contents("/var/www/html/wp-content/debug.log");' | grep -E "^\[[0-9]"
```

## Scenario matrix

One ability per scenario, in `includes/Mcp/Probe/ProbeAbilities.php`. All verified against
adapter `c438ba8`.

| # | What it exercises | Expected | Observed |
| --- | --- | --- | --- |
| 1 | `_meta` object on resource contents | Survives to the wire | `contents[0]._meta = {"ui":{"prefersBorder":true}}` |
| 2 | List-shaped `_meta` on resource contents | Key omitted (cannot serialize as a JSON object), warning logged | Keys are `uri`, `mimeType`, `text` only; warning present |
| 3 | Items carrying only `blob` + `mimeType` | One `BlobResourceContents` per item, each inheriting the resource's own URI | 2 items, both `uri: probe://blob`, both `image/png` |
| 3b | Resource descriptor `mimeType` | Declared value emitted verbatim | `resources/list` reports `image/png` |
| 4a | Descriptor `_meta` object | Reaches `tools/list` | `_meta = {"com.example/hint":"descriptor-meta-object"}` |
| 4b | Descriptor `_meta` list | Key omitted entirely | Absent from the tool object |
| 5 | Embedded resource, nested `_meta` form | Addresses both levels independently | Block `_meta = {"level":"block"}`, contents `_meta = {"level":"contents"}` |
| 6 | Embedded resource, flat `_meta` form | Assigned to the contents, not the block | Contents `_meta = {"level":"contents-from-flat"}`, no block `_meta` |
| 7 | `type: image` result with sibling `annotations` and `_meta` | Both land on the image content block | `annotations` and `_meta` both present alongside `data`/`mimeType` |
| 8 | Result with no `type` key | Stays tool data; not lifted onto a content block | Text block + `structuredContent`; no typed block synthesized |
| 9a | `readOnlyHint: '1'` (WordPress returns stored scalars as strings) | Coerced to boolean | `readOnlyHint: true` |
| 9b | `priority: '0.5'` on a result's content annotations | Coerced to float | `0.5` (float, not string) |
| 10 | Prompt message block the schema DTOs refuse | Degrades to text, siblings untouched, warning logged | All three messages are `text`; warning present |

## Also verified manually in Claude Desktop

Two things the wire cannot tell you, checked with a real host:

**MCP App (SEP-1865)** — a `ui://` resource plus a bound tool renders a live interactive posts
table. Getting there required three protocol details that the prose specs and launch blog posts
get wrong; the only reliable source is
[`src/spec.types.ts` in `modelcontextprotocol/ext-apps`](https://raw.githubusercontent.com/modelcontextprotocol/ext-apps/main/src/spec.types.ts):

- MCP Apps has its **own** protocol version, `LATEST_PROTOCOL_VERSION = "2026-01-26"`. It is not
  the core MCP revision.
- `ui/initialize` params are `{ protocolVersion, appInfo, appCapabilities }` — **`appInfo`**, not
  `clientInfo`. Sending `clientInfo` deadlocks silently: the host rejects the request, the view
  never sends `initialized`, the host never sends the tool result.
- A view **must** send `ui/notifications/size-changed`. Omitting it can mount the iframe at zero
  height, which on screen is indistinguishable from HTML that failed to load.
- MCP Apps is predeclared resource + descriptor binding (`_meta.ui.resourceUri`, nested — the flat
  `ui/resourceUri` from the blog posts is not what shipped). The embedded form was explicitly
  deferred by SEP-1865 and hosts render it as plain text.

**Blob image resource** — `probe://blob` attaches and renders from the resource picker.

One caveat worth recording, because it produced a false negative on the first run: a 1×1
transparent PNG renders as a blank tile carrying a ⚠️ badge, which reads as a broken image. The
badge is not an error — its tooltip is *"Attach a higher resolution image for better results"*,
an advisory that fires below roughly 200px. Swapping the fixture for a 20×20 opaque PNG showed
the image had been decoding correctly all along. If you test image paths against a host, use a
fixture large enough not to trip that advisory.

Also note the host flattens tool output before the model's turn, so **the model inside Claude
Desktop cannot see block-level `_meta` or `annotations`, or descriptor `_meta`**. Their absence
from its answer is not evidence of absence on the wire. Verify metadata with `mcp.py`, and use
the model only for what it can genuinely observe.

## What this does not cover

- Only the `HttpTransport` path. STDIO is untested here.
- Single WordPress version and single PHP version, whatever `wp-env` defaults to.
- Claude Desktop is the only host tested. Other MCP clients may render or ignore these fields
  differently — the blob-image observation above is host behaviour, not protocol behaviour.
- The probe abilities are contrived fixtures, one per scenario. They exercise the handler
  branches; they do not represent realistic ability payloads.
