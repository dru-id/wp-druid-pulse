<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActorResolver {
	public function resolve( int $user_id ): array|\WP_Error {
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user ) {
			return new \WP_Error( 'wp_druid_pulse_no_user', __( 'No authenticated WordPress user is available for this event.', 'wp-druid-pulse' ) );
		}
		if ( defined( 'WP_DRUID' ) ) {
			if ( ! is_callable( array( '\WP_Druid\Services\Users', 'get_druid_id_by_wp_user_id' ) ) ) {
				return new \WP_Error( 'wp_druid_pulse_identity_api', __( 'The active DruID plugin does not provide the required identity lookup.', 'wp-druid-pulse' ) );
			}
			$id = \WP_Druid\Services\Users::get_druid_id_by_wp_user_id( $user_id );
			if ( ! is_string( $id ) || '' === trim( $id ) ) {
				return new \WP_Error( 'wp_druid_pulse_no_object_id', __( 'The user has no DruID identifier. The event was not mapped.', 'wp-druid-pulse' ) );
			}
			return array( 'objectType' => 'person', 'externalIds' => array( 'druid:objectid' => $id ) );
		}

		$email = trim( $user->user_email );
		if ( ! is_email( $email ) || str_ends_with( strtolower( $email ), '@dru-id.internal' )
			|| preg_match( '/^[a-f0-9]{32}@[a-f0-9]{32}\.com$/iD', $email ) ) {
			return new \WP_Error( 'wp_druid_pulse_no_real_email', __( 'The user has no usable email address. The event was not mapped.', 'wp-druid-pulse' ) );
		}
		$profile = array( 'email' => array( 'value' => $email ) );
		foreach ( array( 'givenName' => 'first_name', 'familyName' => 'last_name' ) as $field => $meta ) {
			$value = get_user_meta( $user_id, $meta, true );
			$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
			if ( '' !== $value ) {
				$profile[ $field ] = $value;
			}
		}
		return array( 'objectType' => 'person', 'schemaOrg' => $profile );
	}
}
