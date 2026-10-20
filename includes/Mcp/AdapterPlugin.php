<?php
/**
 * Detects the MCP Adapter plugin, the soft runtime dependency of the MCP servers.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "is the MCP Adapter plugin here, and is it new enough?".
 *
 * The MCP servers run on the separately installed MCP Adapter plugin (wordpress.org
 * slug `mcp-adapter`). It is a soft dependency: the catalog never declares it in a
 * `Requires Plugins` header (that would block activation), so this class is how the
 * bootstrap, the settings page, and the admin notice all agree on whether the servers
 * can boot and what to tell the owner when they cannot.
 *
 * Detection follows the adapter's own guidance: the `WP\MCP\Core\McpAdapter` class
 * must exist AND the `WP_MCP_VERSION` constant must be defined. The adapter plugin
 * defines that constant at include time, so it is set by the time `plugins_loaded`
 * runs; a copy bundled as a library inside another plugin only defines it late, on
 * `rest_api_init`. That gap is exactly how a stray bundled copy is told apart from the
 * canonical plugin — which is why the result is captured once, on `plugins_loaded`
 * ({@see detect()}), and reused by every later reader. This class deliberately
 * carries no adapter import beyond the class-name string, so it is safe to load when
 * the adapter is absent.
 *
 * @since 0.5.0
 */
final class AdapterPlugin {

	/**
	 * Oldest adapter release the servers are built against.
	 */
	public const MIN_VERSION = '0.7.0';

	/**
	 * The adapter's wordpress.org plugin slug.
	 */
	public const SLUG = 'mcp-adapter';

	/**
	 * Status: the adapter is active and new enough.
	 */
	public const STATUS_READY = 'ready';

	/**
	 * Status: the adapter plugin is not active (or only a bundled library copy is present).
	 */
	public const STATUS_MISSING = 'missing';

	/**
	 * Status: the adapter is active but older than {@see MIN_VERSION}.
	 */
	public const STATUS_OUTDATED = 'outdated';

	/**
	 * The adapter class whose presence marks the adapter as loaded.
	 *
	 * Kept as a string so this file never references the adapter's namespace in a way
	 * that would need it to be loadable.
	 */
	private const ADAPTER_CLASS = 'WP\\MCP\\Core\\McpAdapter';

	/**
	 * The version captured by {@see detect()}, or null when no plugin was found.
	 *
	 * @var string|null
	 */
	private static ?string $version = null;

	/**
	 * Whether {@see detect()} has taken its snapshot.
	 *
	 * @var bool
	 */
	private static bool $detected = false;

	/**
	 * Takes the one detection snapshot every reader shares.
	 *
	 * The bootstrap calls this on `plugins_loaded`, before the servers decide whether
	 * to boot. The snapshot matters because a library copy of the adapter bundled by
	 * some other plugin defines `WP_MCP_VERSION` late, on `rest_api_init`: a live
	 * check there would call it "ready" while the servers, which looked earlier, had
	 * already refused to boot. Reading once keeps the boot decision, the admin notice,
	 * and the settings page's REST payload telling the owner the same thing.
	 * Idempotent; later calls return without re-reading.
	 *
	 * @return void
	 */
	public static function detect(): void {
		if ( self::$detected ) {
			return;
		}

		self::$detected = true;
		self::$version  = defined( 'WP_MCP_VERSION' ) && class_exists( self::ADAPTER_CLASS )
			? (string) constant( 'WP_MCP_VERSION' )
			: null;
	}

	/**
	 * Returns the active adapter plugin's version, or null when it is not available.
	 *
	 * Null covers both "nothing installed" and "only a bundled library copy loaded":
	 * the latter has the class but not the constant when {@see detect()} runs.
	 *
	 * @return string|null The version string, or null when the plugin is not active.
	 */
	public static function installedVersion(): ?string {
		self::detect();

		return self::$version;
	}

	/**
	 * Classifies a version string against the minimum requirement.
	 *
	 * Pure, so the three outcomes are testable without defining constants.
	 *
	 * @param string|null $version The installed version, or null when absent.
	 * @return string One of the `STATUS_*` constants.
	 */
	public static function statusFor( ?string $version ): string {
		if ( null === $version ) {
			return self::STATUS_MISSING;
		}

		if ( version_compare( $version, self::MIN_VERSION, '<' ) ) {
			return self::STATUS_OUTDATED;
		}

		return self::STATUS_READY;
	}

	/**
	 * Returns the live adapter status.
	 *
	 * @return string One of the `STATUS_*` constants.
	 */
	public static function status(): string {
		return self::statusFor( self::installedVersion() );
	}

	/**
	 * Reports whether the MCP servers can boot on the adapter.
	 *
	 * @return bool True when the adapter plugin is active and new enough.
	 */
	public static function isAvailable(): bool {
		return self::STATUS_READY === self::status();
	}

	/**
	 * Returns the wp-admin URL where the current user can install, activate, or update the adapter.
	 *
	 * Empty when the user cannot install plugins: every link to this page would hit
	 * core's `install_plugins` check and die, so the callers show no link instead.
	 * On multisite the page lives only in the network admin (a subsite URL redirects
	 * there and drops the query). The directory search page is used rather than the
	 * plugin-information card because the card is built for the thickbox modal and
	 * renders without admin chrome when opened directly; the search result card offers
	 * the same Install / Activate / Update action.
	 *
	 * @return string The plugin-directory search URL, or '' when the user cannot install plugins.
	 */
	public static function installUrl(): string {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return '';
		}

		$path = 'plugin-install.php?tab=search&type=term&s=' . self::SLUG;

		return is_multisite() ? network_admin_url( $path ) : admin_url( $path );
	}

	/**
	 * Returns the translated label for the link to {@see installUrl()}.
	 *
	 * An outdated adapter is already installed, so the action is an update.
	 *
	 * @param string|null $status A `STATUS_*` value, or null to use the live status.
	 * @return string The link label.
	 */
	public static function actionLabel( ?string $status = null ): string {
		$status ??= self::status();

		if ( self::STATUS_OUTDATED === $status ) {
			return __( 'Update MCP Adapter', 'abilities-catalog' );
		}

		return __( 'Install MCP Adapter', 'abilities-catalog' );
	}

	/**
	 * Returns the translated sentence explaining why the servers cannot boot.
	 *
	 * @param string|null $status A `STATUS_*` value, or null to use the live status.
	 * @return string The sentence, or an empty string when the adapter is ready.
	 */
	public static function requirementMessage( ?string $status = null ): string {
		$status ??= self::status();

		if ( self::STATUS_MISSING === $status ) {
			return sprintf(
				/* translators: %s: minimum MCP Adapter version. */
				__( 'The MCP Adapter plugin (version %s or newer) must be installed and active for the MCP server to run.', 'abilities-catalog' ),
				self::MIN_VERSION
			);
		}

		if ( self::STATUS_OUTDATED === $status ) {
			return sprintf(
				/* translators: 1: installed MCP Adapter version, 2: minimum required version. */
				__( 'MCP Adapter %1$s is active, but version %2$s or newer is required.', 'abilities-catalog' ),
				(string) self::installedVersion(),
				self::MIN_VERSION
			);
		}

		return '';
	}
}
