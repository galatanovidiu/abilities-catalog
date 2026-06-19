<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Contracts\CategoryProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers ability classes and registers them with the Abilities API.
 *
 * Discovery is convention-driven: the scan recurses through `includes/Abilities/`,
 * and every class implementing {@see Ability} is registered. Abilities are organized
 * into top-level groups (`Core`, and any add-on group such as `Woo`), each owning its
 * own {@see CategoryProvider}. There is no shared manifest and no shared category
 * file, so a contributor adds files only under their own group folder and never edits
 * a shared list — the safe shape for parallel fan-out.
 *
 * An annotation guard is enforced here as a hard gate: read-only abilities and
 * writes that explicitly declare a boolean `annotations.destructive` (true or
 * false) register; a write that OMITS the `destructive` annotation is treated as
 * unsafe and skipped with a `_doing_it_wrong()` notice. Destructive writes DO
 * register, but EXPOSURE to the browser is gated separately by the adapter: a
 * non-destructive write needs the write setting; a destructive write needs BOTH
 * the write setting and the destructive setting. Capability remains the hard
 * authorization guard in all cases.
 *
 * This gate enforces "annotations present", not a build tier: tier scope (e.g. T1
 * vs T2 writes) is bounded by which ability files are present, not by this guard.
 *
 * @since 0.1.0
 */
final class Registry {

	/**
	 * Discovered ability instances, keyed by ability name.
	 *
	 * @var array<string,\GalatanOvidiu\AbilitiesCatalog\Contracts\Ability>
	 */
	private array $abilities = array();

	/**
	 * Discovered category-provider instances, one per ability group.
	 *
	 * @var array<int,\GalatanOvidiu\AbilitiesCatalog\Contracts\CategoryProvider>
	 */
	private array $categoryProviders = array();

	/**
	 * Registers the Abilities API hooks.
	 *
	 * Categories must be registered before abilities; core fires
	 * `wp_abilities_api_categories_init` ahead of `wp_abilities_api_init`.
	 *
	 * @return void
	 */
	public function register(): void {
		// The Abilities API must be present (WordPress 7.0+).
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->discover();

		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );

		// Contribute the dangerous-tools catalog the adapter reads for its per-ability
		// opt-in. Added after discover() so $this->abilities is populated.
		add_filter( 'abilities_catalog_dangerous_tools', array( $this, 'contributeDangerousTools' ) );

