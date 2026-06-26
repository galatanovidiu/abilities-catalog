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
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Transport\HttpTransport;
use WP_Error;

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
		/** This filter is documented in includes/Mcp/Server.php */
		$observability_handler = apply_filters( 'abilities_catalog_mcp_observability_handler', null );
		if ( ! is_string( $observability_handler )
			|| ! is_a( $observability_handler, McpObservabilityHandlerInterface::class, true ) ) {
			$observability_handler = null;
		}

		$result = $adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'Abilities Catalog — Search', 'abilities-catalog' ),
			__( 'Scalable, search-based discovery of WordPress abilities: overview, search, describe, execute.', 'abilities-catalog' ),
			ABILITIES_CATALOG_VERSION,
			array( HttpTransport::class ),
			null,
			$observability_handler,
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

		// The `category` filter is constrained to the site's actual category slugs, so an agent
		// sees what it can narrow by in the tool schema itself (no prior overview call needed)
		// and a bad slug is rejected up front. Bounded by category count. Omit the enum when the
		// registry is empty — an empty enum is an invalid schema that would match nothing.
		$category_schema = array(
			'type'        => 'string',
			'description' => 'Optional category slug to restrict the search. See "overview" for what each category covers.',
		);
		$category_slugs  = $index->categorySlugs();
		if ( array() !== $category_slugs ) {
			$category_schema['enum'] = $category_slugs;
		}

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
				'description' => 'Find abilities by describing the task in plain words (e.g. "set product sale price", "create a coupon"). Returns the best-matching abilities, ranked, capped to "limit". Each hit carries name, label, description, an "input" signature (param names + types, required marked "*"), safety "annotations" (e.g. readonly, destructive, dangerous — only flags set to true), and whether it is enabled. Optionally narrow with "category". This is how you locate an ability without listing the whole catalog. Then call "describe-ability" for its exact input schema.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'What you want to do, in plain words.',
						),
						'category' => $category_schema,
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
				'description' => 'Run one ability by its exact "name". Call "describe-ability" first to see that ability\'s input_schema, then pass its arguments as an object under "input". Refused if the ability is unknown, disabled in the exposure gate, or your account lacks the capability.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array(
							'type'        => 'string',
							'description' => 'The exact ability name.',
						),
						'input' => array(
							'type'        => 'object',
							'description' => 'The ability\'s arguments, as an object. Use "describe-ability" on the ability name to see its exact input_schema — the keys here must match those field names. Leave empty only if the ability takes no input.',
						),
					),
					'required'   => array( 'name' ),
				),
				'handler'     => static function ( array $args ) use ( $index ) {
					$input = self::resolveExecuteInput( $args );
					if ( is_wp_error( $input ) ) {
						return $input;
					}

					return $index->execute( (string) ( $args['name'] ?? '' ), $input );
				},
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
	 * Resolves the ability input from execute-ability's tool arguments.
	 *
	 * The tool documents its wrapper key as "input". An agent that wraps the arguments
	 * under a guessed key ("params", "args") instead leaves "input" empty, so the ability
	 * then reports its own first required field as missing — an error that points at the
	 * field, not at the real mistake. This detects a misnamed wrapper and returns an error
	 * that names it, so the agent fixes the wrapper in one step instead of chasing a phantom
	 * missing field. A call carrying only "name" is a valid no-input invocation and passes
	 * through as empty input.
	 *
	 * @param array<string,mixed> $args The raw execute-ability tool arguments.
	 * @return array<string,mixed>|\WP_Error The ability input, or an error naming the misnamed wrapper key(s).
	 */
	public static function resolveExecuteInput( array $args ) {
		if ( isset( $args['input'] ) && is_array( $args['input'] ) ) {
			return $args['input'];
		}

		$misplaced = array_keys(
			array_diff_key(
				$args,
				array(
					'name'  => true,
					'input' => true,
				)
			)
		);
		if ( array() === $misplaced ) {
			return array();
		}

		return new WP_Error(
			'input_wrapper_misnamed',
			sprintf(
				'Pass the ability arguments as an object under "input", not "%1$s". Re-send as {"name": "%2$s", "input": { … }}.',
				implode( '", "', $misplaced ),
				(string) ( $args['name'] ?? '' )
			),
			array( 'status' => 400 )
		);
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
