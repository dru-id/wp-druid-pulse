<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'DruID Pulse — Tracking', 'wp-druid-pulse' ); ?></h1>
	<p><?php esc_html_e( 'Choose the content whose page views you want to track. Click selection is independent of this list.', 'wp-druid-pulse' ); ?></p>
	<div class="notice notice-info inline"><p><?php esc_html_e( 'Selected pages record views from signed-in visitors when ready and visible. Events are stored locally and expire after 7 days. Click capture and scheduled delivery to Pulse are not available in this version.', 'wp-druid-pulse' ); ?></p></div>
	<?php if ( '1' === ( $_GET['saved'] ?? null ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Selection saved.', 'wp-druid-pulse' ); ?></p></div>
	<?php endif; ?>
	<p><?php echo esc_html( sprintf( __( 'Saved selections: %d. New content is not selected automatically.', 'wp-druid-pulse' ), count( $selection['post_ids'] ) + (int) $selection['posts_home'] ) ); ?></p>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">
		<p>
			<label for="pulse-search"><?php esc_html_e( 'Search', 'wp-druid-pulse' ); ?></label>
			<input type="search" id="pulse-search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>">
			<label for="pulse-type"><?php esc_html_e( 'Content type', 'wp-druid-pulse' ); ?></label>
			<select id="pulse-type" name="post_type">
				<option value=""><?php esc_html_e( 'All types', 'wp-druid-pulse' ); ?></option>
				<?php foreach ( $types as $name => $type ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['post_type'], $name ); ?>><?php echo esc_html( $type->labels->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="pulse-selection"><?php esc_html_e( 'Selection', 'wp-druid-pulse' ); ?></label>
			<select id="pulse-selection" name="selection">
				<?php foreach ( $views as $name => $label ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['selection'], $name ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'wp-druid-pulse' ), 'secondary', '', false ); ?>
		</p>
	</form>
	<?php if ( 'unavailable' === $filters['selection'] ) : ?>
		<p><?php esc_html_e( 'These references remain saved but cannot produce page views. Uncheck them to remove them. Content restored with the same ID keeps its selection.', 'wp-druid-pulse' ); ?></p>
	<?php endif; ?>
	<form id="wp-druid-pulse-selection" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $save_action ); ?>">
		<?php wp_nonce_field( $save_action ); ?>
		<?php foreach ( $filters as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
		<?php endforeach; ?>
		<table class="widefat striped">
			<caption class="screen-reader-text"><?php esc_html_e( 'Content selection for page views', 'wp-druid-pulse' ); ?></caption>
			<thead><tr>
				<th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Selected', 'wp-druid-pulse' ); ?></span></th>
				<th scope="col"><?php esc_html_e( 'Title', 'wp-druid-pulse' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'wp-druid-pulse' ); ?></th>
				<th scope="col"><?php esc_html_e( 'URL', 'wp-druid-pulse' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $catalog_page['rows'] as $row ) : ?>
				<?php $is_home = 'posts_home' === $row['id']; ?>
				<tr>
					<th scope="row" class="check-column">
						<input type="hidden" name="<?php echo $is_home ? 'posts_home_visible' : 'visible_ids[]'; ?>" value="<?php echo esc_attr( $is_home ? '1' : (string) $row['id'] ); ?>">
						<input type="checkbox" id="pulse-row-<?php echo esc_attr( (string) $row['id'] ); ?>" name="<?php echo $is_home ? 'posts_home' : 'selected_ids[]'; ?>" value="<?php echo esc_attr( $is_home ? '1' : (string) $row['id'] ); ?>" <?php checked( $is_home ? $selection['posts_home'] : in_array( $row['id'], $selection['post_ids'], true ) ); ?>>
					</th>
					<td>
						<label for="pulse-row-<?php echo esc_attr( (string) $row['id'] ); ?>"><strong><?php echo esc_html( $row['title'] ); ?></strong></label>
						<?php if ( ! $is_home ) : ?><br><span class="description"><?php echo esc_html( sprintf( __( 'ID: %d', 'wp-druid-pulse' ), $row['id'] ) ); ?></span><?php endif; ?>
						<?php foreach ( $row['labels'] as $label ) : ?><br><span class="description"><?php echo esc_html( $label ); ?></span><?php endforeach; ?>
					</td>
					<td><?php echo esc_html( $row['type'] ); ?></td>
					<td><?php if ( $row['url'] ) : ?><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['url'] ); ?></a><?php else : ?>—<?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $catalog_page['rows'] ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No content matches these filters.', 'wp-druid-pulse' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( sprintf( __( 'Results: %d', 'wp-druid-pulse' ), $catalog_page['total'] ) ); ?></span>
				<?php if ( $catalog_page['pages'] > 1 ) : ?>
					<?php echo wp_kses_post( paginate_links( array(
						'base' => str_replace( '999999999', '%#%', add_query_arg( 'paged', 999999999, $page_url ) ),
						'format' => '', 'current' => $catalog_page['paged'], 'total' => $catalog_page['pages'],
					) ) ); ?>
				<?php endif; ?>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'Saving only changes the rows shown here. Selections on other pages are preserved.', 'wp-druid-pulse' ); ?></p>
		<input type="hidden" name="complete" value="1">
		<?php submit_button( __( 'Save selection', 'wp-druid-pulse' ) ); ?>
	</form>
</div>
