# mcp-adapter findings — from an end-to-end test of PR #260

Handoff note. Everything below came out of running
[WordPress/mcp-adapter#260](https://github.com/WordPress/mcp-adapter/pull/260) against a real
WordPress install with a real MCP client (Claude Desktop), rather than against unit tests.

**PR #260 itself is clean.** All 11 scenarios in its testing instructions pass, and `composer test`
/ `lint` / `phpstan` are green. Every finding below reproduces on `trunk` and is either
pre-existing code or documentation. None is a regression introduced by the PR.

Verified against `fix/preserve-resource-contents-meta` @ `961f470`, WordPress 7.0.2, PHP 8.1,
`php-mcp-schema` @ `fix/open-type-passthrough`.

---

## 1. Code: the MIME validator rejects the one MIME type MCP Apps requires

`includes/Domain/Utils/McpValidator.php:592`

```php
return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*$/', $mime_type );
```

The pattern permits no RFC 2045 parameters — no `;`, no `=`. So it rejects
`text/html;profile=mcp-app`, which MCP Apps (SEP-1865) states a UI template's `mimeType`
**MUST** be.

The consequence is silent. `RegisterAbilityAsMcpResource` validates the value and drops it on
failure, so a UI resource is published to `resources/list` with **no `mimeType` at all**, and the
server author gets no diagnostic.

Note the inconsistency: `resources/read` emits the parameterized type without complaint. Only the
**descriptor** path validates. So the same string is legal in the body and illegal in the header.

**Correction — this was originally filed as a BLOCKER. It is not one.** Two claims made above were
later disproved:

- *"A host filtering the resource list for UI templates cannot find it … it is simply never
  rendered."* Wrong. With the fix reverted, the MCP App still rendered correctly in Claude Desktop.
  A host locates the template by the explicit `_meta.ui.resourceUri` on the **tool descriptor**,
  then reads the mimeType off the `resources/read` contents — a path the adapter never validated.
  Only the `resources/list` descriptor was affected.
- SEP-1865 makes `text/html;profile=mcp-app` a **MUST** on `resources/read` contents, but only a
  **SHOULD** on the `resources/list` descriptor. The MUST path already worked on `trunk`.

Real severity: a correctness bug on a SHOULD-level descriptor field. Nothing was unrenderable.

**This is a deliberate, test-locked decision, not an oversight.** `McpValidatorTest` had
`test_validate_mime_type_rejects_parameters` asserting exactly this behaviour. It predates MCP
Apps. Changing it therefore needs a maintainer decision, not a drive-by patch.

**Resolution — shipped as `c7f9ba6` "fix: emit a mimeType as declared".** The fix originally
proposed here was a widened regex accepting RFC 2045 parameters. That is not what shipped. MCP
places no format constraint on `mimeType` on any object that carries one, and neither the
TypeScript SDK, the Python SDK nor `php-mcp-schema` validates one — so the adapter now applies no
format check at all:

- `validate_mime_type()`, `validate_image_mime_type()`, `validate_audio_mime_type()`,
  `validate_icon_mime_type()` and the icon MIME allow-list are deleted, along with their call sites
  in the resource registrar, the resource and prompt validators, and icon validation.
- Presence and `is_string()` remain. `image` and `audio` content blocks still require a `mimeType`,
  because the schema marks it required there.
- The value is emitted as declared — trimmed of surrounding whitespace, otherwise untouched. That
  matters: MCP Apps matches a UI template's mimeType by exact, case-sensitive string comparison, so
  it must never be normalised or lowercased.

9 files, +77/−341. Suite green.

MCP Apps works end to end in Claude Desktop — verified, rendering a live interactive table.

---

## 2. Code: `mcp.annotations` silently drops every tool annotation

`includes/Domain/Tools/RegisterAbilityAsMcpTool.php:143`

```php
$ability_meta = $this->ability->get_meta();
if ( ! empty( $ability_meta['annotations'] ) && is_array( $ability_meta['annotations'] ) ) {
```

Tools read `meta.annotations` from the **top level only**. There is no `mcp.annotations` branch.

Meanwhile `RegisterAbilityAsMcpResource::get_mcp_meta()`
(`includes/Domain/Resources/RegisterAbilityAsMcpResource.php:296`) prefers `mcp.{key}` and logs a
deprecation when it falls back to the top level:

> Ability meta key "annotations" is deprecated. Use "mcp.annotations" instead.

So the adapter emits a notice telling authors to migrate to `mcp.annotations`, and a tool author
who complies loses **every** annotation with no warning — including `readOnlyHint`, which is
safety-relevant. The notice fires site-wide, so an author is likely to see it from a resource and
apply the change to their tools too.

Hit live during this test: it produced a false PASS until the source was read. Either teach the
tool registrar `mcp.annotations`, or scope the deprecation notice so it cannot be read as applying
to tools.

---

## 3. Docs (high impact): the MCP Apps guidance teaches the pattern SEP-1865 deferred

`docs/guides/creating-abilities.md:696-745` — the "Returning Structured Resource Contents" section
added by PR #260.

The section presents `ui://` resources with `_meta.ui` returned **embedded in a tool result**, and
frames it in MCP Apps terms ("MCP Apps UI resources use it for CSP config and rendering hints").

That is not how MCP Apps works. SEP-1865 explicitly considered and **deferred** it:

> **Embedded resources:** Current MCP-UI approach, where resources are returned in tool results.
> Although it's more convenient for server development, it was deferred due to the gaps in
> performance optimization and the challenges in the UI review process.

A host that supports MCP Apps renders an embedded resource as **plain text**. Confirmed in Claude
Desktop: an embedded `ui://` resource with `_meta.ui.prefersBorder` arrived as a TXT attachment,
no UI. The client was behaving correctly.

The real contract is three parts, all required:

1. **Predeclare** the resource at `ui://…` with `mimeType: text/html;profile=mcp-app`. Finding 1
   drops this from the `resources/list` descriptor only; the `resources/read` contents carry it
   either way, which is the path the host actually checks.
2. Bind it from the **tool descriptor**: `_meta.ui.resourceUri` (nested; the flat `ui/resourceUri`
   that appears in early blog posts is not what shipped).
3. The tool result returns **data only** — a meaningful text `content` fallback plus
   `structuredContent`. It must **not** embed the resource. The host fetches the template itself
   via `resources/read` and pushes the result into a sandboxed iframe.

The docs are not merely incomplete here; they will actively send an author down a path that cannot
work. Worth correcting in this PR, since this PR added the section.

The rest of the `_meta` guidance in that section is accurate and useful — the JSON-object rule,
the two-level nested/flat distinction, and the `_meta` vs `annotations` split all match observed
behaviour.

---

## 4. Docs: PR #260 testing instruction 7 cannot be followed as written

Instruction 7 says:

> Return a `type: "image"` result with sibling `annotations` and `_meta` …

`includes/Handlers/Tools/ToolsHandler.php:332` guards the image branch on `$result['results']`,
holding **raw bytes**, which it base64-encodes itself:

```php
if ( isset( $result['type'] ) && 'image' === $result['type'] && isset( $result['results'] ) ) {
```

Following the instruction with `'data' => <base64>` — the MCP wire shape, and the natural reading
— misses the branch entirely and falls through to the generic text path with no diagnostic. The
instruction should name `results` and say it takes raw bytes.

(Pre-existing on `trunk`; only the instruction is new.)

---

## What PR #260 got right

Verified on the wire, not just in unit tests. All 11 scenarios pass:

| # | Scenario | Result |
| --- | --- | --- |
| 1 | `_meta` object on resource contents | Present on `contents[0]._meta` |
| 2 | List-shaped `_meta` | Dropped, not emitted as a JSON array; warning logged |
| 3 | Blob-only first item | One `BlobResourceContents` per item, each taking the resource's URI |
| 4a/b | Descriptor `_meta` object / list | Emitted / omitted |
| 5 | Nested embedded resource | `_meta` correct on both levels |
| 6 | Flat embedded resource | `_meta` on contents, `annotations` on block |
| 7 | Image with siblings | Both reach the image block (using `results`) |
| 8 | Result with no `type` | Left as tool data; not lifted onto the block |
| 9a/b | `'1'` → `true`, `'0.5'` → `0.5` | Both coerced |
| 10 | Prompt block the DTOs refuse | Degraded to text, siblings untouched, warning logged |
| 11 | `composer test` / `lint` / `phpstan` | 1130 tests OK, clean |

Dropped-value warnings carry useful context, e.g.
`{"uri":"ui:\/\/probe\/bad-meta"}` and `{"prompt_name":"probe-meta-prompt-invalid-embedded"}`.

Both `_meta` levels and content annotations also survive intact through a real consumer's
`execute`-style meta-tool wrappers, not only on the direct handler path.

**The descriptor `_meta` passthrough this PR normalizes is what makes MCP Apps possible on this
adapter** — `_meta.ui.resourceUri` rides that exact path. Worth saying in the PR description; it
is a stronger motivation than the one currently given.

---

## Appendix: a verified-working MCP App on this adapter

This renders a live interactive table in Claude Desktop, with finding 1's patch applied.

**Resource ability** — the template:

```php
'meta' => array(
    'mcp' => array(
        'type'     => 'resource',
        'uri'      => 'ui://abilities-catalog/posts-list',
        'mimeType' => 'text/html;profile=mcp-app',
    ),
),
// handler returns: [[ 'uri' => 'ui://…', 'mimeType' => 'text/html;profile=mcp-app', 'text' => $html ]]
```

**Tool ability** — the binding:

```php
'meta' => array(
    'mcp' => array(
        '_meta' => array(
            'ui' => array( 'resourceUri' => 'ui://abilities-catalog/posts-list' ),
        ),
    ),
),
// handler returns plain data; the adapter's generic path supplies both the text
// fallback and structuredContent, which satisfies the spec's fallback requirement.
```

### Server-author traps worth documenting

These are view-side, not adapter bugs, but every author writing an MCP App on this adapter will
hit them. Source of truth is `modelcontextprotocol/ext-apps`, `src/spec.types.ts` — the prose
specs and blog posts disagree with it on all three.

- `ui/initialize` params are `{ protocolVersion, appInfo, appCapabilities }`. It is **`appInfo`**,
  not `clientInfo`. Sending `clientInfo` makes the host reject the request; the View then never
  sends `initialized`, so the host never sends the tool result. Deadlocks silently.
- `protocolVersion` is the **MCP Apps** version, `2026-01-26` — not the core MCP revision.
- A View must send `ui/notifications/size-changed` with `{width, height}`. A View that never does
  can be mounted at zero height, which on screen is indistinguishable from HTML that failed to
  load.

Method names that are correct as documented: `ui/initialize`,
`ui/notifications/initialized`, `ui/notifications/tool-input`, `ui/notifications/tool-result`.
`McpUiToolResultNotification.params` is a plain `CallToolResult`, so the data is at
`params.structuredContent`.
