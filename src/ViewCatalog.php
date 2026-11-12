<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ViewCatalog {
	public const PAGE_SIZE = 20;

	public static function types(): array {
		return array_filter(
			get_post_types( array( 'public' => true ), 'objects' ),
			static fn( $type ) => 'attachment' !== $type->name && is_post_type_viewable( $type )
		);
	}

	public static function is_eligible( int $id ): bool {
		$post = get_post( $id );

		return $post && 'publish' === $post->post_status && isset( self::types()[ $post->post_type ] );
	}

	public static function has_posts_home(): bool {
		return 'posts' === get_option( 'show_on_front' );
	}

	public function page( array $selection, array $filters, bool $exclude_password_protected = false ): array {
		if ( 'unavailable' === $filters['selection'] ) {
			return $this->unavailable_page( $selection, $filters );
		}

		$home = $this->home_matches( $selection, $filters );
		$types = $filters['post_type'] ? array( $filters['post_type'] ) : array_keys( self::types() );
		$page = $filters['paged'];
		$args = array(
			'post_type'              => $types,
			'post_status'            => 'publish',
			'orderby'                => array( 'title' => 'ASC', 'ID' => 'ASC' ),
			'ignore_sticky_posts'    => true,
			's'                      => $filters['search'],
			'search_columns'         => array( 'post_title' ),
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( $exclude_password_protected ) {
			$args['has_password'] = false;
		}

		if ( 'selected' === $filters['selection'] ) {
			$args['post__in'] = $selection['post_ids'] ?: array( 0 );
		} elseif ( 'unselected' === $filters['selection'] ) {
			$args['post__not_in'] = $selection['post_ids'];
		}

		do {
			$offset = ( $page - 1 ) * self::PAGE_SIZE;
			$show_home = $home && 1 === $page;
			$args['offset'] = max( 0, $offset - (int) $home );
			$args['posts_per_page'] = self::PAGE_SIZE - (int) $show_home;
			$query = $types ? new \WP_Query( $args ) : null;
			$total = ( $query ? (int) $query->found_posts : 0 ) + (int) $home;
			$last_page = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
			$retry = $page > $last_page;
			$page = min( $page, $last_page );
		} while ( $retry );

		$rows = $show_home ? array( $this->home_row() ) : array();
		foreach ( $query ? $query->posts : array() as $post ) {
			$rows[] = $this->post_row( $post, true );
		}

		return array( 'rows' => $rows, 'total' => $total, 'paged' => $page, 'pages' => $last_page );
	}

	private function unavailable_page( array $selection, array $filters ): array {
		$types = array_keys( self::types() );
		$eligible = array();
		if ( $selection['post_ids'] && $types ) {
			$eligible = get_posts( array(
				'post__in' => $selection['post_ids'], 'post_type' => $types, 'post_status' => 'publish',
				'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => false,
			) );
		}
		$ids = array_values( array_diff( $selection['post_ids'], $eligible ) );
		$posts = array();
		if ( $ids ) {
			foreach ( get_posts( array(
				'post__in' => $ids, 'post_type' => array_keys( get_post_types() ),
				'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'suppress_filters' => false,
			) ) as $post ) {
				$posts[ $post->ID ] = $post;
			}
		}

		$rows = array();
		if ( $selection['posts_home'] && ! self::has_posts_home() && $this->home_matches( $selection, $filters ) ) {
			$rows[] = $this->home_row();
		}
		foreach ( $ids as $id ) {
			$post = $posts[ $id ] ?? null;
			if ( $filters['post_type'] && ( ! $post || $post->post_type !== $filters['post_type'] ) ) {
				continue;
			}
			$row = $post ? $this->post_row( $post, false ) : array(
				'id' => $id, 'title' => sprintf( __( 'Unavailable content (#%d)', 'wp-druid-pulse' ), $id ),
				'type' => '—', 'url' => '', 'labels' => array( __( 'Unavailable', 'wp-druid-pulse' ) ),
			);
			if ( $filters['search'] && false === stripos( $row['title'] . ' ' . $id, $filters['search'] ) ) {
				continue;
			}
			$rows[] = $row;
		}

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page = min( $filters['paged'], $pages );

		return array( 'rows' => array_slice( $rows, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE ), 'total' => $total, 'paged' => $page, 'pages' => $pages );
	}

	private function home_matches( array $selection, array $filters ): bool {
		if ( $filters['post_type'] || ( 'unavailable' !== $filters['selection'] && ! self::has_posts_home() ) ) {
			return false;
		}
		if ( 'selected' === $filters['selection'] && ! $selection['posts_home'] ) {
			return false;
		}
		if ( 'unselected' === $filters['selection'] && $selection['posts_home'] ) {
			return false;
		}

		return '' === $filters['search'] || false !== stripos( $this->home_row()['title'], $filters['search'] );
	}

	private function home_row(): array {
		return array(
			'id' => 'posts_home', 'title' => __( 'Front page (latest posts)', 'wp-druid-pulse' ),
			'type' => __( 'Homepage', 'wp-druid-pulse' ), 'url' => self::has_posts_home() ? home_url( '/' ) : '',
			'labels' => self::has_posts_home() ? array() : array( __( 'Unavailable', 'wp-druid-pulse' ) ),
		);
	}

	private function post_row( \WP_Post $post, bool $available ): array {
		$type = get_post_type_object( $post->post_type );
		$title = $available || current_user_can( 'read_post', $post->ID ) ? get_the_title( $post ) : '';
		$labels = $available ? array() : array( __( 'Unavailable', 'wp-druid-pulse' ) );
		if ( $available && 'page' === get_option( 'show_on_front' ) ) {
			if ( $post->ID === (int) get_option( 'page_on_front' ) ) {
				$labels[] = __( 'Front page', 'wp-druid-pulse' );
			}
			if ( $post->ID === (int) get_option( 'page_for_posts' ) ) {
				$labels[] = __( 'Posts page', 'wp-druid-pulse' );
			}
		}

		return array(
			'id' => $post->ID, 'title' => $title ?: sprintf( __( 'Content #%d (no available title)', 'wp-druid-pulse' ), $post->ID ),
			'type' => $type ? $type->labels->singular_name : $post->post_type,
			'url' => $available ? (string) get_permalink( $post ) : '', 'labels' => $labels,
		);
	}
}
