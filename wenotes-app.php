<?php

require WENOTES_PATH . '/includes/wenotes-hooks.php';

class WENotes extends WENotesHooks {

    protected static $instance = NULL; // this instance
    protected static $couchdb; // CouchDB client object

    // returns an instance of this class if called, instantiating if necessary
    public static function get_instance() {
        NULL === self::$instance and self::$instance = new self();
        return self::$instance;
    }

    // Do smart stuff when this object is instantiated.
    public function init() {
        $this->log('in WENotes init');
        // add our updated links to the site nav links array via the filter
        add_filter('network_edit_site_nav_links', array($this, 'insert_site_nav_link'));
        // register all relevant shortcodes
        $this->register_shortcodes();
        // register all relevant hooks
        $this->register_hooks();
        // set up the custom user profile fields for per-site blog URLs
        add_action('show_user_profile', array($this, 'site_blog_urls_for_user'), 10, 1);
        add_action('edit_user_profile', array($this, 'site_blog_urls_for_user'), 10, 1);
    }

    public function site_init($id = '1') {
        $this->log('in site_init, id = '. $id);
        // set up appropriate ajax js file
        wp_enqueue_script( 'wenotes-site-ajax-request', WENOTES_URL.'app/js/site-ajax.js', array(
            'jquery',
            'jquery-form'
        ));
        // declare the URL to the file that handles the AJAX request
        // (wp-admin/admin-ajax.php)
        wp_localize_script( 'wenotes-site-ajax-request', 'wenotes_site', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'blog_url_nonce' => wp_create_nonce( 'wenotes-blog-url-nonse')
        ));
        add_action( 'wp_ajax_wenotes_site', array($this, 'site_submit'));
        $this->site_tab($id);

