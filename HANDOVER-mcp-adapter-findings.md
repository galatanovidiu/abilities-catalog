# Handover: four findings for `mcp-adapter`

Found while testing [PR #260](https://github.com/WordPress/mcp-adapter/pull/260) end to
end against a real WordPress install (see [TESTING-PR-260.md](TESTING-PR-260.md) for the
harness). **None of these is a PR #260 regression** — findings 1–3 reproduce on `trunk`
and finding 4 is a docs correction to the PR itself.

Ordered by impact.

---

## 1. `validate_mime_type()` rejects the MIME type MCP Apps mandates

**Severity:** blocks MCP Apps entirely. **Status:** patched locally, see below.

### Symptom

A UI resource never advertises its MIME type. `resources/list` returns the resource with
no `mimeType` key at all, so a host filtering the list for UI templates cannot discover
it.

### Root cause

`McpValidator::validate_mime_type()` — `includes/Domain/Utils/McpValidator.php`

```php
return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*$/', $mime_type );
```

The pattern permits no RFC 2045 parameters, so `text/html;profile=mcp-app` fails. The
caller at `RegisterAbilityAsMcpResource.php:160-164` validates before assigning, so an
invalid type is dropped **silently** — no warning, no error.

MCP Apps ([SEP-1865](https://modelcontextprotocol.io/seps/1865-mcp-apps-interactive-user-interfaces-for-mcp),
Final, shipped in Claude Desktop since Jan 2026) states: *"`mimeType` MUST be
`text/html;profile=mcp-app` (other types reserved for future extensions)"*. It is the only
permitted value, so this single rejection closes the whole feature.

### It is deliberate, not an oversight

`tests/phpunit/Unit/Domain/Utils/McpValidatorTest.php` has
`test_validate_mime_type_rejects_parameters`, which asserts exactly this behaviour. It
predates MCP Apps. **Changing it reverses a tested decision and needs maintainer buy-in.**

Note the inconsistency it creates: resource *contents* returned from `resources/read`
carry the parameterized type through fine — only the *descriptor* path validates. So the
same string is legal in one half of the resource and illegal in the other.

### Proposed fix

Accept RFC 2045 parameters, keep rejecting malformed ones:

```php
$token     = '[a-zA-Z0-9!#$&^_.+-]';
$parameter = ';\s*' . $token . '+=(?:' . $token . '+|"[^"]*")';

return (bool) preg_match(
    '/^[a-zA-Z0-9]' . $token . '*\/[a-zA-Z0-9]' . $token . '*(?:\s*' . $parameter . ')*$/',
    $mime_type
);
```

Verified accepting: `text/html;profile=mcp-app`, `text/html`, `image/svg+xml`,
`application/vnd.api+json`, `text/plain; charset=UTF-8`, `text/html;profile="mcp app"`.
Verified rejecting: `notamimetype`, `text/`, `text/html;`, `text/html; charset`,
`text/html; =utf-8`, `/html`.

`test_validate_mime_type_rejects_parameters` must be replaced. Suggested split into
`test_validate_mime_type_accepts_parameters` and
`test_validate_mime_type_rejects_malformed_parameters`.

> ⚠️ **This patch is currently applied, uncommitted, in the `mcp-adapter` working tree**
> on branch `fix/preserve-resource-contents-meta` — two files: `McpValidator.php` and its
> test. Full gate green with it: 1131 tests, PHPStan clean, phpcs clean. It is also
> synced into `abilities-catalog`'s mirrored vendor copy, which is what makes the live MCP
> App test work. `git checkout` those two files to drop it.

---

## 2. `mcp.annotations` silently drops every tool annotation

**Severity:** silent data loss on a safety-relevant field.

### Symptom

Move a tool's annotations from `meta.annotations` to `meta.mcp.annotations` — the location
the adapter's own deprecation notice tells you to use — and **all annotations vanish** from
`tools/list`, including `readOnlyHint`. No warning.

Observed live: eight probe tools emitted `readOnlyHint: true` from `meta.annotations`, and
`null` after moving to `meta.mcp.annotations`.

### Root cause

The `mcp.{key}` lookup convention exists in exactly one class.

| Location | Behaviour |
| --- | --- |
| `RegisterAbilityAsMcpResource.php:175` | `$this->get_mcp_meta( 'annotations', 'array' )` — prefers `mcp.annotations`, falls back to top level **with a deprecation warning** |
| `RegisterAbilityAsMcpTool.php:143` | `$ability_meta['annotations']` — reads top level **only**, no `mcp.annotations` branch |

`get_mcp_meta()`, `validate_type()` and `log_deprecation()` are all **`private` to
`RegisterAbilityAsMcpResource`** (lines 296, ~330, 375). The tool registrar cannot reach
them, so it never grew the behaviour.

The result is a contradiction: registering a tool and a resource in the same site emits
`Ability meta key "annotations" is deprecated. Use "mcp.annotations" instead.` — a
site-wide notice — while following it costs the tool every annotation.

The codebase already regards `mcp.annotations` as settled. `RegisterAbilityAsMcpPrompt.php:207`:

> *"This follows the `mcp.*` override pattern used elsewhere (mcp.uri, mcp.icons, mcp.annotations)."*

The tool registrar is the outlier.

### Proposed fix

Extract `get_mcp_meta()` / `validate_type()` / `log_deprecation()` from
`RegisterAbilityAsMcpResource` into a shared trait (e.g.
`includes/Domain/Utils/ReadsAbilityMcpMeta.php`) and `use` it in both registrars, then
change `RegisterAbilityAsMcpTool` to `$this->get_mcp_meta( 'annotations', 'array' )`.

The duplication *is* the defect — a per-class copy is what let the two diverge — so
fixing only the tool call site leaves the next key to drift the same way.

Two things to check when doing it:

- `RegisterAbilityAsMcpTool` has `private \WP_Ability $ability` but **no** `$error_handler`
  property (the resource registrar has one). `log_deprecation()` needs it, so the trait
  needs either a nullable property on both or an abstract accessor.
- `validate_type( $value, 'array' )` requires a non-empty array with at least one
  meaningful value. Confirm that does not reject a legitimate annotation set such as
  `[ 'readOnlyHint' => false ]` — a single `false` value may fail the
  `'' !== $item` / `null !== $item` screen. Worth a test either way.

**Prompts are not affected.** Per MCP 2025-11-25 the Prompt object has no annotations
field; only message content carries them (`RegisterAbilityAsMcpPrompt.php:199-201`).

---

## 3. The image branch is unreachable via the MCP wire shape

**Severity:** usability trap; silent wrong output.

### Symptom

Returning what looks like a spec-shaped image result produces a **text block**, not an
image, with no diagnostic:

```php
return [ 'type' => 'image', 'data' => $base64, 'mimeType' => 'image/png' ];
```

### Root cause

`includes/Handlers/Tools/ToolsHandler.php:332`

```php
if ( isset( $result['type'] ) && 'image' === $result['type'] && isset( $result['results'] ) ) {
    $image_data = base64_encode( $result['results'] );
```

The guard requires `results` holding **raw bytes**, which the handler base64-encodes
itself. `data` — the key MCP actually puts on the wire, already base64 — misses the guard
and falls through to the generic path, where the result is JSON-encoded into a text block.

An author reading the MCP spec writes `data`. An author reading the adapter source writes
`results`. Nothing tells the first author they are wrong.

### Proposed fix

Accept both, without double-encoding:

```php
if ( isset( $result['type'] ) && 'image' === $result['type']
    && ( isset( $result['results'] ) || isset( $result['data'] ) ) ) {

    $image_data = isset( $result['data'] ) && is_string( $result['data'] )
        ? $result['data']                          // already base64 (MCP wire shape)
        : base64_encode( $result['results'] );     // raw bytes (adapter's legacy shape)
```

Consider validating that a supplied `data` is actually base64, and logging when it is not
— a silent wrong-output path is what made this hard to spot.

There is only one typed branch of this kind (`image`); no `audio` equivalent exists yet,
so this pattern is not yet duplicated.

### Also fix PR #260's testing instruction 7

It currently reads *"Return a `type: "image"` result with sibling `annotations` and
`_meta`"*, which cannot be followed as written — anyone using `data` silently tests the
generic path instead. It should name the key.

---

## 4. PR #260's new docs teach the pattern SEP-1865 deferred

**Severity:** documentation; will send MCP Apps adopters down a dead end.

`docs/guides/creating-abilities.md`, in the section this PR adds, presents embedded
`ui://` resources with `_meta` as the MCP Apps path:

> *"`_meta` travels with the resource but is not part of its body. MCP Apps UI resources
> use it for CSP config and rendering hints."*

Embedding is **not** how MCP Apps works. SEP-1865 models UI as a *predeclared* resource
referenced from the tool descriptor, and explicitly lists the alternative among rejected
designs:

> *"**Embedded resources:** Current MCP-UI approach, where resources are returned in tool
> results. Although it's more convenient for server development, it was deferred due to
> the gaps in performance optimization and the challenges in the UI review process."*

Confirmed empirically: Claude Desktop renders an embedded `ui://` resource as a plain text
attachment. Correct client behaviour, not a bug.

### The actual contract, all three parts required

1. **Resource predeclared** at `ui://…` with `mimeType: text/html;profile=mcp-app`
   *(blocked by finding 1)*
2. **Tool descriptor** carries `_meta.ui.resourceUri` — nested. The flat `ui/resourceUri`
   form appears in the launch blog post and is not what shipped.
3. **Tool result returns data only** — a meaningful text `content` fallback plus
   `structuredContent`. The host fetches the template itself via `resources/read`, renders
   it in a sandboxed iframe, and pushes the result in over postMessage
   (`ui/initialize` → `ui/notifications/initialized` → `ui/notifications/tool-result`).

Suggested doc change: keep the `_meta` passthrough documentation (it is correct and is
what PR #260 fixes), but stop framing embedded resources as the MCP Apps route. Point at
the predeclared pattern instead.

**The good news for PR #260:** part 2 is descriptor `_meta`, which this PR normalizes —
so the PR is a prerequisite for MCP Apps support, not an obstacle to it. Verified working:
`tools/list` emits `{"ui":{"resourceUri":"ui://abilities-catalog/posts-list"}}` intact.

---

## Reproducing any of this

A working example of all three MCP Apps parts lives in
`includes/Mcp/Probe/ProbeAbilities.php` on this branch (`appDefinitions()` and
`postsListTemplate()`), served at `/?rest_route=/abilities-catalog/v1/mcp-probe`.

```bash
npx wp-env start --config=.wp-env.probe.json
```

Confirmed wire values, with the finding-1 patch applied:

| Where | Value |
| --- | --- |
| `tools/list` → `probe-meta-list-posts` → `_meta` | `{"ui":{"resourceUri":"ui://abilities-catalog/posts-list"}}` |
| `resources/list` → `ui://abilities-catalog/posts-list` → `mimeType` | `text/html;profile=mcp-app` |
| `tools/call` → `probe-meta-list-posts` | `content[0].type == "text"`, `structuredContent.count == 7` |

Without the patch, the `mimeType` row is absent.
