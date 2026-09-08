<?php
/**
 * Tests for the per-call consent gate in front of every non-readonly execute.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\ExecutionConsent;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use stdClass;
use WP\MCP\Core\McpRequestContext;
use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\McpSchema\Schemas;
use WP_Abilities_Registry;
use WP_Ability;

/**
 * Proves what satisfies the gate, and — more importantly — what does not.
 *
 * The gate has two mechanisms, chosen by what the client can do: an MCP `2026-07-28`
 * elicitation round trip, or an asserted `user_confirmed` flag for every other client.
 * Each has its own failure surface, and the elicitation one is where the security work
 * lives: the sealed state must reject a retry whose arguments changed, whose signature
 * was edited, or which arrived too late.
 *
 * Abilities are registered as throwaways rather than borrowed from the live catalog, so
 * each annotation shape under test (readonly, destructive, none at all) is exact and does
 * not move when the catalog does.
 *
 * Adapter-dependent: the call context is a vendor type, so this skips when the bundle is
 * absent.
 */
final class ExecutionConsentTest extends TestCase {

	/**
	 * A throwaway read: annotated `readonly`, so the gate must never stop it.
	 */
	private const READ = 'consenttest/read';

	/**
	 * A throwaway write: annotated `destructive`, the ordinary gated case.
	 */
	private const WRITE = 'consenttest/write';

	/**
	 * A throwaway carrying no annotations at all — unclassified, so gated fail-closed.
	 */
	private const UNCLASSIFIED = 'consenttest/unclassified';

	/**
	 * The mechanisms recorded by the audit hook during one test.
	 *
	 * @var list<array{string,string,string,int}>
	 */
	private array $recorded = array();

	/**
	 * Registers the three throwaway abilities and starts recording the audit hook.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( McpToolCallContext::class ) ) {
			$autoload = TESTS_REPO_ROOT_DIR . '/vendor/autoload_packages.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}

		if ( ! class_exists( McpToolCallContext::class ) ) {
			$this->markTestSkipped( 'The mcp-adapter vendor bundle is not installed; run composer install.' );
		}

		$this->register( self::READ, array( 'readonly' => true, 'idempotent' => true ) );
		$this->register( self::WRITE, array( 'readonly' => false, 'destructive' => true ) );
		$this->register( self::UNCLASSIFIED, null );

		$this->recorded = array();
		add_action( 'abilities_catalog_ability_confirmed', array( $this, 'record' ), 10, 4 );

		$this->actingAs( 'administrator' );
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		remove_action( 'abilities_catalog_ability_confirmed', array( $this, 'record' ), 10 );

		foreach ( array( self::READ, self::WRITE, self::UNCLASSIFIED ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		parent::tear_down();
	}

	/**
	 * Captures one audit-hook firing.
	 *
	 * @param string $ability   The ability name.
	 * @param string $digest    The operation digest.
	 * @param string $mechanism The mechanism that satisfied the gate.
	 * @param int    $user_id   The acting user.
	 * @return void
	 */
	public function record( string $ability, string $digest, string $mechanism, int $user_id ): void {
		$this->recorded[] = array( $ability, $digest, $mechanism, $user_id );
	}

	/**
	 * A read passes untouched; a write and an unclassified ability both need consent.
	 *
	 * @return void
	 */
	public function test_only_non_readonly_abilities_need_consent(): void {
		$consent = new ExecutionConsent();

		$this->assertFalse( $consent->requires( $this->ability( self::READ ) ) );
		$this->assertTrue( $consent->requires( $this->ability( self::WRITE ) ) );
		$this->assertTrue(
			$consent->requires( $this->ability( self::UNCLASSIFIED ) ),
			'An ability with no annotations is unclassified, and an unclassified operation must be treated as a write.'
		);

		$this->assertNull(
			$consent->gate( $this->ability( self::READ ), array( 'note' => 'x' ), false, null ),
			'A read must pass without confirmation of any kind.'
		);
		$this->assertSame( array(), $this->recorded, 'A read is not a confirmed operation and must not be recorded.' );
	}

	/**
	 * Without a call context the flag is the only way through, and it is recorded.
	 *
	 * @return void
	 */
	public function test_flag_path_refuses_without_the_flag_and_records_with_it(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );

