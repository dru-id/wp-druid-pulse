<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pulse-clicks">
	<h1><?php esc_html_e( 'DruID Pulse — Clicks', 'wp-druid-pulse' ); ?></h1>
	<p><?php esc_html_e( 'Choose which links and buttons to track for signed-in visitors. Exclusions override tracking rules.', 'wp-druid-pulse' ); ?></p>
	<p><?php esc_html_e( 'To track every link and button on a page, add a rule, keep “All links and buttons” and choose the page. Page views are configured separately.', 'wp-druid-pulse' ); ?></p>
	<?php if ( '1' === ( $_GET['saved'] ?? null ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Click rules saved.', 'wp-druid-pulse' ); ?></p></div><?php endif; ?>
	<noscript><div class="notice notice-error"><p><?php esc_html_e( 'Enable JavaScript to edit click rules.', 'wp-druid-pulse' ); ?></p></div></noscript>
	<form id="wp-druid-pulse-click-rules" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate
		data-invalid-selector="<?php esc_attr_e( 'Enter a valid CSS selector.', 'wp-druid-pulse' ); ?>"
		data-invalid-prefix="<?php esc_attr_e( 'Enter one class prefix without spaces.', 'wp-druid-pulse' ); ?>"
		data-missing-pages="<?php esc_attr_e( 'Choose at least one page for this rule.', 'wp-druid-pulse' ); ?>"
		data-page-label="<?php esc_attr_e( 'Page %1$s of %2$s · %3$s rules', 'wp-druid-pulse' ); ?>"
		data-remove-label="<?php esc_attr_e( 'Remove %s', 'wp-druid-pulse' ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $save_action ); ?>">
		<?php wp_nonce_field( $save_action ); ?>
		<div class="notice notice-error inline" data-editor-error hidden tabindex="-1"><p></p></div>
		<div class="pulse-rule-table"><table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Click tracking rules', 'wp-druid-pulse' ); ?></caption>
			<thead><tr><th scope="col"><?php esc_html_e( 'Action', 'wp-druid-pulse' ); ?></th><th scope="col"><?php esc_html_e( 'Which elements?', 'wp-druid-pulse' ); ?></th><th scope="col"><?php esc_html_e( 'Where?', 'wp-druid-pulse' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove rule', 'wp-druid-pulse' ); ?></span></th></tr></thead>
			<tbody><?php foreach ( $rows as $row ) { require __DIR__ . '/click-rule.php'; } ?></tbody>
		</table></div>
		<p data-empty <?php echo $rows ? 'hidden' : ''; ?>><?php esc_html_e( 'No click rules yet. Add a rule to start tracking.', 'wp-druid-pulse' ); ?></p>
		<div class="pulse-rule-navigation">
			<button type="button" class="button" data-add><?php esc_html_e( 'Add rule', 'wp-druid-pulse' ); ?></button>
			<nav aria-label="<?php esc_attr_e( 'Rule pages', 'wp-druid-pulse' ); ?>" data-pagination>
				<button type="button" class="button" data-previous><?php esc_html_e( 'Previous', 'wp-druid-pulse' ); ?></button>
				<span data-rule-page aria-live="polite"></span>
				<button type="button" class="button" data-next><?php esc_html_e( 'Next', 'wp-druid-pulse' ); ?></button>
			</nav>
		</div>
		<template><?php $row = array( 'mode' => 'include', 'selector' => 'a[href], button', 'pages' => array() ); require __DIR__ . '/click-rule.php'; ?></template>
		<input type="hidden" name="rules_json" value="">
		<input type="hidden" name="complete" value="0">
		<p class="description"><?php esc_html_e( 'Save applies changes on every page of rules. Changing pages keeps your unsaved edits.', 'wp-druid-pulse' ); ?></p>
		<?php submit_button( __( 'Save click rules', 'wp-druid-pulse' ) ); ?>
	</form>
	<script type="application/json" id="pulse-click-page-labels"><?php echo wp_json_encode( $labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
	<dialog id="pulse-page-picker" aria-labelledby="pulse-page-picker-title" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( $search_action ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( $search_action ) ); ?>"
		data-failed="<?php esc_attr_e( 'Pages could not be loaded. Try searching again.', 'wp-druid-pulse' ); ?>"
		data-loading="<?php esc_attr_e( 'Loading pages…', 'wp-druid-pulse' ); ?>"
		data-empty="<?php esc_attr_e( 'No pages match this search.', 'wp-druid-pulse' ); ?>"
		data-status="<?php esc_attr_e( 'Page %1$s of %2$s · %3$s results', 'wp-druid-pulse' ); ?>">
		<h2 id="pulse-page-picker-title"><?php esc_html_e( 'Choose pages', 'wp-druid-pulse' ); ?></h2>
		<div class="pulse-page-search"><label for="pulse-page-search"><?php esc_html_e( 'Page title', 'wp-druid-pulse' ); ?></label><input id="pulse-page-search" type="search"><button type="button" class="button" data-search><?php esc_html_e( 'Search', 'wp-druid-pulse' ); ?></button></div>
		<ul data-chosen-pages aria-label="<?php esc_attr_e( 'Selected pages', 'wp-druid-pulse' ); ?>"></ul>
		<p data-search-status role="status"></p>
		<ul data-page-results></ul>
		<div class="pulse-rule-navigation"><button type="button" class="button" data-search-previous><?php esc_html_e( 'Previous results', 'wp-druid-pulse' ); ?></button><button type="button" class="button" data-search-next><?php esc_html_e( 'Next results', 'wp-druid-pulse' ); ?></button></div>
		<div class="pulse-rule-navigation"><button type="button" class="button button-primary" data-apply-pages><?php esc_html_e( 'Use selected pages', 'wp-druid-pulse' ); ?></button><button type="button" class="button" data-cancel-pages><?php esc_html_e( 'Cancel', 'wp-druid-pulse' ); ?></button></div>
	</dialog>
</div>
