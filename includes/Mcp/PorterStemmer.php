<?php
/**
 * A single-file Porter stemmer used to normalize search tokens.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduces an English word to its stem so query and corpus words match across
 * inflections — "plugins"/"plugin", "updating"/"update" all collapse to one token.
 *
 * This is the classic Porter algorithm (M. F. Porter, 1980), the well-known
 * public-domain PHP port. The {@see AbilityIndex} search runs every query word and
 * every ability's label/description/keyword token through it before comparing, so a
 * verbose natural-language query matches abilities written in a different inflection.
 *
 * The stemmer is a *normalizer*, not a dictionary: it deliberately produces non-words
 * ("policies" -> "polici", "business" -> "busi"). That is fine — both sides of a
 * comparison pass through the identical function, so the same surface word always maps
 * to the same token. The stem is never shown to a user.
 *
 * English only, by design: ability metadata is authored in English as agent context
 * regardless of the site's display language, so the query and the corpus are always
 * English. Multilingual stemming would solve a problem the catalog does not have.
 *
 * @since 0.3.0
 */
final class PorterStemmer {

	/**
	 * Matches a consonant: any non-vowel letter, plus "y" when it follows a vowel
	 * or opens the word.
	 */
	private const REGEX_CONSONANT = '(?:[bcdfghjklmnpqrstvwxz]|(?<=[aeiou])y|^y)';

	/**
	 * Matches a vowel: a/e/i/o/u, plus "y" when it does not follow a vowel.
	 */
	private const REGEX_VOWEL = '(?:[aeiou]|(?<![aeiou])y)';

	/**
	 * Stems one lowercase word. Words of two letters or fewer are returned unchanged.
	 *
	 * @param string $word A single lowercase word (no surrounding whitespace or punctuation).
	 * @return string The stem.
	 */
	public static function stem( string $word ): string {
		if ( strlen( $word ) <= 2 ) {
			return $word;
		}

		$word = self::step1ab( $word );
		$word = self::step1c( $word );
		$word = self::step2( $word );
		$word = self::step3( $word );
		$word = self::step4( $word );
		$word = self::step5( $word );

		return $word;
	}

	/**
	 * Step 1a/1b: strip plurals and -ed/-ing, then tidy the result.
	 *
	 * @param string $word The word.
	 * @return string The word after step 1ab.
	 */
	private static function step1ab( string $word ): string {
		// Part a: plurals.
		if ( 's' === substr( $word, -1 ) ) {
			self::replace( $word, 'sses', 'ss' )
			|| self::replace( $word, 'ies', 'i' )
			|| self::replace( $word, 'ss', 'ss' )
			|| self::replace( $word, 's', '' );
		}

		// Part b: -eed / -ed / -ing.
		if ( 'e' !== substr( $word, -2, 1 ) || ! self::replace( $word, 'eed', 'ee', 0 ) ) {
			$v = self::REGEX_VOWEL;

			if (
				( preg_match( "#$v#", substr( $word, 0, -2 ) ) && self::replace( $word, 'ed', '' ) )
				|| ( preg_match( "#$v#", substr( $word, 0, -3 ) ) && self::replace( $word, 'ing', '' ) )
			) {
				if (
					! self::replace( $word, 'at', 'ate' )
					&& ! self::replace( $word, 'bl', 'ble' )
					&& ! self::replace( $word, 'iz', 'ize' )
				) {
					if (
						self::doubleConsonant( $word )
						&& 'll' !== substr( $word, -2 )
						&& 'ss' !== substr( $word, -2 )
						&& 'zz' !== substr( $word, -2 )
					) {
						$word = substr( $word, 0, -1 );
					} elseif ( 1 === self::m( $word ) && self::cvc( $word ) ) {
						$word .= 'e';
					}
				}
			}
		}

		return $word;
	}

