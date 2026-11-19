<?php
/**
 * Holds back tracking scripts until the visitor has agreed to their category.
 *
 * Runs over the finished HTML: a matching script becomes type="text/plain" with
 * data-shd-consent="<category>", so the browser ignores it. consent.js starts it,
 * in page order, once the category is allowed. Scripts that ask for consent
 * themselves (SHD Tracking Manager, SHD Analytics) and cookieless analytics are
 * left alone. Scripts of a consent tool this banner replaces (CookieFirst) are
 * stopped for good: type="text/plain" without src, marked data-shd-stopped.
 *
 * @package SHD\Consent
 */

namespace SHD\Consent;

defined( 'ABSPATH' ) || exit;

final class Blocker {

	/** Script types browsers run. */
	const RUNNABLE = array( '', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'module' );

	/**
	 * Types a performance plugin writes on a script it holds back itself, and the attribute it
	 * moves the address to. This banner normally runs first (its output buffer starts at
	 * template_redirect 10000, SHD Performance's at 9999), so it sees the untouched tags; this
	 * keeps the rules working if that order ever changes or another plugin holds scripts back.
	 */
	const DELAYED = array( 'shd/delayed' => 'data-shd-src' );

	/**
	 * Built-in rules: category, where to look (src or inline code), text to find.
	 */
	const RULES = array(
		// Statistics.
		array( 'performance', 'src', 'googletagmanager.com/gtag/js?id=G-' ),
		array( 'performance', 'src', 'googletagmanager.com/gtag/js?id=UA-' ),
		array( 'performance', 'src', 'google-analytics.com/' ),
		array( 'performance', 'src', '/google-analytics-for-wordpress/' ),
		array( 'performance', 'src', '/google-analytics-premium/' ),
		array( 'performance', 'src', '/google-analytics-dashboard-for-wp/' ),
		array( 'performance', 'src', 'static.hotjar.com' ),
		array( 'performance', 'src', 'clarity.ms/' ),
		array( 'performance', 'code', 'mi_version' ),
		array( 'performance', 'code', '__gtagTracker' ),
		array( 'performance', 'code', 'static.hotjar.com' ),
		array( 'performance', 'code', 'clarity.ms/tag' ),
		// Marketing.
		array( 'advertising', 'src', 'googletagmanager.com/gtag/js?id=AW-' ),
		array( 'advertising', 'src', 'googletagmanager.com/gtag/js?id=DC-' ),
		array( 'advertising', 'src', 'googleadservices.com' ),
		array( 'advertising', 'src', 'doubleclick.net' ),
		array( 'advertising', 'src', 'connect.facebook.net' ),
		array( 'advertising', 'src', 'snap.licdn.com' ),
		array( 'advertising', 'src', 'analytics.tiktok.com' ),
		array( 'advertising', 'src', 'bat.bing.com' ),
		array( 'advertising', 'src', 's.pinimg.com' ),
		array( 'advertising', 'src', 'cdn.leadinfo.net' ),
		array( 'advertising', 'src', 'bzrcdn.openai.com' ),
		array( 'advertising', 'src', 'static.ads-twitter.com' ),
		array( 'advertising', 'code', 'connect.facebook.net' ),
		array( 'advertising', 'code', '_linkedin_partner_id' ),
		array( 'advertising', 'code', 'analytics.tiktok.com' ),
		array( 'advertising', 'code', 'bat.bing.com' ),
		array( 'advertising', 'code', 'cdn.leadinfo.net' ),
	);

	/** Never held back: they wait for consent themselves (or are this plugin). */
	const KEEP = array( 'shd-consent', 'shd-tracking-manager', 'shd-analytics' );

