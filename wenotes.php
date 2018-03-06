<?php
/**
 * @package WEnotes
 */
/*
Plugin Name: WEnotes
Plugin URI: http://github.com/oeru/wenotes
Description: Display and post to an aggregated WikiEducator Notes stream. Also do sensible things when registering new users to a multisite...
Version: 2.0.0
Author: Jim Tittsler, Dave Lane
Author URI: https://oeru.org, http://WikiEducator.org/User:JimTittsler, http://WikiEducator.org/User:Davelane
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

define( 'WENOTES_VERSION', '2.1.0' );
// plugin computer name
define('WENOTES_NAME', 'wenotes');
// current version
// the path to this file
define('WENOTES_FILE', __FILE__);
// absolute URL for this plugin, including site name, e.g.
// https://sitename.nz/wp-content/plugins/
define('WENOTES_URL', plugins_url("/", __FILE__));
// absolute server path to this plugin
define('WENOTES_PATH', plugin_dir_path(__FILE__));
// couchDB blog feeds database
define('WENOTES_BLOGFEEDS_DB', 'blog-feeds');
define('WENOTES_MENTIONS_DB', 'mentions');
// module details
define('WENOTES_SLUG', 'wenotes');
define('WENOTES_TITLE', 'WikiEducator Notes');
define('WENOTES_MENU', 'WENotes');
// admin details
define('WENOTES_ADMIN_SLUG', 'wenotes_settings');
define('WENOTES_ADMIN_TITLE', 'WikiEducator Notes Settings');
define('WENOTES_ADMIN_MENU', 'WENotes Settings');
// turn on debugging with true, off with false
define('WENOTES_DEBUG', false);
define('LOG_STREAM', getenv('LOG_STREAM'));

// include the dependencies
require WENOTES_PATH . '/wenotes-app.php';

if ( function_exists( 'add_action' ) ) {
  // this starts everything up!
  add_action('plugins_loaded', array(WENotes::get_instance(), 'init'));
} else {
	echo 'This only works as a WordPress plugin.';
	exit;
}
