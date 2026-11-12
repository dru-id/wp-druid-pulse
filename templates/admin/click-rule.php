<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$presets = array(
	'a[href], button' => __( 'All links and buttons', 'wp-druid-pulse' ),
	'a[href]' => __( 'All links', 'wp-druid-pulse' ),
	'button' => __( 'All buttons', 'wp-druid-pulse' ),
);
$preset = isset( $presets[ $row['selector'] ] ) ? $row['selector'] : 'custom';
?>
<tr>
	<td><select data-field="mode" aria-label="<?php esc_attr_e( 'Rule type', 'wp-druid-pulse' ); ?>">
		<option value="include" <?php selected( $row['mode'], 'include' ); ?>><?php esc_html_e( 'Track', 'wp-druid-pulse' ); ?></option>
		<option value="exclude" <?php selected( $row['mode'], 'exclude' ); ?>><?php esc_html_e( 'Exclude', 'wp-druid-pulse' ); ?></option>
	</select></td>
	<td>
		<select data-preset aria-label="<?php esc_attr_e( 'Elements to match', 'wp-druid-pulse' ); ?>">
			<?php foreach ( $presets as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $preset, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
			<option value="custom" <?php selected( $preset, 'custom' ); ?>><?php esc_html_e( 'CSS selector', 'wp-druid-pulse' ); ?></option>
		</select>
		<div data-custom-selector <?php echo 'custom' !== $preset ? 'hidden' : ''; ?>>
			<input data-field="selector" type="text" required value="<?php echo esc_attr( $row['selector'] ); ?>" placeholder="#main-menu a[href]" aria-label="<?php esc_attr_e( 'CSS selector', 'wp-druid-pulse' ); ?>">
			<p class="description"><?php esc_html_e( 'Match the link or button, e.g. #main-menu a[href] or button.offer.', 'wp-druid-pulse' ); ?></p>
		</div>
		<details <?php echo isset( $row['classPrefix'] ) ? 'open' : ''; ?>><summary><?php esc_html_e( 'Filter by class prefix', 'wp-druid-pulse' ); ?></summary>
			<input data-field="prefix" type="text" value="<?php echo esc_attr( $row['classPrefix'] ?? '' ); ?>" placeholder="promo-" aria-label="<?php esc_attr_e( 'Optional class prefix', 'wp-druid-pulse' ); ?>">
			<p class="description"><?php esc_html_e( 'Optional. Only elements with a class starting with this text.', 'wp-druid-pulse' ); ?></p>
		</details>
	</td>
	<td><select data-field="scope" aria-label="<?php esc_attr_e( 'Page scope', 'wp-druid-pulse' ); ?>">
		<option value="all" <?php selected( ! isset( $row['pages'] ) ); ?>><?php esc_html_e( 'Entire website', 'wp-druid-pulse' ); ?></option>
		<option value="pages" <?php selected( isset( $row['pages'] ) ); ?>><?php esc_html_e( 'Selected pages', 'wp-druid-pulse' ); ?></option>
	</select>
		<input data-field="pages" type="hidden" value="<?php echo esc_attr( implode( ',', $row['pages'] ?? array() ) ); ?>">
		<div data-page-selection <?php echo ! isset( $row['pages'] ) ? 'hidden' : ''; ?>>
			<ul data-selected-pages></ul>
			<button type="button" class="button" data-choose-pages><?php esc_html_e( 'Choose pages…', 'wp-druid-pulse' ); ?></button>
		</div>
	</td>
	<td><button type="button" class="button-link-delete" data-remove><?php esc_html_e( 'Remove', 'wp-druid-pulse' ); ?></button></td>
</tr>
