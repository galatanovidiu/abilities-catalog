<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\BooleanInput;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `og-settings/flush-rewrite-rules`.
 *
 * Regenerates the site's entire URL rewrite (permalink) rule set from scratch by
 * wrapping core `flush_rewrite_rules( $hard )`, then reads back the stored
 * `rewrite_rules` option to report how many rules were generated. When `hard` is true
 * (the default) the flush also rewrites the server config file (`.htaccess` on Apache,
 * `web.config` on IIS); when false it updates only the stored rules option.
 *
 * The rewrite rules are the regex-to-query map WordPress uses to route every pretty
 * permalink, so this affects how every front-end URL resolves site-wide.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it regenerates and stores the rule set, and
 *   on a hard flush rewrites the server config file).
 * - `destructive` is false: rewrite rules are derived data, not a source of truth.
 *   They are regenerated from the registered post types, taxonomies, and rewrite
 *   rules, not deleted — flushing again restores them, and the WordPress-managed
 *   `.htaccess` block is rewritten, not the whole file. So there is no irreversible
 *   data loss. (It is a write, so the boolean must still be declared, which is why it
 *   is present and set to false.)
 * - `idempotent` is true: flushing twice yields the same rule set.
 * - `dangerous` is true: the blast radius is WIDE — it regenerates how EVERY
 *   front-end URL routes site-wide and, by default, rewrites the server config file.
 *   A broken rewrite registration or an unwritable config file can disrupt front-end
 *   URLs until corrected. There is no `Support/` guard (no filesystem/source/
 *   upgrader/option-allow-list risk class applies — the FlushObjectCache precedent:
 *   an operational-risk dangerous op needs none); the hard guard is `manage_options`
 *   plus the explicit check at the top of {@see self::execute()}. The Registry
 *   auto-lists any `dangerous` ability in the `abilities_catalog_dangerous_tools`
 *   filter.
 *
 * `meta.screen` points at `options-permalink.php`: saving the Permalinks settings
 * screen is how a human flushes the rewrite rules.
 *
 * Multisite note: rewrite rules are PER-SITE — they are stored in this site's
 * `rewrite_rules` option and the config file (`.htaccess`) is per-site — so
 * `manage_options` is the correct gate even on multisite. This deliberately does NOT
 * use the network-admin (`manage_network_options`) branch that network-shared state
 * (such as the object cache) requires.
 *
 * Security note: core's `flush_rewrite_rules()` performs NO capability check of its
 * own. The `permission_callback` plus the explicit `current_user_can( 'manage_options' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 *
 * This is an all-optional-input ability: every property is optional and `hard` carries
 * a default, so callers may invoke it with an empty object.
 *
 * @since 0.7.0
 */
final class FlushRewriteRules implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/flush-rewrite-rules';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Flush Rewrite Rules', 'abilities-catalog' ),
			'description'         => __( 'Regenerates ALL of the site\'s URL rewrite (permalink) rules from scratch and, by default, rewrites the server config file (.htaccess on Apache, web.config on IIS). Affects how every front-end URL is routed site-wide. Use after registering a new post type, taxonomy, or custom rewrite rule so its URLs resolve. Pass hard=false to update only the stored rules and skip the config-file write. Requires the manage_options capability. The rules are regenerated, not deleted, so it is reversible by flushing again — but a broken rewrite registration or an unwritable config file can disrupt front-end URLs until corrected.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'hard' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'When true (default), also rewrites the server config file (.htaccess on Apache, web.config on IIS); false updates only the stored rules option.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'flushed', 'hard', 'rules_count' ),
				'properties'           => array(
					'flushed'     => array(
						'type'        => 'boolean',
						'description' => __( 'Always true on success: the rewrite rules were regenerated. This is the success signal.', 'abilities-catalog' ),
					),
					'hard'        => array(
						'type'        => 'boolean',
						'description' => __( 'Echoes the effective hard flag: true if the server config file was also rewritten, false if only the stored rules option was updated.', 'abilities-catalog' ),
					),
					'rules_count' => array(
						'type'        => 'integer',
						'description' => __( 'The number of rewrite rules regenerated and stored. 0 is valid (and expected under plain permalinks, where no rules are stored), not a failure.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
					'dangerous'   => true,
				),
				'show_in_rest' => true,
				'screen'       => 'options-permalink.php',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to manage options.
	 *
	 * This is the hard server-side guard. Rewrite rules are per-site infrastructure
	 * (the stored `rewrite_rules` option and the per-site `.htaccess`), so
	 * `manage_options` is the baseline even on multisite — there is deliberately no
	 * network-admin branch. Core's `flush_rewrite_rules()` checks nothing, so this
	 * callback and the matching check in {@see self::execute()} are the only
	 * authorization. The check is object-independent — there is no per-rule capability
	 * in core — so nothing is deferred to a wrapped route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by regenerating the site's rewrite rules.
	 *
	 * The explicit `current_user_can( 'manage_options' )` check is repeated here, at
	 * the top and before the flush, because the wrapped core function performs no
	 * capability check of its own.
	 *
	 * On a hard flush, `wp-admin/includes/misc.php` is loaded first so the
	 * `save_mod_rewrite_rules()` / `iis7_save_url_rewrite_rules()` functions that core
	 * calls exist — they live there and are not loaded in a REST/front-end context.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,bool|int>|\WP_Error The flush result, or a WP_Error.
	 */
	public function execute( $input = null ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_options',
				__( 'You are not allowed to flush rewrite rules.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input = is_array( $input ) ? $input : array();
		$hard  = BooleanInput::sanitize( $input['hard'] ?? true );

		if ( $hard ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- Regenerating rewrite rules is the explicit, capability-gated purpose of this ability, not a per-request call.
		flush_rewrite_rules( $hard );

		$rules = get_option( 'rewrite_rules', array() );

		return array(
			'flushed'     => true,
			'hard'        => $hard,
			'rules_count' => is_array( $rules ) ? count( $rules ) : 0,
		);
	}
}
