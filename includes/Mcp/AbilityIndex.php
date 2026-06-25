<?php
/**
 * The scalable, taxonomy-free discovery reader behind the search MCP server.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers the four bounded discovery questions over the whole ability registry.
 *
 * The curated {@see Server} groups abilities into a hand-maintained domain taxonomy and
 * lists a domain at a time. That does not scale: a site stacking WooCommerce, an SEO
 * plugin, forms and backups can register a thousand-plus abilities, and both "list a
 * domain" and the adapter default server's "dump every ability" then cost tens of
 * thousands of tokens per call and need a taxonomy nobody curated.
 *
 * This reader is the alternative the {@see SearchServer} exposes. It is **taxonomy-free**
 * (it reads the live registry, never the {@see DomainMap}) and every answer is **bounded**
 * regardless of catalog size:
 *
 * - {@see overview()} — a capability map: one row per category (its label, blurb, and
 *   ability count), sorted biggest-first. Size is O(categories), not O(abilities), so an
 *   agent learns "what this site can do" in one cheap call however large the catalog.
 * - {@see search()} — a ranked keyword query over name + label + description + keywords,
 *   capped to a small result set. This replaces the dump: the agent describes the task,
 *   not the domain.
 * - {@see describe()} — the full schema for one named ability, on demand.
 * - {@see execute()} — runs one ability behind the same two guards the curated server
 *   uses: the {@see ExposurePolicy} gate and the ability's own capability check.
 *
 * Like the curated server, discovery **hides nothing**: {@see overview()}, {@see search()}
 * and {@see describe()} include abilities the exposure gate has disabled, each flagged
 * `enabled: false`, so an agent learns a capability exists (and can ask the owner to turn
 * it on) instead of concluding it does not exist. Only {@see execute()} refuses a disabled
 * ability. Capability remains the hard guard on every execute.
 *
 * @since 0.3.0
 */
final class AbilityIndex {

	/**
	 * The exposure gate, consulted to flag (not hide) disabled abilities and to refuse
	 * executing them.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy
	 */
	private ExposurePolicy $policy;

	/**
	 * Field weights for keyword scoring: a hit in the name outranks one in the description.
	 */
	private const FIELD_WEIGHTS = array(
		'name'        => 5.0,
		'label'       => 4.0,
		'keywords'    => 3.0,
		'description' => 1.0,
	);

	/**
	 * How many sample abilities {@see overview()} lists per category.
	 *
	 * Enough to seed an agent's search vocabulary (so it learns what words a query can
	 * use instead of guessing blind), few enough to keep overview O(categories).
	 */
	private const OVERVIEW_EXAMPLES = 5;

	/**
	 * Query words too common to discriminate; dropped before scoring.
	 *
	 * Without this, "the"/"a"/"to" match nearly every ability and make `total_matched`
	 * meaningless. This is a deliberately small list — proper stemming and synonyms are the
	 * upgrade path (alongside semantic search) if keyword recall proves insufficient.
	 *
	 * @var list<string>
	 */
	private const STOPWORDS = array(
		'the',
		'a',
		'an',
		'to',
		'of',
		'on',
		'in',
		'for',
		'with',
		'and',
		'or',
		'my',
		'your',
		'this',
		'that',
		'is',
		'are',
		'be',
		'new',
		'old',
		'whole',
		'all',
		'from',
		'into',
		'it',
		'as',
		'at',
		'by',
		'up',
		'me',
		'please',
		'can',
		'you',
		'i',
		'do',
	);

