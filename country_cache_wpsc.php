<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

define('CCWPSC_DOCUMENTATION', 'http://wptest.means.us.com/2015/03/geolocation-and-wp-super-cache-caching-by-page-visitor-country-instead-of-just-page/');
define('CCWPSC_SETTINGS_SLUG', 'ccwpsc-cache-settings');
define('CCWPSC_ADDON_SCRIPT','cca_wpsc_geoip_plugin.php' );
define('CCWPSC_MAXMIND_DIR', CCWPSC_PLUGINDIR . 'maxmind/');
define('CCWPSC_CRONJOBNAME','country_caching_check_wpsc');
//  a number of plugins share Maxmind data
  if (!defined('CCA_MAXMIND_DATA_DIR'))
define('CCA_MAXMIND_DATA_DIR', WP_CONTENT_DIR . '/cca_maxmind_data/');

// if Super Cache is activated then WPCACHEHOME will be defined
  if (defined('WPCACHEHOME') && validate_file(WPCACHEHOME) === 0 && file_exists(WPCACHEHOME)) {
define('CCWPSC_WPSC_PRESENT',TRUE);
  } else {
 define('CCWPSC_WPSC_PRESENT',FALSE);
  }

  if ( CCWPSC_WPSC_PRESENT && function_exists( 'wp_super_cache_text_domain' )) {
define('CCWPSC_WPSC_ACTIVE',TRUE);
  } else {
 define('CCWPSC_WPSC_ACTIVE',FALSE);
  }

	if (CCWPSC_WPSC_PRESENT && file_exists(WPCACHEHOME . 'plugins/')) {
define('CCWPSC_WPSC_PLUGINDIR', WPCACHEHOME . 'plugins/');
  } else {
 define('CCWPSC_WPSC_PLUGINDIR', FALSE);
  }


if (! function_exists('cca_weekly') ):
  function cca_weekly( $schedules ) {
    $schedules['cca_weekly'] = array( 'interval' => 604800, 'display' => __('Weekly') );
    return $schedules;
  }
  add_filter( 'cron_schedules', 'cca_weekly'); 
endif;


// plugin version checking
add_action( 'admin_init', 'ccwpsc_version_mangement' );
function ccwpsc_version_mangement(){  // credit to "thenbrent" www.wpaustralia.org/wordpress-forums/topic/update-plugin-hook/
	$plugin_info = get_plugin_data( CCWPSC_CALLING_SCRIPT , false, false );  // switch to this line if this function is used from an include
	$last_script_ver = get_option('CCWPSC_VERSION');
	if (empty($last_script_ver)):
	  update_option('CCWPSC_VERSION', $plugin_info['Version']);
  elseif ( version_compare( $plugin_info['Version'] , $last_script_ver ) != 0 ) :
	// this script is later {1} (or earlier {-1}) than the previous installed script so:
	  // do any upgrade action, then:
		if ($version_status > 0):
		  // this flag ensures the activation function is run on plugin upgrade,
		  update_option('CCWPSC_VERSION_UPDATE', true);
		endif;
    update_option('CCWPSC_VERSION', $plugin_info['Version']);
	endif;
}


