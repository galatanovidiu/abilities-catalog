<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a single Abilities API ability.
 *
 * One class per ability, one file per class, discovered by {@see \GalatanOvidiu\AbilitiesCatalog\Registry}
 * via a recursive scan of `includes/Abilities/<Group>/`. Implementations declare
 * the full `wp_register_ability()` argument array; the Registry registers the
 * ability on `wp_abilities_api_init`. Categories are contributed per group by a
 * {@see CategoryProvider} (e.g. `\GalatanOvidiu\AbilitiesCatalog\Abilities\Core\CategoryCatalog`);
 * an ability references its category by slug through `args()['category']`.
 *
 * @since 0.1.0
 */
interface Ability {

	/**
	 * The ability name (id) passed as the first argument to `wp_register_ability()`.
	 *
	 * Format: `namespace/verb-resource`, kebab-case (e.g. `og-content/get-post`).
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * The second argument to `wp_register_ability()`.
	 *
	 * Must include: `label`, `description`, `category` (slug), `input_schema`,
	 * `output_schema`, `execute_callback`, `permission_callback`, and `meta`.
	 * Set `annotations.readonly = true` for a read ability. A write ability sets
	 * `annotations.readonly = false` and MUST explicitly set a boolean
	 * `annotations.destructive` (false for ordinary writes, true for destructive
	 * ones such as permanent deletes). The Registry refuses a write that omits the
	 * `destructive` annotation; destructive writes register but are exposed to the
	 * browser only when the adapter's destructive setting is also on.
	 *
	 * An ability MAY declare its multisite policy scope in
	 * `meta.abilities_catalog.scope`, one of `site` | `network` | `user` |
	 * `global`. The default is `site` (a site-scoped ability declares nothing).
	 * Only a non-site ability sets an explicit scope: `network` (network
	 * management that owns its own targeting), `user` (network-global user
	 * identity), or `global` (operates on the install/network as a whole). On
	 * multisite the policy decorator injects an optional `blog_id` into `site`
	 * abilities only; the other scopes opt out of that injection.
	 *
	 * @return array<string,mixed>
	 */
	public function args(): array;
}
