<?php
/**
 * The per-call consent gate in front of every non-readonly execute.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\McpSchema\Schemas;
use WP_Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers one question: may this ability run right now, and if not, what is missing?
 *
 * The catalog already carries two guards. Capability is the hard one, enforced by every
 * ability's own `permission_callback`. The {@see ExposurePolicy} is the site owner's
 * standing decision about which abilities an MCP client may reach at all. Neither says
 * anything about *this* call: an agent holding an administrator's credentials can run
 * every enabled write without a person seeing it happen. This gate is that third,
 * per-call layer — consent for the operation in front of us, with its exact arguments.
 *
 * It applies to any ability not annotated `readonly` (an ability carrying no annotations
 * at all is gated too — unclassified is treated as a write). It never applies to reads.
 *
 * Which mechanism satisfies it is decided by what the client can actually do, so each
 * client is held to the strongest thing available to it:
 *
 * - **Elicitation** ({@see https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr}),
 *   when the request is MCP `2026-07-28` and the client declared form elicitation. The
 *   first call returns an `input_required` result carrying the question; the client shows
 *   a form, and retries the same call with the answer. On that path an asserted flag
 *   never suffices — the question is issued instead.
 * - **An asserted flag** otherwise (a `2026-07-28` client without the capability, or any
 *   `2025-11-25` request, where MRTR does not exist). The client re-sends the call with
 *   `user_confirmed: true`, the same contract the WordPress.com MCP surfaces use.
 *
 * The honest limit, in both directions: neither mechanism proves a human agreed. An
 * `accept` answer is consent *reported by the client*, and a flag is consent *asserted by
 * the agent*. What the elicitation path adds is binding — see {@see seal()} — so that the
 * arguments which execute are provably the arguments the question was rendered from, and
 * a benign question cannot be swapped for a different write on the retry. Every satisfied
 * gate fires `abilities_catalog_ability_confirmed` naming the mechanism, so a site can
 * keep the trail.
 *
 * @since 0.4.0
 */
final class ExecutionConsent {

	/**
	 * The key this gate issues its one input request under, and reads the answer back from.
	 */
	private const REQUEST_KEY = 'confirm';

	/**
	 * How long an issued question stays answerable, in seconds.
	 *
	 * Long enough for a person to read the arguments and decide, short enough that an
	 * approval does not outlive the conversation that produced it.
	 */
	private const STATE_TTL = 600;

	/**
	 * Binds a sealed state to this gate, so a token minted elsewhere never verifies here.
	 */
	private const STATE_CONTEXT = SearchServer::SERVER_ID . '|execute-ability';

	/**
	 * Longest input rendering shown in the question before it is truncated.
	 *
	 * Truncation weakens what the person *reads*, never what is enforced: the signature
	 * always covers the whole input, so a truncated display cannot hide a swapped argument.
	 */
	private const INPUT_DISPLAY_CAP = 2000;

	/**
	 * Reads the current time, so expiry is testable.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * @param callable(): int|null $clock Current unix time; defaults to `time()`.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Reports whether an ability needs consent before it runs.
	 *
	 * Fail-closed: only an explicit `readonly: true` is exempt. An ability with no
	 * annotations is unclassified, and an unclassified operation is treated as a write.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return bool True when a call to it must be confirmed.
	 */
	public function requires( WP_Ability $ability ): bool {
		return true !== ( self::annotations( $ability )['readonly'] ?? null );
	}

	/**
	 * Decides whether this call may proceed.
	 *
	 * Returns `null` to proceed. Any other value is the tool's result and must be returned
	 * to the client verbatim: an {@see McpInputRequired} asking the question, a
	 * {@see WP_Error} naming what is missing and how to supply it, or an ordinary array
	 * reporting that the person refused.
	 *
	 * @param \WP_Ability                                     $ability   The ability about to run.
	 * @param mixed                                        $input     Its resolved input; a no-input ability normalizes to null.
	 * @param bool                                            $confirmed Whether the call asserted `user_confirmed`.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext|null    $context   The MCP call context, when the adapter supplied one.
	 * @return \WP_Error|\WP\MCP\Domain\Tools\McpInputRequired|array<string, string>|null Null to proceed; otherwise the tool result.
	 */
	public function gate( WP_Ability $ability, $input, bool $confirmed, ?McpToolCallContext $context ) {
		if ( ! $this->requires( $ability ) ) {
			return null;
		}

		// A no-input ability normalizes to null rather than an empty array; both describe
		// the same operation, so both digest and render the same way.
		$input  = is_array( $input ) ? $input : array();
		$digest = self::digest( $ability->get_name(), $input );

		if ( null !== $context && self::canElicit( $context ) ) {
			return $this->elicit( $ability, $input, $digest, $context );
		}

		if ( ! $confirmed ) {
			return new WP_Error(
				'confirmation_required',
				sprintf(
					'"%1$s" changes this site, so it needs the user\'s confirmation. Tell the user exactly what it will do, and only if they agree, call execute-ability again with the same "name" and "input" plus "user_confirmed": true. Do not set that flag on your own initiative.',
					$ability->get_name()
				),
				array( 'status' => 403 )
			);
		}

		$this->recordConsent( $ability, $digest, 'asserted' );

		return null;
	}

