<?php

use WP_Druid_Pulse\EventStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pulse-diagnostics">
	<h1><?php esc_html_e( 'DruID Pulse — Diagnostics', 'wp-druid-pulse' ); ?></h1>
	<p><?php esc_html_e( 'Check whether events are waiting, need attention or are ready to send.', 'wp-druid-pulse' ); ?></p>
	<?php if ( null !== $error ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Read-only diagnostics: initialization, queue or delivery state is unavailable. Check the plugin schema, database access and Action Scheduler before using maintenance actions.', 'wp-druid-pulse' ); ?></p></div>
			<p><?php echo esc_html( $this->error_description( $error->get_error_code() ) ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $feedback ) : ?>
		<div class="notice notice-info"><p><?php echo esc_html( $feedback ); ?></p></div>
	<?php endif; ?>
	<div class="pulse-diagnostic-panels">
		<section class="pulse-diagnostic-panel" aria-labelledby="pulse-installation">
			<h2 id="pulse-installation"><?php esc_html_e( 'Tracking and connection', 'wp-druid-pulse' ); ?></h2>
			<p><?php echo esc_html( is_wp_error( $views ) ? __( 'Page selection is unavailable.', 'wp-druid-pulse' ) : sprintf( __( 'Saved page selections: %d. Review your Page views selection.', 'wp-druid-pulse' ), count( $views['post_ids'] ) + (int) $views['posts_home'] ) ); ?></p>
			<p><?php echo esc_html( is_wp_error( $clicks ) ? __( 'Click rules are unavailable.', 'wp-druid-pulse' ) : sprintf( __( 'Click rules: %1$d inclusions, %2$d exclusions.', 'wp-druid-pulse' ), count( $clicks['include'] ), count( $clicks['exclude'] ) ) ); ?></p>
			<p><?php echo esc_html( is_wp_error( $connection ) ? __( 'Connection settings are incomplete or invalid. Review Connection.', 'wp-druid-pulse' ) : __( 'Connection settings are complete. Remote access and delivery are not verified here.', 'wp-druid-pulse' ) ); ?></p>
		</section>
		<section class="pulse-diagnostic-panel" aria-labelledby="pulse-delivery">
			<h2 id="pulse-delivery"><?php esc_html_e( 'Background delivery', 'wp-druid-pulse' ); ?></h2>
			<?php if ( is_wp_error( $delivery ) ) : ?>
				<p><?php esc_html_e( 'Delivery state is unavailable.', 'wp-druid-pulse' ); ?></p>
			<?php else : ?>
				<p><strong><?php echo esc_html( $delivery['retry_at'] > time() ? sprintf( __( 'Delivery paused until %s.', 'wp-druid-pulse' ), wp_date( 'Y-m-d H:i:s', $delivery['retry_at'] ) ) : __( 'Delivery is not paused.', 'wp-druid-pulse' ) ); ?></strong></p>
				<?php if ( '' !== $delivery['error'] ) : ?><p><?php echo esc_html( $this->error_description( $delivery['error'] ) ); ?></p><?php endif; ?>
			<?php endif; ?>
			<details><summary><?php esc_html_e( 'Technical details and scheduled tasks', 'wp-druid-pulse' ); ?></summary>
			<p><?php echo esc_html( sprintf( __( 'Plugin %1$s · WordPress %2$s · PHP %3$s · Schema %4$s', 'wp-druid-pulse' ), $header['version'], get_bloginfo( 'version' ), PHP_VERSION, $schema ) ); ?></p>
			<p><?php echo esc_html( sprintf( __( 'Action Scheduler: %s', 'wp-druid-pulse' ), $scheduler_version ?: __( 'Unavailable', 'wp-druid-pulse' ) ) ); ?></p>
			<?php if ( ! is_wp_error( $application ) ) : ?><p><strong><?php esc_html_e( 'Installation ID:', 'wp-druid-pulse' ); ?></strong> <code><?php echo esc_html( $application['id'] ); ?></code></p><?php endif; ?>
			<p><?php echo esc_html( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Automatic WP-Cron spawning is disabled. An external cron or Action Scheduler runner may still execute tasks; verify it separately.', 'wp-druid-pulse' ) : __( 'WP-Cron spawning is enabled. Scheduled dates alone do not confirm that a runner is executing tasks.', 'wp-druid-pulse' ) ); ?></p>
			<?php if ( is_wp_error( $tasks ) ) : ?>
				<p><?php esc_html_e( 'Task status is unavailable. Check Action Scheduler.', 'wp-druid-pulse' ); ?></p>
			<?php else : ?>
				<ul><?php foreach ( $tasks as $label => $next ) : ?><li><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $next ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( admin_url( 'tools.php?page=action-scheduler&s=wp_druid_pulse_' ) ); ?>"><?php esc_html_e( 'View tasks and logs in Action Scheduler', 'wp-druid-pulse' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'A completed task may have scheduled a retry; it does not prove event delivery. Task logs follow Action Scheduler retention.', 'wp-druid-pulse' ); ?></p>
			</details>
		</section>
	</div>
	<h2><?php esc_html_e( 'Retained events', 'wp-druid-pulse' ); ?></h2>
	<?php if ( null === $queue ) : ?>
		<p><?php esc_html_e( 'Queue statistics are unavailable for this installation.', 'wp-druid-pulse' ); ?></p>
	<?php else : ?>
		<div class="pulse-diagnostic-counts">
			<?php foreach ( $labels as $key => $label ) : ?>
				<?php if ( 'unknown' === $key && 0 === $queue['counts'][ $key ] ) { continue; } ?>
				<div><strong><?php echo esc_html( number_format_i18n( $queue['counts'][ $key ] ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<p><?php echo esc_html( sprintf( __( 'Queue usage: %1$s / %2$s events · %3$s / 100 MiB of stored context.', 'wp-druid-pulse' ), number_format_i18n( $queue['events'] ), number_format_i18n( EventStore::MAX_EVENTS ), size_format( $queue['bytes'], 2 ) ) ); ?>
			<?php echo esc_html( null === $queue['oldest'] ? __( 'No live pending events.', 'wp-druid-pulse' ) : sprintf( __( 'Oldest live pending event received: %1$s (%2$s ago).', 'wp-druid-pulse' ), $this->date( $queue['oldest'] ), human_time_diff( strtotime( $queue['oldest'] . ' UTC' ) ) ) ); ?></p>
		<p class="description"><?php esc_html_e( 'Events remain here until delivered or until their seven-day expiry. Delivered events disappear; this list is not a delivery history.', 'wp-druid-pulse' ); ?></p>
		<?php if ( null === $error ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pulse-diagnostic-actions">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<?php wp_nonce_field( $action ); ?>
				<button type="submit" class="button" name="operation" value="process"><?php esc_html_e( 'Process pending', 'wp-druid-pulse' ); ?></button>
				<button type="submit" class="button" name="operation" value="cleanup"><?php esc_html_e( 'Clean expired', 'wp-druid-pulse' ); ?></button>
				<p class="description"><?php esc_html_e( 'Process pending starts background work when retries are due. Cleanup removes expired events and accepted records awaiting deletion.', 'wp-druid-pulse' ); ?></p>
			</form>
		<?php endif; ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="pulse-diagnostic-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">
			<label for="pulse-state"><?php esc_html_e( 'State', 'wp-druid-pulse' ); ?></label>
			<select id="pulse-state" name="state"><option value=""><?php esc_html_e( 'All states', 'wp-druid-pulse' ); ?></option><?php foreach ( $labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
			<label for="pulse-type"><?php esc_html_e( 'Type', 'wp-druid-pulse' ); ?></label>
			<select id="pulse-type" name="type"><option value=""><?php esc_html_e( 'All types', 'wp-druid-pulse' ); ?></option><?php foreach ( $queue['types'] as $event_type ) : ?><option value="<?php echo esc_attr( $event_type ); ?>" <?php selected( $type, $event_type ); ?>><?php echo esc_html( $event_type ); ?></option><?php endforeach; ?></select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wp-druid-pulse' ); ?></button>
		</form>
		<p><?php echo esc_html( sprintf( __( '%1$d records match · page %2$d of %3$d · dates in %4$s.', 'wp-druid-pulse' ), $queue['total'], $queue['page'], $queue['pages'], wp_timezone_string() ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php if ( null === $error ) : ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<input type="hidden" name="operation" value="retry">
				<?php wp_nonce_field( $action ); ?>
			<?php endif; ?>
			<div class="pulse-diagnostic-table"><table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Retry', 'wp-druid-pulse' ); ?></th><th scope="col"><?php esc_html_e( 'Event / type', 'wp-druid-pulse' ); ?></th><th scope="col"><?php esc_html_e( 'State / attempts', 'wp-druid-pulse' ); ?></th><th scope="col"><?php esc_html_e( 'Dates', 'wp-druid-pulse' ); ?></th><th scope="col"><?php esc_html_e( 'Last error', 'wp-druid-pulse' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $queue['rows'] as $row ) : ?>
					<tr>
						<td><?php if ( null === $error && 'failed' === $row['state'] ) : ?>
							<input type="checkbox" name="event_ids[]" value="<?php echo esc_attr( $row['event_id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Retry event %s', 'wp-druid-pulse' ), $row['event_id'] ) ); ?>">
							<input type="hidden" name="attempts[<?php echo esc_attr( $row['event_id'] ); ?>]" value="<?php echo esc_attr( $row['attempts'] ); ?>">
						<?php else : ?>—<?php endif; ?></td>
						<td><strong><?php echo esc_html( 'page_view' === $row['event_type'] ? __( 'Page view', 'wp-druid-pulse' ) : ( 'click' === $row['event_type'] ? __( 'Click', 'wp-druid-pulse' ) : $row['event_type'] ) ); ?></strong><details><summary><?php esc_html_e( 'Event ID', 'wp-druid-pulse' ); ?></summary><code><?php echo esc_html( $row['event_id'] ); ?></code></details></td>
						<td><strong><?php echo esc_html( $labels[ $row['state'] ] ); ?></strong><br><?php echo esc_html( sprintf( __( 'Attempts: %d', 'wp-druid-pulse' ), $row['attempts'] ) ); ?></td>
						<td><?php foreach ( array( 'published' => __( 'Occurred', 'wp-druid-pulse' ), 'received_at' => __( 'Received', 'wp-druid-pulse' ), 'expires_at' => __( 'Expires', 'wp-druid-pulse' ) ) as $field => $label ) : ?><div><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $this->date( $row[ $field ] ) ); ?></div><?php endforeach; ?>
							<?php if ( 'waiting' === $row['state'] ) : ?><div><strong><?php esc_html_e( 'Retry from:', 'wp-druid-pulse' ); ?></strong> <?php echo esc_html( $this->date( max( $row['next_attempt_at'], gmdate( 'Y-m-d H:i:s', $delivery['retry_at'] ) ) ) ); ?></div><?php endif; ?></td>
						<td><?php echo esc_html( $this->error_description( $row['last_error'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $queue['rows'] ) : ?><tr><td colspan="5"><?php esc_html_e( 'No retained events match these filters.', 'wp-druid-pulse' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table></div>
			<?php if ( null === $error && in_array( 'failed', array_column( $queue['rows'], 'state' ), true ) ) : ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Retry selected failed events', 'wp-druid-pulse' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Correct the cause before retrying. Waiting periods and the original expiry still apply. Conflicts cannot be retried.', 'wp-druid-pulse' ); ?></p>
			<?php endif; ?>
		</form>
		<?php if ( $queue['pages'] > 1 ) : ?>
			<nav class="tablenav" aria-label="<?php esc_attr_e( 'Event pages', 'wp-druid-pulse' ); ?>"><?php echo wp_kses_post( paginate_links( array( 'base' => str_replace( '999999999', '%#%', add_query_arg( array( 'page' => $slug, 'state' => $state, 'type' => $type, 'paged' => 999999999 ), admin_url( 'admin.php' ) ) ), 'format' => '', 'current' => $queue['page'], 'total' => $queue['pages'] ) ) ); ?></nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