// if Super Cache plugin is updated then all its existing directories (including sub dir "/plugins") are deleted
// so we need to periodically check and email the admin if the CC add-on script needs re-generating 
// this job is only needed & scheduled on first install, and subsequently if Country Caching is enabled but a custom add-on directory has not been defined
add_action( CCWPSC_CRONJOBNAME,  'wpsc_sanity_check' );
function wpsc_sanity_check() {
  if ( get_option ( 'ccwpsc_caching_options' ) ) :
  	  $cc_options = get_option ( 'ccwpsc_caching_options' );
  else :  // its an orphan job left behind after country caching removed
	  wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );
	endif;

  // initialize
	$send = FALSE;
	$subject =  __('Country Caching Problem on ') . get_bloginfo('name'); 
	$msg= '';
	if (is_multisite()):
	  $settings_admin = admin_url('network/admin.php?page=' . CCWPSC_SETTINGS_SLUG);
	else:
	  $settings_admin = admin_url('admin.php?page=' . CCWPSC_SETTINGS_SLUG);
	endif;
	$wpsc_tab = $settings_admin . '&tab=WPSC';
	$wpsc_solution = __("Clicking the Submit button on the settings form ") . 'WPSC Tab  ( ' . $wpsc_tab . ' ) ' . __("will rebuild the script and might fix the problem");

	if ($cc_options['caching_mode'] == 'WPSC'):
		if ( defined( 'CCWPSC_WPSC_CUSTDIR' ) ) :  // defined in Super Cache's own config file
		  if (validate_file(CCWPSC_WPSC_CUSTDIR) > 0 || ! file_exists(CCWPSC_WPSC_CUSTDIR . CCWPSC_ADDON_SCRIPT) ) :
				$send = TRUE;
			  $msg .= __("You have specified a custom directory '") . esc_html(CCWPSC_WPSC_CUSTDIR) . __(" for the WPSC add-on, but this folder does not contain a copy of the script") . "\n";
				$msg .= __("Country caching IS NOT operating. ");
				$msg .= $wpsc_solution;
    	endif;
		elseif ( ! CCWPSC_WPSC_PRESENT || ! file_exists(CCWPSC_WPSC_PLUGINDIR . CCWPSC_ADDON_SCRIPT)) :
			$send = TRUE;
		  $msg .= __("I could not find a copy of the country caching add-on script in your WPSC plugin directory. ") . $wpsc_solution . "\n\n";
			$msg .= __("The add-on was probably deleted when WPSC was updated - you can avoid this problem by configuring WPSC to use a custom plugin directory. ") . __("(See ") . CCWPSC_DOCUMENTATION . "#whycust )\n";
		endif;
	endif;

  if( ! $send && ! empty($cc_options['first_run'])) :
	  $send = TRUE;
		$subject = 	__('Installation of Country Caching on ') . get_bloginfo('name');
		$msg .= __("Congratulations on installing Country Caching (CC) plugin. Use the settings form") . ' ( ' . $wpsc_tab . ' ) ' . __("to enable separate caching for all, or selected, countries.") . "\n\n";
		$msg .= __("Note: The Super Cache Author has identified that add-on scripts (including the one created by this plugin) might be deleted when you update to a new version of WPSC") . "\n";
		$msg .= __("This is a limitation of WPSC, not the CC plugin. The Country Caching Guide for WPSC") . ' ( ' . CCWPSC_DOCUMENTATION . ' ) ' .  __("tells you how to avoid this problem.");
		unset($cc_options['first_run']);
    update_option( 'ccwpsc_caching_options', $cc_options );
		if ($cc_options['caching_mode'] != 'WPSC'): wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME ); endif;
	endif;
	
	if ($send) :
		$send_to = get_bloginfo('admin_email');
		if (!empty($send_to)):
		  wp_mail( $send_to, $subject, $msg . "\n\n" . date(DATE_RFC2822) );
		endif;
	endif;
	return;
}


if( is_admin() ):
  include_once(CCWPSC_PLUGINDIR . 'inc/ccwpsc_settings_form.php');
endif;

//=====================================
// FOR CCA WIDGET ACTION & FILTER HOOKS
//=====================================

// if country caching is enabled this will override "disable geoip" option in the Category Country Aware plugin
add_filter( 'cca_disable_geoip', 'ccwpsc_disable_geoip_cache' );
function ccwpsc_disable_geoip_cache( $current=FALSE ) {
	$ccwpsc_options = get_option( 'ccwpsc_caching_options' );
	if (! $ccwpsc_options) return $current;
	if ( empty($ccwpsc_options['caching_mode']) || $ccwpsc_options['caching_mode'] == 'none' ) return TRUE;
	return FALSE;
}
