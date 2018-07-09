<?php

require WENOTES_PATH . '/includes/wenotes-sites.php';

class WEnotes extends WEnotesSites {

    protected static $instance = NULL; // this instance
    protected static $fresh = false; // true if freshly creating CouchDB...

    // returns an instance of this class if called, instantiating if necessary
    public static function get_instance() {
        NULL === self::$instance and self::$instance = new self();
        return self::$instance;
    }

    // Do smart stuff when this object is instantiated.
    public function init() {
        //$this->log('in WEnotes init');
        // add our updated links to the site nav links array via the filter
        add_filter('network_edit_site_nav_links', array($this, 'insert_site_nav_link'));
        // register all relevant shortcodes
        $this->register_shortcodes();
        // register all relevant hooks
        $this->register_hooks();
        // set up the custom user profile fields for per-site blog URLs
        add_action('show_user_profile', array($this, 'get_feeds_for_user'), 10, 1);
        add_action('edit_user_profile', array($this, 'get_feeds_for_user'), 10, 1);
        // call our ancestor's init script, too!
        WEnotesSites::init();
    }

    /**
     * If a site is archived remove all associated Blog URLs for the site
     * Note - if the site is un-archived, the couchDB mappings can be recreated
     * based on existing database entries
     */
    public function archive_site($site_id) {
        $this->log('archive_site: removing couchDB references for site '.$site_id);
        $this->remove_site($site_id);
    }

    /**
     * If a site is deleted remove all associated Blog URLs for the site
     */
    public function delete_site($site_id, $drop) {
        $this->log('delete_site: removing ($drop = '.$drop.') couchDB references for site '.$site_id);
        $this->remove_site($site_id);
    }

    /**
     * If a site is archived or deleted, remove all associated Blog URLs for the site
     */
    public function remove_site($site_id) {
        $this->log('removing couchDB references for site '.$site_id);
    }


    // add our WEnotes Details per Site tab to the Site Edit Nav
    public function insert_site_nav_link($links) {
        $path =  '../..'.parse_url(WENOTES_URL, PHP_URL_PATH).'wenotes-site.php';
        $links['site-wenotes-details'] =  array('label' => __('WEnotes Details'),
            'url' => $path, 'cap' => 'manage_sites');
        return $links;
    }

