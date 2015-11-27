<?php
/**
 * @package WEnotes
 */
/*
Plugin Name: WEnotes
Plugin URI: http://github.com/oeru/wenotes
Description: Display an aggregated WEnotes stream.
Version: 1.0.1
Author: Jim Tittsler
Author URI: http://WikiEducator.org/User:JimTittsler
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

if ( !function_exists( 'add_action' ) ) {
	echo 'This only works as a WordPress plugin.';
	exit;
}

add_shortcode( 'WEnotes', 'wenotes_func' );

function wenotes_func( $atts ) {
	$a = shortcode_atts( array(
	    'tag' => '_',
	    'count' => 20
	    ), $atts );

	$tag = $a['tag'];
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

