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
        //$this->site_styles();
        /*add_action('wp_enqueue_style', array($this, 'site_styles'));
        //$this->site_scripts();
        add_action('wp_enqueue_script', array($this, 'site_scripts'));*/

        $this->log('adding ajax actions: alter');
        add_action( 'wp_ajax_wenotes_alter', array($this, 'ajax_alter'));
        $this->log('adding ajax actions: delete');
        add_action( 'wp_ajax_wenotes_delete', array($this, 'ajax_delete'));
    }


    public function site_init($id = '1') {
        $this->log('in site_init, id = '. $id);
        $this->site_tab($id);
        $this->log('populated site tab');

        //$this->log('sorting out URL data');
        //$this->site_urldata($id);
    }

    // handle domain update
    public function ajax_alter() {
        global $_POST;
        $this->log('in ajax_alter');
        $this->check_nonce(sanitize_text_field($_POST['site_nonce']),'wenotes-site-nonce'); // dies if nonce isn't good...
        header( "Content-Type: application/json" );
        $details = array(
            'user_id' => $_POST['user_id'],
            'site_id' => $_POST['site_id'],
            'url' => $_POST['url']);
        if ($this->update_feed_for_user_for_site($details['user_id'],$details['site_id'], $details['url'])) {
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
                        $id = $user_id.'-'.$site_id;
                        if ($blog_url) {
                            $line .= '    <td class="blog-url"><input id="url-'.$id.
                                '" class="wenotes-form url" name="url-'.$id.'" type="text" value="'.
                                $blog_url.'" /> <span class="wenotes-feed-alter button" id="alter-'.
                                $id.'" button">update</span><span class="wenotes-feed-delete button" id="delete-'.
                                $id.'">delete</span>&nbsp;'.$this->get_feed_icon[$blog_type].' '.
                                $status_html.'</td>';
                        } else {
                            $line .= '    <td class="blog-url"><input id="url-'.
                                $id.'" class="wenotes-form url" name="url-'.
                                $id.'" type="text" value="" placeholder="None specified" /> <span id="alter-'.
                                $id.'" class="wenotes-feed-alter button">add</span></td>';
                        }
                        $line .= '</tr><tr id="row-'.$id.'" class="initially-hidden blog-feedback"><td id="feedback-'.$id.'" class="blog-feedback" colspan="2">testing</td></tr>';
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
        /*            $user_list_python[] = "    { 'from_user': '".$wp_username."', 'from_user_name': '".$wp_name
                        ."', 'from_user_wp_id': ".$user_id.", 'site_id': ".$site_id
                        ."', 'from_user_email': '".$wp_email.", 'tag': '".$site_name
                        ."', 'feed_url': '".$blog_url
                        ."', 'we_source': 'array-to-feeds.py', 'we_wp_version': 'na', 'type': 'feed' },\n";
                    $user_list_php[] = "    array('from_user' =>'".$wp_username."', 'from_user_name'=>'".$wp_name
                        ."', 'from_user_wp_id'=>".$user_id.", 'site_id'=>".$site_id
                        ."', 'from_user_email'=>'".$wp_email.", 'tag'=>'".$site_name
                        ."', 'feed_url'=>'".$blog_url
                        ."', 'we_source'=>'array-to-feeds.py', 'we_wp_version'=>'na', 'type'=>'feed' ),\n"; */
                }
            }
        /*    $this->log('writing '.count($user_list_python).' entries.');
            // python first
            if ($handle = fopen($outfile.'py', 'w')) {
                $this->log('print out of users with URLs suitable for a Python array going into '.$outfile.'py');
                // first the Python array
                fwrite($handle, 'feeds = ['."\n");
                foreach($user_list_python as $user) {
                    fwrite($handle, $user);
                }
                fwrite($handle, "]\n");
                fclose($handle);
                $this->log('finished writing '.$outfile.'py');
            } else {
                $this->log('couldn\'t create '.$outfile.'py');
            }
            // now php
            if ($handle = fopen($outfile.'php', 'w')) {
                $this->log('print out of users with URLs suitable for a PHP array going into '.$outfile.'php');
                // then the php version of the array
                fwrite($handle, 'feeds = array('."\n");
                foreach($user_list_php as $user) {
                    fwrite($handle, $user);
                }
                fwrite($handle, ");\n");
                // finish off
                fclose($handle);
                $this->log('finished writing '.$outfile.'php');
            } else {
                $this->log('couldn\'t create '.$outfile.'php');
            } */
        /*    if ($cnt = count($records)) {
                $this->log('Registering '.$cnt.' user blog urls.');
                $count = 0;
                foreach($records as $record) {
                    if ($this->register_feed_from_record($record)) {
                        $count++;
                        $this->log('registered feed '.$count.'/'.$cnt.' for user: '.
                            $record['display_name'].'('.$record['user_id'].')');
                    } else {
                        $this->log('failed to register feed for user: '.
                            $record['display_name'].'('.$record['user_id'].')');
                    }
                }
            } else {
                $this->log('No records created')    ;
            } */
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


}
