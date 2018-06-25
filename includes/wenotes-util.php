<?php
/*
 *  Utility functions
 */

require WENOTES_PATH . '/includes/wenotes-base.php';

class WEnotesUtil extends WEnotesBase {

    // given a site object, return the site's name
    public function get_site_tag($site) {
        return strtolower(substr($site->path,1,-1));
    }

    // post a wenotes response to channel... and then let go of the connection.
    public function wenotespostresponse( $a ) {
        $this->log('in wenotespostresponse: '. print_r($a, true));
    	echo json_encode( $a );
    	die();
    }

    // if the blog-feed-finder plugin is enabled, include a links
    // to it for the benefit of learners
    public function bff_enabled() {
        $bff_active = false;
        if (is_plugin_active('blog-feed-finder/blog-feed-finder.php')) {
            $this->log('the Blog Feed Finder plugin is active!');
            $bff_active = true;
        }
        return $bff_active;
    }

}