		$refused = $consent->gate( $ability, array( 'note' => 'x' ), false, null );
		$this->assertWPError( $refused );
		$this->assertSame( 'confirmation_required', $refused->get_error_code() );
		$this->assertStringContainsString( self::WRITE, $refused->get_error_message(), 'The error must name the ability that was refused.' );
		$this->assertStringContainsString( 'user_confirmed', $refused->get_error_message(), 'The error must name the field that unblocks it.' );

		$this->assertNull( $consent->gate( $ability, array( 'note' => 'x' ), true, null ) );
		$this->assertCount( 1, $this->recorded );
		$this->assertSame( self::WRITE, $this->recorded[0][0] );
		$this->assertSame( 'asserted', $this->recorded[0][2] );
		$this->assertSame( get_current_user_id(), $this->recorded[0][3] );
	}

	/**
	 * A client that cannot elicit takes the flag path, whatever revision it speaks.
	 *
	 * A 2025-11-25 request cannot carry MRTR at all, and a 2026-07-28 client that did not
	 * declare the capability would be answered with -32021 by the adapter, failing the
	 * whole call. Both must land on the flag instead.
	 *
	 * @return void
	 */
	public function test_clients_that_cannot_elicit_take_the_flag_path(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );

		$legacy = $this->context( Schemas::V2025_11_25, (object) array( 'elicitation' => new stdClass() ) );
		$this->assertWPError( $consent->gate( $ability, array( 'note' => 'x' ), false, $legacy ) );
		$this->assertNull( $consent->gate( $ability, array( 'note' => 'x' ), true, $legacy ) );

		$modern_incapable = $this->context( Schemas::V2026_07_28, new stdClass() );
		$this->assertWPError( $consent->gate( $ability, array( 'note' => 'x' ), false, $modern_incapable ) );
		$this->assertNull( $consent->gate( $ability, array( 'note' => 'x' ), true, $modern_incapable ) );
	}

	/**
	 * A capable client is asked, and the asserted flag never substitutes for the answer.
	 *
	 * @return void
	 */
	public function test_capable_client_is_asked_and_the_flag_does_not_substitute(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );

		$asked = $consent->gate( $ability, array( 'note' => 'x' ), true, $this->capable() );
		$this->assertInstanceOf(
			McpInputRequired::class,
			$asked,
			'A client that can answer a form must be asked, even when it also asserted the flag.'
		);
		$this->assertSame( array(), $this->recorded, 'An unanswered question is not consent and must not be recorded.' );

		$requests = $asked->input_requests();
		$this->assertArrayHasKey( 'confirm', $requests );
		$this->assertSame( 'elicitation/create', $requests['confirm']['method'] );
		$this->assertSame( 'form', $requests['confirm']['params']['mode'] );
		$this->assertSame( array( 'allow' ), $requests['confirm']['params']['requestedSchema']['required'] );
		$this->assertSame( 'boolean', $requests['confirm']['params']['requestedSchema']['properties']['allow']['type'] );
		$this->assertStringContainsString( self::WRITE, $requests['confirm']['params']['message'] );
		$this->assertStringContainsString( 'destructive', $requests['confirm']['params']['message'], 'The question must show the risk flags.' );
		$this->assertStringContainsString( '"note": "x"', $requests['confirm']['params']['message'], 'The question must show the exact input that would run.' );
		$this->assertIsString( $asked->request_state() );
	}

	/**
	 * An accepted answer under its own state runs the ability and records the mechanism.
	 *
	 * @return void
	 */
	public function test_accepted_answer_satisfies_the_gate(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );
		$input   = array( 'note' => 'x' );

		$asked = $consent->gate( $ability, $input, false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$answered = $consent->gate( $ability, $input, false, $this->answering( $asked->request_state(), 'accept', true ) );
		$this->assertNull( $answered );
		$this->assertCount( 1, $this->recorded );
		$this->assertSame( 'elicitation', $this->recorded[0][2] );
	}

	/**
	 * The state binds the exact arguments, so a retry that changed them is refused.
	 *
	 * This is what the elicitation path buys over the asserted flag: a question rendered
	 * from benign arguments cannot be answered into a different write.
	 *
	 * @return void
	 */
	public function test_state_is_bound_to_the_arguments_it_was_issued_for(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );

		$asked = $consent->gate( $ability, array( 'note' => 'harmless' ), false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$swapped = $consent->gate(
			$ability,
			array( 'note' => 'something else entirely' ),
			false,
			$this->answering( $asked->request_state(), 'accept', true )
		);
		$this->assertWPError( $swapped );
		$this->assertSame( 'confirmation_invalid', $swapped->get_error_code() );
		$this->assertSame( array(), $this->recorded );
	}

	/**
	 * Key order is not preserved across a round trip, so the binding ignores it.
	 *
	 * @return void
	 */
	public function test_binding_is_independent_of_input_key_order(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );

		$asked = $consent->gate( $ability, array( 'note' => 'x', 'extra' => array( 'b' => 2, 'a' => 1 ) ), false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$reordered = $consent->gate(
			$ability,
			array( 'extra' => array( 'a' => 1, 'b' => 2 ), 'note' => 'x' ),
			false,
			$this->answering( $asked->request_state(), 'accept', true )
		);
		$this->assertNull( $reordered, 'The same operation with reordered keys is the same operation.' );
	}

	/**
	 * An edited signature does not verify.
	 *
	 * @return void
	 */
	public function test_tampered_state_is_refused(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );
		$input   = array( 'note' => 'x' );

		$asked = $consent->gate( $ability, $input, false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$state    = (string) $asked->request_state();
		$tampered = substr( $state, 0, -1 ) . ( 'a' === substr( $state, -1 ) ? 'b' : 'a' );

		$result = $consent->gate( $ability, $input, false, $this->answering( $tampered, 'accept', true ) );
		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_invalid', $result->get_error_code() );
	}

	/**
	 * A state past its deadline is refused, and says so distinctly.
	 *
	 * @return void
	 */
	public function test_expired_state_is_refused(): void {
		$now     = 1000000000;
		$issuer  = new ExecutionConsent( static fn (): int => $now );
		$ability = $this->ability( self::WRITE );
		$input   = array( 'note' => 'x' );

		$asked = $issuer->gate( $ability, $input, false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$later  = new ExecutionConsent( static fn (): int => $now + 601 );
		$result = $later->gate( $ability, $input, false, $this->answering( $asked->request_state(), 'accept', true ) );
		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_expired', $result->get_error_code() );
	}

	/**
	 * Answers arriving without the state that framed them prove nothing.
	 *
	 * @return void
	 */
	public function test_answers_without_state_are_refused(): void {
		$consent = new ExecutionConsent();

		$result = $consent->gate(
			$this->ability( self::WRITE ),
			array( 'note' => 'x' ),
			false,
			$this->answering( null, 'accept', true )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_invalid', $result->get_error_code() );
	}

	/**
	 * A valid state with no answer yet repeats the question under the same state.
	 *
	 * Minting a new one on every attempt would let a client extend its own deadline
	 * indefinitely.
	 *
	 * @return void
	 */
	public function test_unanswered_retry_repeats_the_question_under_the_same_state(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );
		$input   = array( 'note' => 'x' );

		$asked = $consent->gate( $ability, $input, false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$again = $consent->gate( $ability, $input, false, $this->context( Schemas::V2026_07_28, $this->formCapability(), $asked->request_state(), new stdClass(), true ) );
		$this->assertInstanceOf( McpInputRequired::class, $again );
		$this->assertSame( $asked->request_state(), $again->request_state(), 'Repeating the question must not extend the deadline.' );
	}

	/**
	 * Declining, cancelling, and answering false all stop without an error.
	 *
	 * A person saying no is the call working, not the call failing: an error result would
	 * read as something to retry.
	 *
	 * @return void
	 */
	public function test_refusals_return_an_outcome_not_an_error(): void {
		$ability = $this->ability( self::WRITE );
		$input   = array( 'note' => 'x' );

		foreach ( array(
			'decline' => array( 'decline', null, 'declined' ),
			'cancel'  => array( 'cancel', null, 'cancelled' ),
			'false'   => array( 'accept', false, 'declined' ),
		) as $case => $expectation ) {
			$consent = new ExecutionConsent();
			$asked   = $consent->gate( $ability, $input, false, $this->capable() );
			$this->assertInstanceOf( McpInputRequired::class, $asked );

			$result = $consent->gate( $ability, $input, false, $this->answering( $asked->request_state(), $expectation[0], $expectation[1] ) );
			$this->assertSame( array( 'outcome' => $expectation[2] ), $result, sprintf( 'The "%s" case must report an outcome.', $case ) );
		}

		$this->assertSame( array(), $this->recorded, 'A refusal is not consent and must not be recorded.' );
	}

	/**
	 * A non-boolean answer is refused: the string "true" is not agreement.
	 *
	 * @return void
	 */
	public function test_non_boolean_answer_is_refused(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );
		$input   = array( 'note' => 'x' );

		$asked = $consent->gate( $ability, $input, false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$result = $consent->gate( $ability, $input, false, $this->answering( $asked->request_state(), 'accept', 'true' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_answer_invalid', $result->get_error_code() );
	}

	/**
	 * A state issued for one user does not verify for another.
	 *
	 * @return void
	 */
	public function test_state_is_bound_to_the_acting_user(): void {
		$consent = new ExecutionConsent();
		$ability = $this->ability( self::WRITE );
		$input   = array( 'note' => 'x' );

		$asked = $consent->gate( $ability, $input, false, $this->capable() );
		$this->assertInstanceOf( McpInputRequired::class, $asked );

		$this->actingAs( 'administrator' );

		$result = $consent->gate( $ability, $input, false, $this->answering( $asked->request_state(), 'accept', true ) );
		$this->assertWPError( $result );
		$this->assertSame( 'confirmation_invalid', $result->get_error_code() );
	}

	/**
	 * Fetches a registered throwaway.
	 *
	 * @param string $name The ability name.
	 * @return \WP_Ability The ability.
	 */
	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		return $ability;
	}

	/**
	 * Registers one throwaway ability with the given annotations.
	 *
	 * @param string                   $name        The ability name.
	 * @param array<string,bool>|null  $annotations The annotations, or null to register none at all.
	 * @return void
	 */
	private function register( string $name, ?array $annotations ): void {
		$registry = WP_Abilities_Registry::get_instance();
		$this->assertNotNull( $registry, 'The abilities registry must be available at test time.' );

		$registry->register(
			$name,
			array(
				'label'               => 'Consent fixture',
				'description'         => 'A throwaway ability used to exercise the consent gate.',
				'category'            => 'og-core-tools',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'note'  => array( 'type' => 'string' ),
						'extra' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => static fn ( $input ) => $input,
				'permission_callback' => static fn (): bool => true,
				'meta'                => null === $annotations ? array() : array( 'annotations' => $annotations ),
			)
		);
	}

	/**
	 * A 2026-07-28 context declaring form elicitation and carrying no continuation.
	 *
	 * @return \WP\MCP\Domain\Tools\McpToolCallContext The context.
	 */
	private function capable(): McpToolCallContext {
		return $this->context( Schemas::V2026_07_28, $this->formCapability() );
	}

	/**
	 * A capable context carrying one answer to this gate's question.
	 *
	 * @param string|null $state  The state to echo back, or null to omit it.
	 * @param string      $action The elicitation action.
	 * @param mixed       $allow  The submitted `allow` value, or null to submit no content.
	 * @return \WP\MCP\Domain\Tools\McpToolCallContext The context.
	 */
	private function answering( ?string $state, string $action, $allow ): McpToolCallContext {
		$answer = new stdClass();
		$answer->action = $action;
		if ( null !== $allow ) {
			$answer->content = (object) array( 'allow' => $allow );
		}

		return $this->context( Schemas::V2026_07_28, $this->formCapability(), $state, (object) array( 'confirm' => $answer ), true );
	}

	/**
	 * The capability object a client declares for form elicitation.
	 *
	 * @return \stdClass The capabilities.
	 */
	private function formCapability(): stdClass {
		return (object) array( 'elicitation' => (object) array( 'form' => new stdClass() ) );
	}

	/**
	 * Builds a tool call context the way the adapter does after schema validation.
	 *
	 * @param string         $revision     The MCP revision.
	 * @param \stdClass      $capabilities The client's declared capabilities.
	 * @param string|null    $state        The echoed continuation state.
	 * @param \stdClass|null $responses    The client's answers.
	 * @param bool           $continuation Whether the client supplied continuation fields.
	 * @return \WP\MCP\Domain\Tools\McpToolCallContext The context.
	 */
	private function context( string $revision, stdClass $capabilities, ?string $state = null, ?stdClass $responses = null, bool $continuation = false ): McpToolCallContext {
		$request = new McpRequestContext(
			Schemas::create()->forVersion( $revision ),
			$capabilities,
			null,
			'HTTP'
		);

		return new McpToolCallContext( $request, $responses ?? new stdClass(), $state, $continuation );
	}
}
