<?php
/*
 *  CouchDB related functions
 */

require WENOTES_PATH . '/includes/wenotes-util.php';

class WEnotesCouch extends WEnotesUtil {

    protected static $couchdb; // CouchDB client object
    // feed types
    protected $feed_types = array(
        'application/atom+xml' => 'Atom',
        'application/rss+xml' => 'RSS',
        'application/json' => 'JSON',
    );
    protected $feed_classes = array(
         'application/atom+xml' => 'wenotes-atom',
         'application/rss+xml' => 'wenotes-rss',
         'application/json' => 'wenotes-rss',
         'application/xml' => 'wenotes-default'
    );

    /**
     *  CouchDB integration
     */
    // initiate couchdb connection or return the existing one...
    public function couchdb($prime = false) {
        if (!$this->couchdb) {
            require_once WENOTES_PATH . '/sag/src/Sag.php';
            $this->log('creating a new couchdb connection');
        	  $current_user = wp_get_current_user();
        	  list( $usec, $ts ) = explode( ' ', microtime() );
            $sag = new Sag( WENOTES_HOST, WENOTES_PORT );
            $sag->login( WENOTES_USER, WENOTES_PASS );
            if ($this->check_db($sag)) {
                //
                $this->couchdb = $sag;
                // if fresh, we need to prime the CouchDB...
                if ($this->fresh) {
                    $sag->setDatabase(WENOTES_BLOGFEEDS_DB);
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
                    /*if ($this->update_all_feed_registrations()) {
                        $this->fresh = false;
                    }*/
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

    // check if the relevant database and views are in place, and if not,
    // create them (and populate them)
    protected function check_db($couch) {
        $this->log('checking if database: '.WENOTES_BLOGFEEDS_DB .' exists...');
        try {
            // get all the databases
            $dbs = json_decode($couch->getAllDatabases()->body);
            // if our database exists, all good
            //$this->log('list of databases: '. print_r($dbs, true));
            if (in_array(WENOTES_BLOGFEEDS_DB, $dbs)) {
                // if not, created it
                $this->log('The database exists.');
            } else {
                $this->log('The database does not exist, creating it');
                try {
                    $couch->createDatabase(WENOTES_BLOGFEEDS_DB);
                    $this->log('Created a new database: '.WENOTES_BLOGFEEDS_DB);
                    $this->log('Turning off "fresh"');
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
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
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
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
        try {
            $data = array();
            $result = json_decode($couch->get('/_design/ids/_view/by_site_id?key=["'.
                $site_id.'"]')->body, true);
            //$this->log('CouchDB number of rows returned: '. count($result['rows']));
            $this->log('******* get_reg_status_by_site CouchDB result: '. print_r($result, true));
            if ($result && count($result['rows'])) {
                $this->log('got result, and non-zero rows...');
                foreach($result['rows'] as $row) {
                    $data[$row['value']['wp_user_id']] = $row['value'];
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

    /* new couch<->WP data model */
    // register a feed for a user for a series of sites
    public function register_new_feed_for_user($user_id, $site_ids, $url, $type) {
        $user = get_userdata($user_id);
        $this->log('register_new_feed_for_user ===========================================');
        // confirm that the user + feed url doesn't already exist
        if ($results = $this->already_registered($user_id, $url)) {
            $this->log('the url is already registered... '.print_r($results, true));
            $num = 0;
            // if the results includes a 'rows' array...
            if (is_array($results['rows'])) {
                foreach($results['rows'] as $interim) {
                    $result = $interim['value'];
                    $num++;
                    $this->log('preexisting CouchDB doc: '.$num.': '. print_r($result, true));
                }
            } /*else {
                $existing_site_ids = $results['value']['0'];
                $this->log('found an existing record id: '.$results['id'].' for sites: '.print_r($existing_site_ids, true));
                foreach($site_ids as $site_id) {
                    if (!in_array($site_id, $existing_site_ids)) {
                        // add the new site to the record:
                        if ($this->register_site_on_user_feed($user_id, $site_id, $url, $type)) {
                            $this->log('added '.$site_id.' to user ('.$user_id.') and url '.$url);
                        } else {
                            $this->log('failed to add '.$site_id.' to user ('.$user_id.') and url '.$url);
                        }
                    }
                }
            }*/
        // if there's no URL entry for a user + site combo, create one.
        } else {
            $this->log('registering a new url: url '.$url.' type '.$type);
            $record = array();
            $record['wp_user_id'] = $user_id;
            $record['feed_url'] = $url;
            $record['feed_type'] = $type;
            $record['wp_site_ids'] = $site_ids; // array of site id numbers
            $record['tags'] = $this->get_site_tags_for_ids($site_ids); // array of site tags
            $record['spam'] = false;
            $record['deleted'] = false;
            $record['username'] = $user->data->user_login;
            $record['nicename'] = $user->data->user_nicename;
            $record['display_name'] = $user->data->display_name;
            $record['first_name'] = $user->first_name;
            $record['last_name'] = $user->last_name;
            $record['gravatar'] = get_avatar_url($user_id,
                array('default'=>'identicon', 'processed_args'=>$avatar_args));
            // mark this with WordPress module type
            $record['we_source'] = WENOTES_SOURCE;
            $record['we_wp_version'] = WENOTES_VERSION;
            $record['type'] = 'feed';
            $this->log('**** registering feed for user '.$user_id.' to '.$url.
                ' ('.$this->feed_types[$type].') for tags '.print_r($record['tags'], true));
            if ($this->register($record)) {
                $this->log('successfully registered');
                return true;
            } else {
                $this->log('failed to register user_id '.$user_id.' and feed '.$url);
            }
        }
        return false;
    }

    // respond to bff_update_user_feed hook
    public function update_feed_hook($args) {
        $this->log('in update_feed_hook function!');
        $user_id = $args['user_id'];
        $site_id = $args['site_id'];
        $url = $args['url'];
        $type = $args['type'];
        if ($this->register_site_on_user_feed($user_id, $site_id, $url, $type)) {
            $this->log('updated feed '.$url.' to include site_id '.$site_id.': '.$url.' ('.$type.')');
        } else {
            $this->log('update_feed_hook failed');
        }
        return;
    }

    // add the site tag to an existing feed registration for a user,
    // creating the feed registration if it doesn't exist.
    public function register_site_on_user_feed($user_id, $site_id, $url, $type) {
        $this->log('////////////////////////// register site on user feed: user '.$user_id.
            ', site '.$site_id.', url '.$url.', type '.$type);
        $create = false;
        // get any feeds for the user
        if ($feeds = $this->get_registered_feeds_for_user($user_id)) {
            $this->log(count($feeds).' feed(s) for user '.$user_id);
            // we'll need this below
            $tag = $this->get_site_tag(get_site($site_id));
            // sift through the existing feed records
            $success = false;
            foreach($feeds as $feed_full) {
                $feed = $feed_full['value'];
                $this->log('***************************************************** feed: '.print_r($feed, true));
                $this->log('comparing urls: ('.$feed['feed_url'].') == ('.$url.')?');
                // does this feed object already have url we're registering for this user?
                if ($feed['feed_url'] == $url) {
                    // make sure we don't try to create another rego for this feed.
                    $create = false;
                    $this->log('This feed+type already exists for this user! Checking site_ids');
                    // does this feed registration already include this site_id?
                    if (is_array($feed['wp_site_ids']) && in_array($site_id, $feed['wp_site_ids'])) {
                        $this->log('found site '.$site_id.' in feed '.$feed['feed_url'].'!!!');
                        $this->log('This feed+type is a duplicate for this user! No changes necessary');
                    // this site_id (and associated tag) needs to be added!
                    } else {
                        if (!is_array($feed['wp_site_ids'])) {
                            $feed['wp_site_ids'] = array();
                            $feed['wp_site_ids'][] = (isset($feed['wp_site_id']))? $feed['wp_site_id']:$feed['wp_site_ids'] ;
                        }
                        $this->log('adding site_id '.$site_id.' and tag '.$tag.' to feed '.$url);
                        $feed['wp_site_ids'][] = $site_id;
                        $feed['tags'][] = $tag;
                        // now save the resulting feed...
                        if ($this->register($feed)) {
                            $this->log('updated the registration info for user '.$user_id.' and feed '.
                                $url.' to include site '.$site_id.' and tag '.$tag.'.');
                            $success = true;
                        } else {
                            $this->log('failed to update registration info for user '.
                                $user_id.' and feed '.$url.' to include site '.
                                $site_id.' and tag '.$tag.'.');
                        }
                    }
                // this feed doesn't have the same feed url as we're registering...
                // but does it already represent the site_id/tag?
                } else {
                    $this->log('feed has a different url '.$feed['feed_url'].' - '.
                        print_r($feed['wp_site_ids'],true));
                    // does this feed object have our site_id? If so, remove it...
                    if (is_array($feed['wp_site_ids']) && in_array($site_id, $feed['wp_site_ids'])) {
                        $this->log('found site (2) '.$site_id.' in feed '.$feed['feed_url'].'!!!');
                        if ($this->deregister_site_from_user_feed($user_id, $site_id, $feed['feed_url'])) {
                            $this->log('successfully removed site '.$site_id.' from feed '.$feed['feed_url']);
                        } else {
                            $this->log('failed to remove site '.$site_id.' from feed '.$feed['feed_url']);
                        }
                    }
                }
            }
            if ($success) {
                return true;
            } else {
                $create = true;
            }
        } else {
            $create = true;
            // create a new user feed entry for this site.
        }

        // if the feed being changed doesn't exist, create it (with the site_id & corresponding tag).
        if ($create) {
            if ($this->register_new_feed_for_user($user_id, array($site_id), $url, $type)) {
                $this->log('created new feed for user '.$user_id.', url '.$url.
                    ', type '.$type.', for site '.$site_id);
                return true;
            } else {
                $this->log('failed to create new feed for user '.$user_id.
                    ', url '.$url.', type '.$type.', for site '.$site_id);
            }
        }
        return false;
        // if another feed exists with that site/tag already, remove it.
    }

    // get the list of registered feeds for the user_id, returning an array
    // of pages. If none, return false.
    public function get_registered_feeds_for_user($user_id) {
        $this->log('Does user '.$user_id . ' have any existing feeds?');
        $couch = $this->couchdb();
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
        try {
            $content = $couch->get('/_design/ids/_view/by_wp_id?key='.
                $user_id);
            $this->log('Result of couchdb query is: '. print_r($content, true));
            $result = json_decode($content->body, true);
            $this->log('CouchDB number of rows returned: '. count($result['rows']));
            //$this->log('CouchDB rows returned: '. print_r($result, true));
            $cnt = count($result['rows']);
            if ($cnt == 1) {
                $this->log('Returned one row!');
                return $result['rows'];
            } elseif ($cnt > 1) {
                $this->log('Whooooah! Returned '.$cnt.' rows!');
                return $result['rows'];
            } else {
                $this->log('no documents returned!');
            }
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
        }
        return false;
    }


    // remove a site tag from an existing feed registration for a user,
    // removing the feed registration if this is the last site associated with it.
    public function deregister_site_from_user_feed($user_id, $site_id, $url) {
        // get the feed for the users with the site/tag
        if ($feeds = $this->get_registered_feeds_for_user($user_id)) {
            $this->log(count($feeds).' feed(s) for user '.$user_id);
            // we'll need this below
            $tag = $this->get_site_tag(get_site($site_id));
            // sift through the existing feed records
            $success = false;
            foreach($feeds as $feed_full) {
                $feed = $feed_full['value'];
                $this->log('--------------------------------------------------- feed: '.print_r($feed, true));
                // does this feed object already have url we're registering for this user?
                if ($feed['feed_url'] == $url) {
                    $this->log('Found the feed for this user! Checking site_ids');
                    // does this feed registration already include this site_id?
                    if (is_array($feed['wp_site_ids']) && in_array($site_id, $feed['wp_site_ids'])) {
                        $this->log('found site (3) '.$site_id.' in feed '.$feed['feed_url'].'!!!');
                        // we're here to remove it
                        foreach($feed['wp_site_ids'] as $key => $id) {
                            if ($id == $site_id) {
                                unset($feed['wp_site_ids'][$key]);
                            }
                        }
                        foreach($feed['tags'] as $key => $t) {
                            if ($t == $tag) {
                                unset($feed['tags'][$key]);
                            }
                        }
                        // if this was the last site_id for this feed, remove the feed rego, too!
                        if (count($feed['wp_site_ids']) == 0) {
                            $this->log('removing this now-redundant feed, with no registered sites...');
                            if ($this->deregister_user_feed($feed['_id'])) {
                                $this->log('successfully removed feed '.print_r($feed, true));
                                return true;
                            }
                        } else {
                            // and then we save the result
                            if ($this->register($feed)) {
                                $this->log('updated the registration to remove site '.$site_id.
                                    ' and tag '.$tag.' from user '.$user_id.' and feed '.
                                    $url.'.');
                                return true;
                            } else {
                                $this->log('failed to update registration to remove site '.
                                    $site_id.' and tag '.$tag.' from user '.
                                    $user_id.' and feed '.$url.'.');
                            }
                        }
                    } else {
                        $this->log('this feed doesn\'t have site_id '.$site_id.' and tag '.$tag.' specified.');
                    }
                } else {
                    $this->log('this isn\'t the feed we\'re looking for.');
                }
            }
        } else {
            $this->log('user '.$user_id.' has no registered feeds!');
        }
        return false;
    }

    // remove feed for a user based on the couchdb ID and REV
    public function deregister_user_feed($id) {
        if ($this->remove($id)) {
            return true;
        }
        return false;
    }
    /* new couch<->WP data model */

    // convert an array of site IDs into their related tags.
    public function get_site_tags_for_ids($ids) {
        $this->log('getting tags for ids: '.print_r($ids, true));
        $tags = array();
        foreach($ids as $id) {
            $tags[] = $this->get_site_tag(get_site($id));
        }
        return $tags;
    }

    // if there's already a doc with this pair of user and site ids,
    // then we've already got a record...
    public function already_registered($user_id, $url) {
        $this->log('Does this user + feed url combo already exist: user '.
            $user_id . ', feed url '.$url);
        $couch = $this->couchdb();
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
        try {
            $content = $couch->get('/_design/ids/_view/by_wp_id_and_url?key=['.
                $user_id.',"'.$url.'"]&descending=true');
            $this->log('Result of couchdb query is: '. print_r($content, true));
            $result = json_decode($content->body, true);
            $this->log('CouchDB number of rows returned: '. count($result['rows']));
            //$this->log('CouchDB rows returned: '. print_r($result, true));
            $cnt = count($result['rows']);
            if ($cnt == 1) {
                $this->log('One row returned!');
                return $result['rows'][0];
            } elseif ($cnt > 1) {
                $this->log('Whooooah! Returned '.$cnt.' rows!');
                return $result['rows'];
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
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
        $result = json_decode($couch->get($id)->body, true);
        $this->log('returned details: '.print_r($result, true));
        // get the _id and _rev
        $this->log('deleting _id: '.$result['_id'].', _rev: '.$result['_rev']);
        // remove it
        try {
            $result = $couch->delete($result['_id'],$result['_rev']);
            $this->log('result from deleting: '. print_r($result, true));
            return true;
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
            return false;
        }
    }

    // submit a feed registration to CouchDB using a feed object
    public function register($feed) {
        // make the connection
        $couch = $this->couchdb();
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
        // get the current time
		    list($usec, $ts) = explode(' ', microtime());
        $feed['created_at'] = date('r', $ts);
	    $feed['we_timestamp'] = date('Y-m-d\TH:i:s.000\Z', $ts);
        $this->log('writing feed description: '. print_r($feed, true));
        try {
            $result = $couch->post($feed);
            $this->log('CouchDB result: '. print_r($result, true));
            return true;
        } catch (SagCouchException $e) {
            $this->log($e->getCode() . " unable to access");
            return false;
        }
    }

/*    // update the feed URL for a given user and site combo, or if it doesn't
    // exist, register it. Note that $feed is an array...
    public function update_registered_feed_for_user_and_site($user_id, $site_id, $feed) {
        // make the connection
        $couch = $this->couchdb();
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
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
    }*/


    // remove a feed records with these details from CouchDB
    public function deregister($unfeed) {
        $this->log('Removing feed object: '. print_r($unfeed, true));
    }

    // submit a wenotes post via WP ajax.
    public static function wenotespost_ajax() {
        $this->log('received post request via ajax: '. print_r($data,true));
        $current_user = wp_get_current_user();
        $this->log('the current user details: '.$current_user->user_login .', '. $current_user->display_name);
        list($usec, $ts) = explode(' ', microtime());
        $data = array(
            'from_user' => $current_user->user_login,
            'from_user_name' => $current_user->display_name,
            'created_at' => date( 'r', $ts ),
            'text' => stripslashes(trim($_POST['notext'])),
            'id' => $current_user->ID . $ts . substr( "00000$usec", 0, 6 ),
            'we_source' => 'course',
            'we_tags' => array( strtolower(trim($_POST['notag'])) ),
            'we_timestamp' => date('Y-m-d\TH:i:s.000\Z', $ts),
            'we_version' => WENOTES_VERSION,
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
        $sag->setDatabase(WENOTES_MENTIONS_DB);
        $this->log('calling sag: '.print_r($sag, true));
        $this->log('with data: '.print_r($data, true));
        $response = $sag->post($data);
        $this->log('response: '.print_r($response, true));

      	$this->wenotespostresponse( array(
      		'posted' => $response->body,
            'status' => $response->status,
            'db' => WENOTES_MENTIONS_DB,
     	));
    }
}