	/**
	 * @param string $html Finished HTML.
	 * @param bool   $hold Hold back tracking scripts (otherwise only scripts of replaced
	 *                     consent tools are stopped).
	 * @return string
	 */
	public static function process( $html, $hold = true ) {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$rules = self::rules();
		$p     = new \WP_HTML_Tag_Processor( $html );
		$held  = 0;
		while ( $p->next_tag( 'SCRIPT' ) ) {
			$type    = strtolower( trim( (string) $p->get_attribute( 'type' ) ) );
			$waiting = isset( self::DELAYED[ $type ] ) ? self::DELAYED[ $type ] : '';
			if ( '' === $waiting && ! in_array( $type, self::RUNNABLE, true ) ) {
				continue;
			}
			$id   = (string) $p->get_attribute( 'id' );
			$src  = $p->get_attribute( '' !== $waiting ? $waiting : 'src' );
			$src  = is_string( $src ) ? trim( $src ) : '';
			$tool = '' !== $src ? Frontend::replaced( $src ) : '';
			if ( '' !== $tool ) {
				// Another consent tool written straight into the page (theme, code snippet): inert.
				$p->remove_attribute( 'src' );
				if ( '' !== $waiting ) {
					$p->remove_attribute( $waiting );
					$p->remove_attribute( 'data-shd-async' );
				}
				$p->set_attribute( 'type', 'text/plain' );
				$p->set_attribute( 'data-shd-stopped', $tool );
				Frontend::stopped( $tool, '' !== $id ? $id : 'page' );
				++$held;
				continue;
			}
			if ( ! $hold || self::kept( $id, $src ) ) {
				continue;
			}
			$code     = '' === $src ? (string) $p->get_modifiable_text() : '';
			$category = self::category( $src, $code, $rules );
			if ( '' === $category ) {
				continue;
			}
			if ( '' !== $waiting ) {
				self::take_over( $p, $waiting, $src );
			} elseif ( '' !== $type ) {
				$p->set_attribute( 'data-shd-type', $type );
			}
			$p->set_attribute( 'type', 'text/plain' );
			$p->set_attribute( 'data-shd-consent', $category );
			++$held;
		}
		return $held > 0 ? $p->get_updated_html() : $html;
	}

	/**
	 * Built-in rules plus the ones from the settings ("category | text" per line).
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public static function rules() {
		$rules = self::RULES;
		foreach ( (array) Settings::get()['block_rules'] as $rule ) {
			if ( is_array( $rule ) && isset( $rule['category'], $rule['text'] ) && in_array( $rule['category'], Settings::CATEGORIES, true ) && '' !== $rule['text'] ) {
				$rules[] = array( $rule['category'], 'src', $rule['text'] );
				$rules[] = array( $rule['category'], 'code', $rule['text'] );
			}
		}
		return $rules;
	}

	/**
	 * Takes a script that a performance plugin holds back for the first interaction: its address
	 * and its async flag are written as the browser knows them, and process() then makes it
	 * text/plain, so consent.js starts it once its category is allowed and the performance
	 * plugin's loader, which looks for its own type, no longer finds it. A data-shd-type the
	 * performance plugin wrote already holds the script's original type, so it is left alone.
	 *
	 * @param \WP_HTML_Tag_Processor $p    Processor.
	 * @param string                 $attr Attribute the address was moved to.
	 * @param string                 $src  The address ('' for an inline script).
	 */
	private static function take_over( $p, $attr, $src ) {
		$p->remove_attribute( $attr );
		if ( '' !== $src ) {
			// esc_url() inside set_attribute() refuses a data: URL; the script then keeps no
			// address and stays inert, which is the safe way round for one that needs consent.
			$p->set_attribute( 'src', $src );
		}
		if ( null !== $p->get_attribute( 'data-shd-async' ) ) {
			$p->remove_attribute( 'data-shd-async' );
			$p->set_attribute( 'async', true );
		}
	}

	/**
	 * @param string $id  Script id.
	 * @param string $src Script URL.
	 * @return bool
	 */
	private static function kept( $id, $src ) {
		foreach ( self::KEEP as $name ) {
			if ( 0 === strpos( $id, $name ) || false !== strpos( $src, '/' . $name . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $src   Script URL ('' for inline scripts).
	 * @param string $code  Inline code.
	 * @param array  $rules Rules.
	 * @return string Category, or '' when the script can run.
	 */
	private static function category( $src, $code, array $rules ) {
		foreach ( $rules as $rule ) {
			$haystack = 'src' === $rule[1] ? $src : $code;
			if ( '' !== $haystack && false !== stripos( $haystack, $rule[2] ) ) {
				return $rule[0];
			}
		}
		return '';
	}
}
