<?php
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) exit();

// brute force delete add-on script from all possible known locations

delete_option('CCWPSC_VERSION');
// done by deactivate but no harm in making sure:
if (defined('CCWPSC_ADDON_SCRIPT') ) :
  if (defined('CCWPSC_PLUGINDIR') && validate_file(CCWPSC_PLUGINDIR) === 0) @unlink(CCWPSC_PLUGINDIR . CCWPSC_ADDON_SCRIPT);
endif;

delete_option('ccwpsc_caching_options');
// wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );  // may add sanity check job if no speed issues reported with dashboard for plugin