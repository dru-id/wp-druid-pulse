<?php

namespace WP_Druid\Services;

if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	exit;
}

final class Users {
	public static array $ids = array();

	public static function get_druid_id_by_wp_user_id( int $id ): ?string {
		return self::$ids[ $id ] ?? null;
	}
}
