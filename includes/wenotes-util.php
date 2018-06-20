<?php
/*
 *  Utility functions
 */

require WENOTES_PATH . '/includes/wenotes-base.php';

class WENotesUtil extends WENotesBase {

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
}
