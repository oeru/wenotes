<?php

require WENOTES_PATH . '/includes/wenotes-base.php';

class WENotes extends WENotesBase {

    protected static $instance = NULL; // this instance
    protected static $couchdb; // CouchDB client object
    protected $fresh = false; // true if freshly creating CouchDB...

    // returns an instance of this class if called, instantiating if necessary
    public static function get_instance() {
        NULL === self::$instance and self::$instance = new self();
        return self::$instance;
    }

    // Do smart stuff when this object is instantiated.
    public function init() {
        //$this->log('in WENotes init');
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
        //$this->update_all_feed_registrations();
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
                        <th class="label user">WordPress User</th>
                        <th class="label url">Blog URL</th>
                        <th class="label status">Status</th>
                    </tr>
                    <?php
                    $reg_status = $this->get_reg_status_by_site($site_id);
                    $this->log('reg_status = '. print_r($reg_status, true));
                    foreach ($users as $index => $data){
                        $user_id = $data->ID;
                        $wp_url = '/wp-admin/network/user-edit.php?user_id='.$user_id;
                        $wp_name = $data->data->display_name;
                        $wp_email = $data->data->user_email;
                        // construct the blog_html
                        $blog_url = $this->get_blog_url_for_user_for_site($user_id, $site_id);
                        if ($blog_url) {
                            $blog_html = '<a href="'.$blog_url.'">'.$blog_url.'</a>';
                        } else {
                            $blog_html = "no blog URL specified";
                        }
                        // construct the blog reg status_html
                        if ($reg_status && $reg_status[$user_id]) {
                            $status_html = 'Registered ('.$reg_status[$user_id]['url'].', set '.$reg_status[$user_id]['we_timestamp'].')';
                        } else {
                            $status_html = 'Not Registered';
                        }
                        $rowclass = "user-row";
                        $rowclass .= ($alt%2==0)? " odd":" even";
                        echo '<tr "'.$rowclass.'">';
                        echo '    <td class="wp-details"><a href="'.$wp_url.'">'.$wp_name.'</a> (<a href="mailto:'.$wp_email.'">'.$wp_email.'</a>)</td>';
                        echo '    <td class="blog-url">'.$blog_html.'</td>';
                        echo '    <td class="blog-url-status">'.$status_html.'</td>';
                        echo '</tr>';
                    }
                } else {
                    $this->log('no users retrieved...');
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

    // check if the relevant database and views are in place, and if not,
    // create them (and populate them)
    private function check_db($couch) {
        $this->log('checking if database: '.WENOTES_BLOGFEEDS_DB .' exists...');
        try {
            // get all the databases
            $dbs = json_decode($couch->getAllDatabases()->body);
            // if our database exists, all good
            $this->log('list of databases: '. print_r($dbs, true));
            if (in_array(WENOTES_BLOGFEEDS_DB, $dbs)) {
                // if not, created it
                $this->log('The database exists.');
            } else {
                $this->log('The database does not exist, creating it');
                try {
                    $couch->createDatabase(WENOTES_BLOGFEEDS_DB);
                    $this->fresh = true;
                } catch (SagCouchException $e) {
                    $this->log($e->getCode() . " unable to access");
                }
            }
            return true;
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
        return false;
    }

    // check couchdb to see if a given user's block url is recorded for a particular
    // course tag
    public function get_reg_status_by_user($user_id) {
        $this->log('getting registered blog urls for user: '.$user_id);
        $couch = $this->couchdb();
        try {
            $data = array();
            $result = json_decode($couch->get('/_design/ids/_view/by_wp_id?key='.
                $user_id.'&descending=true')->body, true);
            $this->log('CouchDB number of rows returned: '. count($result['rows']));
            //$this->log('CouchDB rows returned: '. print_r($result, true));
            if ($result && count($result['rows'])) {
                $this->log('got result, and non-zero rows...');
                foreach($result['rows'] as $row) {
                    $data[$row['value']['site_id']] = $row['value'];
                }
                $this->log('CouchDB data array (get_reg_status_by_user): '. print_r($data, true));
                return $data;
            } else {
                $this->log('no documents returned!');
            }
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
        return false;
    }

    // check couchdb to see what blog urls are registered for a site (course)
    public function get_reg_status_by_site($site_id) {
        $this->log('getting registered blog urls for site: '.$site_id);
        $couch = $this->couchdb();
        try {
            $data = array();
            $result = json_decode($couch->get('/_design/ids/_view/by_site_id?key='.
                $site_id)->body, true);
            //$this->log('CouchDB number of rows returned: '. count($result['rows']));
            $this->log('get_reg_status_by_site CouchDB result: '. print_r($result, true));
            if ($result && count($result['rows'])) {
                $this->log('got result, and non-zero rows...');
                foreach($result['rows'] as $row) {
                    $data[$row['value']['from_user_wp_id']] = $row['value'];
                }
                $this->log('CouchDB data array: '. print_r($data, true));
                return $data;
            } else {
                $this->log('no documents returned!');
            }
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
        return false;
    }

    // update the feed URL for an existing user_id (from user object)
    // & site_id, unless there isn't one, in which case set it.
    public function update_registered_feed($user, $site_id, $newurl) {
        $user_id = $user->ID;
        $this->log('updating registered blog url for user '.$user_id.' and site '.$site_id
            .' to '.$newurl);
        $couch = $this->couchdb();
        try {
            $result = json_decode($couch->get('/_design/ids/_view/by_site_and_wp_id?key=['.
                $user_id.','.$site_id.']')->body, true);
            //$this->log('CouchDB number of rows returned: '. count($result['rows']));
            //$this->log('CouchDB result (update_registered_feed): '. print_r($result, true));
            if (count($result['rows'])) {
                $doc = $result['rows'][0];
                //$this->log('CouchDB result (update_registered_feed): '. print_r($result, true));
                $this->log('CouchDB data array to change: '. print_r($doc, true));
                // setting URL to new value...
                if ($doc['value']['url'] !== $newurl) {
                    $doc_id = $doc['value']['_id'];
                    $doc_rev = $doc['value']['_rev'];
                    $this->log('updating url in doc (_id = '.$doc_id.') from '.$doc['value']['url'].' to '.$newurl.'.');
                    $doc['value']['url'] = $newurl;
                    //$doc_json = json_encode($doc);
                    $this->log('json encoded doc: '. print_r($doc['value'], true));
                    try {
                        $res = $couch->put($doc_id, $doc['value']);
                        $this->log('saved updated entry - result: '. print_r($res, true));
                    } catch (SagCouchException $e) {
                        $this->log($e->getCode() . " unable to access");
                    }
                } else {
                    $this->log('URL value unchanged ('.$newurl.'), not altering CouchDB');
                }
                return $true;
            } else {
                $this->log('registering a new url!');
                $record = array();
                $record['user_id'] = $user_id;
                $record['site_id'] = $site_id;
                $record['url'] = $newurl;
                $record['spam'] = false;
                $record['deleted'] = false;
                $record['user_nicename'] = $user->user_nicename;
                $record['display_name'] = $user->dispay_name;
                if ($this->register_feed_from_record($record)) {
                    $this->log('successfully added record for user_id '.$user_id.
                        ', site_id '.$site_id.' to new url: '.$newurl);
                }
            }
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
        return false;
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
                //$this->log('Result '.$count.' = '. print_r($result, true));
                if($this->register_feed_from_record($result)) {
                    $count++;
                }
            }
            $this->log('Successfully processed '.$count.' user results.');
        }
    }

    /** Process data from a user query or array to register a feed
     *
     * This requires an array with the following:
     * $record['user_id'] (int) - WP user ID
     * $record['meta_key'] (array) or $record['site_id'] (int) - info about site
     * $record['meta_value'] (array) or $record['url'] (str - url),
     *   $record['feed_url'] (str - url), $record['feed_type'] (str - (rss|atom))
     * $record['spam'] (bool) - set to false
     * $record['deleted'] (bool) - set to false
     * $record['user_nicename'] (str) - username for display
     * $record['display_name'] (str) - full user name (first and last)
     */
    public function register_feed_from_record($record) {
        $user_id = (int)$record['user_id'];
        // depending on how record array is constructed...
        $site_id = (isset($record['site_id'])) ? $record['site_id'] : (int)explode('_', $record['meta_key'], 2)[1];
        // site tag - element 1 contains the site ID after 'url_'
        // we don't want to record the default site...
        if ($site_id === 1) {
            // break out of this loop iteration
            $this->log('****continuing on!');
            //continue;
            return true;
        }
        // make sure the user hasn't been deleted
        if (! ($record['spam'] && $record['deleted'])) {
            $feed = array();
            // Derive the:
            // site user (display and usernames)
            $feed['from_user'] = $record['user_nicename'];
            $feed['from_user_name'] = $record['display_name'];
            // get wp user id
            $feed['from_user_wp_id'] = $user_id;
            // otherwise, proceed.
            $feed['site_id'] = $site_id;
            $feed['tag'] = $this->get_site_tag(get_site($site_id));
            // web URL and check if it includes RSS
            if (isset($record['url']) && isset($record['feed_url']) && isset($record['feed_type'])) {
                $this->log('found a fully documented feed: '.
                    $record['feed_url'].'('.$record['feed_type'].') - the saved url: '
                    .$record['url'].' and root url: '. $record['url_host']);
            } else if (isset($record['meta_value']) && !isset($record['url'])) {
                // check exiting URL to see if it's a valid feed...
                $this->log('checking meta_value: '.print_r($record['meta_value'], true));
                if ($urls = $this->check_for_feed($record['meta_value'])) {
                    $feed['url'] = $urls['url'];
                    $feed['url_host'] = $urls['url_host'];
                    $feed['feed_url'] = $urls['feed_url'];
                    $feed['feed_type'] = $urls['feed_type'];
                }
            } else if (isset($record['url']) && !isset($record['feed_url'])) {
                // check exiting URL to see if it's a valid feed...
                $this->log('testing URL '.$record['url'].' for feed.');
                if ($urls = $this->check_for_feed($record['url'])) {
                    $feed['url'] = $urls['url'];
                    $feed['url_host'] = $urls['url_host'];
                    $feed['feed_url'] = $urls['feed_url'];
                    $feed['feed_type'] = $urls['feed_type'];
                }
            }
            // find an avatar URL
            $avatar_args = array();
            $feed['gravatar'] = get_avatar_url($record['user_id'],
                array('default'=>'identicon', 'processed_args'=>$avatar_args));

            // mark this with WordPress module type
            $feed['we_source'] = 'wenotes_wp';
            $feed['we_wp_version'] = WENOTES_VERSION;
            $feed['type'] = 'feed';

            // commit this feed...
            // first checking if the combination of
            // wp ID and url are already there...
            if ($id = $this->already_registered($feed['from_user_wp_id'],$site_id)) {
                // merge any changes?
                $this->log('existing id: '.$id);
            } else {
                // this is a new registration
                $this->log('adding user_id: '.$feed['from_user_wp_id'].', site: '.$site_id.', url: '.$feed['url']);
                $this->register_feed($feed);
            }
        } else {
            if ($record['spam']) {
                $this->log('This record (user '.$user.', site '.$site_id.') has been deemed spammy.');
            }
            if ($record['deleted']) {
                $this->log('This record (user '.$user.', site '.$site_id.') has been deleted.');
            }
            // tidy up any references in the CouchDB
            $unfeed = array();
            $this->deregister_feed($unfeed);
        }
        return true;
    }

    // if there's already a doc with this pair of user and site ids,
    // then we've already got a record...
    public function already_registered($user_id, $site_id) {
        $this->log('Does this user + site combo already exist: user '.
            $user_id . ', site '.$site_id);
        $couch = $this->couchdb();
        try {
            //$rows = $couch->get('53cb4a62eb50208180d0f0b2bb02a4d2');
            /*$content = $couch->get('/_design/ids/_view/by_site_and_wp_id_short?key=['.
                $user_id.','.(int)$site_id.']&descending=true');*/
            $content = $couch->get('/_design/ids/_view/by_site_and_wp_id?key=['.
                $user_id.','.(int)$site_id.']&descending=true');
            $this->log('Result of couchdb query is: '. print_r($content, true));
            $result = json_decode($content->body, true);
            $this->log('CouchDB number of rows returned: '. count($result['rows']));
            //$this->log('CouchDB rows returned: '. print_r($result, true));
            if (count($result['rows'])) {
                // tidy up older versions...
                $i = 0;
                $preserved = false;
                foreach($result['rows'] as $row) {
                    if ($i == 0) {
                        $this->log('*** preserving: '. print_r($row, true));
                        $preserved = $row['id'];
                    } else {
                        // cleaning up excess documents - these shouldn't
                        // be here...
                        $this->log('--- removing: '. print_r($row, true));
                        $this->remove($row['id']);
                    }
                    $i++;
                }
                if ($preserved) {
                    $this->log('yes, returning existing id: '.$preserved);
                    return $preserved;
                }
            } else {
                $this->log('no documents returned!');
            }
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
        return false;
    }

    // remove a couch document
    private function remove($id) {
        $this->log('Removing document id '.$id);
        // first request the document based on the ID
        $couch = $this->couchdb();
        $result = json_decode($couch->get($id)->body, true);
        $this->log('returned details: '.print_r($result, true));
        // get the _id and _rev
        $this->log('deleting _id: '.$result['_id'].', _rev: '.$result['_rev']);
        // remove it
        try {
            $result = $couch->delete($result['_id'],$result['_rev']);
            $this->log('result from deleting: '. print_r($result, true));
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
    }

    // submit a feed registration to CouchDB using a feed object
    public function register_feed($feed) {
        //$this->log('Feed object: '. print_r($feed, true));
        // make the connection
        $couch = $this->couchdb();
        // get the current time
		list($usec, $ts) = explode(' ', microtime());
        $feed['created_at'] = date('r', $ts);
		$feed['we_timestamp'] = date('Y-m-d\TH:i:s.000\Z', $ts);
        //$this->log('writing feed description: '. print_r($feed, true));
        try {
            //$result = $couch->get('_all_docs');
            $result = $couch->post($feed);
            //$this->log('CouchDB result: '. print_r($result, true));
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
        // need to check if the same url + user details already exist in couchdb
        // in which case we might have to add a tag.
        // If the same user details + tag exist, then we might need to update the
        // url...
        // Otherwise, it might be a no-op.
    }

    // update the feed URL for a given user and site combo, or if it doesn't
    // exist, register it.
    public function update_url_for_user_and_site($user_id, $site_id, $feed) {
        // make the connection
        $couch = $this->couchdb();
        // get the current time
		list($usec, $ts) = explode(' ', microtime());
        $feed['created_at'] = date('r', $ts);
		$feed['we_timestamp'] = date('Y-m-d\TH:i:s.000\Z', $ts);
        //$this->log('writing feed description: '. print_r($feed, true));
        try {
            //$result = $couch->get('_all_docs');
            $result = $couch->post($feed);
            //$this->log('CouchDB result: '. print_r($result, true));
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
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
            $result['url'] = $url;
            $found = false;
            // make sure there's no "edit" mentioned in the path...
            if (preg_match('/edit/', $path, $matches)) {
                $this->log('This URL looks like an "edit" URL:'.$url);
                return(false);
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
        // figure out the CouchDB equivalents for the user's sites
        $user_id = $user->ID;
        $reg_status = $this->get_reg_status_by_user($user_id);
        $this->log('reg_status = '. print_r($reg_status, true));
        $html = "";
        $count = 0;
        foreach ($sites as $site) {
            $stripe = ($count++%2) ? "even" : "odd";
            $site_id = $site->userblog_id;
            $html .= '<li class="wenotes-site '.$stripe.'"><a title="Site id is '.$site_id.'" href="'.$site->path.'">'.$site->blogname.'</a>';
            if ($url = $this->get_blog_url_for_user_for_site($user_id, $site_id)) {
              $html .= ' (<a href="'.$url.'">'.$url.'</a>)';
            } else {
              $html .= ' (no URL specified)';
            }
            if ($reg_status && isset($reg_status[$site_id])) {
                $this->log('Info for this site: '. print_r($reg_status[$site_id], true));
                $html .= ' Registered ('.$reg_status[$site_id]['url'].', set '.$reg_status[$site_id]['we_timestamp'].')';
            } else {
                $html .= ' Not Registered';
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
        //$this->log('in WENotes register_shortcodes');
        add_shortcode( 'WEnotes', array($this, 'wenotes_func'));
        add_shortcode( 'WEnotesPost', array($this, 'wenotespost'));
    }

    /*
     * Hooks for WP actions
     */

    // initialise the hook methods
    public function register_hooks() {
        //$this->log('in WENotes register_hooks');
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

        // other Hooks
        add_action( 'wp_ajax_wenotes', array($this, 'wenotespost_ajax'));
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
        $old_url = get_user_meta($user_id, 'url_'.$site_id);
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
                $this->update_registered_feed($user, $site_id, $new_url);
            }
        }
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

    // submit a WENotes post from the WordPress form.
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

    /**
     *  CouchDB integration
     */
    // initiate couchdb connection or return the existing one...
    public function couchdb() {
        if (!$this->couchdb) {
            require_once( 'sag/src/Sag.php' );
            $this->log('creating a new couchdb connection');
        	  $current_user = wp_get_current_user();
        	  list( $usec, $ts ) = explode( ' ', microtime() );
            $sag = new Sag( WENOTES_HOST, WENOTES_PORT );
            $sag->login( WENOTES_USER, WENOTES_PASS );
            if ($this->check_db($sag)) {
                $sag->setDatabase(WENOTES_BLOGFEEDS_DB);
                $this->couchdb = $sag;
                // if fresh, we need to prime the CouchDB...
                if ($this->fresh) {
                    // install the design document
                    $design_doc = file_get_contents(WENOTES_PATH.
                        '/includes/design_ids.json');
                    //$design_doc = '{ "_id" : "_design/ids" }';
                    //$this->log('design document: '. print_r(json_decode($design_doc), true));
                    $this->log('design document: '. $design_doc);
                    try {
                        $this->log('setting up _design/ids document...');
                        $response = $this->couchdb->putNew($design_doc,'/_design/ids');
                        $this->log('response: '. print_r($response, true));
                    } catch (SagCouchException $e) {
                        $this->log($e->getCode() . " unable to access");
                    }
                    // if we're successful, turn off "fresh"
                    if ($this->update_all_feed_registrations()) {
                        $this->fresh = false;
                    }
                }
            } else {
                $this->log('failed to check the database!');
                return false;
            }
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
