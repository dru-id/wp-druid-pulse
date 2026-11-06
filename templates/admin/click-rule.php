<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<tr>
	<td><select data-field="mode" aria-label="<?php esc_attr_e( 'Rule type', 'wp-druid-pulse' ); ?>">
		<option value="include" <?php selected( $row['mode'], 'include' ); ?>><?php esc_html_e( 'Include', 'wp-druid-pulse' ); ?></option>
		<option value="exclude" <?php selected( $row['mode'], 'exclude' ); ?>><?php esc_html_e( 'Exclude', 'wp-druid-pulse' ); ?></option>
	</select></td>
	<td><input data-field="selector" type="text" class="regular-text" required value="<?php echo esc_attr( $row['selector'] ); ?>" aria-label="<?php esc_attr_e( 'CSS selector', 'wp-druid-pulse' ); ?>"></td>
	<td><input data-field="prefix" type="text" value="<?php echo esc_attr( $row['classPrefix'] ?? '' ); ?>" aria-label="<?php esc_attr_e( 'Optional class prefix', 'wp-druid-pulse' ); ?>"></td>
	<td><select data-field="scope" aria-label="<?php esc_attr_e( 'Page scope', 'wp-druid-pulse' ); ?>">
		<option value="all" <?php selected( ! isset( $row['pages'] ) ); ?>><?php esc_html_e( 'All frontend pages', 'wp-druid-pulse' ); ?></option>
		<option value="pages" <?php selected( isset( $row['pages'] ) ); ?>><?php esc_html_e( 'Specific content', 'wp-druid-pulse' ); ?></option>
	</select><br><input data-field="pages" type="text" value="<?php echo esc_attr( implode( ', ', $row['pages'] ?? array() ) ); ?>" placeholder="123, 456, home:posts" aria-label="<?php esc_attr_e( 'Content IDs, separated by commas', 'wp-druid-pulse' ); ?>"></td>
	<td><button type="button" class="button" data-remove><?php esc_html_e( 'Remove', 'wp-druid-pulse' ); ?></button></td>
</tr>
