<?php
/**
 * Conformance and anti-rot guards for the shipped `core` knowledge bundle.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Knowledge;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\Concept;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Two standing guards over the shipped bundle. Conformance: every shipped concept
 * parses and carries a non-empty type, and the expected concepts are present.
 * Anti-rot (the replacement for the deleted per-recipe entry tests): every ability a
 * `Skill` or `Guideline` body routes through a domain tool — written as
 * `<domain> execute <ability>` — must be a live registered ability owned by that exact
 * domain, so a renamed or remapped ability fails here.
 */
final class BundleConformanceTest extends TestCase {

	/**
	 * Scans the shipped `core` bundle for each test.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\Knowledge\KnowledgeBundle
	 */
	private KnowledgeBundle $bundle;

	/**
	 * Builds the bundle from the shipped directory.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$bundle = KnowledgeBundle::fromDirectory( ABILITIES_CATALOG_DIR . 'includes/knowledge', 'core' );
		$this->assertNotWPError( $bundle, 'The shipped core bundle must scan without error.' );
		$this->bundle = $bundle;
	}

	/**
	 * The shipped bundle carries the nine migrated and authored concepts.
	 *
	 * @return void
	 */
	public function test_ships_the_expected_concepts(): void {
		$ids = array_map( static fn ( Concept $c ) => $c->id(), $this->bundle->children() );

		sort( $ids );
		$this->assertSame(
			array(
				'authoring-conventions',
				'back-up-content',
				'configure-reading',
				'create-content',
				'edit-an-image',
				'find-content',
				'moderate-comments',
				'organize-with-terms',
				'overview',
			),
			$ids
		);
	}

	/**
	 * Every shipped concept parses with a non-empty type and a readable body.
	 *
	 * @return void
	 */
	public function test_every_concept_conforms(): void {
		foreach ( $this->bundle->children() as $concept ) {
			$this->assertNotEmpty( $concept->type(), sprintf( 'Concept "%s" must declare a type.', $concept->id() ) );
			$this->assertNotEmpty( $concept->title(), sprintf( 'Concept "%s" must have a title.', $concept->id() ) );
			$this->assertIsString( $concept->body(), sprintf( 'Concept "%s" must have a readable body.', $concept->id() ) );
			$this->assertNotEmpty( $concept->body(), sprintf( 'Concept "%s" body must be non-empty.', $concept->id() ) );
		}
	}

	/**
	 * Every ability a Skill/Guideline body routes is live and owned by its stated domain.
	 *
	 * Abilities are cited in the canonical call form `<domain> execute <ability>`; this
	 * parses those pairs and checks each ability against the live registry and the
	 * domain map, the same guard the deleted per-recipe tests performed.
	 *
	 * @return void
	 */
	public function test_routed_abilities_are_live_and_domain_owned(): void {
		$map   = new DomainMap();
		$pairs = 0;

		foreach ( $this->bundle->children() as $concept ) {
			if ( ! in_array( $concept->type(), array( 'Skill', 'Guideline' ), true ) ) {
				continue;
			}

			preg_match_all(
				'/\b([a-z][a-z0-9-]*) execute ([a-z][a-z0-9-]*\/[a-z][a-z0-9-]*)/',
				(string) $concept->body(),
				$matches,
				PREG_SET_ORDER
			);

			foreach ( $matches as $match ) {
				++$pairs;
				$domain  = $match[1];
				$ability = $match[2];

				$this->assertTrue(
					wp_has_ability( $ability ),
					sprintf( '"%s" cites "%s", which is not a registered ability.', $concept->id(), $ability )
				);
				$this->assertSame(
					$domain,
					$map->domainOf( $ability ),
					sprintf( '"%s" routes "%s" through the "%s" tool, but the map owns it elsewhere.', $concept->id(), $ability, $domain )
				);
			}
		}

		$this->assertGreaterThan( 0, $pairs, 'The anti-rot guard must find ability references to check.' );
	}
}
