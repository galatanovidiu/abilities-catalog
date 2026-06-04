<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a group's ability-category catalog.
 *
 * One provider per ability group (e.g. `Core`, `Woo`), discovered by
 * {@see \GalatanOvidiu\AbilitiesCatalog\Registry} via the same directory scan
 * that finds abilities. Each provider owns the categories its group's abilities
 * reference through `args()['category']`, so a new group adds its own provider
 * under its own folder and never edits a shared list.
 *
 * The Registry merges every provider's {@see categories()} and registers them on
 * `wp_abilities_api_categories_init`. Category slugs are global to the Abilities
 * API, so providers across groups must not reuse the same slug for different
 * meanings.
 *
 * Labels and descriptions call `__()`, so {@see categories()} must be invoked at
 * or after the relevant init hook (when translations are available), never at
 * file load.
 *
 * @since 0.4.0
 */
interface CategoryProvider {

	/**
	 * The group's category descriptors, keyed by slug.
	 *
	 * @return array<string,array{slug:string,label:string,description:string}>
	 */
	public function categories(): array;
}
