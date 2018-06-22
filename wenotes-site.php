<?php
/*
 *  Displays per-site WEnotes information, e.g. list of registered user blog URLs
 *  for feed harvesting
 */

/* Load WordPress Administration Bootstrap */

/* Start Wordpress Site Admin boilerplate */
require_once( dirname( __FILE__ ) . '/../../../wp-admin/admin.php' );

if ( ! current_user_can( 'manage_sites' ) ) {
	wp_die( __( 'Sorry, you are not allowed to edit this site.' ) );
}

get_current_screen()->add_help_tab( array(
	'id'      => 'wenotes-details',
	'title'   => __( 'WEnotes Details' ),
	'content' =>
		'<p>' . __( '<strong>WEnotes Details</strong> &mdash; This page shows a list of learners (with a current WordPress user account) associated with this Course, and those who have registered blog URLs which WEnotes periodically checks for suitably tagged posts to aggregate into the Course feed.' ) . '</p>'
) );

get_current_screen()->set_help_sidebar(
	'<p><strong>' . __( 'For more information:' ) . '</strong></p>' .
	'<p>' . __( '<a href="https://codex.wordpress.org/Network_Admin_Sites_Screen">Documentation on Site Management</a>' ) . '</p>' .
	'<p>' . __( '<a href="https://wordpress.org/support/forum/multisite/">Support Forums</a>' ) . '</p>'
);

$id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;

if ( ! $id ) {
	wp_die( __('Invalid site ID.') );
}

$details = get_site( $id );
if ( ! $details ) {
	wp_die( __( 'The requested site does not exist.' ) );
}

if ( ! can_edit_network( $details->site_id ) ) {
	wp_die( __( 'Sorry, you are not allowed to access this page.' ), 403 );
}

$parsed_scheme = parse_url( $details->siteurl, PHP_URL_SCHEME );
$is_main_site = is_main_site( $id );

if ( isset( $_REQUEST['action'] ) && 'update-site' == $_REQUEST['action'] ) {
	check_admin_referer( 'edit-site' );

	switch_to_blog( $id );

	// Rewrite rules can't be flushed during switch to blog.
	delete_option( 'rewrite_rules' );

	$blog_data = wp_unslash( $_POST['blog'] );
	$blog_data['scheme'] = $parsed_scheme;

	if ( $is_main_site ) {
		// On the network's main site, don't allow the domain or path to change.
		$blog_data['domain'] = $details->domain;
		$blog_data['path'] = $details->path;
	} else {
		// For any other site, the scheme, domain, and path can all be changed. We first
		// need to ensure a scheme has been provided, otherwise fallback to the existing.
		$new_url_scheme = parse_url( $blog_data['url'], PHP_URL_SCHEME );

		if ( ! $new_url_scheme ) {
			$blog_data['url'] = esc_url( $parsed_scheme . '://' . $blog_data['url'] );
		}
		$update_parsed_url = parse_url( $blog_data['url'] );

		// If a path is not provided, use the default of `/`.
		if ( ! isset( $update_parsed_url['path'] ) ) {
			$update_parsed_url['path'] = '/';
		}

		$blog_data['scheme'] = $update_parsed_url['scheme'];
		$blog_data['domain'] = $update_parsed_url['host'];
		$blog_data['path'] = $update_parsed_url['path'];
	}

	$existing_details = get_site( $id );
	$blog_data_checkboxes = array( 'public', 'archived', 'spam', 'mature', 'deleted' );
	foreach ( $blog_data_checkboxes as $c ) {
		if ( ! in_array( $existing_details->$c, array( 0, 1 ) ) ) {
			$blog_data[ $c ] = $existing_details->$c;
		} else {
			$blog_data[ $c ] = isset( $_POST['blog'][ $c ] ) ? 1 : 0;
		}
	}

	update_blog_details( $id, $blog_data );

	// Maybe update home and siteurl options.
	$new_details = get_site( $id );

	$old_home_url = trailingslashit( esc_url( get_option( 'home' ) ) );
	$old_home_parsed = parse_url( $old_home_url );

	if ( $old_home_parsed['host'] === $existing_details->domain && $old_home_parsed['path'] === $existing_details->path ) {
		$new_home_url = untrailingslashit( esc_url_raw( $blog_data['scheme'] . '://' . $new_details->domain . $new_details->path ) );
		update_option( 'home', $new_home_url );
	}

	$old_site_url = trailingslashit( esc_url( get_option( 'siteurl' ) ) );
	$old_site_parsed = parse_url( $old_site_url );

	if ( $old_site_parsed['host'] === $existing_details->domain && $old_site_parsed['path'] === $existing_details->path ) {
		$new_site_url = untrailingslashit( esc_url_raw( $blog_data['scheme'] . '://' . $new_details->domain . $new_details->path ) );
		update_option( 'siteurl', $new_site_url );
	}

	restore_current_blog();
	wp_redirect( add_query_arg( array( 'update' => 'updated', 'id' => $id ), 'site-info.php' ) );
	exit;
}

if ( isset( $_GET['update'] ) ) {
	$messages = array();
	if ( 'updated' == $_GET['update'] ) {
		$messages[] = __( 'Site info updated.' );
	}
}

/* translators: %s: site name */
$title = sprintf( __( 'Edit Site: %s' ), esc_html( $details->blogname ) );

$parent_file = 'sites.php';
$submenu_file = 'sites.php';

require( ABSPATH . 'wp-admin/admin-header.php' );

/* End Wordpress Site Admin boilerplate */

/**
 * Start the plugin only if in Admin side and if site is Multisite
 * see http://stackoverflow.com/questions/13960514/how-to-adapt-my-plugin-to-multisite/
 */
if (is_admin() && is_multisite()) {
    $wenotes = WEnotes::get_instance();
    $wenotes->log('testing!!');
}
?>

<div class="wrap">
<h1 id="edit-site"><?php echo $title; ?></h1>
<p class="edit-site-actions"><a href="<?php echo esc_url( get_home_url( $id, '/' ) ); ?>"><?php _e( 'Visit' ); ?></a> | <a href="<?php echo esc_url( get_admin_url( $id ) ); ?>"><?php _e( 'Dashboard' ); ?></a></p>
<?php

network_edit_site_nav( array(
	'blog_id'  => $id,
	'selected' => 'site-wenotes-details'
) );

if ( ! empty( $messages ) ) {
	foreach ( $messages as $msg ) {
		echo '<div id="message" class="updated notice is-dismissible"><p>' . $msg . '</p></div>';
	}
}



?>
	<?php
	// The main site of the network should not be updated on this page.
	if ( $is_main_site ) { ?>
    <table class="form-table">
        <tr class="form-field">
	        <th scope="row"><?php _e( 'Site Address (URL)' ); ?></th>
	        <td><?php echo esc_url( $details->domain . $details->path ); ?></td>
	    </tr>
    </table>
	<?php
    }
	// For any other site, the scheme, domain, and path can all be changed.
	else {
        $wenotes->site_init($id);
    }
    ?>

</div>
<?php
require( ABSPATH . 'wp-admin/admin-footer.php' );
