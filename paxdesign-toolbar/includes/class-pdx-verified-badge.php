<?php
/**
 * PDX_Verified_Badge — original PAXDesign premium verified badge (server-gated).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PDX_Verified_Badge {

	public const CONTEXT_ACCOUNT = 'account';
	public const CONTEXT_EMAIL   = 'email';

	/** @return string Inner SVG markup (PAXDesign original — circle + check). */
	public static function svg_markup( int $size = 16 ): string {
		$size = max( 12, min( 24, $size ) );
		return sprintf(
			'<svg class="pdx-vb" width="%1$d" height="%1$d" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
			. '<circle class="pdx-vb__bg" cx="12" cy="12" r="10"/>'
			. '<ellipse class="pdx-vb__shine" cx="12" cy="9" rx="5.5" ry="3.75"/>'
			. '<path class="pdx-vb__check" d="M7.75 12.25l2.65 2.65 5.85-6.1"/>'
			. '</svg>',
			$size
		);
	}

	/**
	 * @param array{size?:int,context?:string,tooltip?:string,inline?:bool,class?:string} $args
	 */
	public static function render( bool $verified, array $args = [] ): string {
		if ( ! $verified ) {
			return '';
		}

		$size    = max( 12, min( 24, (int) ( $args['size'] ?? 16 ) ) );
		$context = (string) ( $args['context'] ?? self::CONTEXT_EMAIL );
		$tip     = (string) ( $args['tooltip'] ?? self::tooltip_for_context( $context ) );
		$inline  = ! empty( $args['inline'] );
		$extra   = isset( $args['class'] ) ? sanitize_html_class( (string) $args['class'] ) : '';

		$classes = trim( 'pdx-verified-badge' . ( $inline ? ' pdx-verified-badge--inline' : '' ) . ( $extra ? ' ' . $extra : '' ) );

		return sprintf(
			'<span class="%1$s" role="img" tabindex="0" aria-label="%2$s" data-pdx-tip="%2$s">%3$s</span>',
			esc_attr( $classes ),
			esc_attr( $tip ),
			self::svg_markup( $size )
		);
	}

	/**
	 * @param array{size?:int,context?:string,tooltip?:string,inline?:bool,class?:string} $args
	 */
	public static function name_with_badge( string $name, bool $verified, array $args = [] ): string {
		$args['context'] = self::CONTEXT_ACCOUNT;
		return sprintf(
			'<span class="pdx-name-with-badge">%s%s</span>',
			esc_html( $name ),
			self::render( $verified, $args )
		);
	}

	public static function tooltip_for_context( string $context ): string {
		return self::CONTEXT_ACCOUNT === $context ? 'Verified Account' : 'Email Verified';
	}
}
