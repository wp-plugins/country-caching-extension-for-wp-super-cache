<?php
/*
Plugin Name: Country Caching For WP Super Cache
Plugin URI: http://means.us.com
Description: Makes Country GeoLocation work with WP Super Cache 
Author: Andrew Wrigley
Version: 0.5.2
Author URI: http://means.us.com/
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if (!defined('CCWPSC_PLUGINDIR'))  define('CCWPSC_PLUGINDIR',plugin_dir_path(__FILE__));
if (!defined('CCWPSC_CALLING_SCRIPT'))  define('CCWPSC_CALLING_SCRIPT', __FILE__);


if(require(dirname(__FILE__).'/inc/wp-php53.php')) // TRUE if running PHP v5.3+.
	require_once 'country_cache_wpsc.php';
else wp_php53_notice('Country Caching for WPSC');
