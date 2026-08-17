<?php
/**
 * Error text a person can act on: durations in seconds, not milliseconds.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every timeout these plugins set is in SECONDS — `wp_remote_post( $url, array( 'timeout' => 20 ) )`
 * — and the error WordPress hands back when one fires is in milliseconds, because that is what
 * curl says:
 *
 *   cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received
 *
 * So the number in the message matches nothing in the code, and a site owner reading it cannot
 * tell a 20-second ceiling from a 20-minute one without doing arithmetic first. The managed
 * proxy had the same fault on its own alerts (its breaker reported "240000 milliseconds" for a
 * 240-second ceiling on 2026-08-17), which is what prompted fixing it on both sides.
 *
 * APPLIED TO EVERY WP_Error MESSAGE WE RENDER, not only the ones from HTTP calls. Deciding
 * per call site which WP_Error might carry a curl string is a judgement that has to be made
 * correctly 176 times and then re-made by whoever adds the 177th. The conversion is a no-op on
 * text with no millisecond duration in it — `wp_insert_post()` failures pass through
 * byte-identical — so the uniform rule costs nothing and cannot be applied wrongly.
 *
 * NOT SHARED WITH THE PROXY, deliberately. api.cloudscale.consulting runs without WordPress
 * loaded and its copy (cs_seconds_not_ms in text-lib.php) sits beside the failover
 * discriminator that reads the same curl strings to tell a slow provider from a dead one. The
 * two implementations agree and each is pinned by its own test; if you change the wording
 * here, change it there too.
 *
 * @package CloudScale_Shared
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'CloudScale_Error_Text' ) ) {
	return;
}

/**
 * Turns a machine's error text into something stated in the units we configured.
 */
class CloudScale_Error_Text {

	/**
	 * Restate any millisecond duration in the message as seconds.
	 *
	 * Whole seconds stay whole (20000 -> "20 seconds"), anything else keeps one decimal so a
	 * sub-second duration is still a duration ("0.5 seconds" rather than "0"). The bare `ms`
	 * form some upstreams use in their own JSON errors is caught too; `ms\b` cannot match
	 * inside a word, so "3 msgs pending" is left alone.
	 *
	 * @param  mixed $msg Error text. Anything non-string is cast, so a caller may pass
	 *                    get_error_message() results straight through without checking.
	 * @return string The same text with milliseconds expressed in seconds.
	 */
	public static function in_seconds( $msg ) {
		$msg = (string) $msg;
		if ( '' === $msg || ! preg_match( '/\d/', $msg ) ) {
			return $msg;
		}

		return (string) preg_replace_callback(
			'/(\d+)\s*(?:milliseconds?|ms)\b/i',
			static function ( $m ) {
				$ms = (int) $m[1];
				if ( 0 === $ms % 1000 ) {
					$secs = (string) intdiv( $ms, 1000 );
				} else {
					$secs = rtrim( rtrim( number_format( $ms / 1000, 1, '.', '' ), '0' ), '.' );
				}

				return $secs . ' ' . ( '1' === $secs ? 'second' : 'seconds' );
			},
			$msg
		);
	}
}