	/**
	 * @param \GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy $policy The exposure gate.
	 */
	public function __construct( ExposurePolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Returns the capability map: one row per non-empty category, biggest first.
	 *
	 * Each row carries the category's human label and description (the registered blurb)
	 * plus how many abilities it holds, how many of those the exposure gate has enabled, and
	 * a few example abilities (name + label) as vocabulary seeds — so an agent reading the
	 * map sees what it can search for instead of guessing a query blind.
	 * The result is O(categories), so it stays small even with thousands of abilities — the
	 * cheap orientation read an agent makes once to see what a site can do.
	 *
	 * @return array{categories: list<array<string,mixed>>, total_abilities: int, total_enabled: int} The map.
	 */
	public function overview(): array {
		$abilities = wp_get_abilities();

		$counts   = array();
		$enabled  = array();
		$examples = array();
		foreach ( $abilities as $ability ) {
			$name             = $ability->get_name();
			$slug             = (string) $ability->get_category();
			$counts[ $slug ]  = ( $counts[ $slug ] ?? 0 ) + 1;
			$enabled[ $slug ] = ( $enabled[ $slug ] ?? 0 ) + ( $this->policy->allows( $name ) ? 1 : 0 );

			// Keep the first few abilities per category as vocabulary seeds for search.
			$examples[ $slug ] = $examples[ $slug ] ?? array();
			if ( count( $examples[ $slug ] ) >= self::OVERVIEW_EXAMPLES ) {
				continue;
			}

			$examples[ $slug ][] = array(
				'name'  => $name,
				'label' => (string) $ability->get_label(),
			);
		}

		$rows = array();
		foreach ( wp_get_ability_categories() as $category ) {
			$slug = $category->get_slug();
			if ( empty( $counts[ $slug ] ) ) {
				continue;
			}

			$rows[] = array(
				'category'    => $slug,
				'label'       => $category->get_label(),
				'description' => $category->get_description(),
				'abilities'   => $counts[ $slug ],
				'enabled'     => $enabled[ $slug ],
				'examples'    => $examples[ $slug ] ?? array(),
			);
		}

		usort( $rows, static fn ( array $a, array $b ): int => $b['abilities'] <=> $a['abilities'] );

		return array(
			'categories'      => $rows,
			'total_abilities' => count( $abilities ),
			'total_enabled'   => array_sum( $enabled ),
		);
	}

	/**
	 * Ranked keyword search over the registry, capped to a small result set.
	 *
	 * Scores each ability by weighted substring hits of the query words across its name,
	 * label, keywords and description, scaled by how many query words matched, with a bonus
	 * when the whole phrase appears in the name or label. Abilities scoring zero are
	 * dropped. An optional category narrows the corpus. The reply reports how many abilities
	 * matched in total so the agent knows whether to refine.
	 *
	 * A query that matches nothing is not a dead end: the reply also carries the category map
	 * (the same orientation {@see overview()} gives) so the agent can re-orient and retry with
	 * real vocabulary instead of guessing again.
	 *
	 * @param string      $query    The natural-language/keyword query.
	 * @param string|null $category Restrict to this category slug, or null for all.
	 * @param int         $limit    Max results (clamped to 1..50).
	 * @return array{query: string, returned: int, total_matched: int, abilities: list<array<string,mixed>>, no_match?: bool, categories?: list<array<string,mixed>>, next_step?: string} The ranked hits, plus the category map when nothing matched.
	 */
	public function search( string $query, ?string $category, int $limit ): array {
		$limit = max( 1, min( 50, $limit ) );
		$q     = strtolower( trim( $query ) );
		$words = self::significantWords( $q );

		$scored = array();
		foreach ( wp_get_abilities() as $ability ) {
			$slug = (string) $ability->get_category();
			if ( null !== $category && '' !== $category && $slug !== $category ) {
				continue;
			}

			$name  = $ability->get_name();
			$meta  = $ability->get_meta();
			$score = $this->score(
				$words,
				$q,
				$name,
				(string) $ability->get_label(),
				(string) $ability->get_description(),
				implode( ' ', (array) ( $meta['keywords'] ?? array() ) )
			);

			if ( $score <= 0 ) {
				continue;
			}

			$scored[] = array(
				'name'        => $name,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				'category'    => $slug,
				'enabled'     => $this->policy->allows( $name ),
				'_score'      => $score,
			);
		}

		usort( $scored, static fn ( array $a, array $b ): int => $b['_score'] <=> $a['_score'] );

		$total = count( $scored );
		$hits  = array_slice( $scored, 0, $limit );
		foreach ( $hits as &$hit ) {
			unset( $hit['_score'] );
		}
		unset( $hit );

		$result = array(
			'query'         => $query,
			'returned'      => count( $hits ),
			'total_matched' => $total,
			'abilities'     => $hits,
		);

		// A query that matched nothing means the agent guessed words the catalog does not
		// index. Hand back the category map (with example abilities) so it can see what this
		// site actually offers and retry with real vocabulary rather than guessing again.
		if ( 0 === $total ) {
			$result['no_match']   = true;
			$result['categories'] = $this->overview()['categories'];
			$result['next_step']  = 'No ability matched this query. Read "categories" below to see what this site can do, then call search-abilities again using words from an example name or label (or pass a category slug to narrow).';
		}

		return $result;
	}

	/**
	 * Returns the full schema and metadata for one ability (even if disabled).
	 *
	 * @param string $name The full ability name.
	 * @return array<string,mixed>|\WP_Error The ability detail, or a recoverable WP_Error when the name is unknown.
	 */
	public function describe( string $name ) {
		$ability = $this->lookup( $name );
		if ( null === $ability ) {
			return $this->unknown( $name );
		}

		$meta    = $ability->get_meta();
		$enabled = $this->policy->allows( $name );

		return array(
			'name'          => $name,
			'label'         => $ability->get_label(),
			'description'   => $ability->get_description(),
			'category'      => (string) $ability->get_category(),
			'input_schema'  => $ability->get_input_schema(),
			'output_schema' => $ability->get_output_schema(),
			'annotations'   => $meta['annotations'] ?? (object) array(),
			'enabled'       => $enabled,
			'enabled_note'  => $enabled ? null : 'Disabled in the MCP exposure gate; the site owner must enable it (Settings → MCP Server) before it can run.',
		);
	}

	/**
	 * Runs one ability behind the exposure gate and its own capability check.
	 *
	 * Deny-by-default: an unknown name and a gate-disabled ability both return recoverable
	 * WP_Errors rather than running anything. A known, enabled ability then passes through
	 * its registered `permission_callback` (the hard capability guard) before executing.
	 *
	 * @param string              $name   The full ability name.
	 * @param array<string,mixed> $params The ability input.
	 * @return mixed|\WP_Error The ability result, or a WP_Error on an unknown/disabled/forbidden/failed call.
	 */
	public function execute( string $name, array $params ) {
		$ability = $this->lookup( $name );
		if ( null === $ability ) {
			return $this->unknown( $name );
		}

		if ( ! $this->policy->allows( $name ) ) {
			return new WP_Error(
				'ability_disabled',
				sprintf( 'The ability "%s" is disabled in the MCP exposure gate. Ask the site owner to enable it on Settings → MCP Server.', $name ),
				array( 'status' => 403 )
			);
		}

		// Reconcile the JSON input with the ability's schema the same way the adapter's
		// own execute tool does — e.g. an empty {} becomes null for a no-input ability,
		// which would otherwise be rejected as "input provided but no schema".
		$args = AbilityArgumentNormalizer::normalize( $ability, $params );

		$allowed = $ability->check_permissions( $args );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return new WP_Error(
				'forbidden',
				sprintf( 'You do not have permission to run "%s".', $name ),
				array( 'status' => 403 )
			);
		}

		return $ability->execute( $args );
	}