		// Contribute the screen-link map the adapter reads to deep-link a write
		// entry to the wp-admin screen it touched. Added after discover() so
		// $this->abilities is populated.
		add_filter( 'abilities_catalog_screen_links', array( $this, 'contributeScreenLinks' ) );
	}

	/**
	 * Contributes the registered dangerous abilities to the `abilities_catalog_dangerous_tools` map.
	 *
	 * Iterates the discovered abilities and adds each one whose
	 * `meta.annotations.dangerous` is exactly `true`, keyed by ability name with its
	 * human label as the value. The adapter's Settings page reads this map to render
	 * the per-ability opt-in checkboxes and to allow-list submitted opt-in names.
	 *
	 * @param array<string,string> $tools Existing dangerous-tools map.
	 * @return array<string,string> The map including this plugin's dangerous abilities.
	 */
	public function contributeDangerousTools( array $tools ): array {
		foreach ( $this->abilities as $name => $ability ) {
			$annotations = $ability->args()['meta']['annotations'] ?? array();

			if ( true !== ( $annotations['dangerous'] ?? null ) ) {
				continue;
			}

			$tools[ $name ] = (string) ( $ability->args()['label'] ?? $name );
		}

		return $tools;
	}

	/**
	 * Contributes the registered abilities' screen templates to the `abilities_catalog_screen_links` map.
	 *
	 * Iterates the discovered abilities and adds each one that declares a
	 * non-empty string `meta.screen`, keyed by ability name with the
	 * admin-relative URL template as the value. The adapter reads this map to
	 * deep-link a logged write entry to the wp-admin screen the action touched;
	 * the template's `{placeholder}` tokens are filled from the call's own
	 * params. Read-only abilities carry no `screen` key, so they are naturally
	 * excluded — and any ability whose `annotations.readonly` is true is skipped
	 * defensively as well.
	 *
	 * @param array<string,string> $links Existing screen-links map.
	 * @return array<string,string> The map including this plugin's screen templates.
	 */
	public function contributeScreenLinks( array $links ): array {
		foreach ( $this->abilities as $name => $ability ) {
			$meta        = $ability->args()['meta'] ?? array();
			$annotations = $meta['annotations'] ?? array();

			if ( true === ( $annotations['readonly'] ?? null ) ) {
				continue;
			}

			$screen = $meta['screen'] ?? null;

			if ( ! is_string( $screen ) || '' === $screen ) {
				continue;
			}

			$links[ $name ] = $screen;
		}

		return $links;
	}

	/**
	 * Registers every ability category contributed by the discovered providers.
	 *
	 * Each ability group owns a {@see CategoryProvider} (e.g. the Core group's
	 * `Abilities\Core\CategoryCatalog`); this merges their `categories()` and registers
	 * each one. Categories are defined per group, not per ability, so this registers
	 * the full set regardless of which ability files are present. Every ability's
	 * `args()['category']` slug must exist in some provider. `categories()` is called
	 * here, on the categories init hook, so its `__()` calls have translations ready.
	 *
	 * @return void
	 */
	public function registerCategories(): void {
		foreach ( $this->categoryProviders as $provider ) {
			foreach ( $provider->categories() as $slug => $category ) {
				wp_register_ability_category(
					$slug,
					array(
						'label'       => $category['label'] ?? $slug,
						'description' => $category['description'] ?? '',
					)
				);
			}
		}
	}

	/**
	 * Registers each discovered ability, enforcing the annotation guard.
	 *
	 * Read-only abilities register. A write registers when it explicitly declares a
	 * boolean `annotations.destructive` (true or false); a write that omits the
	 * `destructive` annotation (treated as unsafe) is refused. The adapter's write
	 * and destructive settings govern browser EXPOSURE separately.
	 *
	 * @return void
	 */
	public function registerAbilities(): void {
		foreach ( $this->abilities as $name => $ability ) {
			$args = $ability->args();

			$annotations          = $args['meta']['annotations'] ?? array();
			$is_readonly          = true === ( $annotations['readonly'] ?? null );
			$has_annotated_safety = array_key_exists( 'destructive', $annotations )
				&& is_bool( $annotations['destructive'] );

			if ( ! $is_readonly && ! $has_annotated_safety ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: ability name. */
						esc_html__( 'Ability "%s" was refused: it must be read-only or a write that explicitly sets annotations.destructive to a boolean. A write that omits the destructive annotation is treated as unsafe and not registered.', 'abilities-catalog' ),
						esc_html( $name )
					),
					'0.2.0'
				);
				continue;
			}

			foreach ( array( 'input_schema', 'output_schema' ) as $schema_key ) {
				if ( ! isset( $args[ $schema_key ] ) || ! is_array( $args[ $schema_key ] ) ) {
					continue;
				}

				$args[ $schema_key ] = $this->normalizeSchema( $args[ $schema_key ] );
			}

			$name = strtolower( $name );
			if ( ! $name ) {
				continue;
			}

			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Coerces empty `properties` maps to JSON objects throughout a schema.
	 *
	 * Two PHP-array serialization quirks break the client-side AJV validator that
	 * runs on every ability, so this normalizer repairs both for current and
	 * future abilities without each author having to remember them:
	 *
	 * 1. A non-empty `'properties' => array()` serializes to a JSON array (`[]`),
	 *    but JSON Schema requires `properties` to be an object (`{}`). Empty
	 *    `properties` maps are replaced with a `stdClass`.
	 * 2. A `'required' => array()` serializes to `[]`, but the JSON Schema
	 *    meta-schema requires `required` to have at least one item; AJV rejects
	 *    the whole schema ("required must NOT have fewer than 1 items"). An empty
	 *    `required` means "nothing required", so it is dropped.
	 *
	 * @param array<string,mixed> $schema The schema node to normalize.
	 * @return array<string,mixed> The normalized schema node.
	 */
	private function normalizeSchema( array $schema ): array {
		foreach ( $schema as $key => $value ) {
			if ( 'required' === $key && is_array( $value ) && array() === $value ) {
				unset( $schema[ $key ] );
				continue;
			}

			if ( 'properties' === $key && is_array( $value ) ) {
				if ( array() === $value ) {
					$schema[ $key ] = new \stdClass();
					continue;
				}

				foreach ( $value as $prop_name => $prop_schema ) {
					if ( ! is_array( $prop_schema ) ) {
						continue;
					}

					$value[ $prop_name ] = $this->normalizeSchema( $prop_schema );
				}
				$schema[ $key ] = $value;
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$schema[ $key ] = $this->normalizeSchema( $value );
		}

		return $schema;
	}

	/**
	 * Scans `includes/Abilities/<Group>/.../` and instantiates the discovered classes.
	 *
	 * The scan recurses to any depth, so a group nests its abilities however it
	 * likes (e.g. `Core/Content/`, `Woo/Products/Basic/`). The fully-qualified class
	 * name is derived from the file path so the autoloader resolves it. A class is
	 * kept as an ability when it implements {@see Ability}, and as a category source
	 * when it implements {@see CategoryProvider}; every other class is ignored.
	 *
	 * @return void
	 */
	private function discover(): void {
		$base = ABILITIES_CATALOG_DIR . 'includes/Abilities/';

		if ( ! is_dir( $base ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $base ), -strlen( '.php' ) );
			$class    = __NAMESPACE__ . '\\Abilities\\' . str_replace( '/', '\\', $relative );

			if ( ! class_exists( $class ) ) {
				continue;
			}

			if ( is_subclass_of( $class, Ability::class ) ) {
				$ability                             = new $class();
				$this->abilities[ $ability->name() ] = $ability;
				continue;
			}

			if ( ! is_subclass_of( $class, CategoryProvider::class ) ) {
				continue;
			}

			$this->categoryProviders[] = new $class();
		}
	}
}
