<?php
/**
 * Transport-agnostic discovery and bodies for the skills tool.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Skills\CreateContent;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers the skills tool's two actions against the merged set of recipes.
 *
 * This is the deep module behind the skills tool. It takes plain arguments and
 * returns plain data or a `WP_Error`; it knows nothing about MCP, the adapter, or
 * any transport. {@see SkillsTool} is the thin shim that adapts these methods to the
 * MCP tool result shape.
 *
 * A skill is a task-oriented recipe keyed by id: `id => [ title, when_to_use, body ]`
 * where `body` is a **string or a callable** returning a string. A callable body
 * stays unbuilt until {@see get()} resolves it, so a long recipe never costs context
 * during {@see list()} or in the tool description (spec §10). Our own skills come
 * from {@see builtins()}; third parties add theirs through the
 * `abilities_catalog_mcp_skills` filter; this class merges both. The filter runs once
 * per instance (cached in {@see skills()}), not once per action.
 *
 * @since 0.2.0
 */
final class SkillsRegistry {

	/**
	 * The merged skills after the `abilities_catalog_mcp_skills` filter.
	 *
	 * Resolved once on first use and reused, so the filter runs a single time per
	 * instance. Null until resolved.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $skills = null;

	/**
	 * Lists every skill as a discovery summary, without building any body.
	 *
	 * The lazy discovery surface: it returns each skill's id, title, and when-to-use
	 * so an agent can route without spending the body context. Malformed entries (a
	 * non-array, or one missing the required keys) are skipped so a bad third-party
	 * skill never appears half-formed.
	 *
	 * @return list<array{id:string,title:string,when_to_use:string}> One summary per skill, in registration order.
	 */
	public function list(): array {
		$items = array();

		foreach ( $this->skills() as $id => $skill ) {
			if ( ! $this->isWellFormed( $skill ) ) {
				continue;
			}

			$items[] = array(
				'id'          => (string) $id,
				'title'       => $skill['title'],
				'when_to_use' => $skill['when_to_use'],
			);
		}

		return $items;
	}

	/**
	 * Returns one skill's full recipe, resolving its body.
	 *
	 * The only call that spends body context: it resolves the body (invoking it when
	 * it is a callable) and returns the complete recipe. An empty id, an unknown id,
	 * or a body that cannot resolve to a string each yields a `WP_Error` the agent can
	 * recover from rather than a fatal.
	 *
	 * @param string $id The skill id.
	 * @return array{id:string,title:string,when_to_use:string,body:string}|\WP_Error
	 *         The recipe, or a `WP_Error` when the id is missing, unknown, or unreadable.
	 */
	public function get( string $id ) {
		if ( '' === $id ) {
			return new WP_Error(
				'abilities_catalog_mcp_missing_skill',
				__( 'This action needs a skill "id".', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$skills = $this->skills();
		if ( ! isset( $skills[ $id ] ) || ! $this->isWellFormed( $skills[ $id ] ) ) {
			return new WP_Error(
				'abilities_catalog_mcp_unknown_skill',
				sprintf(
					/* translators: %s: skill id. */
					__( 'Skill "%s" is not registered.', 'abilities-catalog' ),
					$id
				),
				array( 'status' => 404 )
			);
		}

		$skill = $skills[ $id ];
		$body  = $this->resolveBody( $skill['body'] );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return array(
			'id'          => $id,
			'title'       => $skill['title'],
			'when_to_use' => $skill['when_to_use'],
			'body'        => $body,
		);
	}

	/**
	 * Resolves the merged skills, applying the extensibility filter once.
	 *
	 * A misbehaving filter that returns a non-array is ignored in favor of the
	 * built-ins, so the registry never breaks the skills tool.
	 *
	 * @return array<string,mixed> Skill id => its `[ title, when_to_use, body ]` descriptor.
	 */
	private function skills(): array {
		if ( null !== $this->skills ) {
			return $this->skills;
		}

		/**
		 * Filters the skills the skills tool exposes.
		 *
		 * Add a recipe with `$skills['my-skill'] = [ 'title' => …, 'when_to_use' => …,
		 * 'body' => … ]`, where `body` is a string or a callable returning a string. A
		 * callable body is invoked only when a `get` asks for it, so a long body costs
		 * no context until then. Preserve the entries already present; replacing the
		 * whole array drops the built-in skills.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string,mixed> $skills Skill id => `[ title, when_to_use, body ]` descriptor.
		 */
		$filtered = apply_filters( 'abilities_catalog_mcp_skills', self::builtins() );

		$this->skills = is_array( $filtered ) ? $filtered : self::builtins();

		return $this->skills;
	}

	/**
	 * The plugin's own skills, before the filter merges in third-party ones.
	 *
	 * Each built-in body is registered as a callable so the recipe text is not built
	 * until {@see get()} resolves it.
	 *
	 * @return array<string,array{title:string,when_to_use:string,body:callable}> The built-in skills.
	 */
	private static function builtins(): array {
		return array(
			CreateContent::ID => array(
				'title'       => CreateContent::title(),
				'when_to_use' => CreateContent::whenToUse(),
				'body'        => array( CreateContent::class, 'body' ),
			),
		);
	}

	/**
	 * Reduces a string-or-callable body to a string.
	 *
	 * Invokes a callable body (this is where a lazy recipe is finally built) and
	 * accepts a plain string as-is. Any other type is a registration mistake the agent
	 * cannot act on, so it becomes a `WP_Error` rather than a broken result.
	 *
	 * @param mixed $body The body as registered: a string or a callable returning one.
	 * @return string|\WP_Error The body text, or a `WP_Error` when it cannot resolve to a string.
	 */
	private function resolveBody( $body ) {
		if ( is_callable( $body ) ) {
			$body = call_user_func( $body );
		}

		if ( is_string( $body ) ) {
			return $body;
		}

		return new WP_Error(
			'abilities_catalog_mcp_invalid_skill',
			__( 'This skill has no readable body.', 'abilities-catalog' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Reports whether a skill descriptor carries the parts both actions need.
	 *
	 * It requires string title and when_to_use (the body is checked at resolve time,
	 * since it may be a callable). Without this, a third party could register a
	 * non-string title and the later `(string)` of it would warn — or fatal for an
	 * object — defeating the clean-degradation guarantee. The check never invokes a
	 * callable body, so {@see list()} stays lazy. It keeps `list` and `get` agreeing
	 * on what counts as a real skill: a malformed entry is invisible to `list` and
	 * unknown to `get`.
	 *
	 * @param mixed $skill The candidate descriptor.
	 * @return bool True when it is an array carrying string title and when_to_use, and a body.
	 *
	 * @phpstan-assert-if-true array{title:string,when_to_use:string,body:mixed} $skill
	 */
	private function isWellFormed( $skill ): bool {
		return is_array( $skill )
			&& isset( $skill['title'], $skill['when_to_use'], $skill['body'] )
			&& is_string( $skill['title'] )
			&& is_string( $skill['when_to_use'] );
	}
}
