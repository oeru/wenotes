<?php
/*
 *  WordPress API "meta" related functions
 */

require WENOTES_PATH . '/includes/wenotes-couch.php';

class WENotesFeed extends WENotesCouch {

    // feed types
    protected $feed_types = array(
        'application/atom+xml' => 'Atom',
        'application/rss+xml' => 'RSS',
        'application/json' => 'JSON',
    );
    protected $feed_classes = array(
         'application/atom+xml' => 'bff-atom',
         'application/rss+xml' => 'bff-rss',
         'application/json' => 'bff-rss',
         'application/xml' => 'bff-default'
    );
    protected $errors = array(), $messages = array();


    // given a site id and user id, get any associated blog url or return false
    public function get_feed_for_user_for_site($user_id, $site_id) {
        // get the blog URL set for a user_id and site_id combo
        if ($url = get_user_meta($user_id, 'url_'.$site_id, true)) {
            $this->log('found url '.$url.' for user '.$user_id.' and site '.$site_id);
            return $url;
        }
        $this->log('no url found for user '.$user_id.' and site '.$site_id);
        return false;
    }

    // given a site id and user id, get any associated blog url or return false
    public function get_feed_type_for_user_for_site($user_id, $site_id, $url) {
        // get the blog URL set for a user_id and site_id combo
        if ($url != '') {
            if ($type = get_user_meta($user_id, 'feedtype_'.$site_id, true)) {
                $this->log('found feed type '.$type.' for user '.$user_id.' and site '.$site_id);
                return $type;
            } else if ($type = $this->get_feed_type($url)) {
                if (update_user_meta($user_id, 'feedtype_'.$site_id, $type)) {
                    $this->log('successfully set user '.$user_id.' feed type for site '.
                        $site_id.' to '.$type);
                }
                return $type;
            }
            $this->log('no feed type found for user '.$user_id.' and site '.$site_id);
        } else {
            $this->log('no url supplied for user '.$user_id.', site '.$site_id);
        }
        return false;
    }

    // alter the existing blog feed listed for a site and user
    // setting the feed type along the way, returning false if it fails to set
    // the value or find a feed type...
    public function update_feed_for_user_for_site($user_id, $site_id, $url) {
        $new_url = sanitize_text_field($url);
        // get the old URL and make sure it's not the same as the new URL...
        if ($old_url = $this->get_feed_for_user_for_site($user_id, $site_id)) {
            if ($old_url == $new_url) {
                $this->log('No change necessary - the old and new URL are both '. $old_url);
                $this->messages[] = array(
                    'message' => 'No update necessary - same URL!');
                if ($type = $this->get_feed_type($new_url)) {
                    $this->log('found feed of type '.$type.' at '.$new_url.'.');
                    $this->messages[] = array(
                        'message' => 'found '.$type.' feed at '.$new_url.'.');
                    return true;
                } else {
                    $this->errors[] = array('error' => 'No recognisable feed found at '.$new_url.'.',
                        'replace_url' => $old_url);
                    return false;
                }
            }
            $this->log('updating '.$old_url.' feed to '.$new_url.' of type '.$type);
        } else {
            $this->log('setting feed to '.$new_url.' of type '.$type);
        }
        // get the blog URL set for a user_id and site_id combo
        if ($type = $this->get_feed_type($new_url)) {
            $this->log('found feed of type '.$type.' at '.$new_url.'.');
        } else {
            $this->errors[] = array('error' => 'No recognisable feed found at '.$new_url.'.');
            return false;
        }
        // set the value
        if (update_user_meta($user_id, 'url_'.$site_id, $new_url)) {
            $this->messages[] = array('message' => 'set feed url to '.$new_url.
                ' for user '.$user_id.' and site '.$site_id);
            if (update_user_meta($user_id, 'feedtype_'.$site_id, $type)) {
                $this->messages[] = array('message' => 'set feed type to '.$type.
                    ' for user '.$user_id.' and site '.$site_id);
                return true;
            }
            $this->errors[] = array('error' => 'failed to set feed type to '.
                    $type.' for user '.$user_id.' and site '.$site_id);
            return false;
        }
        $this->errors[] = array('error' => 'failed to set feed to '.
            $new_url.' for user '.$user_id.' and site '.$site_id);
        return false;
    }