	/**
	 * Splits a query into discriminating words: drops stopwords and 1-2 char tokens.
	 *
	 * @param string $query The lowercased query.
	 * @return list<string> The significant words (falls back to all 1+ char words if every word was a stopword).
	 */
	private static function significantWords( string $query ): array {
		$all = preg_split( '/[^a-z0-9]+/', $query, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		$kept = array();
		foreach ( $all as $word ) {
			if ( strlen( $word ) < 3 || in_array( $word, self::STOPWORDS, true ) ) {
				continue;
			}

			$kept[] = $word;
		}

		// A query that is all stopwords (e.g. "get it") still gets something to match on.
		return empty( $kept ) ? $all : $kept;
	}

	/**
	 * Scores one ability against the query (0 = no match).
	 *
	 * @param list<string> $words       The lowercased query words.
	 * @param string       $phrase      The full lowercased query.
	 * @param string       $name        The ability name.
	 * @param string       $label       The ability label.
	 * @param string       $description The ability description.
	 * @param string       $keywords    The space-joined keywords meta.
	 * @return float The relevance score.
	 */
	private function score( array $words, string $phrase, string $name, string $label, string $description, string $keywords ): float {
		if ( empty( $words ) ) {
			return 0.0;
		}

		$fields = array(
			'name'        => strtolower( $name ),
			'label'       => strtolower( $label ),
			'keywords'    => strtolower( $keywords ),
			'description' => strtolower( $description ),
		);

		$score   = 0.0;
		$matched = 0;
		foreach ( $words as $word ) {
			$hit = false;
			foreach ( self::FIELD_WEIGHTS as $field => $weight ) {
				if ( '' === $fields[ $field ] || false === strpos( $fields[ $field ], $word ) ) {
					continue;
				}

				$score += $weight;
				$hit    = true;
			}
			if ( ! $hit ) {
				continue;
			}

			++$matched;
		}

		if ( 0 === $matched ) {
			return 0.0;
		}

		// Reward covering more of the query, and a full-phrase hit in the name/label.
		$score *= $matched / count( $words );
		if ( false !== strpos( $fields['name'] . ' ' . $fields['label'], $phrase ) ) {
			$score += 10.0;
		}

		return $score;
	}

	/**
	 * Quietly looks up an ability by name from the registry.
	 *
	 * Uses the keyed registry array rather than `wp_get_ability()`, which emits a
	 * `_doing_it_wrong` notice on a miss — expected noise here, since an agent routinely
	 * probes guessed names that this server answers with a recoverable error.
	 *
	 * @param string $name The full ability name.
	 * @return \WP_Ability|null The ability, or null when it is not registered.
	 */
	private function lookup( string $name ): ?\WP_Ability {
		$abilities = wp_get_abilities();

		return $abilities[ $name ] ?? null;
	}

	/**
	 * The shared "unknown ability" recoverable error, pointing at search.
	 *
	 * @param string $name The unknown name.
	 * @return \WP_Error
	 */
	private function unknown( string $name ): WP_Error {
		return new WP_Error(
			'unknown_ability',
			sprintf( 'No ability named "%s". Use the "search-abilities" tool to find the correct name.', $name ),
			array( 'status' => 404 )
		);
	}
}
