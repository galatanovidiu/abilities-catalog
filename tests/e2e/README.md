# Dual-revision MCP end-to-end verification

This suite validates the catalog against the exact review branches of
`wordpress/mcp-adapter` and `wordpress/php-mcp-schema`. It uses
`@modelcontextprotocol/inspector` exactly `2.4.0` for valid client behavior and
a raw HTTP/STDIO companion only for envelopes Inspector cannot generate.

## Run locally

Install the locked dependencies, start the isolated test environment, and run:

```bash
composer install --no-interaction --no-progress
npm ci --min-release-age=0
npm run wp-env:test start
bash tests/e2e/run-local.sh
npm run wp-env:test stop
```

`run-local.sh` creates one temporary administrator application password and
three temporary options, keeps the credential out of files and output, and
removes all four in a trap. The tracked mu-plugin fixtures are inert unless
their temporary option is enabled.

For another site, configure the same abilities and direct-tool fixture, then
run the Node entrypoint with either `MCP_AUTH_HEADER` or
`WP_API_USERNAME`/`WP_API_PASSWORD`. Endpoint variables are documented in the
script (`MCP_BASE_URL`, `MCP_SEARCH_ENDPOINT`, `MCP_CURATED_ENDPOINT`, and
`MCP_DEFAULT_ENDPOINT`).

## Evidence boundaries

- Schema-package proof belongs to `php-mcp-schema` and covers canonical inputs,
  generation, validation/hydration, and its Composer artifact.
- Adapter proof belongs to `mcp-adapter` and covers the raw transport corpus and
  extracted plugin artifact.
- Inspector proof here covers the three live HTTP endpoints, exact legacy and
  modern negotiation, session behavior, tools/resources/prompts, twelve
  representative read-only abilities, exact modern result fields, and valid
  `x-mcp-header` mirroring.
- The raw companion here covers malformed headers/envelopes, revision-specific
  method rejection, typed errors, Origin/method policy, one persistent STDIO
  process alternating exact revisions, and the `execute-ability` consent gate on
  both mechanisms — the asserted flag, the elicitation round trip, its
  argument/state binding, a decline, and read passthrough. The elicitation path
  exists only here: Inspector's proxy speaks `2025-11-25`, which cannot carry
  MRTR.

No catalog write ability is executed. The consent checks drive one mu-plugin
fixture ability, annotated as a write so the gate applies, whose callback
returns its own input and changes nothing.
