<?php
/*
 *  WordPress API "meta" related functions
 */

require WENOTES_PATH . '/includes/wenotes-feed.php';

class WENotesSites extends WENotesFeed {

    public function init() {
        $this->log('in WENotesSites init');

        $this->log('enabling styles');
        // set up appropriate ajax js file
        wp_register_style('wenotes-sites-style', WENOTES_URL.'css/sites.css');
        //enqueue
        wp_enqueue_style('wenotes-sites-style');
        $this->log('enabling scripts');
        wp_enqueue_script( 'wenotes-sites', WENOTES_URL.'js/sites.js',
            array('jquery','jquery-form'));
        wp_localize_script( 'wenotes-sites', 'wenotes_site_data', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'site_nonce' => wp_create_nonce('wenotes-site-nonse')
        ));
        $this->log('adding ajax actions: alter');
        add_action( 'wp_ajax_wenotes_alter', array($this, 'ajax_alter'));
        $this->log('adding ajax actions: delete');
        add_action( 'wp_ajax_wenotes_delete', array($this, 'ajax_delete'));
    }


    public function site_init($id = '1') {
        $this->log('in site_init, id = '. $id);
        $this->site_tab($id);
        $this->log('populated site tab');
    }

    // handle domain update
    public function ajax_alter() {
        global $_POST;
        $this->log('in ajax_alter');
        $this->check_nonce(sanitize_text_field($_POST['site_nonce']),'wenotes-site-nonce'); // dies if nonce isn't good...
        header( "Content-Type: application/json" );
        $details = array(
            'is_add' => $_POST['is_add'],
            'user_id' => $_POST['user_id'],
            'site_id' => $_POST['site_id'],
            'url' => $_POST['url']);
        if ($type = $this->update_feed_for_user_for_site($details['user_id'],$details['site_id'],
            $details['url'])) {
            // if we added a new URL, change the resulting form
            if ($details['is_add']) {
                $details['new_form'] = $this->alter_url_form($details['user_id'],$details['site_id'],
                    $details['url'], $type, $status);
            }
            $this->ajax_response(array('success' => 'true', 'messages' => $this->messages, 'details' => $details));
        } else {
            $this->ajax_response(array('failure' => 'true', 'errors' => $this->errors, 'details' => $details));
        }
        $this->log('ajax_submit done, dying...');
        wp_die();
    }

    // handle domain delete
    public function ajax_delete() {
        global $_POST;
        $this->log('in ajax_alter');
        $this->check_nonce(sanitize_text_field($_POST['site_nonce']), 'wenotes-site-nonce'); // dies if nonce isn't good...
        header( "Content-Type: application/json" );
        $details = array(
            'user_id' => $_POST['user_id'],
            'site_id' => $_POST['site_id'],
            'url' => $_POST['url']);
        if ($this->delete_feed_for_user_for_sites($details['user_id'],
            $details['site_id'],$details['url'])) {
            // if we don't already have an "add" interface, provide one
            if (! $details['is_add']) {
                $details['new_form'] = $this->add_url_form($details['user_id'],$details['site_id']);
            }
           $this->ajax_response(array('success' => 'true', 'messages' => $this->messages, 'details' => $details));
       } else {
           $this->ajax_response(array('failure' => 'true', 'errors' => $this->errors, 'details' => $details));
       }
        wp_die();
    }

    // Print the site page itself
    public function site_tab($site_id) {
        // get the site's name:
        $site = get_site($site_id);
        $site_name = $this->get_site_tag($site);
        $this->log('site: '.print_r($site, true));
        ?>
        <div class="wrap" id="wenotes-site-detail">
            <h2>WEnotes details for <strong><?php echo $site->blogname.' ('.$site_name.')'; ?></strong></h2>
            <p>Site users and their registered blog feed addresses for WENotes monitoring.</p>
            <p>Administrators can update feed URIs or delete them altogether on a per user basis.
            Note that you must provide well constructed (with a leading Scheme, e.g. http:// or https://) for feed URIs.</p>
            <?php
            if ($this->bff_enabled()) {
                echo "<p>You can test blog feed URIs using the <a href='/blog-feed-finder/' target='_blank'>Blog Feed Finder</a> on this site. You can then copy-and-paste valid feed URIs into this form.</p>";
            }
            ?>
            <table class="wenotes-table segment">
            <?php
                // get the WP users for this site/course
                $users = $this->get_users_for_site($site_id);
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
                        <th class="label url">Feed Details</th>
                    </tr>
                    <?php
                    $reg_status = $this->get_reg_status_by_site($site_id);
                    $this->log('reg_status = '. print_r($reg_status, true));
                    foreach ($users as $index => $data){
                        $user_id = $data->ID;
                        $wp_url = '/wp-admin/network/user-edit.php?user_id='.$user_id;
                        $wp_username = $data->data->user_name;
                        $wp_name = $data->data->display_name;
                        $wp_email = $data->data->user_email;
                        // construct the blog_html
                        $blog_url = $this->get_feed_for_user_for_site($user_id, $site_id);
                        $blog_type = $this->get_feed_type_for_user_for_site($user_id, $site_id, $blog_url);
                        // construct the blog reg status_html
                        if ($reg_status && $reg_status[$user_id]) {
                            $status_html = '<span title="Registered on '.
                                $reg_status[$user_id]['we_timestamp'].'">Registered</span>';
                        }
                        $rowclass = 'user-row';
                        $rowclass .= ($alt%2==0)? ' odd':' even';
                        $line = '<tr class="'.$rowclass.' wenotes-user">';
                        $line .= '    <td class="wenotes-details"><a href="'.$wp_url.'">'.
                            $wp_name.'</a> (<a href="mailto:'.$wp_email.'">'.$wp_email.'</a>)</td>';
                        if ($blog_url) {
                            $line .= $this->alter_url_form($user_id, $site_id, $blog_url, $blog_type, $status_html);
                        } else {
                            $line .= $this->add_url_form($user_id, $site_id);
                        }
                        $line .= $this->feedback_markup($user_id, $site_id);
                        echo $line;
                    }
                } else {
                    $this->log('no users retrieved...');
                    echo '<tr "'.$rowclass.'">';
                    echo ' <td class="no-users">This Site has no Users.</td>';
                    echo '</tr>';
                }?>
            </table>
        </div>
        <?php
    }

    public function get_users_for_site($site_id) {
        // get the WP users for this site/course
        $searchFilter = 'blog_id='.$site_id.
            '&orderby=display_name&orderby=nicename';
        $users = get_users($searchFilter);
        return $users;
    }

    public function alter_url_form($user_id, $site_id, $url, $type, $status = '') {
        $id = $user_id.'-'.$site_id;
        if ($status == '') { $status = '<span>Not Registered</span>'; }
        $txt = '    <td id="cell-'.$id.'" class="blog-url wenotes-form-cell"><input id="url-'.$id.
            '" class="wenotes-form url" name="url-'.$id.'" type="text" value="'.
            $url.'" /> <span class="wenotes-feed-alter update button" id="alter-'.
            $id.'" button">update</span><span class="wenotes-feed-delete button" id="delete-'.
            $id.'">delete</span>&nbsp;'.$this->get_feed_icon($type).
            ' '.$status.'</td>';
        return $txt;
    }

    public function add_url_form($user_id, $site_id) {
        $id = $user_id.'-'.$site_id;
        $txt = '    <td id="cell-'.$id.'" class="blog-url wenotes-form-cell"><input id="url-'.
            $id.'" class="wenotes-form url" name="url-'.
            $id.'" type="text" value="" placeholder="None specified" /> <span id="alter-'.
            $id.'" class="wenotes-feed-alter add button">add</span></td>';
        return $txt;
    }

    public function feedback_markup($user_id, $site_id) {
        $id = $user_id.'-'.$site_id;
        $txt = '    <tr id="row-'.$id.'" class="blog-feedback"><td id="feedback-'.
            $id.'" class="blog-feedback" colspan="2"></td></tr>';
        return $txt;
    }

    // Print the site page itself
    public function site_urldata($site_id) {
        // get the site's name:
        $site = get_site($site_id);
        $site_name = $this->get_site_tag($site);
        $this->log('site url data: '.print_r($site, true));
        // get the WP users for this site/course
        $searchFilter = 'blog_id='.$site_id.'&orderby=display_name&orderby=nicename';
        $users = get_users($searchFilter);
        $this->log('users for blog '.$site_id.':'. print_r($users,true));
        if (count($users)) {
            $alt = 0;
            $reg_status = $this->get_reg_status_by_site($site_id);
            $this->log('reg_status = '. print_r($reg_status, true));
            $timestamp = date("Ymd_His");
            // work out a safe dir to put this output data:
            $outdir = wp_upload_dir();
            $outfile = $outdir['basedir'].'/wenotes/wenotes-'.$site_name.'-'.$timestamp.'.';
            $records = array();
            $user_list_python = array();
            $user_list_php = array();
            foreach ($users as $index => $data){
                $user_id = $data->ID;
                $wp_url = '/wp-admin/network/user-edit.php?user_id='.$user_id;
                $wp_username = $data->data->user_login;
                $wp_name = $data->data->display_name;
                $wp_email = $data->data->user_email;
                // construct the blog_html
                if ($blog_url = $this->get_feed_for_user_for_site($user_id, $site_id)) {
                   $this->log('found feed URL '.$blog_url);
                } else {
                   $this->log('no blog URL found for user '.$user_id.' for site '.$site_id);
                }
                if ($blog_feed_type = $this->get_feed_type_for_user_for_site($user_id, $site_id, $blog_url)) {
                    $this->log('found feed type of '.$blog_feed_type.' ('.$this->feed_types[$blog_feed_type].')');
                }
                // if we got a valid URL and feed type...
                if ($blog_url && $blog_feed_type) {
                    $records[] = array(
                        'user_id' => $user_id,
                        'site_id' => $site_id,
                        'url' => $blog_url,
                        'feed_url' => $blog_url,
                        'feed_type' => $blog_feed_type,
                        'spam' => false,
                        'deleted' => false,
                        'user_nicename' => $wp_username,
                        'display_name' => $wp_name
                    );
                }
            }
        } else {
            $this->log('no users retrieved...');
        }
    }

    // a function to check nonces...
    protected function check_nonce($nonce, $label) {
        $this->log('testing nonce '.$nonce.' "'.$label.'"');
        /*if ( ! wp_verify_nonce($nonce, $label) ) {
            $this->log('nonce "'.$label.'" failed verification.');
            die ("Busted - someone's trying something funny with the $nonce \"".$label."\" nonce");
        }*/
        $this->log('nonce  "'.$label.'" ('.$nonce.') verified.');
        return true;
    }

    // finds sites, finds users for site, and feeds defined
    public function survey_feeds($get_type = false) {
        $survey = array();
        // get sites
        $sites = get_blog_list(0, 'all');
        if (count($sites)) {
            $this->log('found '.count($sites).' sites');
            foreach($sites as $site) {
                $site_id = $site['blog_id'];
                if ($site_id == 1) {
                    $this->log('skipping default site (1)');
                    continue;
                }
                $this->log('site '.$site_id.': '.$site['path']);
                $survey[$site_id]['path'] = $site['path'];
                // get the users for the site
                $users = $this->get_users_for_site($site_id);
                if (count($users)) {
                    foreach($users as $user) {
                        $user_id = $user->ID;
                        $login = $user->data->user_login;
                        $email = $user->data->user_email;
                        $this->log('user '.$user_id.': '.$login.
                            ', '.$email.'.');
                        $survey[$site_id]['users'][$user_id]['name'] = $login;
                        $survey[$site_id]['users'][$user_id]['email'] = $email;
                        // get the "default URL" for each user.
                        if ($default_url = $this->get_default_feed_for_user($user_id)) {
                            $this->log('Default!!!!!!! '.print_r($default_url, true));
                            $url = $default_url['url'];
                            $type = $default_url['type'];
                            $this->log('++++ default feed for '.$email.': '.$url.'('.$type.')');
                            // add to Object
                            $survey[$site_id]['users'][$user_id]['default']['url'] = $url;
                            $survey[$site_id]['users'][$user_id]['default']['type'] = $type;
                        } else {
                            $this->log('no default feed for '.$email.'.');
                        }
                        // get any feed and type for this user + site
                        if ($feed = $this->get_feed_for_user_for_site($user_id, $site_id)) {
                            $survey[$site_id]['users'][$user_id]['feed']['url'] = $feed;
                            if ($type = $this->get_feed_type_for_user_for_site($user_id, $site_id, $feed, $get_type)) {
                                $this->log($login.': '.$feed.' ('.$type.')');
                                $survey[$site_id]['users'][$user_id]['feed']['type'] = $type;
                            } else {
                                $this->log($login.': '.$feed.' (???)');
                            }
                        }

                    }
                } else {
                    $this->log('site '.$site_id.' has no registered users');
                }
            }
            return $survey;
        }
        return false;
    }
    // finds sites, finds users for site, and feeds defined
    public function survey_feeds_print($just_urls = false) {
        // build the array, don't find types of feeds
        if ($survey = $this->survey_feeds(false)) {
            //$this->log('survey: '.print_r($survey, true));
            echo '<ul class="sites">';
            foreach($survey as $site_id => $site) {
                $site_name = $this->get_site_tag(get_site($site_id));
                echo '<li class="site"><p class="site"><a href="'.
                    WENOTES_URL.'wenotes-site.php?id='.$site_id.
                    '">'.$site_name.'</a>&nbsp;('.$site_id.
                    ') - '.$site['path'].'</p>';
                if (isset($site['users'])) {
                    echo '<ul class="users">';
                    foreach($site['users'] as $user_id => $user) {
                        if ($just_urls && !isset($user['feed'])) {
                            continue;
                        }
                        echo '<li class="user">';
                        echo '<p class="user">'.$user['name'].' ('.$user['email'].')';
                        if (isset($user['feed'])) {
                            echo ' '.$user['feed']['url'];
                            if (isset($user['feed']['type'])) {
                                echo ' ('.$this->feed_types[$user['feed']['type']].')';
                            }
                        }
                        if (isset($user['default'])) {
                            echo ' Default: '.$user['default']['url'];
                            if (isset($user['default']['type'])) {
                                echo ' ('.$this->feed_types[$user['default']['type']].')';
                            }
                        }
                        echo '</p>';
                        echo '</li>';
                    }
                    echo '</ul>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
    }

    // go through all the users on the site, and update any feed URL data in CouchDB:
    public function update_all_feed_registrations() {
        // build the array, don't find types of feeds
        if ($feedusers = $this->build_user_feed_site_array()) {
            //$this->log('survey: '.print_r($survey, true));
            $new = 0; $existing = 0;
            //ksort($feedusers);
            echo '<div class="wenotes-rego">';
            foreach($feedusers as $user_id => $userdata) {
                foreach($userdata['feeds'] as $url => $feeddata) {
                    $this->log('url '.$url.' and data: '.print_r($feeddata, true));
                    $type = $feeddata['type'];
                    $site_ids = $feeddata['site_ids'];
                    $this->log('tracking down site_ids array: '.print_r($site_ids, true));
                    $tags = $this->get_site_tags_for_ids($site_ids);
                    $this->log('registering feed for '.$userdata['display_name'].' ('.
                        $userdata['user_nicename'].'), setting '.$url.
                        ' ('.$this->feed_types[$type].')');
                    if ($this->register_new_feed_for_user($user_id, $site_ids, $url, $type)) {
                        $new++;
                        $msg = $new.' registered feed for '.$userdata['display_name'].' ('.
                            $userdata['user_nicename'].'), setting '.$url.
                            ' ('.$this->feed_types[$type].') for tags: '.$this->print_tags($tags);
                        $this->log($msg);
                        echo '<p class="new">'.$msg.'</p>';
                    } else {
                        $existing++;
                        $msg = $existing.' existing feed for '.$userdata['display_name'].' ('.
                            $userdata['user_nicename'].'), leaving '.$url.
                            ' ('.$this->feed_types[$type].') unchanged for tags: '.$this->print_tags($tags);
                        $this->log($msg);
                        echo '<p class="existing">'.$msg.'</p>';
                    }
                }
            }
            $this->log('Phew. '.$new.' new feeds registered.');
            echo '<p>'.$new.' new feeds registered.</p>';
            echo '<p>'.$existing.' existing feeds checked and left unchanged.</p>';
            echo '</div>';
        }
    }

    // create a mixed array of users, sites to which each is registered,
    // the complexity of this function is due to the fact that you can't
    // search Wordpress Meta keys by wildcard... :(
    public function build_user_feed_site_array() {
        // get a list of all active blogs/sites/courses:
        $sites_args = array(
            'site_not_in' => array(1),
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
        );
        // this will be a list of users with feeds, and for which site.
        $feedusers = array();
        // for each site, get a list of users who have defined a feed URL...
        if ($sites = get_sites($sites_args)) {
            foreach($sites as $site) {
                // get the site ID so we can construct the meta_key...
                $site_id = get_object_vars($site)["blog_id"];
                // skip the default site
                if ($site_id == 1) { continue; }
                $this->log('----- working on site '.$site_id.' ('.$this->get_site_tag($site).')');
                // get all Users who have url_* defined as a meta key...
                $user_args = array(
                    'blog_id' => $site_id, // just on this site
                    'meta_key' => 'url_'.$site_id,
                );
                // this should be all users who have a feed defined for the site
                $users = get_users($user_args);
                $this->log('found '.count($users).' user(s) with a feed defined.');
                foreach($users as $user) {
                    // get a list of all sites for which they're registered and have specified
                    // a feed url...
                    $user_id = $user->ID;
                    $this->log('       ----- working with user '.$user->dispay_name.' ('.$user_id.')');
                    if ($url = get_user_meta($user_id, 'url_'.$site_id, true)) {
                        $this->log('          - url '.$url);
                        if ($type = get_user_meta($user_id, 'feedtype_'.$site_id, true)) {
                            $this->log('          - type '.$this->feed_types[$type]);
                            $feedusers[$user_id]['display_name'] = $user->display_name;
                            $feedusers[$user_id]['user_nicename'] = $user->user_nicename;
                            $feedusers[$user_id]['feeds'][$url]['type'] = $type;
                            // add element for this site_id
                            $feedusers[$user_id]['feeds'][$url]['site_ids'][] = $site_id;
                            $this->log('working on user '.$user_id.', url '.$url.' ('.$type.') and adding site_id '.$site_id);
                        }
                    }
                }
                $this->log('----- done with site '.$site_id);
            }
        }
        if (count($feedusers) > 0) {
            //$this->log('returning feedusers: '.print_r($feedusers, true));
            return $feedusers;
        }
        return false;
    }

    // return an array of tags as a string
    public function print_tags($tags) {
        $text = implode(', ', $tags);
        return $text;
    }
}