        // this is just a convenient place from which to trigger this function
        // for now.
        $this->update_all_feed_registrations();
    }

    // Print the site page itself
    public function site_tab($site_id) {
        // get the site's name:
        $site = get_site($site_id);
        $site_name = $this->get_site_tag($site);
        $this->log('site: '.print_r($site, true));

        $this->log('site_tab');
        ?>
        <div class="wrap" id="wenotes-site-detail">
            <h2>WENotes details for <strong><?php echo $site->blogname.' ('.$site_name.')'; ?></strong></h2>
            <p>Site users and their registered blog URLs for WENotes monitoring.</p>
            <table class="wenotes-table segment">
            <?php
                // get the WP users for this site/course
                $searchFilter = 'blog_id='.$site_id.
                    '&orderby=display_name&orderby=nicename';
                $users = get_users($searchFilter);
                $this->log('users for blog '.$site_id.':'. print_r($users,true));
                // now find the Mautic contacts corresponding to the
                // users, and whether or not they're in the segment
                //$people = $this->get_blog_urls_by_email($users);
                //$this->log('people: '.print_r($people, true));
                if (count($users)) {
                    $alt = 0;
                    ?>
                    <tr class="heading">
                        <th class="label">WordPress User</th>
                        <th class="label">Blog URL</th>
                    </tr>
                    <?php
                    foreach ($users as $index => $data){
                        $user_id = $data->ID;
                        $referrer =
                            '/wp-content/plugins/wpms-mautic/mautic-site.php?id='.
                            $site_id;
                        $wp_url = '/wp-admin/network/user-edit.php?user_id='.$user_id.
                            '&wp_http_referer='.$referrer;
                        $wp_name = $data->data->display_name;
                        $wp_email = $data->data->user_email;
                        $blog_url = $this->get_blog_url_for_user_for_site($user_id, $site_id);
                        if ($blog_url) {
                            $blog_html = '<a href="'.$blog_url.'">'.$blog_url.'</a>';
                        } else {
                            $blog_html = "no blog URL specified";
                        }
                        $rowclass = "user-row";
                        $rowclass .= ($alt%2==0)? " odd":" even";
                        echo '<tr "'.$rowclass.'">';
                        echo '    <td class="wp-details"><a href="'.$wp_url.'">'.$wp_name.'</a> (<a href="mailto:'.$wp_email.'">'.$wp_email.'</a>)</td>';
                        echo '    <td class="blog-url">'.$blog_html.'</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr "'.$rowclass.'">';
                    echo ' <td class="no-users">This Site has no Users.</td>';
                    echo '</tr>';
                }?>
            </table>
            <!--<input type="hidden" id="mautic-create-segment-nonce" value="<?php echo $nonce_create_segment; ?>" />-->
        </div>
        <?php
    }

    /*
     *  CouchDB related functions
     */

    // check couchdb to see if a given user's block url is recorded for a particular
    // course tag
    public function get_feed_registration_status($user_id, $url, $tag) {

    }

    public function alter_registered_feed_for_user_and_tag($user_id, $oldurl, $tag, $newurl) {

    }

    // Get a list of all blog urls for all users associated with all sites (courses)
    // and ensure that each relationship is reflected with a "registered" feed
    // entry in couchdb
    // Note: this is only to be used to initialise things and make them
    // correspond to the current state of the data!
    public function update_all_feed_registrations() {
        global $wpdb;
        $result = array();
        $usermeta_table = "wp_usermeta";
        $user_table = "wp_users";

        // check for listed sites
        $query = 'SELECT m.user_id, u.user_nicename, u.display_name, '
            .'u.user_email, u.spam, u.deleted, m.meta_key, m.meta_value FROM '.
            $usermeta_table.' m LEFT JOIN '.$user_table.' u ON m.user_id = u.ID '
            .'WHERE m.meta_key LIKE "url_%" ORDER BY u.user_registered;';
        $this->log('WENotes query: '. $query);
        if ($results = $wpdb->get_results($query, ARRAY_A)) {
            $count = 0;
            //$this->log('WENotes - successful query! Result: '. print_r($result, true));
            $this->log('WENotes - successful query!');
            // go through the results.
            foreach ($results as $result) {
                $cnt = $count;
                $count++;
                $this->log('Result '.$count.' = '. print_r($result, true));
                // make sure the user hasn't been deleted
                if (! ($result['spam'] && $result['deleted'])) {
                    $feed = array();
                    // Derive the:
                    // site user (display and usernames)
                    $feed['from_user'] = $result['user_nicename'];
                    $feed['from_user_name'] = $result['display_name'];
                    // get wp user id
                    $feed['from_user_wp_id'] = $result['user_id'];
                    // site tag - element 1 contains the site ID after 'url_'
                    $site_id = (int)explode('_', $result['meta_key'], 2)[1];
                    // we don't want to record the default site...
                    if ($site_id === 1) {
                        // break out of this loop iteration
                        $this->log('****continuing on!');
                        continue;
                    }
                    // otherwise, proceed.
                    $feed['site_id'] = $site_id;
                    $feed['tags'][] = $this->get_site_tag(get_site($site_id));
                    // web URL and check if it includes RSS
                    if ($urls = $this->check_for_feed($result['meta_value'])) {
                        $feed['url'] = $urls['url_host'];
                        $feed['feed_url'] = $urls['feed_url'];
                        $feed['feed_type'] = $urls['feed_type'];
                    }

                    // find an avatar URL
                    $avatar_args = array();
                    $feed['gravatar'] = get_avatar_url($result['user_id'],
                        array('default'=>'identicon', 'processed_args'=>$avatar_args));

                    // mark this with WordPress module type
                    $feed['we_source'] = 'wenotes_wp';
                    $feed['we_wp_version'] = WENOTES_VERSION;
                    $feed['type'] = 'feed';

                    // commit this feed...
                    $this->register_feed($feed);
                }
                // if user has been marked spam or delete
                else {
                    // tidy up any references in the CouchDB
                    $unfeed = array();
                    $this->deregister_feed($unfeed);
                }
            }
        }
    }

    // submit a feed registration to CouchDB using a feed object
    public function register_feed($feed) {
        $this->log('Feed object: '. print_r($feed, true));
        // need to check if the same url + user details already exist in couchdb
        // in which case we might have to add a tag.
        // If the same user details + tag exist, then we might need to update the
        // url...
        // Otherwise, it might be a no-op.
    }

    // remove an feed records with these details from CouchDB
    public function deregister_feed($unfeed) {
        $this->log('Removing feed object: '. print_r($unfeed, true));
    }

    // check if a
    // return an array with "url" and, if detected, "url_rss"
    // return "url" and "url_feed" = 'norss'
    // return "false" if indeterminate
    public function check_for_feed($url) {
        $this->log('check for feed: '. $url);
        // check if we have a valid URL
        if ($parts = parse_url($url)) {
            // test for .rss or rss at the end of a URL
            $path = $parts['path'];
            $result = array();
            $found = false;
            // make sure there's no "edit" mentioned in the path...
            if (preg_match('/edit/', $path, $matches)) {

            }
            // check if the provided URL includes feed arugment
            if (preg_match('/(rss|atom|xml)$/', $path, $matches)) {
                $this->log('match: '. print_r($matches, true));
                $found = $this->test_feed($url);
            } elseif (preg_match('/(feed)/', $path, $matches)) {
                $this->log('feed matches: '. print_r($matches, true));
                $found = $this->test_feed($url);
            } else {
                $this->log('no matches, checking for default feed');
                $found = $this->test_feed($url);
            }
            $result['feed_url'] = $url;
            $result['url_host'] = $parts['scheme'].'://'.$parts['host'];
            // if found isn't false, we got a feed...
            if ($found) {
                $this->log('A '.$found.' feed found at '. $url);
                $result['feed_type'] = $found;
                return $result;
            } else {
                $this->log('No feed found: '. $url);
                $feed_url = $this->look_for_feed($url);
                $result['feed_type'] = 'none';
                return $result;
                // check for valid site
            }
        } else {
            $this->log('Invalid URL: '. $url);
        }
        // if all else fails, return false
        return false;
    }

    // look for a valid feed given a blog site URL in well known places:
    public function look_for_feed($url) {

    }


    // check if an actual RSS or Atom feed is returned for this URL
    // true or false
    // assumes a valid URL
    public function test_feed($url) {
        $content = file_get_contents($url);
        try {
            $xml = new SimpleXmlElement($content);
        } catch (Exception $e){
            $this->log('the content found at '.$url.' is not a valid feed.');
            return false;
        }
        $type = 'none';
        if ($feed->channel->item) {
            $type = 'rss';
        } elseif ($feed->entry) {
            $type = 'atom';
        } else {
            $type = 'xml';
        }
        $this->log('found type = '. $type);
        return $type;
    }

    /*
     * Wordpress related functions
     */

    // given a site id and user id, get any associated blog url
    public function get_blog_url_for_user_for_site($user_id, $site_id) {
        global $wpdb;
        $result = array();
        $usermeta_table = "wp_usermeta";
        $user_table = "wp_users";

        // check for listed sites
        $query = 'SELECT m.user_id, u.user_nicename, u.display_name, m.meta_key, m.meta_value FROM '.
            $usermeta_table.' m LEFT JOIN '.$user_table.' u ON m.user_id = u.ID WHERE m.user_id = '.
            $user_id .' AND m.meta_key = "url_'. $site_id .'";';
        $this->log('WENotes query: '. $query);
        if ($result = $wpdb->get_results($query, ARRAY_A)) {
            $this->log('WENotes - successful query! Result: '. print_r($result, true));
            // this only returns a single value - the first one...
            if ($url = $result[0]['meta_value']) {
                $this->log('found URL: '.$url);
                return $url;
            } else {
                $this->log('no suitable URL found...');
            }
        }
        return false;
    }

    // given a site object, return the site's name
    public function get_site_tag($site) {
        return strtolower(substr($site->path,1,-1));
    }

    // add our WENotes Details per Site tab to the Site Edit Nav
    public function insert_site_nav_link($links) {
        $path =  '../..'.parse_url(WENOTES_URL, PHP_URL_PATH).'wenotes-site.php';
        $links['site-wenotes-details'] =  array('label' => __('WENotes Details'),
            'url' => $path, 'cap' => 'manage_sites');
        return $links;
    }

    // show site_blog_urls for a given user
    public function site_blog_urls_for_user($user) {
        $sites = get_blogs_of_user($user->ID);
        $this->log('user sites: '. print_r($sites, true));
        $total = count($sites) ? count($sites) : "no";
        ?>
        <h2 class="wenotes-site">OERu Courses</h2>
        <table class="form-table wenotes-sites">
            <tbody>
                <tr>
                    <th>You are registered for <?php echo $total; ?> courses. Any
                      blog URLS you have specified for WENotes monitoring are show
                      in brackets.</th>
                    <td>
                        <ol class="wenotes-list">
        <?php
        $html = "";
        $count = 0;
        foreach ($sites as $site) {
            $stripe = ($count++%2) ? "even" : "odd";
            $html .= '<li class="wenotes-site '.$stripe.'"><a title="Site id is '.$site->userblog_id.'" href="'.$site->path.'">'.$site->blogname.'</a>';
            if ($url = $this->get_blog_url_for_user_for_site($user->ID, $site->userblog_id)) {
              $html .= ' (<a href="'.$url.'">'.$url.'</a>)';
            } else {
              $html .= ' (no URL specified)';
            }
            $html .= '</li>';
        }
        echo $html;
        ?>
                         </ol>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /*
     * shortcodes
     */
   public function register_shortcodes() {
        $this->log('in WENotes register_shortcodes');
        add_shortcode( 'WEnotes', array($this, 'wenotes_func'));
        add_shortcode( 'WEnotesPost', array($this, 'wenotespost'));
    }

    /*
     * Hooks for WP actions
     */

    // initialise the hook methods
    public function register_hooks() {
        $this->log('in WENotes register_hooks');
        /* See
         *https://core.trac.wordpress.org/browser/tags/4.7.3/src/wp-includes/ms-functions.php#L0
         */
        // register the hook methods
        // add a new site
        add_action('wpmu_new_blog', array($this, 'add_site'), 10, 6);
        // change an existing site do_action( 'update_blog_public', $blog_id, $value );
        add_action('update_blog_public', array($this, 'update_site'), 10, 2);
        // when an existing site is archived - do_action( 'archive_blog', int $blog_id )
        add_action('archive_blog', array($this, 'archive_site'), 10, 1);
        // remove an existing site - do_action( 'delete_blog', $blog_id, $drop );
        add_action('delete_blog', array($this, 'delete_site'), 10, 2);
        // a new user is registered - do_action( 'user_register', $user_id );
        add_action('user_register', array($this, 'add_user'), 10, 1);
        // an existing user logs in (starting new session) -  do_action( 'wp_login', $user->user_login, $user );
        add_action('wp_login', array($this, 'user_login'), 10, 2);
        // an existing user updates their profile - do_action()'profile_update', $user_id, $old_user_data );
        add_action('profile_update', array($this, 'update_user'), 10, 2);
        // do_action( 'add_user_to_blog', $user_id, $role, $blog_id );
        add_action('add_user_to_blog', array($this, 'add_user_to_site'), 10, 3);
        // do_action( 'remove_user_from_blog', $user_id, $blog_id );)
        add_action('remove_user_from_blog', array($this, 'remove_user_from_site'), 10, 2);
        // do_action( 'after_signup_site', $domain, $path, $title, $user, $user_email, $key, $meta );
        //add_action('after_signup_user', array($this, 'after_user_signup_to_site'), 10, 7);

        // other Hooks
        add_action( 'wp_ajax_wenotes', array($this, 'wenotespost_ajax'));
    }

    public static function wenotespost( $atts ) {
      	$a = shortcode_atts( array(
      	    'tag' => '',
      	    'button' => 'Post a WEnote',
      	    'leftmargin' => '53',
      	    'anonymous' => 'You must be logged in to post to WEnotes.'
      	), $atts );
      	$current_user = wp_get_current_user();
      	if ( $current_user->ID == 0 ) {
      		  $wenotespostdiv = '';
      		  if ( $a['anonymous'] ) {
      			   $wenotespostdiv = '<div><p>' . $a['anonymous'] . '</p></div>';
      		  }
      	} else {
      		  wp_enqueue_script( 'wenotespostwp',
      			    plugins_url( 'wenotes/WEnotesPostWP.js', __FILE__ ),
      			    array( 'jquery' ),
      			    WENOTES_VERSION,
      			    true
            );
      		  $wenotespostdiv = <<<EOD
<div id="WEnotesPost1"></div>
<script type="text/javascript">/*<![CDATA[*/
     $ = window.jQuery;
     $(function() {
         WEnotesPostWP("WEnotesPost1", '${a['tag']}', '${a['button']}', '${a['leftmargin']}');
      })/*]]>*/</script>
EOD;
        }
      	return $wenotespostdiv;
    }

    public static function wenotes_func( $atts ) {
      	$a = shortcode_atts( array(
      	    'tag' => '_',
      	    'count' => 20
    	  ), $atts );
      	$tag = strtolower( $a['tag'] );
      	$count = $a['count'];

       	$wenotesdiv = <<<EOD
<div class="WEnotes WEnotes-$count-$tag" data-tag="$tag" data-count="$count">
    <img class="WEnotesSpinner" src="//wikieducator.org/skins/common/images/ajax-loader.gif" alt="Loading..." style="margin-left: 53px;">
</div>
<script type="text/javascript">/*<![CDATA[*/
    $ = window.jQuery;
    window.wgUserName = null;
    $(function() {
        if (!window.WEnotes) {
            window.WEnotes = true;
            $.getScript('//wikieducator.org/extensions/WEnotes/WEnotes-min.js');
        }
})/*]]>*/</script>
EOD;
    	  return $wenotesdiv;
    }

    // post a wenotes response to channel... and then let go of the connection.
    public function wenotespostresponse( $a ) {
    	  echo json_encode( $a );
    	  die();
    }

    // initiate couchdb connection or return the existing one...
    public function couchdb() {
        if (!$this->couchdb) {
            require_once( 'sag/src/Sag.php' );
            $this->log('creating a new couchdb connection');
        	  $current_user = wp_get_current_user();
        	  list( $usec, $ts ) = explode( ' ', microtime() );
            $sag = new Sag( WENOTES_HOST, WENOTES_PORT );
            $sag->setDatabase( WENOTES_DB );
            $sag->login( WENOTES_USER, WENOTES_PASS );
            $this->couchdb = $sag;
        } else {
            $this->log('returning the existing couchdb connection');
        }
        return $this->couchdb;
    }

    // submit a wenotes post via WP ajax.
    public static function wenotespost_ajax() {
        $data = array(
            'from_user' => $current_user->user_login,
            'from_user_name' => $current_user->display_name,
            'created_at' => date( 'r', $ts ),
            'text' => stripslashes(trim($_POST['notext'])),
            'id' => $current_user->ID . $ts . substr( "00000$usec", 0, 6 ),
            'we_source' => 'course',
            'we_tags' => array( strtolower(trim($_POST['notag'])) ),
            'we_timestamp' => date('Y-m-d\TH:i:s.000\Z', $ts)
        );
        if ( $current_user->user_email ) {
            $data['gravatar'] = md5( strtolower( trim( $current_user->user_email ) ) );
        }
        if ( $current_user->user_url ) {
            $data['profile_url'] = $current_user->user_url;
        }
        if ( isset( $_POST['we_page'] ) ) {
            $data['we_page'] = stripslashes($_POST['we_page']);
        }
        if ( isset( $_POST['we_root'] ) ) {
            $data['we_root'] = $_POST['we_root'];
        }
        if ( isset( $_POST['we_parent'] ) ) {
            $data['we_parent'] = $_POST['we_parent'];
        }

        // get the
        $sag = $this->couchdb();
        $sag->post($data);

      	$this->wenotespostresponse( array(
      		'posted' => true
      	));
    }
}
