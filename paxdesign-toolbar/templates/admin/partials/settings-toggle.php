<?php
/**
 * Enterprise settings row with toggle on the right.
 *
 * @var string $name        Input name attribute.
 * @var string $label       Setting title.
 * @var string $description Optional helper text.
 * @var bool   $checked     Whether the toggle is on.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$description = $description ?? '';
?>
<div class="pdx-settings-row">
	<div class="pdx-settings-row__main">
		<span class="pdx-settings-row__label" id="<?php echo esc_attr( $name ); ?>-label"><?php echo esc_html( $label ); ?></span>
		<?php if ( $description !== '' ) : ?>
		<p class="pdx-settings-row__desc" id="<?php echo esc_attr( $name ); ?>-desc"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
	</div>
	<label class="pdx-toggle pdx-settings-row__control" aria-labelledby="<?php echo esc_attr( $name ); ?>-label"<?php echo $description !== '' ? ' aria-describedby="' . esc_attr( $name ) . '-desc"' : ''; ?>>
		<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $checked ) ); ?>>
		<span class="pdx-toggle__track" aria-hidden="true"></span>
		<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
	</label>
</div>