	/**
	 * Runs the elicitation round trip: issue the question, then verify the answer.
	 *
	 * @param \WP_Ability                            $ability The ability about to run.
	 * @param array<string,mixed>                    $input   Its resolved input.
	 * @param string                                 $digest  The digest binding this operation.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext $context The MCP call context.
	 * @return \WP_Error|\WP\MCP\Domain\Tools\McpInputRequired|array<string, string>|null Null to proceed; otherwise the tool result.
	 */
	private function elicit( WP_Ability $ability, array $input, string $digest, McpToolCallContext $context ) {
		$state = $context->request_state();

		if ( null === $state ) {
			// Answers without the state that framed them prove nothing about which
			// operation was approved, so they are never accepted on their own.
			if ( $context->is_continuation() ) {
				return new WP_Error(
					'confirmation_invalid',
					sprintf( 'The confirmation for "%s" arrived without its "requestState". Call execute-ability again with the same "name" and "input" and no continuation fields to get a fresh question.', $ability->get_name() ),
					array( 'status' => 400 )
				);
			}

			return $this->ask( $ability, $input, $digest );
		}

		$payload = self::unseal( $state );
		if ( null === $payload || $payload['d'] !== $digest ) {
			return new WP_Error(
				'confirmation_invalid',
				sprintf( 'The "requestState" does not match this call to "%s". Echo it back exactly as issued, with the same "name" and "input"; if the arguments changed, call execute-ability again with no continuation fields to get a fresh question.', $ability->get_name() ),
				array( 'status' => 400 )
			);
		}

		if ( $payload['x'] <= ( $this->clock )() ) {
			return new WP_Error(
				'confirmation_expired',
				sprintf( 'The confirmation for "%s" expired. Call execute-ability again with the same "name" and "input" and no continuation fields to ask again.', $ability->get_name() ),
				array( 'status' => 400 )
			);
		}

		$answer = self::answer( $context );
		if ( null === $answer ) {
			// The person has not answered yet. Repeat the same question under the same
			// state rather than minting a new one, so the deadline is not extended.
			return $this->ask( $ability, $input, $digest, $state );
		}

		$action = isset( $answer->action ) && is_string( $answer->action ) ? $answer->action : '';
		if ( 'decline' === $action || 'cancel' === $action ) {
			return array( 'outcome' => 'decline' === $action ? 'declined' : 'cancelled' );
		}

		$allow = isset( $answer->content ) && $answer->content instanceof \stdClass ? ( $answer->content->allow ?? null ) : null;
		if ( 'accept' !== $action || ! is_bool( $allow ) ) {
			return new WP_Error(
				'confirmation_answer_invalid',
				sprintf( 'The answer to the confirmation for "%s" was not usable. Submit "%s" as {"action": "accept", "content": {"allow": true}} — the string "true" is not a boolean — or as {"action": "decline"}.', $ability->get_name(), self::REQUEST_KEY ),
				array( 'status' => 400 )
			);
		}

		if ( false === $allow ) {
			return array( 'outcome' => 'declined' );
		}

		$this->recordConsent( $ability, $digest, 'elicitation' );

		return null;
	}