    // remove the existing blog feed (and any feed type) listed for a site and user
    public function delete_feed_for_user_for_sites($user_id, $site_id) {
        $url_token = 'url_'.$site_id;
        $feedtype_token = 'feedtype_'.$site_id;
        if ($url = get_user_meta($user_id, $url_token, true)) {
            if (delete_user_meta($user_id, $url_token)) {
                $this->messages[] = array('message' => 'deleted '.
                    $url_token.' which was set to '.$url.
                    ' for user '.$user_id.' and site '.$site_id);
                if ($feedtype = get_user_meta($user_id, $feedtype_token, true)) {
                    if (delete_user_meta($user_id, $feedtype_token)) {
                        $this->messages[] = array('message' => 'deleted '.$feedtype_token.
                            ' which was of '.$feedtype.' for user '.$user_id.
                            ' and site '.$site_id);
                    }
                } else {
                    $this->messages[] = array('message' => $feedtype_token.
                        ' not defined, so not deleted, for user '.$user_id.
                        ' and site '.$site_id);
                }
                return true;
            } else {
                $this->errors[] = array('error' => 'failed to delete '.$url_token.
                    'for user '.$user_id.' and site '.$site_id);
                return false;
            }
        }
        $this->message[] = array('message' => $url_token.' not set for user '.$user_id.' and site '.$site_id);
        return false;
    }


    // check and see if there are any references to feeds in the content of the page
    public function get_feed_type($url) {
        $this->log('checking the content of '.$url.' to work out the feed type.');
        // failing that, get the actual HTML...
        $content = file_get_contents($url, FALSE, NULL, 0, WENOTES_MAX_FILE_READ_CHAR);
        // check if the content is valid XML, and if so, what type...
        if ($type = $this->is_valid_xml($content)) {
            // is it an type we're looking for?
            if (array_key_exists($type, $this->feed_types)) {
                $this->log('the content is of type "'.$this->feed_types[$type].'".');
                return $type;
            }
            $this->log('the content is XML, but not of a sort we support as a feed type');
        } else {
            $this->log('the content isn\'t valid XML.');
        }
        // check if the content is valid JSON...
        if ($type = $this->is_valid_json($content)) {
            $this->log('ok, found that it\'s in JSON format, so it\'s probably a feed.');
            return $type;
        }
        return false;
    }

    private function is_valid_xml($content) {
        // first check if the suspected feed URL points to a valid XML feed
        try {
            libxml_use_internal_errors(true);
            $xml = new SimpleXmlElement($content);
        } catch (Exception $e){
            return false;
        }
        $type = 'none';
        if ($xml->channel->item && $xml->channel->item->count() > 0) {
            $type = 'application/rss+xml';
        } elseif ($xml->entry) {
            $type = 'application/atom+xml';
        } else {
            $type = 'xml';
        }
        $this->log('found type = '. $type);
        return $type;
    }

    // check if a string is valid JSON
    private function is_valid_json($content) {
        $this->log('check if the content found at '.$url.' is valid JSON.');
        json_decode($content);
        // if not, check if it's a valid JSON feeds
        if (json_last_error() == JSON_ERROR_NONE) {
            $this->log('the content is, however, valid JSON.');
            $type = 'application/json';
            return $type;
        }
        $this->log('hmm, this content isn\'t valid JSON.');
        return false;
    }

    // return html for a feed icon
    protected function get_feed_icon($type) {
        $msg = '';
        if (isset($this->feed_classes[$type])) {
            $msg = '<span title="'.$this->feed_types[$type].
                ' Format" class="'.$this->feed_classes[$type].
                ' bff-feed" ></span>';
        }
        return $msg;
    }
}
