<?php
/**
 * Transport-agnostic list / describe / execute logic for a domain.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP_Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers the three domain-tool actions against the live ability registry.
 *
 * This is the deep module behind every domain tool. It takes plain arguments and
 * returns plain data or a `WP_Error`; it knows nothing about MCP, the adapter, or
 * any transport. {@see DomainToolHandler} is the thin shim that adapts these
 * methods to the MCP tool result shape.
 *
 * Capability is the hard guard: `execute()` dispatches through the `WP_Ability`
 * wrapper, whose `execute()` runs the ability's own `permission_callback` and
 * input/output validation. Ahead of it sits the coarser, owner-controlled exposure
 * gate ({@see ExposurePolicy}): `execute()` refuses a disabled ability before
 * dispatch. The gate hides nothing — `list` still reports every ability (each
 * carrying an `enabled` flag) and `describe` still returns its full schema, so an
 * agent can learn a disabled ability and ask a human to enable it. `list` and
 * `describe` return unfiltered metadata otherwise (it is low-sensitivity; the
 * per-ability permission checks are input-aware and often defer to a wrapped REST
 * route, so filtering here would mislead).
 *
 * @since 0.2.0
 */
final class DomainRouter {

	/**
	 * The taxonomy that says which ability belongs to which domain.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap
	 */
	private DomainMap $map;

	/**
	 * The exposure gate that says which abilities may be executed.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy
	 */
	private ExposurePolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap       $map    The ability -> domain taxonomy.
	 * @param \GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy  $policy The per-ability exposure gate.
	 */
	public function __construct( DomainMap $map, ExposurePolicy $policy ) {
		$this->map    = $map;
		$this->policy = $policy;
	}

	/**
	 * Lists every registered ability mapped to the domain, with its flags.
	 *
	 * The lazy ability index for the domain. Returns unfiltered metadata; each entry
	 * carries an `enabled` flag so the agent knows which abilities `execute` will run
	 * and which the owner has gated off.
	 *
	 * @param string $domain The domain slug.
	 * @return list<array{name:string,label:string,description:string,readonly:bool,destructive:bool,dangerous:bool,enabled:bool}>
	 *         One summary per ability, in registration order.
	 */
	public function list( string $domain ): array {
		$items = array();

		foreach ( wp_get_abilities() as $name => $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			if ( $this->map->domainOf( (string) $name ) !== $domain ) {
				continue;
			}

			$items[] = $this->summarize( (string) $name, $ability );
		}

		return $items;
	}

	/**
	 * Describes one ability's schemas and annotations.
	 *
	 * @param string $domain  The domain slug the ability must belong to.
	 * @param string $ability The full ability name.
	 * @return array{name:string,label:string,description:string,input_schema:array<string,mixed>,output_schema:array<string,mixed>,annotations:array<string,mixed>}|\WP_Error
	 *         The description, or a `WP_Error` when the ability is missing or out of domain.
	 */
	public function describe( string $domain, string $ability ) {
		$resolved = $this->requireMember( $domain, $ability );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return array(
			'name'          => $ability,
			'label'         => $resolved->get_label(),
			'description'   => $this->describeDescription( $ability, $resolved->get_description() ),
			'input_schema'  => $resolved->get_input_schema(),
			'output_schema' => $resolved->get_output_schema(),
			'annotations'   => $this->annotations( $resolved ),
		);
	}

	/**
	 * Runs one ability through its `WP_Ability` wrapper.
	 *
	 * The wrapper enforces the ability's `permission_callback` and input/output
	 * validation, so a caller without the capability gets a `WP_Error` here.
	 *
	 * A no-input ability declares an empty `input_schema`, and core's
	 * `WP_Ability::validate_input()` then rejects any non-null input with
	 * `ability_missing_input_schema`. MCP clients send `{}` for a no-argument call,
	 * which arrives here as an empty array, so the empty array is normalized to
	 * `null` before dispatch (the same normalization the adapter applies on its own
	 * ability-wrap path; ours is handler-backed, so it bypasses that).
	 *
	 * @param string              $domain  The domain slug the ability must belong to.
	 * @param string              $ability The full ability name.
	 * @param array<string,mixed> $input   Arguments for the ability.
	 * @return mixed|\WP_Error The ability's result, or a `WP_Error` (out of domain, or from the ability).
	 */
	public function execute( string $domain, string $ability, array $input ) {
		$resolved = $this->requireMember( $domain, $ability );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( ! $this->policy->allows( $ability ) ) {
			return $this->disabled( $ability );
		}

		return $resolved->execute( AbilityArgumentNormalizer::normalize( $resolved, $input ) );
	}

