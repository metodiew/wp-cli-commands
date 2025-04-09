<?php
/**
 * When you have a WordPress multisite you cannot simpy use wp user delete as each sub-site has users attached to the sites.
 * If you want to keep the content, you should reassign the content, which is happening in wp-admin/network/users.php page.
 *
 * The script helps automating the process and it's super useful when you wnat to replicate a produciton environment and
 * delete all users, for localhost purposes.
 */

// Make sure WP-CLI is available before proceeding
if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

// Register a new WP-CLI command: `wp content reassign --to=<user_id>`
WP_CLI::add_command( 'content reassign', function( $args, $assoc_args ) {
	// Get the placeholder user ID from the command argument
	$placeholder_user_id = isset( $assoc_args['to'] ) ? intval( $assoc_args['to'] ) : null;

	// Validate the user ID
	if ( ! $placeholder_user_id || ! get_userdata( $placeholder_user_id ) ) {
		WP_CLI::error( 'Please provide a valid user ID using --to=<user_id>.' );
	}

	// Get a list of all user IDs in the network, excluding the placeholder user
	$all_user_ids    = get_users( [ 'fields' => 'ID' ] );
	$users_to_delete = array_diff( $all_user_ids, [ $placeholder_user_id ] );

	// Get all site IDs in the network
	$sites = get_sites( [ 'fields' => 'ids' ] );

	foreach ( $sites as $blog_id ) {
		switch_to_blog( $blog_id );
		WP_CLI::log( 'Switched to site ID '. $blog_id );

		// Get all post types
		$post_types = get_post_types( [], 'names' );

		// Reassign content for each user marked for deletion
		foreach ( $users_to_delete as $user_id ) {
			foreach ( $post_types as $post_type ) {
				// Fetch all post IDs authored by the user for this post type
				$posts = get_posts( [
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'author'         => $user_id,
					'fields'         => 'ids',
				] );

				// Reassign post authorship
				foreach ( $posts as $post_id ) {
					wp_update_post( [
						'ID'          => $post_id,
						'post_author' => $placeholder_user_id,
					] );
				}

				if ( $posts ) {
					WP_CLI::log( 'Reassigned ' . count( $posts ) . ' {$post_type} posts from user $user_id to $placeholder_user_id on site $blog_id.' );
				}
			}
		}

		// Get all users that belong to the current site
		$blog_users = get_users( [
			'blog_id' => $blog_id,
			'fields'  => 'ID',
		] );

		// Remove all users from the site except the placeholder user
		foreach ( $blog_users as $user_id ) {
			if ( $user_id === $placeholder_user_id ) {
				continue;
			}

			remove_user_from_blog( $user_id, $blog_id );
			WP_CLI::log( 'Removed user '. $user_id .' from site '. $blog_id );
		}

		// Ensure the placeholder user is added to the site as an administrator
		if ( ! is_user_member_of_blog( $placeholder_user_id, $blog_id ) ) {
			add_user_to_blog( $blog_id, $placeholder_user_id, 'administrator' );
			WP_CLI::log( 'Added user '. $placeholder_user_id .' as administrator to site '. $blog_id );
		}

		// Return to the original blog
		restore_current_blog();
	}

	// Delete the removed users from the entire network
	foreach ( $users_to_delete as $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = wpmu_delete_user( $user_id );
		if ( $deleted ) {
			WP_CLI::success( 'Deleted user '. $user_id .' from the network.' );
		} else {
			WP_CLI::warning( 'Failed to delete user '. $user_id );
		}
	}

	WP_CLI::success( 'Content reassignment and user cleanup complete.' );
} );
