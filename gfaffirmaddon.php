<?php
/*
Plugin Name: GravityForms Affirm payment
Plugin URI: https://gravityextra.com
Description: GravityForms + Affirm payment
Version: 1.0
Author: GravityExtra
Author URI: https://gravityextra.com

------------------------------------------------------------------------
Copyright 2012-2016 Rocketgenius Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

define( 'GFAFFIRM_ADDON_VERSION', '1.0' );

add_action( 'gform_loaded', array( 'GFAffirm_Bootstrap', 'load' ), 5 );


class GFAffirm_Bootstrap {
	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		} 
		require_once( 'class-gfaffirmaddon.php' );
		// require_once( 'google-calendar-api.php' );
		// require_once( 'settings.php' );
		// $setting = new Setting();

		GFAddOn::register( 'GFAffirmAddOn' );
	}

}

function gf_affirm() {
	return class_exists( 'GFAffirmAddOn' ) ? GFAffirmAddOn::get_instance() : false;
}