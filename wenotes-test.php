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
/**
 * Start the plugin only if in Admin side and if site is Multisite
 * see http://stackoverflow.com/questions/13960514/how-to-adapt-my-plugin-to-multisite/
 */
if (is_admin() && is_multisite()) {
    $wenotes = WEnotes::get_instance();
    $wenotes->log('testing!!');


    //$wenotes->survey_feeds_print(false);
    //$wenotes->survey_feeds_print(true);
    $wenotes->update_all_feed_registrations();
}
?>
<h1>Test WEnotes CouchDB integration</h1>
<?php
require( ABSPATH . 'wp-admin/admin-footer.php' );