	/**
	 * Step 1c: turn a trailing "y" into "i" when the stem has a vowel.
	 *
	 * @param string $word The word.
	 * @return string The word after step 1c.
	 */
	private static function step1c( string $word ): string {
		$v = self::REGEX_VOWEL;

		if ( 'y' === substr( $word, -1 ) && preg_match( "#$v#", substr( $word, 0, -1 ) ) ) {
			self::replace( $word, 'y', 'i' );
		}

		return $word;
	}

	/**
	 * Step 2: fold common derivational suffixes (-ational, -tional, -izer, ...).
	 *
	 * @param string $word The word.
	 * @return string The word after step 2.
	 */
	private static function step2( string $word ): string {
		switch ( substr( $word, -2, 1 ) ) {
			case 'a':
				self::replace( $word, 'ational', 'ate', 0 )
				|| self::replace( $word, 'tional', 'tion', 0 );
				break;
			case 'c':
				self::replace( $word, 'enci', 'ence', 0 )
				|| self::replace( $word, 'anci', 'ance', 0 );
				break;
			case 'e':
				self::replace( $word, 'izer', 'ize', 0 );
				break;
			case 'g':
				self::replace( $word, 'logi', 'log', 0 );
				break;
			case 'l':
				self::replace( $word, 'bli', 'ble', 0 )
				|| self::replace( $word, 'alli', 'al', 0 )
				|| self::replace( $word, 'entli', 'ent', 0 )
				|| self::replace( $word, 'eli', 'e', 0 )
				|| self::replace( $word, 'ousli', 'ous', 0 );
				break;
			case 'o':
				self::replace( $word, 'ization', 'ize', 0 )
				|| self::replace( $word, 'ation', 'ate', 0 )
				|| self::replace( $word, 'ator', 'ate', 0 );
				break;
			case 's':
				self::replace( $word, 'alism', 'al', 0 )
				|| self::replace( $word, 'iveness', 'ive', 0 )
				|| self::replace( $word, 'fulness', 'ful', 0 )
				|| self::replace( $word, 'ousness', 'ous', 0 );
				break;
			case 't':
				self::replace( $word, 'aliti', 'al', 0 )
				|| self::replace( $word, 'iviti', 'ive', 0 )
				|| self::replace( $word, 'biliti', 'ble', 0 );
				break;
		}

		return $word;
	}

	/**
	 * Step 3: fold a second set of derivational suffixes (-icate, -ful, -ness, ...).
	 *
	 * @param string $word The word.
	 * @return string The word after step 3.
	 */
	private static function step3( string $word ): string {
		switch ( substr( $word, -1 ) ) {
			case 'e':
				self::replace( $word, 'icate', 'ic', 0 )
				|| self::replace( $word, 'ative', '', 0 )
				|| self::replace( $word, 'alize', 'al', 0 );
				break;
			case 'i':
				self::replace( $word, 'iciti', 'ic', 0 );
				break;
			case 'l':
				self::replace( $word, 'ical', 'ic', 0 )
				|| self::replace( $word, 'ful', '', 0 );
				break;
			case 's':
				self::replace( $word, 'ness', '', 0 );
				break;
		}

		return $word;
	}

	/**
	 * Step 4: strip a suffix outright when the stem is long enough (-al, -ance, -ent, ...).
	 *
	 * @param string $word The word.
	 * @return string The word after step 4.
	 */
	private static function step4( string $word ): string {
		switch ( substr( $word, -2, 1 ) ) {
			case 'a':
				self::replace( $word, 'al', '', 1 );
				break;
			case 'c':
				self::replace( $word, 'ance', '', 1 )
				|| self::replace( $word, 'ence', '', 1 );
				break;
			case 'e':
				self::replace( $word, 'er', '', 1 );
				break;
			case 'i':
				self::replace( $word, 'ic', '', 1 );
				break;
			case 'l':
				self::replace( $word, 'able', '', 1 )
				|| self::replace( $word, 'ible', '', 1 );
				break;
			case 'n':
				self::replace( $word, 'ant', '', 1 )
				|| self::replace( $word, 'ement', '', 1 )
				|| self::replace( $word, 'ment', '', 1 )
				|| self::replace( $word, 'ent', '', 1 );
				break;
			case 'o':
				if ( 'tion' === substr( $word, -4 ) || 'sion' === substr( $word, -4 ) ) {
					self::replace( $word, 'ion', '', 1 );
				} else {
					self::replace( $word, 'ou', '', 1 );
				}
				break;
			case 's':
				self::replace( $word, 'ism', '', 1 );
				break;
			case 't':
				self::replace( $word, 'ate', '', 1 )
				|| self::replace( $word, 'iti', '', 1 );
				break;
			case 'u':
				self::replace( $word, 'ous', '', 1 );
				break;
			case 'v':
				self::replace( $word, 'ive', '', 1 );
				break;
			case 'z':
				self::replace( $word, 'ize', '', 1 );
				break;
		}

		return $word;
	}

