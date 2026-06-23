<?php
/**
 * Guards how an add-on domain's description reaches its MCP tool blurb.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\Server;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use ReflectionMethod;

/**
 * Proves the `abilities_catalog_mcp_domains` filter description flows into the
 * server's per-domain tool blurb: an add-on domain reads with its own description,
 * a core domain keeps its curated blurb (the filter cannot override it), and an
 * add-on domain that registered no description falls back to the generic blurb.
 *
 * Exercises the private blurb resolver directly (it is pure given a domain slug and
 * the domain map), so this does not boot the process-wide MCP adapter singleton.
 */
final class AddonDomainBlurbTest extends TestCase {

	/**
	 * The blurb resolver under test.
	 *
	 * @var \ReflectionMethod
	 */
	private ReflectionMethod $blurb;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->blurb = new ReflectionMethod( Server::class, 'domainBlurb' );
		$this->blurb->setAccessible( true );
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'abilities_catalog_mcp_domains' );
		remove_all_filters( 'abilities_catalog_mcp_domain_map' );
		parent::tear_down();
	}

	/**
	 * Resolves the private domainBlurb for a slug against a fresh map.
	 *
	 * @param string $domain The domain slug.
	 * @return string The blurb.
	 */
	private function blurbFor( string $domain ): string {
		return (string) $this->blurb->invoke( new Server(), $domain, new DomainMap() );
	}

	/**
	 * An add-on domain reads with the description it registered.
	 *
	 * @return void
	 */
	public function test_addon_domain_uses_its_registered_description(): void {
		add_filter(
			'abilities_catalog_mcp_domains',
			static function ( array $domains ): array {
				$domains['forms'] = array(
					'description' => 'Manage Contact Form 7 forms.',
					'abilities'   => array( 'cf7/list-forms' ),
				);

				return $domains;
			}
		);

		$this->assertSame( 'Manage Contact Form 7 forms.', $this->blurbFor( 'forms' ) );
	}

	/**
	 * A curated core domain keeps its hand-written blurb; the filter cannot override it.
	 *
	 * @return void
	 */
	public function test_core_domain_ignores_the_addon_filter(): void {
		add_filter(
			'abilities_catalog_mcp_domains',
			static function ( array $domains ): array {
				$domains['content'] = array(
					'description' => 'HIJACKED',
					'abilities'   => array(),
				);

				return $domains;
			}
		);

		$this->assertStringContainsString( 'Manage content', $this->blurbFor( 'content' ) );
		$this->assertStringNotContainsString( 'HIJACKED', $this->blurbFor( 'content' ) );
	}

	/**
	 * A domain opened without a description (e.g. via the domain-map filter) falls
	 * back to the generic add-on blurb.
	 *
	 * @return void
	 */
	public function test_undescribed_addon_domain_falls_back_to_generic(): void {
		add_filter(
			'abilities_catalog_mcp_domain_map',
			static function ( array $includes ): array {
				$includes['commerce'] = array( 'acme/get-product' );

				return $includes;
			}
		);

		$this->assertStringContainsString( 'another plugin contributed', $this->blurbFor( 'commerce' ) );
	}
}
