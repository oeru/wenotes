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
    public function update_registered_feed($user_id, $site_id, $newurl, $type) {
        //$user_id = $user->ID;
        $user = get_userdata($user_id);
        $this->log('updating registered blog url for user '.$user_id.' and site '.$site_id
            .' to '.$newurl.' ('.$this->feed_types[$type].')');
        $couch = $this->couchdb();
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
        try {
            $result = json_decode($couch->get('/_design/ids/_view/by_site_and_wp_id?key=['.
                $user_id.','.$site_id.']')->body, true);
            //$this->log('CouchDB number of rows returned: '. count($result['rows']));
            //$this->log('CouchDB result (update_registered_feed): '. print_r($result, true));
            // if there's a valid entry for a user + site combination already, change it
            if (count($result['rows'])) {
                $doc = $result['rows'][0];
                //$this->log('CouchDB result (update_registered_feed): '. print_r($result, true));
                $this->log('CouchDB data array to change: '. print_r($doc, true));
                // setting URL to new value, if it has a new value...
                if ($doc['value']['feed_url'] !== $newurl) {
                    $doc_id = $doc['value']['_id'];
                    $doc_rev = $doc['value']['_rev'];
                    $this->log('updating url in doc (_id = '.$doc_id.') from '.$doc['value']['feed_url'].' to '.$newurl.'.');
                    $doc['value']['feed_url'] = $newurl;
                    $doc['value']['feed_type'] = $type;
                    //$doc_json = json_encode($doc);
                    $this->log('json encoded doc: '. print_r($doc['value'], true));
                    try {
                        $res = $couch->put($doc_id, $doc['value']);
                        $this->log('saved updated entry - result: '. print_r($res, true));
                    } catch (SagCouchException $e) {
                        $this->log($e->getCode() . " unable to access");
                    }
                // if the URL hasn't been altered, then stop.
                } else {
                    $this->log('URL value unchanged ('.$newurl.'), not altering CouchDB');
                }
                return true;
            // if there's no URL entry for a user + site combo, create one.
            } else {
                $this->log('registering a new url!');
                $record = array();
                $record['user_id'] = $user_id;
                $record['site_id'] = $site_id;
                $record['feed_url'] = $newurl;
                $record['feed_type'] = $type;
                $record['spam'] = false;
                $record['deleted'] = false;
                $record['user_nicename'] = $user->user_nicename;
                $record['display_name'] = $user->dispay_name;
                if ($this->register_feed_from_record($record)) {
                    $this->log('successfully added record for user_id '.$user_id.
                        ', site_id '.$site_id.' to new url: '.$newurl);
                        return true;
                }
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
        // confirm that the user + feed url doesn't already exist
        if ($results = $this->already_registered($user_id, $url)) {
            // if there's a valid entry for a user + url combination already continue
            if (count($results['rows']) > 0) {
                $num = 0;
                foreach($results['rows'] as $interim) {
                    $result = $interim['value'];
                    $num++;
                    $this->log('preexisting CouchDB doc: '.$num.': '. print_r($result, true));
                }
            // if there's no URL entry for a user + site combo, create one.
            } else {
                $this->log('registering a new url!');
                $record = array();
                $record['wp_user_id'] = $user_id;
                $record['feed_url'] = $url;
                $record['feed_type'] = $type;
                $record['wp_site_ids'] = $site_ids; // array of site id numbers
                $record['tags'] = $this->get_site_tags_for_ids($site_ids); // array of site tags
                $record['spam'] = false;
                $record['deleted'] = false;
                $record['user_nicename'] = $user->user_nicename;
                $record['display_name'] = $user->dispay_name;
                $record['gravatar'] = get_avatar_url($user_id,
                    array('default'=>'identicon', 'processed_args'=>$avatar_args));
                // mark this with WordPress module type
                $record['we_source'] = 'wenotes_wp';
                $record['we_wp_version'] = WENOTES_VERSION;
                $record['type'] = 'feed';
                $this->log('**** registering feed for user '.$user_id.' to '.$url.
                    ' ('.$this->feed_types[$type].') for tags '.print_r($record['tags'], true));
                if ($this->register($record)) {
                    return true;
                } else {
                    $this->log('failed to register user_id '.$user_id.' and feed '.$url);
                }
            }
        } else {
            $this->log('check for registered feed for user_id'.$user_id.' and '.$url.' failed.');
        }
        return false;
    }
    // add the site tag to an existing feed registration for a user,
    // creating the feed registration if it doesn't exist.
    public function register_site_on_user_feed($user_id, $site_id, $url, $type) {

    }
    // remove a site tag from an existing feed registration for a user,
    // removing the feed registration if this is the last site associated with it.
    public function deregister_site_from_user_feed($user_id, $site_id, $url) {

    }
    // remove feed for a user
    public function deregister_user_feed($user_id, $url) {

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
                $user_id.','.$url.']&descending=true');
            $this->log('Result of couchdb query is: '. print_r($content, true));
            $result = json_decode($content->body, true);
            $this->log('CouchDB number of rows returned: '. count($result['rows']));
            //$this->log('CouchDB rows returned: '. print_r($result, true));
            $cnt = count($result['rows']);
            if ($cnt == 1) {
                return $result['rows'][0];
            } elseif ($cnt > 1) {
                $this->log('Whooooah! Returned '.$cnt.' rows!');
                return $result['rows'][0];
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

    // submit a feed registration to CouchDB using a feed record object
    public function register($record) {
        // make the connection
        $couch = $this->couchdb();
        $couch->setDatabase(WENOTES_BLOGFEEDS_DB);
        // get the current time
		    list($usec, $ts) = explode(' ', microtime());
        $feed['created_at'] = date('r', $ts);
	    $feed['we_timestamp'] = date('Y-m-d\TH:i:s.000\Z', $ts);
        $this->log('writing feed description: '. print_r($feed, true));
        try {
            $result = $couch->post($record);
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
