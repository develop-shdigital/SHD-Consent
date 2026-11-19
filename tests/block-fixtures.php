<?php
/**
 * Check what the blocker holds back, including scripts a performance plugin has already
 * held back for the first interaction (SHD Performance writes type="shd/delayed" and moves
 * the address to data-shd-src).
 *
 * Usage (inside a WordPress install with the plugin active):
 *   wp eval-file tests/block-fixtures.php [fixtures-dir]
 *
 * The built-in cases always run. With a directory, every *.html file in it is run through
 * the blocker as well and must come back unchanged unless it holds a known tracking script:
 * save the site's own pages there to see that nothing else is touched.
 *
 * @package SHD\Consent
 */

use SHD\Consent\Blocker;

if ( ! class_exists( Blocker::class ) ) {
	fwrite( STDERR, "Activate the shd-consent plugin first.\n" );
	exit( 1 );
}

$failed = 0;

/**
 * @param string $label What is checked.
 * @param mixed  $got   Result.
 * @param mixed  $want  Expected result.
 */
function shdc_check( $label, $got, $want ) {
	global $failed;
	$ok = $got === $want;
	if ( ! $ok ) {
		++$failed;
	}
	printf( "  %s %-52s %s\n", $ok ? 'ok  ' : 'FAIL', $label, $ok ? '' : 'got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) );
}

/**
 * The attributes of every script tag, by id.
 *
 * @param string $html HTML.
 * @return array<string,array<string,string|true>>
 */
function shdc_scripts( $html ) {
	$out = array();
	$p   = new WP_HTML_Tag_Processor( $html );
	$n   = 0;
	while ( $p->next_tag( 'SCRIPT' ) ) {
		$id  = (string) $p->get_attribute( 'id' );
		$row = array();
		foreach ( array( 'type', 'src', 'async', 'data-shd-consent', 'data-shd-type', 'data-shd-src', 'data-shd-async', 'data-shd-stopped' ) as $name ) {
			$value = $p->get_attribute( $name );
			if ( null !== $value ) {
				$row[ $name ] = $value;
			}
		}
		$out[ '' !== $id ? $id : 'script-' . ( ++$n ) ] = $row;
	}
	return $out;
}

/**
 * What SHD Performance does to a script it starts on the first interaction.
 *
 * @param string $html HTML.
 * @return string
 */
function shdc_delay( $html ) {
	$classic = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript' );
	$p       = new WP_HTML_Tag_Processor( $html );
	while ( $p->next_tag( 'SCRIPT' ) ) {
		$type = strtolower( trim( (string) $p->get_attribute( 'type' ) ) );
		if ( ! in_array( $type, $classic, true ) ) {
			continue;
		}
		$src = $p->get_attribute( 'src' );
		if ( '' !== $type ) {
			$p->set_attribute( 'data-shd-type', $type );
		}
		if ( is_string( $src ) && '' !== $src ) {
			if ( null !== $p->get_attribute( 'async' ) ) {
				$p->set_attribute( 'data-shd-async', true );
			}
			$p->set_attribute( 'data-shd-src', $src );
			$p->remove_attribute( 'src' );
			$p->remove_attribute( 'async' );
			$p->remove_attribute( 'defer' );
		}
		$p->set_attribute( 'type', 'shd/delayed' );
	}
	return $p->get_updated_html();
}

$tracking = '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABC123" id="google_gtagjs-js" async></script>'
	. '<script id="google_gtagjs-js-after">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'config\',\'G-ABC123\');</script>'
	. '<script id="monsterinsights-inline">var __gtagTracker=function(){};</script>'
	. '<script src="https://connect.facebook.net/en_US/fbevents.js" id="meta-pixel-js"></script>'
	. '<script type="module" src="https://cdn.leadinfo.net/ping.js" id="leadinfo-js"></script>';
$page     = '<html><head>' . $tracking . '</head><body></body></html>';

echo "Tracking scripts as WordPress writes them\n";
$s = shdc_scripts( Blocker::process( $page, true ) );
shdc_check( 'gtag loader held for statistics', isset( $s['google_gtagjs-js']['data-shd-consent'] ) ? $s['google_gtagjs-js']['data-shd-consent'] : null, 'performance' );
shdc_check( 'gtag loader keeps src and async', isset( $s['google_gtagjs-js']['src'], $s['google_gtagjs-js']['async'] ), true );
// Google Consent Mode: the gtag() bootstrap must run, so that calls queue in dataLayer and
// are sent once the loader is started. Holding it back would lose them.
shdc_check( 'gtag bootstrap still runs', isset( $s['google_gtagjs-js-after']['data-shd-consent'] ), false );
shdc_check( 'MonsterInsights code held', isset( $s['monsterinsights-inline']['data-shd-consent'] ) ? $s['monsterinsights-inline']['data-shd-consent'] : null, 'performance' );
shdc_check( 'Meta pixel held for marketing', isset( $s['meta-pixel-js']['data-shd-consent'] ) ? $s['meta-pixel-js']['data-shd-consent'] : null, 'advertising' );
shdc_check( 'module type remembered', isset( $s['leadinfo-js']['data-shd-type'] ) ? $s['leadinfo-js']['data-shd-type'] : null, 'module' );

echo "\nThe same page after a performance plugin has held the scripts back\n";
$d = shdc_scripts( Blocker::process( shdc_delay( $page ), true ) );
shdc_check( 'gtag loader held for statistics', isset( $d['google_gtagjs-js']['data-shd-consent'] ) ? $d['google_gtagjs-js']['data-shd-consent'] : null, 'performance' );
shdc_check( 'gtag loader waits as text/plain', isset( $d['google_gtagjs-js']['type'] ) ? $d['google_gtagjs-js']['type'] : null, 'text/plain' );
shdc_check( 'address written back to src', isset( $d['google_gtagjs-js']['src'] ) ? $d['google_gtagjs-js']['src'] : null, 'https://www.googletagmanager.com/gtag/js?id=G-ABC123' );
shdc_check( 'async written back', isset( $d['google_gtagjs-js']['async'] ), true );
shdc_check( 'data-shd-src removed', isset( $d['google_gtagjs-js']['data-shd-src'] ), false );
shdc_check( 'data-shd-async removed', isset( $d['google_gtagjs-js']['data-shd-async'] ), false );
shdc_check( 'no type left for consent.js to restore', isset( $d['google_gtagjs-js']['data-shd-type'] ), false );
shdc_check( 'gtag bootstrap left to the other plugin', isset( $d['google_gtagjs-js-after']['type'] ) ? $d['google_gtagjs-js-after']['type'] : null, 'shd/delayed' );
shdc_check( 'MonsterInsights code held', isset( $d['monsterinsights-inline']['data-shd-consent'] ) ? $d['monsterinsights-inline']['data-shd-consent'] : null, 'performance' );
shdc_check( 'module type remembered', isset( $d['leadinfo-js']['data-shd-type'] ) ? $d['leadinfo-js']['data-shd-type'] : null, 'module' );

echo "\nA replaced consent tool that a performance plugin has held back\n";
$c = shdc_scripts( Blocker::process( shdc_delay( '<html><head><script src="https://consent.cookiefirst.com/sites/x/consent.js" id="cookiefirst-js"></script></head><body></body></html>' ), true ) );
shdc_check( 'stopped for good', isset( $c['cookiefirst-js']['data-shd-stopped'] ) ? $c['cookiefirst-js']['data-shd-stopped'] : null, 'CookieFirst' );
shdc_check( 'no address left', isset( $c['cookiefirst-js']['src'] ) || isset( $c['cookiefirst-js']['data-shd-src'] ), false );

echo "\nOur own scripts\n";
$o = shdc_scripts( Blocker::process( shdc_delay( '<html><head><script id="shd-consent-head">window.shdConsentConfig={};</script></head><body><script id="shd-consent-js">void 0;</script></body></html>' ), true ) );
shdc_check( 'head script never held', isset( $o['shd-consent-head']['data-shd-consent'] ), false );
shdc_check( 'banner script never held', isset( $o['shd-consent-js']['data-shd-consent'] ), false );

$dir = isset( $args[0] ) ? rtrim( $args[0], '/' ) : '';
if ( '' !== $dir && is_dir( $dir ) ) {
	echo "\nSaved pages in $dir\n";
	foreach ( (array) glob( $dir . '/*.html' ) as $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$html   = (string) file_get_contents( $file );
		$result = Blocker::process( $html, true );
		$before = shdc_scripts( $html );
		$after  = shdc_scripts( $result );
		$count  = 0;
		foreach ( $after as $id => $row ) {
			if ( isset( $row['data-shd-consent'] ) || isset( $row['data-shd-stopped'] ) ) {
				++$count;
			}
		}
		shdc_check( basename( $file ) . ': same number of scripts', count( $after ), count( $before ) );
		printf( "       %d of %d scripts held back%s\n", $count, count( $before ), 0 === $count && $result === $html ? ' (page unchanged)' : '' );
	}
}

echo "\n" . ( $failed ? "FAILED: {$failed}\n" : "All checks passed.\n" );
exit( $failed ? 1 : 0 );