	/**
	 * Step 5: drop a trailing "e" and a doubled "l" where the measure allows.
	 *
	 * @param string $word The word.
	 * @return string The fully stemmed word.
	 */
	private static function step5( string $word ): string {
		// Part a: trailing "e".
		if ( 'e' === substr( $word, -1 ) ) {
			if ( self::m( substr( $word, 0, -1 ) ) > 1 ) {
				self::replace( $word, 'e', '' );
			} elseif ( 1 === self::m( substr( $word, 0, -1 ) ) && ! self::cvc( substr( $word, 0, -1 ) ) ) {
				self::replace( $word, 'e', '' );
			}
		}

		// Part b: doubled "l".
		if ( self::m( $word ) > 1 && self::doubleConsonant( $word ) && 'l' === substr( $word, -1 ) ) {
			$word = substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Replaces a suffix in place when it is present and (optionally) the stem's measure
	 * is greater than $m.
	 *
	 * @param string   $str   The word, modified in place when the suffix matches.
	 * @param string   $check The suffix to look for.
	 * @param string   $repl  The replacement.
	 * @param int|null $m     Minimum measure of the remaining stem, or null for no check.
	 * @return bool True when the suffix was present (whether or not the measure allowed the replace).
	 */
	private static function replace( string &$str, string $check, string $repl, ?int $m = null ): bool {
		$len = 0 - strlen( $check );

		if ( substr( $str, $len ) === $check ) {
			$substr = substr( $str, 0, $len );
			if ( null === $m || self::m( $substr ) > $m ) {
				$str = $substr . $repl;
			}

			return true;
		}

		return false;
	}

	/**
	 * The Porter "measure": the count of vowel-consonant sequences in the stem.
	 *
	 * @param string $str The stem.
	 * @return int The measure.
	 */
	private static function m( string $str ): int {
		$c = self::REGEX_CONSONANT;
		$v = self::REGEX_VOWEL;

		$str = (string) preg_replace( "#^$c+#", '', $str );
		$str = (string) preg_replace( "#$v+$#", '', $str );

		preg_match_all( "#($v+$c+)#", $str, $matches );

		return count( $matches[1] );
	}

	/**
	 * Whether the word ends in a doubled consonant (e.g. "tt", "ss").
	 *
	 * @param string $str The word.
	 * @return bool True when the last two letters are the same consonant.
	 */
	private static function doubleConsonant( string $str ): bool {
		$c = self::REGEX_CONSONANT;

		return (bool) preg_match( "#$c{2}$#", $str, $matches ) && $matches[0][0] === $matches[0][1];
	}

	/**
	 * Whether the word ends consonant-vowel-consonant, with the final consonant not
	 * w, x, or y (the Porter *o condition).
	 *
	 * @param string $str The word.
	 * @return bool True when the cvc condition holds.
	 */
	private static function cvc( string $str ): bool {
		$c = self::REGEX_CONSONANT;
		$v = self::REGEX_VOWEL;

		return (bool) preg_match( "#($c$v$c)$#", $str, $matches )
			&& 3 === strlen( $matches[1] )
			&& 'w' !== $matches[1][2]
			&& 'x' !== $matches[1][2]
			&& 'y' !== $matches[1][2];
	}
}