    // show site_blog_urls for a given user
    public function get_feeds_for_user($user) {
        $sites = get_blogs_of_user($user->ID);
        $this->log('user sites: '. print_r($sites, true));
        // we don't want to count the default site with, id = 1
        // so we unset it.
        unset($sites[1]);
        // get the total
        if (count($sites) == 1) {
            $site_count_msg = 'You are registered for one course';
        } else if (count($sites) > 1) {
            $site_count_msg = 'You are registered for '.count($sites).' courses.';
        } else {
            $site_count_msg = 'You are not registered for any courses.';
        }
        ?>
        <h2 class="wenotes-site">OERu Courses</h2>
        <table class="form-table wenotes-sites">
            <tbody>
                <tr>
                    <th><p><?php echo $site_count_msg; ?> Any
                      personal blog feed addresses you have specified for WEnotes monitoring are show
                      in brackets.</p>
                      <?php if ($this->bff_enabled()) { echo "<p>You can update
                      your blog feed addresses using our handy <a href=\"/blog-feed-finder/\">Blog Feed Finder</a>...</p>"; } ?>
                    </th>
                    <td>
                        <ol class="wenotes-list">
        <?php
        // figure out the CouchDB equivalents for the user's sites
        $user_id = $user->ID;
        $reg_status = $this->get_reg_status_by_user($user_id);
        $this->log('reg_status = '. print_r($reg_status, true));
        $html = "";
        $count = 0;
        foreach ($sites as $site) {
            $site_id = $site->userblog_id;
            // we don't assign a blog URL to the default site
            if ($site_id == 1) {
                continue;
            }
            $stripe = ($count++%2) ? "even" : "odd";
            $html .= '<li class="wenotes-site '.$stripe.'"><a title="Site id is '.$site_id.'" href="'.$site->path.'">'.$site->blogname.'</a>';
            if ($url = $this->get_feed_for_user_for_site($user_id, $site_id)) {
              $html .= ' (<a href="'.$url.'">'.$url.'</a>)';
            } else {
              $html .= ' (no URL specified)';
            }
            if ($reg_status && isset($reg_status[$site_id]['url'])) {
                $this->log('Info for this site: '. print_r($reg_status[$site_id], true));
                $msg = ($reg_status[$site_id]['url'] == '') ? '' : $reg_status[$site_id]['url'].', set ';
                $html .= ' Registered '.$msg.' (on '.$reg_status[$site_id]['we_timestamp'].') for WEnotes scanning.';
            } else {
                $html .= ' Not yet registered for WEnotes scanning.';
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
        //$this->log('in WEnotes register_shortcodes');
        add_shortcode( 'WEnotes', array($this, 'wenotes_func'));
        add_shortcode( 'WEnotesPost', array($this, 'wenotespost'));
    }

    /*
     * Hooks for WP actions
     */

    // initialise the hook methods
    public function register_hooks() {
        //$this->log('in WEnotes register_hooks');
        /* See
         *https://core.trac.wordpress.org/browser/tags/4.7.3/src/wp-includes/ms-functions.php#L0
         */
        // register the hook methods
        // add a new site
        //add_action('wpmu_new_blog', array($this, 'add_site'), 10, 6);
        // change an existing site do_action( 'update_blog_public', $blog_id, $value );
        //add_action('update_blog_public', array($this, 'update_site'), 10, 2);
        // when an existing site is archived - do_action( 'archive_blog', int $blog_id )
        add_action('archive_blog', array($this, 'archive_site'), 10, 1);
        // remove an existing site - do_action( 'delete_blog', $blog_id, $drop );
        add_action('delete_blog', array($this, 'delete_site'), 10, 2);
        // a new user is registered - do_action( 'user_register', $user_id );
        add_action('user_register', array($this, 'add_user'), 11, 1);
        // an existing user logs in (starting new session) -  do_action( 'wp_login', $user->user_login, $user );
        //add_action('wp_login', array($this, 'user_login'), 10, 2);
        // an existing user updates their profile - do_action( 'profile_update', $user_id, $old_user_data );
        add_action('profile_update', array($this, 'change_user'), 11, 2);
        // do_action( 'add_user_to_blog', $user_id, $role, $blog_id );
        add_action('add_user_to_blog', array($this, 'add_user_to_site'), 10, 3);
        // do_action( 'remove_user_from_blog', $user_id, $blog_id );)
        add_action('remove_user_from_blog', array($this, 'remove_user_from_site'), 10, 2);
        // do_action( 'after_signup_site', $domain, $path, $title, $user, $user_email, $key, $meta );
        //add_action('after_signup_user', array($this, 'after_user_signup_to_site'), 10, 7);

        // Blog Feed Finder Hooks
        add_action( 'bff_update_user_feed', array($this, 'update_feed_hook'), 10, 2);

        // other Hooks
        add_action( 'wp_ajax_wenotes', array($this, 'wenotespost_ajax'));

    }


    /**
     * Fires immediately after an existing user is updated.
     * @param int    $user_id       User ID.
     * @param object $old_user_data Object containing user's data prior to update.
    */
    public function change_user($user_id, $old_user_data) {
        $site_id = get_current_blog_id();
        $this->log('in (new) profile_update hook');
        $this->log('site_id: '. $site_id);
        // user meta data
        /*$old_url = get_user_meta($user_id, 'url_'.$site_id);
        if (count($old_url) > 1) {
            $this->log('uh oh, we have more than one URL for this user and site: '.
                print_r($old_url, true));
        }
        $this->log('saved URL for this site: '.print_r($old_url[0], true));
        if (isset($_POST['courseblog'])) {
            $user = wp_get_current_user();
            $new_url = htmlspecialchars($_POST['courseblog']);
            $this->log('current new URL: '. $new_url);
            if ($new_url != $old_url) {
                $this->update_registered_feed($user->ID, $site_id, $new_url);
            }
        }*/
        // add meta data to user data
        /*foreach($meta as $key => $val) {
            $new_user_data->data->$key = current($val);
        }
        $this->log('new user data:'.print_r($new_user_data, true));*/
    }

    /**
     * Adds a user to a blog.
     *
     * @param int    $user_id User ID.
     * @param string $role    User role.
     * @param int    $blog_id Blog ID.
     */
    public function add_user_to_site($user_id, $role, $blog_id) {
        // we want to make sure any added URL is pushed to CouchDB...
        $this->log('in hook add_user_to_site');
        // check for any past valid blog feed URLs and make the most recent
        // the default for this course
        if ($feed = $this->get_default_feed_for_user($user_id)) {
            $this->log('Found a default feed URL: '.$feed['url'].' ('.$feed['type'].') for user '.
               $user_id.' from course '.$feed['site_id'].', now set it for this course '.$blog_id);
            if ($this->update_feed_for_user_for_site($user_id, $blog_id, $feed['url'], $feed['type'])) {
                $this->log('Assigned feed '.$url.' for user '.
                   $user_id.' and course '.$blog_id);
            } else {
                $this->log('This was probably an invalid feed ');
            }
        }

    }

    /**
     * Fires before a user is removed from a site.
     *
     * @since MU
     *
     * @param int $user_id User ID.
     * @param int $blog_id Blog ID.
     */
    public function remove_user_from_site($user_id, $blog_id) {
        // make sure any related blog url record in CouchDB is removed
        $this->log('in hook remove_user_from_site');
    }

    /**
     * Wenotes Posting
     */

    // submit a WEnotes post from the WordPress form.
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
            //$.getScript('//wikieducator.org/extensions/WEnotes/WEnotes-min.js');
            $.getScript('//c.wikieducator.org/extensions/WEnotes/WEnotesClient.js');
        }
})/*]]>*/</script>
EOD;
        return $wenotesdiv;
    }

}
