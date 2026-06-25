<?php
/**
 * Integration tests for the domain router against real abilities.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter;
use GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises list / describe / execute against the registered content abilities,
 * including the low-privilege execute denial that proves capability is the guard and
 * the deny-by-default exposure gate that refuses an ability the owner has not enabled.
 */
final class DomainRouterTest extends TestCase {

	/**
	 * A router whose exposure gate enables nothing (the shipped deny-by-default state).
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter
	 */
	private DomainRouter $router;

	/**
	 * Builds a fresh deny-by-default router for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->router = $this->routerWith( array() );
	}

	/**
	 * Clears the exposure option so each test starts from the shipped default.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );
		parent::tear_down();
	}

	/**
	 * Builds a router whose exposure gate enables exactly the given abilities.
	 *
	 * The gate reads the option once on first use, so the option must be set before the
	 * policy resolves; building a fresh policy here guarantees that.
	 *
	 * @param list<string> $enabled The ability names to enable.
	 * @return \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter The router.
	 */
	private function routerWith( array $enabled ): DomainRouter {
		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, $enabled );

		return new DomainRouter( new DomainMap(), new ExposurePolicy() );
	}

	/**
	 * list returns content abilities, each carrying the three risk flags.
	 *
	 * @return void
	 */
	public function test_list_returns_content_abilities_with_flags(): void {
		$items = $this->router->list( 'content' );
		$names = array_column( $items, 'name' );

		$this->assertContains( 'og-content/get-post', $names );
		$this->assertContains( 'og-terms/create-category', $names );
		$this->assertContains( 'og-comments/get-comment', $names );
		$this->assertContains( 'og-search/search-content', $names );

		foreach ( $items as $item ) {
			$this->assertArrayHasKey( 'readonly', $item );
			$this->assertArrayHasKey( 'destructive', $item );
			$this->assertArrayHasKey( 'dangerous', $item );
			$this->assertArrayHasKey( 'enabled', $item );
		}

		$this->assertTrue( $this->itemNamed( $items, 'og-content/get-post' )['readonly'] );
	}

	/**
	 * list reports each ability's exposure state, hiding none.
	 *
	 * The gate is deny-by-default, so an enabled ability reads `enabled: true` while the
	 * rest stay `false` — and both still appear in the index.
	 *
	 * @return void
	 */
	public function test_list_marks_each_ability_enabled_state(): void {
		$router = $this->routerWith( array( 'og-content/get-post' ) );
		$items  = $router->list( 'content' );

		$this->assertTrue( $this->itemNamed( $items, 'og-content/get-post' )['enabled'] );
		$this->assertFalse( $this->itemNamed( $items, 'og-content/create-post' )['enabled'] );
	}

	/**
	 * Every curated domain lists a non-empty, correctly-scoped set of abilities.
	 *
	 * This is the per-domain coverage check: each curated domain tool owns real
	 * abilities, and `list` never leaks one that belongs to another domain.
	 *
	 * @dataProvider curatedDomains
	 *
	 * @param string $domain The curated domain slug.
	 * @return void
	 */
	public function test_each_domain_lists_only_its_own_abilities( string $domain ): void {
		$map   = new DomainMap();
		$items = $this->router->list( $domain );

		$this->assertNotEmpty( $items, sprintf( 'Domain "%s" should list at least one ability.', $domain ) );

		foreach ( $items as $item ) {
			$this->assertSame(
				$domain,
				$map->domainOf( $item['name'] ),
				sprintf( 'Ability "%s" listed under "%s" maps to a different domain.', $item['name'], $domain )
			);
		}
	}

	/**
	 * The curated domain slugs, one per data row.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function curatedDomains(): array {
		return array(
			'content'     => array( 'content' ),
			'media'       => array( 'media' ),
			'appearance'  => array( 'appearance' ),
			'design'      => array( 'design' ),
			'plugins'     => array( 'plugins' ),
			'users'       => array( 'users' ),
			'settings'    => array( 'settings' ),
			'tools'       => array( 'tools' ),
			'site-health' => array( 'site-health' ),
			'updates'     => array( 'updates' ),
			'dashboard'   => array( 'dashboard' ),
			'network'     => array( 'network' ),
		);
	}

	/**
	 * list never leaks an ability from another domain.
	 *
	 * @return void
	 */
	public function test_list_excludes_other_domains(): void {
		$map   = new DomainMap();
		$names = array_column( $this->router->list( 'content' ), 'name' );

		$this->assertNotContains( 'og-media/list-image-sizes', $names );
		foreach ( $names as $name ) {
			$this->assertSame( 'content', $map->domainOf( $name ) );
		}
	}

	/**
	 * describe returns the schemas and annotations for an in-domain ability.
	 *
	 * @return void
	 */
	public function test_describe_returns_schema_and_annotations(): void {
		$description = $this->router->describe( 'content', 'og-content/get-post' );

		$this->assertIsArray( $description );
		$this->assertSame( 'og-content/get-post', $description['name'] );
		$this->assertArrayHasKey( 'input_schema', $description );
		$this->assertArrayHasKey( 'output_schema', $description );
		$this->assertTrue( $description['annotations']['readonly'] ?? false );
	}

	/**
	 * describe appends the "disabled" note for a gated-off ability, and omits it once enabled.
	 *
	 * describe never refuses — the agent must still be able to study a disabled ability —
	 * so the note in the description is how the gate state reaches the agent there.
	 *
	 * @return void
	 */
	public function test_describe_notes_disabled_state(): void {
		$gated = $this->router->describe( 'content', 'og-content/get-post' );
		$this->assertIsArray( $gated );
		$this->assertStringContainsString( 'currently disabled', $gated['description'] );

		$enabled = $this->routerWith( array( 'og-content/get-post' ) )->describe( 'content', 'og-content/get-post' );
		$this->assertIsArray( $enabled );
		$this->assertStringNotContainsString( 'currently disabled', $enabled['description'] );
	}

	/**
	 * describe refuses an ability that belongs to a different domain.
	 *
	 * @return void
	 */
	public function test_describe_rejects_out_of_domain_ability(): void {
		$error = $this->router->describe( 'content', 'og-media/list-image-sizes' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_unknown_ability', $error->get_error_code() );
	}

	/**
	 * describe refuses an empty ability name.
	 *
	 * @return void
	 */
	public function test_describe_rejects_empty_ability(): void {
		$error = $this->router->describe( 'content', '' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_missing_ability', $error->get_error_code() );
	}

	/**
	 * An in-domain-but-unregistered name errors cleanly (no core notice).
	 *
	 * @return void
	 */
	public function test_describe_rejects_unregistered_name_without_notice(): void {
		$error = $this->router->describe( 'content', 'content/does-not-exist' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_unknown_ability', $error->get_error_code() );
	}

	/**
	 * An unregistered name directs the agent to the authoritative discovery path.
	 *
	 * The unknown-ability 404 is the dominant friction surface for a guessing agent; the
	 * message must point at the exact path that resolves any wrong name and its inputs —
	 * `list` for the exact names, then `describe` for the input schema — so the agent stops
	 * guessing and reads instead.
	 *
	 * @return void
	 */
	public function test_unregistered_ability_message_points_to_list_and_describe(): void {
		$error = $this->router->describe( 'content', 'content/totally-made-up-name' );

		$this->assertWPError( $error );
		$this->assertStringContainsString( 'action "list"', $error->get_error_message() );
		$this->assertStringContainsString( 'describe', $error->get_error_message() );
		$this->assertStringContainsString( 'content', $error->get_error_message() );
	}

	/**
	 * The server never guesses the intended name — no fuzzy "Did you mean" suggestion.
	 *
	 * A near-miss typo and a far-off name both get the same authoritative directive: read the
	 * exact names from `list`. The server does not steer the agent with an edit-distance guess,
	 * which can point at a wrong-but-close ability.
	 *
	 * @return void
	 */
	public function test_unknown_ability_never_suggests_a_guess(): void {
		// "content/get-pots" is one edit from the real "og-content/get-post" — still no suggestion.
		$near = $this->router->describe( 'content', 'content/get-pots' );
		$far  = $this->router->describe( 'content', 'content/zzzzzzzzzzzzzzzzzzzz' );

		foreach ( array( $near, $far ) as $error ) {
			$this->assertWPError( $error );
			$this->assertStringContainsString( 'action "list"', $error->get_error_message() );
			$this->assertStringNotContainsString( 'Did you mean', $error->get_error_message() );
		}
	}

	/**
	 * The out-of-domain error also points the agent at `list`, so a wrong-prefix guess
	 * (e.g. appearance/list-themes when theme abilities are themes/*) is recoverable.
	 *
	 * @return void
	 */
	public function test_out_of_domain_message_points_to_list(): void {
		$error = $this->router->describe( 'content', 'og-media/list-image-sizes' );

		$this->assertWPError( $error );
		$this->assertSame( 'abilities_catalog_mcp_unknown_ability', $error->get_error_code() );
		$this->assertStringContainsString( 'action "list"', $error->get_error_message() );
	}

	/**
	 * The unknown-ability error names the prefixes the domain owns, so the dominant guess
	 * (tool name != ability prefix) recovers to a real prefix without a round-trip.
	 *
	 * `content/search-posts` is the trace-proven miss: the agent reaches the content tool and
	 * invents that name, when the real ability is `og-search/search-content`. The error must name
	 * the owned prefixes (so the agent learns `terms/`, `comments/` belong here too) and the
	 * exact `og-search/search-content` placement — without ever offering an edit-distance guess.
	 *
	 * @return void
	 */
	public function test_unknown_ability_names_the_domain_owned_prefixes(): void {
		$message = $this->router->describe( 'content', 'content/search-posts' )->get_error_message();

		$this->assertStringContainsString( 'terms/*', $message, 'The owned prefixes orient the next guess.' );
		$this->assertStringContainsString( 'og-search/search-content', $message, 'The exact placement names the real search ability.' );
		$this->assertStringContainsString( 'action "list"', $message, 'The authoritative path still follows.' );
		$this->assertStringNotContainsString( 'Did you mean', $message, 'Naming the taxonomy is not a fuzzy suggestion.' );
	}

	/**
	 * execute runs an ability for a user with the capability.
	 *
	 * @return void
	 */
	public function test_execute_runs_ability_for_admin(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Hello' ) );

		$router = $this->routerWith( array( 'og-content/get-post' ) );
		$result = $router->execute( 'content', 'og-content/get-post', array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
	}

	/**
	 * execute runs a no-input ability called with an empty argument object.
	 *
	 * A no-input ability declares an empty `input_schema`, and core then rejects any
	 * non-null input with `ability_missing_input_schema`. MCP clients send `{}` for a
	 * no-argument call, which reaches the router as an empty array. The router must
	 * normalize that empty array to `null` so the call dispatches instead of erroring.
	 *
	 * @return void
	 */
	public function test_execute_runs_no_input_ability_with_empty_arguments(): void {
		$this->actingAs( 'administrator' );

		$router = $this->routerWith( array( 'og-dashboard/get-at-a-glance' ) );
		$result = $router->execute( 'dashboard', 'og-dashboard/get-at-a-glance', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'wp_version', $result );
	}

	/**
	 * execute refuses an ability the owner has not enabled, before any capability check.
	 *
	 * Deny-by-default: even an administrator gets the gate error, which names the ability
	 * and points at the settings page so a human can enable it.
	 *
	 * @return void
	 */
	public function test_execute_refuses_disabled_ability(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create();

		$result = $this->router->execute( 'content', 'og-content/get-post', array( 'id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_ability_disabled', $result->get_error_code() );
		$this->assertStringContainsString( 'options-general.php?page=abilities-catalog-mcp', $result->get_error_message() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * execute denies a user without the ability's capability (capability is the guard).
	 *
	 * The ability is enabled first so the gate lets the call through to the capability
	 * check — the exposure gate sits in front of, never replaces, the capability guard.
	 *
	 * @return void
	 */
	public function test_execute_denies_low_privilege_user(): void {
		$this->actingAs( 'subscriber' );

		$router = $this->routerWith( array( 'og-content/create-post' ) );
		$result = $router->execute( 'content', 'og-content/create-post', array( 'title' => 'Nope' ) );

		$this->assertWPError( $result );
		// The router pre-checks the capability and returns its own 403 'forbidden'
		// (mirroring AbilityIndex), rather than letting core's execute() genericize it.
		$this->assertSame( 'forbidden', $result->get_error_code() );
		// The denial is recovery-oriented: it states the cause and the next step.
		$this->assertStringContainsString( 'administrator', $result->get_error_message() );
	}

	/**
	 * A capability denial while targeting a site by blog_id points at og-users/list-my-sites.
	 *
	 * The usual cause is that the caller is not a member of the targeted site, so the
	 * recovery matches the invalid-blog_id error: list the sites you can act on. The hint
	 * is added only when the input carried a blog_id.
	 *
	 * @return void
	 */
	public function test_execute_denial_with_blog_id_points_to_list_my_sites(): void {
		$this->actingAs( 'subscriber' );

		$router = $this->routerWith( array( 'og-content/create-post' ) );
		$result = $router->execute( 'content', 'og-content/create-post', array( 'title' => 'Nope', 'blog_id' => 2 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertStringContainsString( 'og-users/list-my-sites', $result->get_error_message() );
	}

	/**
	 * An invalid input shape points the caller at `describe` for the exact schema.
	 *
	 * When the agent guesses a field name, core rejects the input with `ability_invalid_input`.
	 * The router appends a `describe` hint naming the ability, so the agent recovers by reading
	 * the schema instead of guessing another field. The original error code is preserved.
	 *
	 * @return void
	 */
	public function test_execute_invalid_input_points_to_describe(): void {
		$this->actingAs( 'administrator' );

		// "og-content/get-post" requires "id" and forbids extra properties, so an unknown field is
		// an invalid input shape — exactly the field-guessing case this hint serves.
		$router = $this->routerWith( array( 'og-content/get-post' ) );
		$result = $router->execute( 'content', 'og-content/get-post', array( 'not_a_real_field' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertStringContainsString( 'describe', $result->get_error_message() );
		$this->assertStringContainsString( 'og-content/get-post', $result->get_error_message() );
	}

	/**
	 * A non-input error (e.g. a capability denial) is not rewritten with a describe hint.
	 *
	 * `describe` cannot fix a permission failure, so the guidance is scoped to input-shape
	 * errors only — a denied caller must not be told to go read the schema.
	 *
	 * @return void
	 */
	public function test_execute_permission_error_has_no_describe_hint(): void {
		$this->actingAs( 'subscriber' );

		$router = $this->routerWith( array( 'og-content/create-post' ) );
		$result = $router->execute( 'content', 'og-content/create-post', array( 'title' => 'Nope' ) );

		$this->assertWPError( $result );
		// The router's permission pre-check returns 'forbidden' (a non-input-shape error),
		// so guideInvalidInput never appends the describe hint.
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertStringNotContainsString( 'describe', $result->get_error_message() );
	}

	/**
	 * Returns the list item with the given ability name, failing if absent.
	 *
	 * @param array<int,array<string,mixed>> $items The list items.
	 * @param string                         $name  The ability name to find.
	 * @return array<string,mixed> The matching item.
	 */
	private function itemNamed( array $items, string $name ): array {
		foreach ( $items as $item ) {
			if ( $item['name'] === $name ) {
				return $item;
			}
		}

		$this->fail( sprintf( 'List did not contain "%s".', $name ) );
	}
}
