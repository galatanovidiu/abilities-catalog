<?php
/**
 * The scalable, search-based MCP server (a sibling of the curated domain server).
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Transport\HttpTransport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings up a second MCP endpoint built for scale, on top of the same adapter.
 *
 * The curated {@see Server} exposes one tool per hand-curated domain and lists a domain
 * at a time — clear at the core catalog's size, but it needs a maintained taxonomy and a
 * domain `list` still dumps every ability a big domain owns. With a thousand-plus
 * abilities from arbitrary plugins, neither the curated dump nor the adapter default
 * server's "discover everything" fits an agent's context.
 *
 * This server is the alternative: a flat, taxonomy-free surface of four **bounded**
 * discovery tools backed by {@see AbilityIndex} — `overview` (a capability map), `search`
 * (ranked keyword retrieval), `describe` and `execute` — plus the standalone `knowledge`
 * tool (OKF recipes and guidelines, shared with the curated server via
 * {@see KnowledgeToolFactory}). Discovery cost depends on the result set, not the catalog
 * size. It coexists with the curated server and the adapter default server on its own route
 * ({@see restRoute()}); each is a separate consumer of the one ability registry, gated by
 * the same {@see ExposurePolicy} and per-ability capability.
 *
 * @since 0.3.0
 */
final class SearchServer {

	/**
	 * Unique server identifier within the adapter's registry.
	 */
	public const SERVER_ID = 'abilities-catalog-search';

	/**
	 * REST namespace segment for the server endpoint.
	 */
	public const ROUTE_NAMESPACE = 'abilities-catalog/v1';

	/**
	 * REST route segment for the server endpoint.
	 */
	public const ROUTE = 'mcp-search';

	/**
	 * Returns the registered REST route path (namespace + route).
	 *
	 * @return string The route path, e.g. `/abilities-catalog/v1/mcp-search`.
	 */
	public static function restRoute(): string {
		return '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE;
	}

	/**
	 * Brings up the server when the adapter is available.
	 *
	 * Called from the bootstrap after {@see Server::boot()}, which has already loaded the
	 * adapter vendor bundle (or reported it missing). When the adapter is absent this
	 * returns silently — {@see Server::boot()} owns the single "dependencies missing"
	 * notice, so this never double-reports.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! class_exists( McpAdapter::class ) ) {
			return;
		}

		( new self() )->register();
	}

	/**
	 * Hooks server creation onto the adapter's init cycle.
	 *
	 * @return void
	 */
	public function register(): void {
		McpAdapter::instance();

		add_action( 'mcp_adapter_init', array( $this, 'createServer' ) );
	}

	/**
	 * Creates the search server with its four discovery tools plus the knowledge tool on `mcp_adapter_init`.
	 *
	 * `create_server()` returns the adapter on success or a `WP_Error`; a failure is logged
	 * under WP_DEBUG and otherwise swallowed so a server that cannot boot never breaks the
	 * catalog. The transport permission is the same coarse "may this user reach the server"
	 * floor the curated server uses; every `execute` still runs the ability's own capability
	 * check.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The adapter announced by the init action.
	 * @return void
	 */
	public function createServer( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'Abilities Catalog — Search', 'abilities-catalog' ),
			__( 'Scalable, search-based discovery of WordPress abilities: overview, search, describe, execute.', 'abilities-catalog' ),
			ABILITIES_CATALOG_VERSION,
			array( HttpTransport::class ),
			null,
			null,
			$this->tools(),
			array(),
			array(),
			static fn (): bool => is_user_logged_in()
		);

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		self::log( 'Search MCP server creation failed: ' . $result->get_error_message() );
	}

	/**
	 * Builds the four bounded discovery tools (all backed by one {@see AbilityIndex}) plus
	 * the standalone knowledge tool.
	 *
	 * A tool the adapter rejects is logged and skipped rather than aborting the server.
	 *
	 * @return list<\WP\MCP\Domain\Tools\McpTool> The tools to register.
	 */
	private function tools(): array {
		$index      = new AbilityIndex( new ExposurePolicy() );
		$permission = static fn (): bool => is_user_logged_in();

		$specs = array(
			array(
				'name'        => 'overview',
				'description' => 'Capability map of this site: one row per category (label, description, ability count), biggest first. Call this FIRST to learn what the site can do — it stays small no matter how many abilities are installed. Then use "search-abilities" to find a specific ability.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'handler'     => static fn () => $index->overview(),
			),
			array(
				'name'        => 'search-abilities',
				'description' => 'Find abilities by describing the task in plain words (e.g. "set product sale price", "create a coupon"). Returns the best-matching abilities (name, label, description, whether enabled), ranked, capped to "limit". Optionally narrow with "category" (a slug from overview). This is how you locate an ability without listing the whole catalog. Then call "describe-ability" for its exact input schema.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'What you want to do, in plain words.',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'Optional category slug (from overview) to restrict the search.',
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Max results to return (1-50, default 20).',
						),
					),
					'required'   => array( 'query' ),
				),
				'handler'     => static fn ( array $args ) => $index->search(
					(string) ( $args['query'] ?? '' ),
					isset( $args['category'] ) ? (string) $args['category'] : null,
					(int) ( $args['limit'] ?? 20 )
				),
			),
			array(
				'name'        => 'describe-ability',
				'description' => 'Get the full input/output schema and metadata for one ability by its exact name (from search-abilities or overview). Use this to learn exactly what fields "execute-ability" needs before calling it. Do not guess names — search first.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array(
							'type'        => 'string',
							'description' => 'The exact ability name, e.g. "og-content/create-post".',
						),
					),
					'required'   => array( 'name' ),
				),
				'handler'     => static fn ( array $args ) => $index->describe( (string) ( $args['name'] ?? '' ) ),
			),
			array(
				'name'        => 'execute-ability',
				'description' => 'Run one ability by its exact name with the parameters its schema requires (see describe-ability). Refused if the ability is unknown, disabled in the exposure gate, or your account lacks the capability.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'   => array(
							'type'        => 'string',
							'description' => 'The exact ability name.',
						),
						'params' => array(
							'type'        => 'object',
							'description' => 'The ability input, matching its describe-ability schema.',
						),
					),
					'required'   => array( 'name' ),
				),
				'handler'     => static fn ( array $args ) => $index->execute(
					(string) ( $args['name'] ?? '' ),
					(array) ( $args['params'] ?? array() )
				),
			),
		);

		$tools = array();
		foreach ( $specs as $spec ) {
			$spec['permission'] = $permission;
			$tool               = McpTool::fromArray( $spec );
			if ( is_wp_error( $tool ) ) {
				self::log( sprintf( 'Failed to build the "%s" search tool: %s', $spec['name'], $tool->get_error_message() ) );

				continue;
			}

			$tools[] = $tool;
		}

		// The cross-cutting knowledge tool is a standalone tool, not folded into the
		// discovery surface: the same `knowledge` tool the curated server exposes, built
		// from the one shared {@see KnowledgeToolFactory} so its description and schema do
		// not drift between the two endpoints.
		$knowledge = KnowledgeToolFactory::create( $permission );
		if ( is_wp_error( $knowledge ) ) {
			self::log( 'Failed to build the "knowledge" tool: ' . $knowledge->get_error_message() );

			return $tools;
		}

		$tools[] = $knowledge;

		return $tools;
	}

	/**
	 * Logs a server diagnostic, but only under WP_DEBUG.
	 *
	 * @param string $message The diagnostic message.
	 * @return void
	 */
	private static function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-guarded diagnostic; an optional feature that failed to boot has no other channel.
		error_log( 'Abilities Catalog: ' . $message );
	}
}
