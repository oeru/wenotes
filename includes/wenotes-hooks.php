<?php
include_once WENOTES_PATH . '/includes/wenotes-base.php';


class WENotesHooks extends WENotesBase {
    /**
     * Fires immediately after a new user is registered.
     *
     * @param int $user_id User ID.
     */
    public function add_user($user_id) {
        $this->log('in user_register hook');
    }
    /**
     * Fires after the user has successfully logged in.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       WP_User object of the logged-in user.
     */
    public function user_login($user_login, $user) {
        $this->log('in user_register hook');
        $user_id = $user->ID;
        $site_id = get_current_blog_id();
    }

    /**
     * Fires immediately after an existing user is updated.
     * @param int    $user_id       User ID.
     * @param object $old_user_data Object containing user's data prior to update.
    */
    public function update_user($user_id, $old_user_data) {
        $this->log('in profile_update hook');
        $site_id = get_current_blog_id();
        $this->log('old user data:'.print_r($old_user_data, true));
        // basic user data
        $new_user_data = get_userdata($user_id);
        // user meta data
        $meta = get_user_meta($user_id);
        // add meta dta to user data
        foreach($meta as $key => $val) {
            $new_user_data->data->$key = current($val);
        }
        $this->log('new user data:'.print_r($new_user_data, true));
    }

    /**
     * Adds a user to a blog.
     *
     * @param int    $user_id User ID.
     * @param string $role    User role.
     * @param int    $blog_id Blog ID.
     */
    public function add_user_to_site($user_id, $role, $blog_id) {
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
        $this->log('in hook remove_user_from_site');
    }
}
