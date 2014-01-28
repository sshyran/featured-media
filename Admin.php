<?php
/*
Copyright © 2014 Code for the People Ltd

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

namespace FeaturedMedia;

class Admin {

	public $no_recursion = false;

	public $preview_args = array(
		'width'  => 256, /* fits admin area meta box */
		'height' => 512
	);

	public function __construct() {

		# Filters
		# (none)

		# Actions
		add_action( 'add_meta_boxes',    array( $this, 'action_add_meta_boxes' ), 10, 2 );
		add_action( 'save_post',         array( $this, 'action_save_post' ), 10, 2 );
		add_action( 'load-post.php',     array( $this, 'action_load_post' ) );
		add_action( 'load-post-new.php', array( $this, 'action_load_post' ) );

		# AJAX Actions:
		add_action( 'wp_ajax_featured_media_fetch', array( $this, 'ajax_fetch' ) );

	}

	public function ajax_fetch() {

		if ( ! isset( $_POST['post_id'] ) or ! $post = get_post( absint( $_POST['post_id'] ) ) ) {
			wp_send_json_error( array(
				'error_code'    => 'no_post',
				'error_message' => __( 'Invalid post ID.', 'featured-media' )
			) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) )
			die( '-1' );

		if ( ! isset( $_POST['url'] ) or ! $url = trim( wp_unslash( $_POST['url'] ) ) ) {
			wp_send_json_error( array(
				'error_code'    => 'no_url',
				'error_message' => __( 'No URL entered.', 'featured-media' )
			) );
		}

		$url = esc_url_raw( $url );

		if ( is_ssl() )
			$url = set_url_scheme( $url );

		if ( ! FeaturedMedia::init()->get_oembed_provider( $url ) ) {
			wp_send_json_error( array(
				'error_code'    => 'no_provider',
				'error_message' => __( 'The URL you entered is not supported.', 'featured-media' )
			) );
		}

		$oembed = new oEmbed( $url );

		# @TODO test that oEmbed is for a video

		if ( ! $details = $oembed->fetch_details( $this->preview_args ) ) {
			wp_send_json_error( array(
				'error_code'    => 'no_results',
				'error_message' => __( 'Video details could not be fetched. Please try again shortly.', 'featured-media' )
			) );
		}

		wp_send_json_success( $details );

	}

	public function action_add_meta_boxes( $post_type, \WP_Post $post ) {

		if ( ! post_type_supports( $post_type, 'thumbnail' ) )
			return;

		remove_meta_box( 'postimagediv', null, 'side' );

		add_meta_box(
			'featured-media',
			__( 'Featured Media', 'featured-media' ),
			array( $this, 'metabox' ),
			$post_type,
			'side',
			'high'
		);

	}

	public function action_load_post() {

		$post_type = get_current_screen()->post_type;

		if ( ! post_type_supports( $post_type, 'thumbnail' ) )
			return;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

	}

	public function enqueue_styles() {

		$plugin = FeaturedMedia::init();

		wp_enqueue_style(
			'featured-media',
			$plugin->plugin_url( 'assets/admin.css' ),
			array( 'wp-admin' ),
			$plugin->plugin_ver( 'assets/admin.css' )
		);

	}

	public function enqueue_scripts() {

		$plugin = FeaturedMedia::init();

		wp_enqueue_script(
			'featured-media',
			$plugin->plugin_url( 'assets/admin.js' ),
			array( 'backbone', 'jquery', 'media-editor' ),
			$plugin->plugin_ver( 'assets/admin.js' )
		);

		wp_localize_script(
			'featured-media',
			'featuredmedia',
			array(
				'post' => array(
					'id'    => get_the_ID(),
					'nonce' => wp_create_nonce( 'update-post_' . get_the_ID() ),
				),
			)
		);

	}

	public function metabox( \WP_Post $post, array $args ) {

		wp_nonce_field( "featured-media-{$post->ID}", '_featured_media_nonce' );

		$video   = new PostVideo( $post );
		$preview = $video->get_embed_code( $this->preview_args );

		if ( has_post_thumbnail( $post->ID ) )
			$thumbnail = get_the_post_thumbnail( $post->ID, array_values( $this->preview_args ) );
		else
			$thumbnail = '';

		$gallery = '';
		$class = 'no_fm';
		if ( !empty( $thumbnail ) or !empty( $preview ) )
			$class = '';

		?>
		<div id="featured-media-container">
			<div class="fm_thumbnail"<?php if ( empty( $thumbnail ) ) echo ' style="display:none"'; ?>><a href="#" class="fm_x fm_thumbnail_x">X</a><div class="fm"><?php echo $thumbnail; ?></div></div>
			<div class="fm_previewer"<?php if ( empty( $preview ) ) echo ' style="display:none"'; ?>><a href="#" class="fm_x fm_previewer_x">X</a><div class="fm"><?php echo $preview; ?></div></div>
			<div class="fm_gallery"><?php echo $gallery; ?></div>
			<p id="featured-media-set" class="<?php echo $class; ?>">
				<a href="#" data-set-feature="thumbnail"><?php _e( 'Image', 'featured-media' ); ?></a>
				<a href="#" data-set-feature="video"><?php _e( 'Video', 'featured-media' ); ?></a>
				<a href="#" data-set-feature="gallery"><?php _e( 'Gallery', 'featured-media' ); ?></a>
			</p>
			<input type="text" name="featured-media-video-url" placeholder="<?php esc_attr_e( 'Enter video page URL', 'featured-media' ); ?>" value="<?php echo esc_attr( $video->get_url() ); ?>" data-for-feature="video">
		</div>
		<?php

	}

	public function action_save_post( $post_id, \WP_Post $post ) {

		if ( $this->no_recursion )
			return;
		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) )
			return;
		if ( ! isset( $_POST[ '_featured_media_nonce' ] ) )
			return;

		check_admin_referer( "featured-media-{$post->ID}", '_featured_media_nonce' );

		$video = new PostVideo( $post );
		$video_url   = trim( wp_unslash( $_POST['featured-media-video-url'] ) );

		if ( ! empty( $video_url ) ) {

			if ( ! $video->update_url( $video_url ) ) {
				# @TODO set admin error?
				return;
			}

			$video->update_details();

		} else {

			$video->delete_url();

		}

	}

}