	/**
	 * Builds the input-required result carrying the confirmation question.
	 *
	 * The message names the ability, its risk flags, and the exact arguments, because the
	 * signature in the state binds those same arguments: what the person reads is what
	 * runs. The requested schema is one boolean, the flat primitive shape elicitation
	 * forms allow.
	 *
	 * @param \WP_Ability         $ability The ability about to run.
	 * @param array<string,mixed> $input   Its resolved input.
	 * @param string              $digest  The digest binding this operation.
	 * @param string|null         $state   An existing state to repeat under, or null to mint one.
	 * @return \WP\MCP\Domain\Tools\McpInputRequired The question.
	 */
	private function ask( WP_Ability $ability, array $input, string $digest, ?string $state = null ): McpInputRequired {
		$label = $ability->get_label();
		$label = '' === $label ? $ability->get_name() : $label;

		$rendered = (string) wp_json_encode( $input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $rendered ) > self::INPUT_DISPLAY_CAP ) {
			$rendered = substr( $rendered, 0, self::INPUT_DISPLAY_CAP ) . "\n… (truncated for display; the full input is what will run)";
		}

		$message = sprintf(
			"Run \"%1\$s\" (%2\$s) on this site?\n\nRisk: %3\$s\n\nInput:\n%4\$s",
			$label,
			$ability->get_name(),
			self::risk( $ability ),
			$rendered
		);

