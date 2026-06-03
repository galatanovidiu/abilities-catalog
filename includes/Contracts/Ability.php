<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Contracts;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Contract for a single Abilities API ability.
 *
 * One class per ability, one file per class, discovered by {@see \Automattic\AbilitiesCatalog\Registry}
 * via a directory scan of `includes/Abilities/<Domain>/`. Implementations declare
 * their category and the full `wp_register_ability()` argument array; the Registry
 * registers the category (deduplicated) and the ability on the correct hooks.
 *
 * @since 0.1.0
 */
interface Ability
{
	/**
	 * The ability name (id) passed as the first argument to `wp_register_ability()`.
	 *
	 * Format: `namespace/verb-resource`, kebab-case (e.g. `content/get-post`).
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * The ability category descriptor.
	 *
	 * The `slug` MUST equal `args()['category']`. The Registry registers each
	 * unique slug once on `wp_abilities_api_categories_init`.
	 *
	 * @return array{slug:string,label:string,description:string}
	 */
	public function category(): array;

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
	 * @return array<string,mixed>
	 */
	public function args(): array;
}