	/**
	 * Resolves an ability that must exist and belong to the domain.
	 *
	 * Guards `wp_get_ability()` with `wp_has_ability()` so an unknown name returns
	 * a clean error instead of tripping core's `_doing_it_wrong` notice.
	 *
	 * @param string $domain  The domain slug.
	 * @param string $ability The full ability name.
	 * @return \WP_Ability|\WP_Error The ability, or a `WP_Error` describing why it is unavailable.
	 */
	private function requireMember( string $domain, string $ability ) {
		if ( '' === $ability ) {
			return new WP_Error(
				'abilities_catalog_mcp_missing_ability',
				__( 'This action needs an "ability" name.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( $this->map->domainOf( $ability ) !== $domain ) {
			return new WP_Error(
				'abilities_catalog_mcp_unknown_ability',
				sprintf(
					/* translators: 1: ability name, 2: domain slug. */
					__( 'Ability "%1$s" is not part of the "%2$s" domain.', 'abilities-catalog' ),
					$ability,
					$domain
				) . $this->recoveryHint( $domain, $ability ),
				array( 'status' => 404 )
			);
		}

		if ( ! wp_has_ability( $ability ) ) {
			return $this->notRegistered( $domain, $ability );
		}

		$resolved = wp_get_ability( $ability );
		if ( ! $resolved instanceof WP_Ability ) {
			return $this->notRegistered( $domain, $ability );
		}

		return $resolved;
	}

	/**
	 * Builds the "ability is not registered" error.
	 *
	 * One definition for one outcome, shared by the `wp_has_ability()` guard and the
	 * defensive post-resolve check. The message carries a recovery hint so an agent
	 * that guessed a name can recover without a human ({@see recoveryHint()}).
	 *
	 * @param string $domain  The domain slug the call was made against.
	 * @param string $ability The full ability name.
	 * @return \WP_Error The not-registered error.
	 */
	private function notRegistered( string $domain, string $ability ): WP_Error {
		return new WP_Error(
			'abilities_catalog_mcp_unknown_ability',
			sprintf(
				/* translators: 1: ability name, 2: domain slug. */
				__( 'Ability "%1$s" is not registered in the "%2$s" domain.', 'abilities-catalog' ),
				$ability,
				$domain
			) . $this->recoveryHint( $domain, $ability ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Builds the recovery sentence appended to an unknown-ability error.
	 *
	 * Always tells the agent it can call `list` to see the domain's abilities — the
	 * one action that resolves any wrong name. When a registered name is close enough
	 * to the guess (a likely typo or a wrong prefix), it also offers a "Did you mean"
	 * suggestion so the agent can retry in one step. The suggestion is advisory; the
	 * `list` hint is the guaranteed path. This is why the unknown-ability path stays a
	 * recoverable error rather than a dead end — it teaches the caller how to proceed.
	 *
	 * @param string $domain  The domain slug whose abilities to suggest from.
	 * @param string $ability The unrecognized ability name the caller sent.
	 * @return string The leading-space recovery sentence(s) to append to the message.
	 */
	private function recoveryHint( string $domain, string $ability ): string {
		$suggestion = $this->nearestName( $ability, $this->namesInDomain( $domain ) );

		$did_you_mean = null === $suggestion ? '' : sprintf(
			/* translators: %s: a suggested ability name. */
			__( ' Did you mean "%s"?', 'abilities-catalog' ),
			$suggestion
		);

		return $did_you_mean . __( ' Call this tool with action "list" to see the available abilities.', 'abilities-catalog' );
	}

	/**
	 * Returns the registered ability names mapped to a domain.
	 *
	 * The names-only counterpart to {@see list()}: it walks the live registry once and
	 * keeps the in-domain names, without building the fuller summaries `list()` returns.
	 * Used to source "Did you mean" suggestions on the error path, so a guess never has
	 * to pay for the full listing.
	 *
	 * @param string $domain The domain slug.
	 * @return list<string> The in-domain registered ability names.
	 */
	private function namesInDomain( string $domain ): array {
		$names = array();

		foreach ( wp_get_abilities() as $name => $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			if ( $this->map->domainOf( (string) $name ) !== $domain ) {
				continue;
			}

			$names[] = (string) $name;
		}

		return $names;
	}

	/**
	 * Picks the candidate name closest to a guess, when one is close enough.
	 *
	 * Uses the edit distance ({@see levenshtein()}) and only returns a candidate within
	 * a small relative threshold (a third of the guess length, at least two edits), so a
	 * genuine typo or wrong prefix yields a suggestion while an unrelated name yields
	 * none — a far-off "Did you mean" would mislead more than help.
	 *
	 * @param string       $needle     The unrecognized ability name.
	 * @param list<string> $candidates The registered names to match against.
	 * @return string|null The nearest name within threshold, or null when none is close.
	 */
	private function nearestName( string $needle, array $candidates ): ?string {
		$best          = null;
		$best_distance = PHP_INT_MAX;

		foreach ( $candidates as $candidate ) {
			$distance = levenshtein( $needle, $candidate );
			if ( $distance >= $best_distance ) {
				continue;
			}

			$best_distance = $distance;
			$best          = $candidate;
		}

		$threshold = max( 2, intdiv( strlen( $needle ), 3 ) );

		return null !== $best && $best_distance <= $threshold ? $best : null;
	}

	/**
	 * Builds the `list` summary for one ability.
	 *
	 * The `description` is the raw ability description (so the settings page can reuse
	 * the index without the disabled note); the `enabled` flag carries the gate state.
	 * The longer "disabled" note is appended only by {@see describe()}, the call an agent
	 * makes to study how to use the ability.
	 *
	 * @param string      $name    The ability name.
	 * @param \WP_Ability $ability The ability.
	 * @return array{name:string,label:string,description:string,readonly:bool,destructive:bool,dangerous:bool,enabled:bool}
	 */
	private function summarize( string $name, WP_Ability $ability ): array {
		$annotations = $this->annotations( $ability );

		return array(
			'name'        => $name,
			'label'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'readonly'    => true === ( $annotations['readonly'] ?? null ),
			'destructive' => true === ( $annotations['destructive'] ?? null ),
			'dangerous'   => true === ( $annotations['dangerous'] ?? null ),
			'enabled'     => $this->policy->allows( $name ),
		);
	}

	/**
	 * Appends the "currently disabled" note to a `describe` description when gated off.
	 *
	 * An enabled ability returns its description unchanged. A disabled one gets a short
	 * note so the agent reading the schema knows the call will be refused until a human
	 * enables it — the gate informs rather than hides.
	 *
	 * @param string $ability     The full ability name.
	 * @param string $description The ability's own description.
	 * @return string The description, with the disabled note appended when gated off.
	 */
	private function describeDescription( string $ability, string $description ): string {
		if ( $this->policy->allows( $ability ) ) {
			return $description;
		}

		return trim(
			$description . ' ' . __( 'Note: this ability is currently disabled for the MCP server and cannot be executed until an administrator enables it on the MCP server settings page.', 'abilities-catalog' )
		);
	}

	/**
	 * Builds the "ability is disabled" error for the exposure gate.
	 *
	 * Returned by {@see execute()} before dispatch when the owner has not enabled the
	 * ability. It names the ability and points at the settings page, since the agent's
	 * only recourse is to ask a human to enable it. Status 403: the request is
	 * well-formed and the ability exists, but the server refuses to run it.
	 *
	 * @param string $ability The full ability name.
	 * @return \WP_Error The disabled error.
	 */
	private function disabled( string $ability ): WP_Error {
		return new WP_Error(
			'abilities_catalog_mcp_ability_disabled',
			sprintf(
				/* translators: 1: ability name, 2: settings page URL. */
				__( 'Ability "%1$s" is disabled for the MCP server. An administrator can enable it on the MCP server settings page: %2$s', 'abilities-catalog' ),
				$ability,
				abilities_catalog_mcp_settings_url()
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * Returns an ability's annotations, always as an array.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return array<string,mixed> The annotations, or an empty array when absent.
	 */
	private function annotations( WP_Ability $ability ): array {
		$annotations = $ability->get_meta()['annotations'] ?? array();

		return is_array( $annotations ) ? $annotations : array();
	}
}