		return new McpInputRequired(
			array(
				self::REQUEST_KEY => array(
					'method' => 'elicitation/create',
					'params' => array(
						'mode'            => 'form',
						'message'         => $message,
						'requestedSchema' => array(
							'type'       => 'object',
							'properties' => array(
								'allow' => array(
									'type'        => 'boolean',
									'title'       => sprintf( 'Run %s', $label ),
									'description' => 'Approve this exact operation. It will not run unless you approve it.',
								),
							),
							'required'   => array( 'allow' ),
						),
					),
				),
			),
			$state ?? $this->seal( $digest )
		);
	}

	/**
	 * Reports whether this client can answer a form elicitation request.
	 *
	 * Mirrors the check the adapter itself applies before sending one: MRTR exists only
	 * under `2026-07-28`, and an empty `elicitation` object declares form mode. Getting
	 * this wrong in the permissive direction is not a soft failure — the adapter answers
	 * an unanswerable request with `-32021` and HTTP 400, failing the whole call.
	 *
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext $context The MCP call context.
	 * @return bool True when a question can be asked.
	 */
	private static function canElicit( McpToolCallContext $context ): bool {
		if ( Schemas::V2026_07_28 !== $context->revision() ) {
			return false;
		}

		$elicitation = $context->client_capabilities()->elicitation ?? null;
		if ( ! $elicitation instanceof \stdClass ) {
			return false;
		}

		return isset( $elicitation->form ) || array() === get_object_vars( $elicitation );
	}

	/**
	 * Pulls this gate's answer out of the client's response map.
	 *
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext $context The MCP call context.
	 * @return \stdClass|null The answer, or null when the client has not answered this question.
	 */
	private static function answer( McpToolCallContext $context ): ?\stdClass {
		$answer = $context->input_responses()->{self::REQUEST_KEY} ?? null;

		return $answer instanceof \stdClass ? $answer : null;
	}

	/**
	 * Renders an ability's risk flags for the question.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return string e.g. `destructive, dangerous`, or a note when nothing is flagged.
	 */
	private static function risk( WP_Ability $ability ): string {
		$flags = array_keys(
			array_filter(
				self::annotations( $ability ),
				static fn ( $value ): bool => true === $value
			)
		);
		$flags = array_values( array_diff( $flags, array( 'readonly', 'idempotent' ) ) );

		return array() === $flags ? 'writes to this site' : implode( ', ', $flags );
	}

	/**
	 * Reads an ability's annotation map, whatever shape its meta stored it in.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return array<string,mixed> The annotations.
	 */
	private static function annotations( WP_Ability $ability ): array {
		$annotations = $ability->get_meta()['annotations'] ?? array();

		return is_array( $annotations ) ? $annotations : (array) $annotations;
	}

	/**
	 * Fires the audit hook for a satisfied gate.
	 *
	 * @param \WP_Ability $ability   The ability about to run.
	 * @param string      $digest    The digest of the exact operation.
	 * @param string      $mechanism `elicitation` or `asserted`.
	 * @return void
	 */
	private function recordConsent( WP_Ability $ability, string $digest, string $mechanism ): void {
		/**
		 * Fires when the consent gate is satisfied, immediately before the ability runs.
		 *
		 * Neither mechanism proves a human agreed, so this is the record of what was
		 * claimed and how. A site or add-on that needs an audit trail hooks it; the
		 * catalog stores nothing itself.
		 *
		 * @since 0.4.0
		 *
		 * @param string $ability   The full ability name, e.g. `og-content/update-post`.
		 * @param string $digest    The sha256 digest of the exact `{name, input}` that will run.
		 * @param string $mechanism `elicitation` for an answered form, `asserted` for the flag.
		 * @param int    $user_id   The acting user.
		 */
		do_action( 'abilities_catalog_ability_confirmed', $ability->get_name(), $digest, $mechanism, get_current_user_id() );
	}

	/**
	 * Digests one operation: the ability name plus its input, order-independently.
	 *
	 * A JSON object's key order is not preserved across a client round trip, so the input
	 * is sorted before hashing. Otherwise a faithful retry could fail to match the
	 * question it is answering.
	 *
	 * @param string              $name  The ability name.
	 * @param array<string,mixed> $input The ability input.
	 * @return string The sha256 digest.
	 */
	private static function digest( string $name, array $input ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'name'  => $name,
					'input' => self::sortKeys( $input ),
				)
			)
		);
	}

	/**
	 * Recursively sorts map keys while leaving list order alone.
	 *
	 * @param array<mixed> $value The value to canonicalize.
	 * @return array<mixed> The canonicalized value.
	 */
	private static function sortKeys( array $value ): array {
		$sorted = array();
		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = is_array( $item ) ? self::sortKeys( $item ) : $item;
		}

		if ( ! array_is_list( $sorted ) ) {
			ksort( $sorted );
		}

		return $sorted;
	}

	/**
	 * Seals the continuation state the client echoes back.
	 *
	 * The adapter passes `requestState` through unverified, so everything that makes the
	 * retry trustworthy lives here. The state binds the acting user, the site, this gate,
	 * a digest of the exact operation, and a deadline — then signs the lot. It carries no
	 * secret, so it needs integrity, not confidentiality; the client only echoes it.
	 *
	 * It is deliberately stateless: no row to write, expire or clean up. The cost is that
	 * a state stays usable until it expires, so a replay inside the window re-runs the
	 * same operation the person approved, with the same arguments. It cannot be turned
	 * against a different one.
	 *
	 * @param string $digest The digest binding this operation.
	 * @return string The sealed state.
	 */
	private function seal( string $digest ): string {
		$payload = (string) wp_json_encode(
			array(
				'u' => get_current_user_id(),
				'b' => get_current_blog_id(),
				'c' => self::STATE_CONTEXT,
				'd' => $digest,
				'x' => ( $this->clock )() + self::STATE_TTL,
			)
		);
		$encoded = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding of a signed payload, not obfuscation.

		return $encoded . '.' . hash_hmac( 'sha256', $encoded, self::key() );
	}

	/**
	 * Verifies a sealed state and returns its payload.
	 *
	 * Treats the state as attacker-controlled throughout: the signature is checked before
	 * the payload is decoded, and the binding is checked before any field is believed.
	 *
	 * @param string $state The state the client echoed back.
	 * @return array{u:int,b:int,d:string,x:int}|null The payload, or null when it does not verify.
	 */
	private static function unseal( string $state ) {
		$parts = explode( '.', $state );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], self::key() ), $parts[1] ) ) {
			return null;
		}

		$decoded = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding this server's own signed payload, verified above.
		if ( ! is_string( $decoded ) ) {
			return null;
		}

		$payload = json_decode( $decoded, true );
		if ( ! is_array( $payload )
			|| ! isset( $payload['u'], $payload['b'], $payload['c'], $payload['d'], $payload['x'] )
			|| self::STATE_CONTEXT !== $payload['c']
			|| ! is_string( $payload['d'] )
			|| ! is_int( $payload['x'] )
			|| $payload['u'] !== get_current_user_id()
			|| $payload['b'] !== get_current_blog_id() ) {
			return null;
		}

		return array(
			'u' => (int) $payload['u'],
			'b' => (int) $payload['b'],
			'd' => $payload['d'],
			'x' => $payload['x'],
		);
	}

	/**
	 * The signing key for {@see seal()}.
	 *
	 * Derived from the site's auth salt, so it rotates with the site's other secrets and
	 * is never stored by this plugin.
	 *
	 * @return string The key.
	 */
	private static function key(): string {
		return wp_salt( 'auth' ) . '|' . self::STATE_CONTEXT;
	}
}
