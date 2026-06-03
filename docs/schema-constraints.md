# Ability execution: input/output schema constraints

Hard-won during the T1-reads build (62 abilities). An ability can register, appear in the
abilities registry, and list as a tool, yet still **fail when executed** by a consumer. The
reason is schema strictness: a consumer typically validates input and output against the
declared JSON Schema with a strict validator (for example a browser client's AJV over the
`/wp-abilities/v1/abilities/<name>/run` REST path), and the runtime marshals the arguments.
Server-side `wp_get_ability( $name )->execute( $input )` does **not** catch these — its
validator is more lenient, and CLI loads more than REST — so a schema that passes in
`wp eval` can still fail for a consumer. Each constraint below was a real, debugged failure.

## 1. PHP empty `array()` serializes as JSON `[]`, breaking strict validators

- `'properties' => array()` → `[]`. JSON Schema needs `properties` to be an object `{}`.
- `'required' => array()` → `[]`. The JSON Schema meta-schema requires `required` to have
  ≥1 item; a strict validator rejects the whole schema ("data/required must NOT have fewer
  than 1 items") → "Invalid schema provided for validation".
- Fix (central, in `Registry::normalizeSchema`): coerce empty `properties` → `stdClass`, and
  DROP empty `required`. Applied to every ability's `input_schema` + `output_schema` at
  registration, recursively. Keep this — future abilities hit the same trap.

## 2. No-input abilities: empty `input_schema` + zero-arg-tolerant callbacks

- Core convention (`WP_Ability::invoke_callback`): the callback receives `$input` ONLY if
  `get_input_schema()` is non-empty. With an empty input schema it is called with ZERO args.
  A callback typed `execute($input)` then fatals "Too few arguments".
- A non-empty object input schema with no usable properties ALSO fails: the consumer passes a
  non-object and the validator reports "input is not of type object".
- Canonical pattern (matches core `get-environment-info`): `'input_schema' => array()` (empty)
  AND callbacks `execute($input = null)` / `hasPermission($input = null)`. Then the consumer
  skips input validation and the server calls them with no args.

## 3. All-optional list abilities need a property with a `default`

- When the resolved argument object is empty, some runtimes (e.g. a browser's WebMCP
  marshalling) pass a non-object to the tool (→ "input is not of type object"). They pass a
  proper object only when the schema yields a non-empty object — i.e. some property has a
  `default`.
- Abilities that already had a defaulted prop (`context: view`, `page: 1`, `per_page`) worked
  on an empty `{}` call. The two that lacked any default (`themes/list-themes`,
  `plugins/list-plugins`) failed on `{}` and worked only when a param was supplied.
- Fix: give all-optional list abilities a NEUTRAL defaulted property — a `context` param with
  `default => 'view'` (does not filter results). Do NOT default a real filter (e.g. `status`);
  that changes semantics.

## 4. Runtime output must also be objects, not empty arrays

- Same PHP quirk at runtime: returning `'settings' => array()` (empty) serializes to `[]` and
  fails a `type: object` output schema. Cast to `(object)` when the field is declared an object
  (e.g. `templates/get-global-styles` settings/styles).

## 5. Net-new reads need admin includes in REST context

- REST requests do not load `wp-admin/includes/*`. `WP_Debug_Data::debug_data()`
  (`site-health/get-info`) needs `plugin`, `update`, `theme`, `file` (`get_home_path`),
  `class-wp-site-health`, `class-wp-debug-data`. CLI (`wp eval`) loads more, so it passes there
  and fails only over REST. Load every dependency via `AdminIncludes::load(...)`.

## Verify the right way

- Always test **execution**, not just registration or a lenient server-side `execute()`. A
  strict consumer's validate + run path is where these surface.
- A consumer often surfaces only a generic "the invocation failed". Get the real reason by
  capturing the consumer's error output, or by temporarily returning `$e->getMessage()` from
  the ability's catch.
