# Claude Desktop test prompt

Copy the block below into Claude Desktop. Companion to [TESTING-PR-260.md](TESTING-PR-260.md).

## Before you start

- wp-env running: `npx wp-env start --config=.wp-env.probe.json`
- The `abilities-catalog-probe` connector is in `claude_desktop_config.json`
- Restart Claude Desktop (**Cmd+Q**, not window-close) after any change to the probe
  abilities — the tool and resource lists are read once per connection

## The prompt

```
Use the "abilities-catalog-probe" MCP connector. Work through this in order and
report as you go. Quote raw JSON field names verbatim — do not paraphrase payloads.

=== PART A: MCP App (the main test) ===

A1. Run the tool probe-meta-list-posts with empty arguments {}.

Then tell me, separately and precisely:
  - Did you receive a "content" array with a text fallback? Quote its first 100 chars.
  - Did you receive "structuredContent"? What is structuredContent.count, and how
    many entries are in structuredContent.posts?
  - Did the tool descriptor carry _meta? If you can see it, quote it exactly —
    I expect {"ui":{"resourceUri":"ui://abilities-catalog/posts-list"}}.
  - Did anything about this result differ from an ordinary tool result — did the
    host fetch a UI resource, render an iframe, or otherwise treat it specially?
    If you cannot observe that from your position, say so plainly.

A2. List the connector's resources if you are able. Report whether
    ui://abilities-catalog/posts-list appears and what mimeType it advertises.
    I expect "text/html;profile=mcp-app". If you have no resources/list capability,
    say so instead of guessing.

=== PART B: metadata regression re-check ===

Run each with empty arguments {} and report ONLY what actually reached you:

B1. probe-meta-tool-embedded-nested
    Two _meta objects are sent: {"level":"contents"} on the resource and
    {"level":"block"} on the block. How many of the two can you see — 0, 1 or 2?

B2. probe-meta-tool-image
    Did this arrive as a real image content block, or as text/JSON?
    Can you see annotations (audience, priority 0.9) or _meta?

B3. probe-meta-tool-no-type
    Confirm "annotations" and "_meta" appear as ordinary keys INSIDE the payload
    and were not treated as rendering hints.

B4. probe-meta-tool-result-priority-string
    If any annotations object reaches you, report the JSON type of
    annotations.priority — number 0.5 or string "0.5". If none reaches you, say
    the question is unanswerable rather than answering it negatively.

=== FINAL QUESTION ===

Split your observations into two buckets and state which bucket each item above
fell into:
  (a) metadata carried INSIDE the result payload — visible to you
  (b) metadata attached to the content BLOCK or tool descriptor — visible or not

For bucket (b), do not guess whether the server omitted it or the client stripped
it before it reached you. If you cannot distinguish those two cases, say so.
```

## What to look for yourself

A1 is the test that matters, and the decisive evidence is on screen rather than in the
reply — the model sits downstream of rendering and generally cannot tell you whether an
iframe appeared.

| What you see | What it means |
| --- | --- |
| Styled card list — titles as links, coloured status pills (`publish` green, `draft` amber), author · date · ID | MCP Apps works end to end |
| Plain JSON blob of the 7 posts | Host does not support MCP Apps, or the extension was not negotiated. The spec **requires** this fallback, so it is correct behaviour, not a failure |
| Empty frame, or the literal text "Waiting for the host…" | Most interesting case: the host found and rendered the template but never sent `ui/notifications/tool-result`. Isolates the failure to the postMessage handshake rather than discovery |

For the third case, `Cmd+Option+I` opens devtools; the iframe console shows whether
`ui/initialize` ever got a response.

## Expected wire values

Confirmed by direct JSON-RPC before involving any client, so a mismatch in Claude
Desktop is the client's doing, not the server's:

| Where | Value |
| --- | --- |
| `tools/list` → `probe-meta-list-posts` → `_meta` | `{"ui":{"resourceUri":"ui://abilities-catalog/posts-list"}}` |
| `resources/list` → `ui://abilities-catalog/posts-list` → `mimeType` | `text/html;profile=mcp-app` |
| `tools/call` → `probe-meta-list-posts` | `content[0].type == "text"` plus `structuredContent.count == 7` |

The `mimeType` row depends on the local `McpValidator::validate_mime_type()` patch —
unpatched, the adapter rejects RFC 2045 parameters and drops the key, leaving the
template undiscoverable.
