<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves term references to existing term IDs within a taxonomy.
 *
 * The post-term abilities (`terms/attach-post-terms`, `terms/detach-post-terms`)
 * accept terms as IDs, slugs, or names but never create terms — that is the job of
 * `terms/create-term`. This resolver maps each reference to an existing term ID and
 * reports anything it cannot find, so the abilities can return an actionable error
 * instead of silently creating or skipping terms.
 *
 * @since 0.5.0
 */
final class TermResolver {

	/**
	 * Resolves a list of term references (IDs, slugs, or names) to term IDs.
	 *
	 * @param array<int|string> $terms    Term references.
	 * @param string            $taxonomy The taxonomy slug.
	 * @return array{ids:int[],missing:string[]} Resolved term IDs (de-duplicated)
	 *               and the references that did not match an existing term.
	 */
	public static function resolve( array $terms, string $taxonomy ): array {
		$ids     = array();
		$missing = array();

		foreach ( $terms as $term ) {
			$id = self::resolveOne( $term, $taxonomy );

			if ( null === $id ) {
				$missing[] = (string) $term;
				continue;
			}

			$ids[] = $id;
		}

		return array(
			'ids'     => array_values( array_unique( $ids ) ),
			'missing' => array_values( array_unique( $missing ) ),
		);
	}

	/**
	 * Resolves a single term reference to an existing term ID.
	 *
	 * @param int|string $term     A term ID, slug, or name.
	 * @param string     $taxonomy The taxonomy slug.
	 * @return int|null The term ID, or null when no matching term exists.
	 */
	private static function resolveOne( $term, string $taxonomy ): ?int {
		if ( is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) ) ) {
			$found = get_term( (int) $term, $taxonomy );

			return $found && ! is_wp_error( $found ) ? (int) $found->term_id : null;
		}

		$value = (string) $term;
		$found = get_term_by( 'slug', $value, $taxonomy );
		if ( ! $found ) {
			$found = get_term_by( 'name', $value, $taxonomy );
		}

		return $found ? (int) $found->term_id : null;
	}
}
