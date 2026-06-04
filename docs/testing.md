# Testing — TDD workflow

Tests run with PHPUnit inside a `wp-env` (Docker) WordPress 7.0 environment. WordPress 7.0
core ships the Abilities API, so the test environment needs no extra plugin: `wp_register_ability()`
and the rest are provided by core.

## One-time setup

```bash
npm install                 # installs @wordpress/env
npm run wp-env:test start   # boots the test environment (port 8890)
npm run test:php:setup      # composer install inside the container (PHPUnit + polyfills)
```

`composer install` writes `vendor/` into the plugin directory, which is bind-mounted into the
container, so the dependencies are visible both on the host and inside `wp-env`.

## Running tests

```bash
npm run test:php            # runs the whole suite (unit + integration)
```

To run a single file or filter while iterating:

```bash
npm run wp-env:test -- run cli --env-cwd=wp-content/plugins/abilities-catalog/ \
  vendor/bin/phpunit -c phpunit.xml.dist --no-coverage --filter GetComment
```

## Coverage (opt-in)

The default `test:php` run passes `--no-coverage` so it is fast and quiet — no code coverage driver
is needed. Coverage reporting is still configured in `phpunit.xml.dist`; to produce a report, start
the environment with the Xdebug coverage driver, then run the coverage script:

```bash
npm run wp-env:test start -- --xdebug=coverage   # boot with the coverage driver
npm run test:php:coverage                         # writes tests/_output/php-coverage.xml
```

Coverage is intentionally not a CI gate while the suite is young. Promote it to CI (run with
`--xdebug=coverage` and upload the report) once coverage is high enough to be meaningful.

## Layout

- `phpunit.xml.dist` — two suites: `unit` and `integration`.
- `tests/phpunit/bootstrap.php` — loads the WP test library and activates the plugin.
- `tests/phpunit/TestCase.php` — base class with user/role helpers (`actingAs()`).
- `tests/phpunit/Unit/` — pure logic, no database (schema normalization, the `Support/` guards:
  source validation and the read/write option allow-lists).
- `tests/phpunit/Integration/` — code exercised against real WordPress: ability registration,
  ability execution, the capability gate on the dangerous tier, the upgrader lock, and the
  filesystem guard.
- `tests/phpunit/Fixtures/` — test doubles (e.g. an unsafe write ability used to prove the
  Registry annotation guard refuses it).

Test files end in `Test.php`. Integration tests for an ability mirror its source path, e.g.
`includes/Abilities/Comments/GetComment.php` → `tests/phpunit/Integration/Abilities/Comments/GetCommentTest.php`.

## The red-green-refactor loop (required for new abilities)

Every new or changed ability is built test-first:

1. **Red** — write a failing test that states the behavior. For a read ability: create the
   fixture data, `actingAs('administrator')`, call
   `wp_get_ability('<name>')->execute([...])`, assert the shaped output. Add a logged-out case
   asserting a `WP_Error` with code `ability_invalid_permissions` (the Abilities API runs the
   `permission_callback` inside `execute()`). Run `npm run test:php` and watch it fail.
2. **Green** — write the smallest ability code that makes the test pass.
3. **Refactor** — clean up with the test as a safety net.

`tests/phpunit/Integration/RegistryTest.php` is a standing guard: it discovers every ability
class on disk and asserts each one registers and points at a registered category. A new ability
that trips the annotation guard or a schema serialization quirk fails this test without any
per-ability test being written.

## Continuous integration

`.github/workflows/test.yml` runs the suite on every pull request and on pushes to `main`,
against PHP 8.1 and 8.3.
