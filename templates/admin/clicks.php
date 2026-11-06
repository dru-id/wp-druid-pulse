<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'DruID Pulse — Clicks', 'wp-druid-pulse' ); ?></h1>
	<p><?php esc_html_e( 'Choose which links and HTML buttons to track for signed-in visitors. No clicks are captured until an inclusion rule is saved. Page views are configured independently.', 'wp-druid-pulse' ); ?></p>
	<p><?php esc_html_e( 'Selectors match the link or button itself: use #main-menu a[href] for links inside a menu, a[href] for all links, or button.offer for buttons with that class. A class prefix matches the start of any individual class. Exclusions take priority; overlapping rules record the action once.', 'wp-druid-pulse' ); ?></p>
	<p><?php esc_html_e( 'For specific content, enter its IDs separated by commas, or home:posts for the latest-posts homepage. IDs remain valid when permalinks change. Unavailable content does not capture clicks.', 'wp-druid-pulse' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-druid-pulse' ) ); ?>"><?php esc_html_e( 'Find content IDs in the catalog', 'wp-druid-pulse' ); ?></a></p>
	<p><?php esc_html_e( 'Events include the source page, element label and known destination, including telephone and email links. Form values and HTML are not collected. Events stay in the local queue for up to 7 days; scheduled delivery to Pulse is not available yet.', 'wp-druid-pulse' ); ?></p>
	<?php if ( '1' === ( $_GET['saved'] ?? null ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Click rules saved.', 'wp-druid-pulse' ); ?></p></div><?php endif; ?>
	<noscript><div class="notice notice-error"><p><?php esc_html_e( 'Enable JavaScript to edit and validate CSS selectors.', 'wp-druid-pulse' ); ?></p></div></noscript>
	<form id="wp-druid-pulse-click-rules" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-invalid-selector="<?php esc_attr_e( 'Enter a valid CSS selector.', 'wp-druid-pulse' ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $save_action ); ?>">
		<?php wp_nonce_field( $save_action ); ?>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Type', 'wp-druid-pulse' ); ?></th><th><?php esc_html_e( 'CSS selector', 'wp-druid-pulse' ); ?></th><th><?php esc_html_e( 'Class prefix (optional)', 'wp-druid-pulse' ); ?></th><th><?php esc_html_e( 'Pages', 'wp-druid-pulse' ); ?></th><th><?php esc_html_e( 'Action', 'wp-druid-pulse' ); ?></th></tr></thead>
			<tbody><?php foreach ( $rows as $row ) { require __DIR__ . '/click-rule.php'; } ?></tbody>
		</table>
		<p><button type="button" class="button" data-add><?php esc_html_e( 'Add rule', 'wp-druid-pulse' ); ?></button></p>
		<template><?php $row = array( 'mode' => 'include', 'selector' => '' ); require __DIR__ . '/click-rule.php'; ?></template>
		<input type="hidden" name="complete" value="0">
		<?php submit_button( __( 'Save click rules', 'wp-druid-pulse' ) ); ?>
	</form>
</div>
