<?php
defined( 'ABSPATH' ) || exit;

/**
 * Money is integer cents end to end. No floats are stored or summed.
 */
final class WWP_Money {

	/**
	 * Parse what South African clients actually type:
	 * "R 12 500,50", "12,500.50", "12500", "R1 234", "-350", "1.234,56".
	 * @return int|null cents, or null when the text is not a number.
	 */
	public static function parse( $text ): ?int {
		if ( is_int( $text ) ) {
			return $text * 100;
		}
		$s = trim( str_replace( array( "\u{2212}", "\u{2013}" ), '-', (string) $text ) ); // typographic minus / en dash
		if ( '' === $s ) {
			return 0;
		}
		$neg = false;
		if ( preg_match( '/^\((.*)\)$/', $s, $m ) ) { // accounting negative
			$s   = $m[1];
			$neg = true;
		}
		// Sign may come before or after the currency: "-R 400", "R -400", "−R 400" (how the dashboard prints it).
		if ( str_starts_with( $s, '-' ) ) {
			$neg = ! $neg;
			$s   = ltrim( substr( $s, 1 ) );
		}
		$s = preg_replace( '/^(ZAR|R)\s*/i', '', $s );
		if ( str_starts_with( $s, '-' ) ) {
			$neg = ! $neg;
			$s   = substr( $s, 1 );
		}
		$s = preg_replace( '/[\s\x{00A0}\x{202F}\']/u', '', $s ); // spaces, NBSP, thin space, apostrophe
		if ( ! preg_match( '/^[0-9.,]+$/', $s ) ) {
			return null;
		}
		$lastDot   = strrpos( $s, '.' );
		$lastComma = strrpos( $s, ',' );
		$dec       = null;
		if ( false !== $lastDot && false !== $lastComma ) {
			$dec = $lastDot > $lastComma ? '.' : ',';
		} elseif ( false !== $lastComma ) {
			// "12,50" decimal vs "12,500" thousands: decimal when 1-2 digits follow the only comma.
			$dec = ( substr_count( $s, ',' ) === 1 && strlen( $s ) - $lastComma - 1 <= 2 ) ? ',' : null;
		} elseif ( false !== $lastDot ) {
			$dec = ( substr_count( $s, '.' ) === 1 && strlen( $s ) - $lastDot - 1 <= 2 ) ? '.' : null;
		}
		if ( null === $dec ) {
			$int  = str_replace( array( ',', '.' ), '', $s );
			$frac = '';
		} else {
			$pos  = strrpos( $s, $dec );
			$int  = str_replace( array( ',', '.' ), '', substr( $s, 0, $pos ) );
			$frac = substr( $s, $pos + 1 );
		}
		if ( '' === $int ) {
			$int = '0';
		}
		if ( strlen( $int ) > 13 ) {
			return null; // > R 10 trillion: a typo, not a balance.
		}
		$cents = (int) $int * 100 + (int) str_pad( substr( $frac, 0, 2 ), 2, '0' );
		return $neg ? -$cents : $cents;
	}

	/** R 1 234 567.89 style (space thousands, as on SA statements). */
	public static function format( int $cents, bool $show_cents = false, bool $signed = false ): string {
		$neg  = $cents < 0;
		$abs  = abs( $cents );
		$rand = intdiv( $abs, 100 );
		$out  = 'R ' . number_format( $rand, 0, '.', "\u{202F}" );
		if ( $show_cents ) {
			$out .= '.' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
		}
		if ( $neg ) {
			return '−' . $out;
		}
		return $signed ? '+' . $out : $out;
	}
}
