<?php
/*
Plugin Name: Facebook to WP Posts
Plugin URI: http://bbioon.com/
Description: A simple plugin to get facebook page posts and save them as wordpress posts with comments
Author: Ahmed Wael
Author URI: http://bbioon.com
Version: 0.3
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

//add_action( 'facebook_data', 'get_posts_from_fb_page', 10, 3 );
function get_posts_from_fb_page( $page_id, $access_token, $limit = 15 ) {
	if ( $limit > 100 ) {
		$limit = 100; //Set maximum limit of posts in request
	}
	$temp_data = get_transient( 'fb_data_' . $page_id );
	if ( $temp_data ) {
		//data is still in db
		return $temp_data;
	} else {
		//data expired.
		$link     = 'https://graph.facebook.com/' . $page_id . '/posts?fields=id,name,full_picture,picture,message,comments{created_time,from,message,like_count}&limit=' . $limit . '&access_token=' . $access_token;
		$response = wp_remote_retrieve_body( wp_remote_get( $link ) );
		$response = json_decode( $response, true );
		if ( isset( $response['error'] ) ) {
			//Error message
			return false;
		} else {
			delete_transient( 'fb_data_' . $page_id );
			set_transient( 'fb_data_' . $page_id, $response, DAY_IN_SECONDS ); //Save data in db for one day only
			//return data to use it
			return $response;
		}
	}
}

function fb_upload_image_from_url( $image_url = '', $post_id = false ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tmp = download_url( $image_url );
	// Set variables for storage
	// fix file filename for query strings
	preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $image_url, $matches );
	$file_array['name']     = basename( $matches[0] );
	$file_array['tmp_name'] = $tmp;
	// If error storing temporarily, unlink
	if ( is_wp_error( $tmp ) ) {
		@unlink( $file_array['tmp_name'] );
		$file_array['tmp_name'] = '';
	}
	$time = current_time( 'mysql' );
	$file = wp_handle_sideload( $file_array, array( 'test_form' => false ), $time );
	if ( isset( $file['error'] ) ) {
		return new WP_Error( 'upload_error', $file['error'] );
	}
	$url        = $file['url'];
	$type       = $file['type'];
	$file       = $file['file'];
	$title      = preg_replace( '/\.[^.]+$/', '', basename( $file ) );
	$parent     = (int) absint( $post_id ) > 0 ? absint( $post_id ) : 0;
	$attachment = array(
		'post_mime_type' => $type,
		'guid'           => $url,
		'post_parent'    => $parent,
		'post_title'     => $title,
		'post_content'   => '',
	);
	$id         = wp_insert_attachment( $attachment, $file, $parent );
	if ( ! is_wp_error( $id ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$data = wp_generate_attachment_metadata( $id, $file );
		wp_update_attachment_metadata( $id, $data );
		if ( $parent > 0 ) {
			update_post_meta( $parent, '_thumbnail_id', $id );
		}

	}

	return $id;
}

add_action( 'init', 'fb_posts_to_wp_posts' );
function fb_posts_to_wp_posts() {
	$saved_posts    = [];
	$p_id           = '11239244970';
	$access_token   = '8881067075299314|xXB66LrLJNxtDm1Slq8XfAC6mQLY';
	$comments_limit = 10;
	if ( get_option( 'fb_post_ids_' . $p_id ) ) {
		$saved_posts = get_option( 'fb_post_ids_' . $p_id );
	}
	$fb_posts = get_posts_from_fb_page( $p_id, $access_token );
	if ( $fb_posts['data'] ) {
		//require_once ABSPATH . 'wp-includes/class-wp-user.php';
		foreach ( $fb_posts['data'] as $wp_post ) {
			if ( in_array( $wp_post['id'], $saved_posts ) ) {
				continue; //Do not duplicate this post
			} else {
				//insert post and update posts array
				$post_thumb       = $wp_post['full_picture'];
				$post_content     = $wp_post['message'];
				$post_shared_date = $wp_post['created_time'];
				$comments         = $wp_post['comments'];
				/**
				 * ToDo search for hashtags and convert them to wp post tags
				 * ToDo Create a category for the posts using the page name
				 */
				$post_args = array(
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_title'   => wp_trim_words( $post_content, 5, '...' ),
					'post_content' => $post_content
				);
				$post_id   = wp_insert_post( $post_args );
				if ( ! is_wp_error( $post_id ) ) {

					$thumb_id = fb_upload_image_from_url( $post_thumb, $post_id );
					update_post_meta( $post_id, 'fb_shared_date', $post_shared_date );


					if ( isset( $comments['data'] ) ) {

						$fb_comments_arr = $comments['data'];
						if ( $comments_limit ) {
							$fb_comments_arr = array_slice( $comments['data'], $comments_limit ); //put a limit for comments count
						}

						foreach ( $fb_comments_arr as $one_comment ) {
							$commentdata = array(
								'comment_post_ID'      => $post_id,
								// to which post the comment will show up
								'comment_author'       => $one_comment['from']['name'],
								//fixed value - can be dynamic
								'comment_author_email' => '',
								//fixed value - can be dynamic
								'comment_author_url'   => 'http://facebook.com/' . $one_comment['from']['id'],
								//fixed value - can be dynamic
								'comment_content'      => $one_comment['message'],
								//fixed value - can be dynamic
								'comment_type'         => '',
								//empty for regular comments, 'pingback' for pingbacks, 'trackback' for trackbacks
								'comment_parent'       => 0,
								//0 if it's not a reply to another comment; if it's a reply, mention the parent comment ID here
								'user_id'              => 1,
								//passing current user ID or any predefined as per the demand
							);

							//Insert new comment and get the comment ID
							$comment_id = wp_new_comment( $commentdata );
						}
					}
					$saved_posts[] = $wp_post['id'];
					update_option( 'fb_post_ids_' . $p_id, $saved_posts );
				}
			}
		}
	}
}