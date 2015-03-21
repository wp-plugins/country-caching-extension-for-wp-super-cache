<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// determine whether a normal or multisite settings form link is required
$cc_networkadmin = is_network_admin() ? 'network_admin_' : '';
add_filter( $cc_networkadmin . 'plugin_action_links_' . plugin_basename( CCWPSC_CALLING_SCRIPT ), 'ccwpsc_add_sitesettings_link' );
function ccwpsc_add_sitesettings_link( $links ) {
	if (is_multisite()):
	  $admin_suffix = 'network/admin.php?page=' . CCWPSC_SETTINGS_SLUG;
	else:
	  $admin_suffix = 'admin.php?page=' . CCWPSC_SETTINGS_SLUG;
	endif;
	return array_merge(
		array('settings' => '<a href="' . admin_url($admin_suffix) . '">Country Caching Settings</a>'),
		$links
	);
}

// ensure CSS for dashboard forms is sent to browser
add_action('admin_enqueue_scripts', 'ccwpsc_load_admincssjs');
function ccwpsc_load_admincssjs() {
  if( (! wp_script_is( 'cca-textwidget-style', 'enqueued' )) && $GLOBALS['pagenow'] == 'admin.php' ): wp_enqueue_style( 'cca-textwidget-style', plugins_url( 'css/cca-textwidget.css' , __FILE__ ) ); endif;
}

// automatically display admin notice messages when using the add_menu_page (like WP does for add_options_page's)
function ccwpsc_admin_notices_action() {  
    settings_errors( 'ccwpsc_group' );
}
if (is_multisite()):
  add_action( 'network_admin_notices', 'ccwpsc_admin_notices_action' );
else:
  add_action( 'admin_notices', 'ccwpsc_admin_notices_action' );
endif;

// return permissions of a directory or file as a 4 character "octal" string
function ccwpsc_return_permissions($item) {
 clearstatcache(true, $item);
 $item_perms = @fileperms($item);
return empty($item_perms) ? '' : substr(sprintf('%o', $item_perms), -4);	
}


// INSTANTIATE OBJECT
if( is_admin() ) {
  $ccwpsc_settings_page = new CCWPSCcountryCache();
}
/*===============================================
CLASS FOR SETTINGS FORM AND GENERATION OF ADD-ON SCRIPT
================================================*/
class CCWPSCcountryCache {   // everything belyond this point this class
//======================
  private $initial_option_values = array(
	  'activation_status' => 'new',
		'caching_mode' => 'none',
		'wpsc_path' => '',
		'wpsc_cust_error' => FALSE,
		'cache_iso_cc' => '',
		'cron_frequency' => 'cca_weekly',
		'list_jobs' =>FALSE,
		'diagnostics' => FALSE,
		'initial_message'=> ''
	);

	public static $crontime_array = array('hourly'=>'Hourly', 'twicedaily'=>'Twice Daily', 'daily'=>'Daily', 'cca_weekly'=>'Weekly', 'never'=>'NEVER');
	public $options = array();
  public $user_type;
  public $submit_action;
	private $caching_status;
  private $valid_wpsc_custdir;
  private $wpsc_cust_error;

  public function __construct() {
	  register_activation_hook(CCWPSC_CALLING_SCRIPT, array( $this, 'CCWPSC_activate' ) );
		register_deactivation_hook(CCWPSC_CALLING_SCRIPT, array( $this, 'CCWPSC_deactivate'));
		$this->is_plugin_update = get_option( 'CCWPSC_VERSION_UPDATE' );
    $ccwpsc_maxmind_dir = CCWPSC_MAXMIND_DIR;

    // Maxmind data is used by a variety of plugins so we store its location etc in an option
    $this->maxmind_status = get_option('cc_maxmind_status' , array());

    $ccwpsc_maxmind_data_dir = CCWPSC_MAXMIND_DIR;
		if (empty($this->options)):
		  $this->options = $this->initial_option_values;
			$this->options['first_run'] = TRUE;
		endif;
  	if ( get_option ( 'ccwpsc_caching_options' ) ) :
  	  $this->options = get_option ( 'ccwpsc_caching_options' );
      if (empty($this->options['ccwpsc_maxmind_data_dir']) ):  $this->options['ccwpsc_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR; endif;
  	endif;

		update_option( 'ccwpsc_caching_options', $this->options );
		
		$this->wpsc_cust_error = FALSE;
		$this->valid_wpsc_custdir = FALSE;
		$this->validate_wpsc_custpath();


    	//  whenever there is a plugin upgrade we want to check the sanity of existing Maxmind data and rebuild the WPSC Cache add-on script as there may be logic changes 
		 //  we don't want the user to have to manually re-install Maxmind data if there is a change on plugin update
     if ($this->is_plugin_update || $this->options['ccwpsc_maxmind_data_dir'] != CCA_MAXMIND_DATA_DIR):
				$this->options['ccwpsc_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR;
        $this->CCWPSC_activate();
     endif;

    if (is_multisite() ) :
     	    $this->user_type = 'manage_network_options';
          add_action( 'network_admin_menu', array( $this, 'add_plugin_page' ) ); 
    			$this->submit_action = "../options.php";
    else:
    		 $this->user_type = 'manage_options';
         add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
    		 $this->submit_action = "options.php";
    endif;

    add_action( 'admin_init', array( $this, 'page_init' ) );
  }

	// REMOVE EXTENSION SCRIPT ON DEACTIVATION
  public function CCWPSC_deactivate()   {
    wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );
		// brute force delete add-on script from all possible known locations
   	if ( ! empty($this->options['wpsc_path'])  && validate_file( $this->options['wpsc_path'] ) === 0 ) @unlink($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT);
    if ( CCWPSC_WPSC_PLUGINDIR && validate_file(CCWPSC_WPSC_PLUGINDIR)===0 ) @unlink(CCWPSC_WPSC_PLUGINDIR . CCWPSC_ADDON_SCRIPT);
		if (defined('CCWPSC_WPSC_CUSTDIR') && validate_file(CCWPSC_WPSC_CUSTDIR) === 0) @unlink(CCWPSC_WPSC_CUSTDIR . CCWPSC_ADDON_SCRIPT);
		$this->options['activation_status'] = 'deactivated';
    update_option( 'ccwpsc_caching_options', $this->options );
  }

	//  ACTIVATION/RE-ACTIVATION
  public function CCWPSC_activate() {
	
	  // ensure sanity of GeoIP on re-activation or plugin update ( a number of plugins share/update the data files)
    $cca_ipv4_file = CCA_MAXMIND_DATA_DIR . 'GeoIP.dat';
    $cca_ipv6_file = CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat';
		if ( $this->is_plugin_update || ( $this->options['caching_mode'] == 'WPSC' && ( ! file_exists($cca_ipv4_file) || ! file_exists($cca_ipv6_file) || @filesize($cca_ipv4_file) < 131072 || @filesize($cca_ipv6_file) < 131072 ) ) ) :
        // caching was enabled before deactivation, rebuild Maxmind directory if in error or location has changed
        //  if plugin update, then the user may not open the settings form and see error messages
        if ( ! $this->save_maxmind_data($this->is_plugin_update) ):			 // if method argument is true then email will be sent on failure
				   $this->options['initial_message'] =  $this->maxmind_status['result_msg'];  // display error/warning msg when user opens settings form
				endif;
				update_option( 'cc_maxmind_status', $this->maxmind_status );
    endif;

	  // if still scheduled cancel job to check for removal of WPSC add-on script
	  wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );
		// if country caching is enabled then rebuild add-on script which is removed on deactivation
    if ( $this->options['caching_mode'] == 'WPSC') :
		   $this->options['activation_status'] = 'activating';
  		 $script_update_result = $this->ccwpsc_build_script( $this->options['cache_iso_cc']);
  		 if ( empty($this->options['last_output_err']) ) :
  		    $script_update_result = $this->ccwpsc_write_script($script_update_result, $this->options['cache_iso_cc']);
  		 endif;
    	 if ( $script_update_result != 'Done' ) :
   		    $this->options['initial_message'] =  __('You have reactivated this plugin - however there was a problem rebuilding the Super Cache add-on script: ') . $script_update_result . ')';
			 else: 
			    $this->options['initial_message'] =  ('You have re-activated Country Caching, and the add-on script for WPSC appears to have been rebuilt successfully');
				  // if the default add-on dir is being used then we need to monitor it to identify when WP deletes the script; which it will do whenever the WPSC plugin is updated
				  if ( ! defined('CCWPSC_WPSC_CUSTDIR') && $this->options['cron_frequency'] != 'never' && array_key_exists($this->options['cron_frequency'], self::$crontime_array) ) :
				     if ( ! wp_next_scheduled( CCWPSC_CRONJOBNAME ) ) : wp_schedule_event( time(), $this->options['cron_frequency'], CCWPSC_CRONJOBNAME ); endif;
				  endif;
       endif;
		elseif ( ! empty($this->options['first_run']) && $this->options['cron_frequency'] != 'never' && array_key_exists($this->options['cron_frequency'], self::$crontime_array) ) :
			 if ( ! wp_next_scheduled( CCWPSC_CRONJOBNAME ) ) : wp_schedule_event( time(), $this->options['cron_frequency'], CCWPSC_CRONJOBNAME ); endif;
  	endif;
		$this->options['activation_status'] = 'activated';
		update_option( 'ccwpsc_caching_options', $this->options );
  }  //  END CCWPSC_activate()


  // Add Country Caching options page to Dashboard->Settings
  public function add_plugin_page() {
    add_menu_page(
          'Country Caching Settings', /* html title tag */
          'WPSC Country Caching', // title (shown in dash->Settings).
          $this->user_type, // 'manage_options', // min user authority
          CCWPSC_SETTINGS_SLUG, // page url slug
          array( $this, 'create_ccwpsc_site_admin_page' ),  //  function/method to display settings menu
  				'dashicons-admin-plugins'
    );
  }

  // Register and add settings
  public function page_init() {        
    register_setting(
      'ccwpsc_group', // group the field is part of 
    	'ccwpsc_caching_options',  // option prefix to name of field
			array( $this, 'sanitize' )
    );
  }


  // THE SETTINGS FORM FRAMEWORK ( callback func specified in add_options_page func)
  public function create_ccwpsc_site_admin_page() {

	  // if site is not using Cloudflare GeoIP warn if Maxmind data is not installled
		if ( empty($_SERVER["HTTP_CF_IPCOUNTRY"]) && (! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') || ! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat')) ) :
		  $this->options['initial_message'] .= __('Maxmind "IP to Country look-up" data files need to be installed. They will be installed automatically from Maxmind when you check the "Enable WPSC" check box and save your settings. This may take a few seconds.<br />'); 
    endif;

   // render settings form
?>  <div class="wrap cca-cachesettings">  
      <div id="icon-themes" class="icon32"></div> 
      <h2>WPSC Country Caching</h2>  
<?php 
    if (!empty($this->options['initial_message'])) echo '<div class="cca-msg">' . $this->options['initial_message'] . '</div>';
    $this->options['initial_message'] = '';
		$active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'WPSC';
		$override_tab = empty($this->options['override_tab']) ? '' : $this->options['override_tab'];
		if ($override_tab == 'files') :
		  $active_tab = 'files';
		endif;
?>
      <h2 class="nav-tab-wrapper">  
         <a href="?page=<?php echo CCWPSC_SETTINGS_SLUG ?>&tab=WPSC" class="nav-tab <?php echo $active_tab == 'WPSC' ? 'nav-tab-active' : ''; ?>">WPSC Country Cache Settings</a>  
         <a href="?page=<?php echo CCWPSC_SETTINGS_SLUG ?>&tab=Configuration" class="nav-tab <?php echo $active_tab == 'Configuration' ? 'nav-tab-active' : ''; ?>">Monitoring &amp; Support</a>
<?php
		 if ( $active_tab == 'files' || ! empty($override_tab) ) : ?>
         <a href="?page=<?php echo CCWPSC_SETTINGS_SLUG ?>&tab=files" class="nav-tab <?php echo $active_tab == 'files' ? 'nav-tab-active' : ''; ?>">Dir &amp; File Settings</a>  
<?php    endif; ?>
      </h2>  
      <form method="post" action="<?php echo $this->submit_action; ?>">  
<?php 
      settings_fields( 'ccwpsc_group' );
  		if( $active_tab == 'Configuration' ) :
   			 $this->render_config_panel();
	 		elseif ( $active_tab == 'files' ) :
			   $this->render_file_panel();
      elseif ($override_tab == 'downloaded'):
      		$this->render_upload_check_panel();
  		 else : $this->render_wpsc_panel();
  		endif;
?> 
      </form> 
    </div> 
<?php
		 update_option( 'ccwpsc_caching_options', $this->options);
  }  //  END create_ccwpsc_site_admin_page()


  // render the Settings Form Tab for building the Super Cache add-on script
  public function render_wpsc_panel() {
 	  $this->validate_wpsc_custpath();
	?>
	  <div class="cca-brown"><p><?php echo $this->ccwpsc_wpsc_status();?></p></div>
    <hr /><h3>WP Super Cache (WPSC)</h3>
    <p><?php _e('Under');?> <a href="<?php echo admin_url('options-general.php?page=wpsupercache&tab=settings');?>">WP Super Cache's Advanced Settings</a>
    <b><?php _e('ENSURE ');?></b> <i>"Legacy page caching"</i><b> <?php echo __('is checked.') . '</b> ' . __('(otherwise WPSC will continue to cache as normal)'); ?>.</p>
    <p><b>Then:</b></p>
		<p><input type="checkbox" id="ccwpsc_use_ccwpsc_wpsc" name="ccwpsc_caching_options[caching_mode]" <?php checked($this->options['caching_mode']=='WPSC'); ?>><label for="ccwpsc_use_ccwpsc_wpsc">
		 <?php _e('Enable Country Caching add-on for WPSC'); ?></label></p>
    <hr /><h3><?php _e('Minimise country caching overheads'); ?></h3>
		<?php _e('Use same cache for most countries. Create separate caches for these country codes ONLY:'); ?>
		<input name="ccwpsc_caching_options[cache_iso_cc]" type="text" value="<?php echo $this->options['cache_iso_cc']; ?>" />
		<i>(<?php _e('e.g.');?> "CA,DE,AU")</i>
		<p><i><?php _e('Limiting countries individually cached to only those necessary minimises caching overhead. Example: if you set the field to "CA,DE,AU", cached copies of the page will be created for Canada, Germany, Australia, PLUS the standard cached page for visitors from ANYWHERE ELSE');?>.</i></p>

	<input type="hidden" id="ccwpsc_geoip_action" name="ccwpsc_caching_options[action]" value="WPSC" />
<?php
   if($this->options['caching_mode']=='WPSC'):
			  _e('<br /><p><i>This plugin includes GeoLite data created by MaxMind, available from <a href="http://www.maxmind.com">http://www.maxmind.com</a>.</i></p>');
	 endif;

   submit_button('Save Caching Settings','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
  }  // END function render_wpsc_panel


	// this settings form panel is only displayed if the user has downloaded the add-on script
  public function render_upload_check_panel() {
?>
		<div class="cca-brown"><p><?php
		_e('You have downloaded a copy of the add-on script.<br />To ensure correct settings are maintained, the Country Caching plugin <u>needs to know if you have uploaded it to your server</u>.');		
?></p></div>	
		<p><input type="radio" id="ccwpsc_notdone" name="ccwpsc_caching_options[override_tab]" value="downloaded" checked>
			   <label for="ccwpsc_notdone"><?php _e("Hold on, don't do anything yet, I've not had chance to upload it!!!"); ?></label><br />
				 <input type="radio" id="ccwpsc_notdone" name="ccwpsc_caching_options[override_tab]" value="uploaded">
			   <label for="ccwpsc_notdone"><?php _e("I've uploaded the script. Country Caching should modify its settings to identify use of this new script."); ?></label><br />
				 <input type="radio" id="ccwpsc_notdone" name="ccwpsc_caching_options[override_tab]" value="abandoned">
			   <label for="ccwpsc_notdone"><?php _e("I've decided I am NOT going to upload this particular version of the Script. Keep current settings and display the usual Country Caching settings form"); ?></label><br />
		</p>

		<input type="hidden" id="ccwpsc_geoip_action" name="ccwpsc_caching_options[action]" value="WPSC" />
<?php
      submit_button('Update Caching Settings','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
	}


  // this settings form panel is only displayed if the plugin has been unable to write the add-on script the add-on directory
  public function render_file_panel() { 
    if (! empty($this->options['override_tab']) && $this->options['override_tab'] == 'files'):
      $this->options['override_tab']  = 'io_error';
    endif;
	  echo '<p>'  . __('This section is of use if the CC plugin is unable to write the generated WPSC add-on script (') .  CCWPSC_ADDON_SCRIPT . __(') to "') . '<span class="cca-brown">' . $this->options['wpsc_path'] . '</span>" (';
    echo __('the folder where WPSC expects to find any add-on scripts') . ').</p>';
	  echo '<p>' . __('This problem is usually due to your server\'s Directory and File permissions settings. It can be solved by <b>either</b>:') . '</p><ol>';
	  echo '<li>' . __('changing directory ("wp-content/ac-plugins" +  possibly "wp-content/") permissions. ');
	  echo __('On a shared server appropriate  permissions for these is usually "755"; but on a <b>"dedicated" server</b> "775" might be needed and "664" for the script (if present)') . '.</li>';
	  echo '<li>' . __('<b>or</b>; by <b>clicking the download button</b> below to save ' ) . '"' . CCWPSC_ADDON_SCRIPT . '" ' . __('to your computer; then using FTP to up-load it to SuperCache\'s add-on folder') . '"<span class="cca-brown">' . $this->options['wpsc_path'] . '</span>"' . __('; you may need to create this folder first ') . '.</li></ol>';
 		echo '<span class="cca-brown">' . __('You should view the Country Caching guide for ') . '<a href="' . CCWPSC_DOCUMENTATION . '#perms">' . __('more about these solutions') . '</a> ' . __('and the best for your server') . '.</span>';
    echo '<hr /><h4>' . __('Information about current directory &amp; file permissions') . ':</h4>';

    if (!empty($this->options['last_output_err'])):
        echo '<span class="cca-brown">' . __('Last reported error: ') . ':</span> ' . $this->options['last_output_err'] . '<br />';
    endif;

    echo '<span class="cca-brown">' . __('Directory "wp-content"') . ':</span> ' . __('permissions = ') . ccwpsc_return_permissions(WP_CONTENT_DIR) . '<br />';
    echo '<span class="cca-brown">' . __('Directory "') . $this->options['wpsc_path'] . '" :</span> ' . __('permissions = ') . ccwpsc_return_permissions($this->options['wpsc_path']) . '<br />';
    echo '<span class="cca-brown">Permissions for add-on script "' . CCWPSC_ADDON_SCRIPT . '": </span>' . ccwpsc_return_permissions($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT) . '<br />';
		clearstatcache();
    $dir_stat = @stat(WP_CONTENT_DIR);
    if (function_exists('posix_getuid') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')
         && function_exists('posix_getgid') && function_exists('posix_getegid') && $dir_stat) :
      $real_process_uid  = posix_getuid(); 
      $real_process_data =  posix_getpwuid($real_process_uid);
      $real_process_user =  $real_process_data['name'];
    	$real_process_group = posix_getgid();
      $real_process_gdata =  posix_getpwuid($real_process_group);
      $real_process_guser =  $real_process_gdata['name'];	
      $e_process_uid  = posix_geteuid(); 
      $e_process_data =  posix_getpwuid($e_process_uid);
      $e_process_user =  $e_process_data['name'];
    	$e_process_group = posix_getegid();
      $e_process_gdata =  posix_getpwuid($e_process_group);
      $e_process_guser =  $e_process_gdata['name'];	
    	$dir_data =  posix_getpwuid($dir_stat['uid']);
    	$dir_owner = $dir_data['name'];
    	$dir_gdata =  posix_getpwuid($dir_stat['gid']);
    	$dir_group = $dir_gdata['name'];
      echo '<span class="cca-brown">' . __('This plugin is being run by "real user":') . '</span> ' . $real_process_user . ' (UID:' . $real_process_uid . ') Group: ' . $real_process_guser .' (GID:' . $real_process_group . ') N.B. this user may also be a member of other groups.<br>'; 
    	echo '<span class="cca-brown">' . __('The effective user is: ') . '</span>' . $e_process_user . ' (UID:' . $e_process_uid . ' GID:' . posix_getegid() . ')<br>'; 
      echo '<span class="cca-brown">' . __('"wp-content" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br />';
      unset($dir_stat);
      $dir_stat = @stat($this->options['wpsc_path']);
    	$dir_data =  @posix_getpwuid($dir_stat['uid']);
      if ( $dir_stat ) :
        echo '<span class="cca-brown">' . __('WPSC "add-on" directory') . '</span>: ' . __('Owner = ') . $dir_data['name'] . ' (UID:' . $dir_stat['uid'] . ') | Group = ' .  $dir_group . ' (GID:' . $dir_stat['gid'] . ')<br /><hr />';
    	endif;
    else:
      __('Unable to obtain information on the plugin process owner(user).  Your server might not have the PHP posix extension (installed on the majority of Linux servers) which this plugin uses to get this info.') . '<br /><hr />';
    endif;
?>
		<input type="hidden" id="ccwpsc_geoip_action" name="ccwpsc_caching_options[action]" value="download" />
<?php
		  echo '<br /><br /><b>' .  __('This download add-on will separately cache') .  ' "<u>';
			if (! isset($this->options['override_isocodes'])) : $this->options['override_isocodes'] = $this->options['cache_iso_cc']; endif;
			if (empty($this->options['override_isocodes']) ):
			  echo  __('all countries') . '</u>".</b>';
			else:
			   echo $this->options['override_isocodes'] . '</u>"; ' .  __('the standard cache will be used for all other countries.') . '</b>';
			endif;
			_e(' (To change these settings before download, go back to the "WPSC settings" tab, ensure "enable" is checked and save your revised country settings.)');
      submit_button('Download Add-on Script','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 

  }  // render_file_panel function END


  // render tab panel for monitoring and diagnostic information
  public function render_config_panel() {
?>
    <p class="cca-brown"><?php echo __('View the ') . ' <a href="' . CCWPSC_DOCUMENTATION . '" target="_blank">' . __('Country Caching Guide');?></a>.</p>
		<hr /><h3>Monitor &amp; notify me of possible problems:</h3>
		<p><i><a href="<?php echo CCWPSC_DOCUMENTATION . '#whycust'; ?>" target="_blank"><?php _e("Whats this all about???");?></a></i></p>
 		<select id="ccwpsc_cron_frequency" name="ccwpsc_caching_options[cron_frequency]">
<?php       
			if (array_key_exists($this->options['cron_frequency'], self::$crontime_array)) $selected_frequency = $this->options['cron_frequency'];
      foreach(self::$crontime_array as $period_key=>$period_name ) :
       	echo '<option value="' . $period_key . '" ' . selected( $selected_frequency, $period_key, FALSE ) .  '>' . $period_name . '</option>';
      endforeach;
      echo '</select> ';
			_e(" to my WP Admin Email.");
?>

 	  <p><i><?php _e("Note: The WP scheduler ONLY RUNS when your site is visited. If you've scheduled hourly notification but your site is first visited after 12 hours, the email won't be sent until then.");?></i><br>
		<?php _e("Monitoring does not apply if you are using a custom add-on directory."); ?></p>

		<hr /><h3>Problem Fixing</h3>
    <p><input id="ccwpsc_force_reset" name="ccwpsc_caching_options[force_reset]" type="checkbox"/>
    <label for="ccwpsc_force_reset"><?php _e("Reset CCA Country Caching to initial values (also removes the country caching add-on script(s) generated for WPSC).");?></label></p><hr />

		<h3>Information about the add-on script being used by Super Cache:</h3>
		<p><input type="checkbox" id="ccwpsc_addon_info" name="ccwpsc_caching_options[addon_data]" ><label for="ccwpsc_addon_info">
 		  <?php _e('Display script data'); ?></label></p>
<?php
   if (!empty($this->options['addon_data'])) :
			$this->options['addon_data'] = '';
			clearstatcache( true, $this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT );
			if ( ! file_exists( $this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT) ) :
			  echo '<br /><span class="cca-brown">' . __('The Add-on script does not exist.') . '</span><br>';
			else:		
  			 include_once($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT);
  			 if ( function_exists('cca_enable_geoip_cachekey') ):
				    $add_on_ver = cca_enable_geoip_cachekey('cca_version');
						echo '<span class="cca-brown">' . __('Add-on script version: ') . '</span>' . $add_on_ver . '<br>';
  				  $new_codes = cca_enable_geoip_cachekey('cca_options');
						$valid_codes = $this->is_valid_ISO_list($new_codes);
            if ($valid_codes):
          		  echo '<span class="cca-brown">' . __('The script is set to separately cache') .  ' "<u>';
          			if (empty($new_codes) ):
          			 echo  __('all countries') . '</u>".<br />';
          			else:
          			  echo $new_codes . '</u>"; ' .  __('the standard cache will be used for all other countries.') . '<br />';
          			endif;
					  else:
					    echo  __('Add-on script "') . CCWPSC_ADDON_SCRIPT . __(' is present in "') . $this->options['wpsc_path'] . __('" but has an INVALID Country Code List (values: "') . esc_html($new_codes) . __('") and should be deleted.') . '<br /';
  				  endif;
					else:
					  echo  __('The add-on script "') . CCWPSC_ADDON_SCRIPT . __(' is present in "') . $this->options['wpsc_path'] . __('" but I am unable to identify its settings.') . '<br />';
					endif;
					$max_dir = cca_enable_geoip_cachekey('cca_data');
					if ($max_dir != 'cca_data'):
					  echo __('The script looks for Maxmind data in "') . esc_html($max_dir) . '".<br />';
					endif;
			 endif;
		endif;

?>
		<h3>GeoIP Information and Status:</h3>
		<p><input type="checkbox" id="ccwpsc_geoip_info" name="ccwpsc_caching_options[geoip_data]" ><label for="ccwpsc_geoip_info">
 		  <?php _e('Display GeoIP data'); ?></label></p>
<?php
		if ($this->options['geoip_data']) :
			 $this->options['geoip_data'] = '';
    	 if (! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) ) :
			    echo '<br /><span class="cca-brown">' . __('It looks like Cloudflare is being used for GeoIP.') . '</span><br>';
			 endif;
 			 echo '<br /><b>' . __('Maxmind Status recorded by plugin') . ':</b><br />Directory: <span class="cca-brown">' . CCA_MAXMIND_DATA_DIR . '</span><br />';
			 if (! empty($this->maxmind_status) && ! empty($this->maxmind_status['health'])) :
			 	  if ($this->maxmind_status['health'] == 'fail'):
				    echo __('Maxmind may not be working; error recorded= ') . $this->maxmind_status['result_msg']  . '<br />';
				  elseif ($this->maxmind_status['health'] == 'warn'):
				     echo __('The plugin identified an error on last up date but GeoIP is probably still working; error recorded= ') . $this->maxmind_status['result_msg'] . '<br />';
				  endif;
			    echo   __('Files last updated: ') .' <span class="cca-brown">(IPv4 data) ' . date('j M Y Hi e', $this->maxmind_status['ipv4_file_date']) .  ' &nbsp; ';
				  echo __(" (IPv6 data) ") . date('j M Y Hi e', $this->maxmind_status['ipv6_file_date']) . '</span><br />';
			else:
			   echo __("The plugin has not stored information on current state of Maxmind files (if you haven't already enabled Country Caching this is to be expected") . '<br />';
			endif;
			clearstatcache();
      echo __('On Checking files right now:') .  '<br /><span class="cca-brown">"GeoIP.dat" ';
			if ( file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') ) :
				 _e('was present, and  ');
			else:
				 _e('could not be found, and ');
			endif;
			if ( file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat') ) :
				 echo __('"GeoIPv6.dat" was present, in the Maxmind directory.') . '</span><br>';
			else:
				 echo  __('"GeoIPv6.dat" could not be found, in the Maxmind directory.') . '</span><br>';
			endif;

		endif;
?>
		<hr /><h3>Information useful for support requests:</h3>
		<p><input type="checkbox" id="ccwpsc_diagnostics" name="ccwpsc_caching_options[diagnostics]"><label for="ccwpsc_diagnostics"><?php _e('List plugin values'); ?></label></p>
<?php
		if ($this->options['diagnostics']) :
		  $this->options['diagnostics'] = '';
		  $this->validate_wpsc_custpath();
		  echo '<h4>WP Supercache Status:</h4>';
			echo '<div class="cca-brown">' . $this->ccwpsc_wpsc_status() . '</div>';
      echo '<h4>Constants:</h4>';
      echo '<span class="cca-brown">WPCACHEHOME (defined by WPSC) = </span>'; echo defined('WPCACHEHOME') ? WPCACHEHOME : 'not defined';
      echo '<br /><span class="cca-brown">CCWPSC_WPSC_PRESENT (WPSC plugin exists &amp; activated) = </span>'; echo (defined('CCWPSC_WPSC_PRESENT') && CCWPSC_WPSC_PRESENT) ? 'TRUE' : 'FALSE';
      echo '<br /><span class="cca-brown">CCWPSC_WPSC_PLUGINDIR (default WPSC addon directory) = </span>'; echo defined('CCWPSC_WPSC_PLUGINDIR') ? CCWPSC_WPSC_PLUGINDIR : 'not defined';
      echo '<br /><span class="cca-brown">CCWPSC_WPSC_CUSTDIR (custom WPSC addon folder) = </span>'; echo defined('CCWPSC_WPSC_CUSTDIR') ? CCWPSC_WPSC_CUSTDIR : 'not defined';
      echo '<br /><span class="cca-brown">CCWPSC_ADDON_SCRIPT = </span>'; echo defined('CCWPSC_ADDON_SCRIPT') ? CCWPSC_ADDON_SCRIPT : 'not defined';
      echo '<br /><span class="cca-brown">CCWPSC_CRONJOBNAME = </span>'; echo defined('CCWPSC_CRONJOBNAME') ? CCWPSC_CRONJOBNAME : 'not defined';

      echo '<h4>Variables:</h4>';
		  $esc_options = esc_html(print_r($this->options, TRUE ));  // option values from memory there is a slim chance stored values will differ
		  echo '<span class="cca-brown">' . __("Current setting values") . ':</span>' . str_replace ( '[' , '<br /> [' , print_r($esc_options, TRUE )) . '</p>';
// 0.7.0 display maxmind_status option
			echo '<hr /><h4>Maxmind Data status:</h4>';
		  $esc_options = esc_html(print_r($this->maxmind_status, TRUE ));  // option values from memory there is a slim chance stored values will differ
		  echo '<span class="cca-brown">' . __("Current values") . ':</span>' . str_replace ( '[' , '<br /> [' , print_r($esc_options, TRUE )) . '</p>';
// 0.7.0 end
      echo '<h4>' . __('File and Directory Permissions') . ':</h4>';
      echo '<span class="cca-brown">' . __('Last file/directory error":') . '</span> ' . $this->options['last_output_err'];
			clearstatcache();
      $wpcontent_stat = @stat(WP_CONTENT_DIR);
      if (function_exists('posix_getuid') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')
           && function_exists('posix_getgid') && function_exists('posix_getegid') && $wpcontent_stat) :
        $real_process_uid  = posix_getuid(); 
        $real_process_data =  posix_getpwuid($real_process_uid);
        $real_process_user =  $real_process_data['name'];
      	$real_process_group = posix_getgid();
        $real_process_gdata =  posix_getpwuid($real_process_group);
        $real_process_guser =  $real_process_gdata['name'];	
        $e_process_uid  = posix_geteuid(); 
        $e_process_data =  posix_getpwuid($e_process_uid);
        $e_process_user =  $e_process_data['name'];
      	$e_process_group = posix_getegid();
        $e_process_gdata =  posix_getpwuid($e_process_group);
        $e_process_guser =  $e_process_gdata['name'];	
      	$wpcontent_data =  posix_getpwuid($wpcontent_stat['uid']);
      	$wpcontent_owner = $wpcontent_data['name'];
      	$wpcontent_gdata =  posix_getpwuid($wpcontent_stat['gid']);
      	$wpcontent_group = $wpcontent_gdata['name'];
        echo '<span class="cca-brown">' . __('This plugin is being run by "real user":') . '</span> ' . $real_process_user . ' (UID:' . $real_process_uid . ') Group: ' . $real_process_guser .' (GID:' . $real_process_group . ') N.B. this user may also be a member of other groups.<br>'; 
      	echo '<span class="cca-brown">' . __('The effective user is: ') . '</span>' . $e_process_user . ' (UID:' . $e_process_uid . ' GID:' . posix_getegid() . ')<br>'; 
        echo '<span class="cca-brown">' . __('"wp-content" directory') . '</span>: ' . __('Owner = ') . $wpcontent_data['name'] . ' (UID:' . $wpcontent_stat['uid'] . ') | Group = ' .  $wpcontent_group . ' (GID:' . $wpcontent_stat['gid'] . ')<br />';
      else:
        __('Unable to obtain information on the plugin process owner(user).  Your server might not have the PHP posix extension (installed on the majority of Linux servers) which this plugin uses to get this info.') . '<br />';
      endif; 
      echo '<span class="cca-brown">' . __('"wp-content" folder permissions: </span>') . ccwpsc_return_permissions(WP_CONTENT_DIR) . '<br />';
      echo '<span class="cca-brown">' . __('"WPSC add-on\'s folder" ') . CCWPSC_WPSC_PLUGINDIR . __(' permissions') .'</span>: ' . ccwpsc_return_permissions(CCWPSC_WPSC_PLUGINDIR) . '<br />';
      if ($this->valid_wpsc_custdir) :
        echo '<span class="cca-brown">' . __('"Custom WPSC add-on folder" ') . CCWPSC_WPSC_CUSTDIR . __(' permissions') .'</span>: ' . ccwpsc_return_permissions(CCWPSC_WPSC_CUSTDIR) . '<br />';
      else:
        echo __('You have not defined a custom WPSC add-on folder (or the path you defined was invalid)') . '<br />';
      endif;
      echo '<span class="cca-brown">Permissions for add-on script "' . CCWPSC_ADDON_SCRIPT . '": </span>' . ccwpsc_return_permissions($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT);

		endif;
?>
		<p><input type="checkbox" id="ccwpsc_list_jobs" name="ccwpsc_caching_options[list_jobs]" ><label for="ccwpsc_list_jobs">
		 <?php _e('List scheduled WP jobs'); ?></label></p>
<?php
      if ( ! empty($this->options['list_jobs'])) :
  		  $this->options['list_jobs'] = '';
        _e("This is the list of jobs scheduled on your site (it does NOT mean your WP scheduler/cron is actually working).<br />");
      	_e("If country caching is enabled AND you are not using a custom add-on folder, then the job 'country_caching_check_wpsc' (yellow highlight) should be listed ONCE below. ");
  			_e("Otherwise the job should not be listed.<hr /><br />");
        $this->ccwpsc_list_cron_jobs(CCWPSC_CRONJOBNAME);
      endif;
      echo '<hr /><input type="hidden" id="ccwpsc_geoip_action" name="ccwpsc_caching_options[action]" value="Configuration" />';
     submit_button('Submit','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 

  }   // END function render_config_panel


  // used to list cron jobs in the Config Panel
  function ccwpsc_list_cron_jobs($highlight = '') {
    $cron = _get_cron_array();
    foreach( $cron as $time => $job )  :
  	  $jobname = (key($job)==$highlight) ? '<b class="cca-highlight">' . key($job) . '</b>' : key($job);
      echo $jobname . ' (OS time: ' . $time . ')<br /> ';
      print_r($job[key($job)]);
  		echo '<hr />';
    endforeach;
	}


  // validate and save settings fields changes
  public function sanitize( $input ) {

    if ($this->options['activation_status'] != 'activated') return $this->options;
		$input['action'] = empty($input['action']) ? '' : strip_tags($input['action']);

 // the user has requested download of the add-on script
    if ( $input['action'] == 'download'):
  	  $this->options['override_tab'] = 'downloaded';
			if (! isset($this->options['override_isocodes'])) : $this->options['override_isocodes'] = $this->options['cache_iso_cc']; endif;
 		  $this->options['initial_message']  = __('IMPORTANT! You have downloaded the add-on script, you must use the "<i>WPSC</i>" tab to inform this plugin of the action you have taken.');
  		update_option( 'ccwpsc_caching_options', $this->options );
      $addon_script = $this->ccwpsc_build_script($this->options['override_isocodes']);
      header("Pragma: public");
      header("Expires: 0");
      header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
      header("Cache-Control: private", false);
      header("Content-Type: application/octet-stream");
      header('Content-Disposition: attachment; filename="' . CCWPSC_ADDON_SCRIPT . ";" );
      header("Content-Transfer-Encoding: binary");
      echo $addon_script;
      exit;
    endif;

  	// initialize messages
		$settings_msg = '';
		$msg_type = 'updated';
    $delete_result = '';

// handle follow-up arising after a failure to write the add-on script to WPSC's add-on directory
    if (isset($input['override_tab'] )):

		   // process special settings form submit that only appears after a user downloads add-on script to their local machine
  		 if ( $input['override_tab'] == 'downloaded'):  // user has submitted settings, but nothing has changed since script was downloaded
  		   return $this->options;
       endif;
			 if ( $input['override_tab'] == 'abandoned'):  // user has opted not to use the downloaded script - return settings for standard mode
    	    $this->options['override_tab'] = 'io_error';
    		  $this->options['override_isocodes'] = $this->options['cache_iso_cc'];
      	  return $this->options;
       endif;

			 // user has confirmed manual upload of add-on script - alter plugin settings to reflect the add-on is in use.
			 if ( $input['override_tab'] == 'uploaded'):
  		    clearstatcache();
			    if ( file_exists( $this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT) ) :
			  	   include_once($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT);
  			     if ( function_exists('cca_enable_geoip_cachekey') ):
				       $new_codes = cca_enable_geoip_cachekey('cca_options');
						   $valid_codes = $this->is_valid_ISO_list($new_codes);
               if ($valid_codes):
  				 	      $this->options['cache_iso_cc'] = $new_codes;
  			 			    $this->options['caching_mode'] = 'WPSC';
							    unset($this->options['override_tab']);
									unset($this->options['override_isocodes']);
                  add_settings_error('geoip_group', esc_attr( 'settings_updated' ), 	__('Settings have been updated.'),	'updated'	);
  					      return $this->options;
					     else:
					       $settings_msg = __('Add-on script "') . CCWPSC_ADDON_SCRIPT . __(' is present in "') . $this->options['wpsc_path'] . __('" but has an INVALID Country Code List (values: "') . esc_html($new_codes) . __('") and should be deleted.');
  				     endif;
					 else:
					   $settings_msg = __('The add-on script "') . CCWPSC_ADDON_SCRIPT . __(' is present in "') . $this->options['wpsc_path'] . __('" but I am unable to identify its settings.') ;
					 endif;
  			else:
				  $settings_msg = __('The add-on script "') . CCWPSC_ADDON_SCRIPT . __(' still DOES NOT EXIST in directory "') . $this->options['wpsc_path'] . '".';
  			endif;
				$msg_type = 'error';
    		if ($settings_msg != '') :
          add_settings_error('geoip_group', esc_attr( 'settings_updated' ), $settings_msg,	$msg_type	);
        endif;
      	return $this->options;
  		endif;

    endif;
    if ($this->options['activation_status'] != 'activated'): return $this->options; endif;  // activation hook carries out its own "sanitizing"


//  PROCESS INPUT FROM  "FILES" TAB
		if ($input['action'] == 'files'):  return $this->options; endif;

// PROCESS config tab input 
    if ($input['action'] == 'Configuration') :

		  $this->options['diagnostics'] = empty($input['diagnostics']) ? FALSE : TRUE;
		  $this->options['addon_data'] = empty($input['addon_data']) ? FALSE : TRUE;
			$this->options['geoip_data'] = empty($input['geoip_data']) ? FALSE : TRUE;
			$this->options['list_jobs'] = empty($input['list_jobs']) ? FALSE : TRUE;
			if ( ! empty($input['cron_frequency']) && $this->options['cron_frequency'] != $input['cron_frequency']) :
			  // the user has changed the frequency setting for cron jobs - if one is running replace it by one with the new frequency
			  $this->options['cron_frequency'] = $input['cron_frequency'];
				if ( wp_next_scheduled( CCWPSC_CRONJOBNAME ) ) :
			    wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );
					if ( $this->options['cron_frequency'] != 'never' && ! defined('CCWPSC_WPSC_CUSTDIR') ):
				    wp_schedule_event( time(), $this->options['cron_frequency'], CCWPSC_CRONJOBNAME );
					endif;
				endif;
			endif;

			if (! empty($input['force_reset']) ) :
			  update_option('ccwpsc_caching_options',$this->initial_option_values);
				$this->options = $this->initial_option_values;
				$this->options['activation_status'] = 'activated';
				wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );
		    $delete_result = $this->delete_wpsc_addon();	
  		  if ($delete_result != ''):
  				$msg_type = 'error';
  			  $msg_part = $delete_result;
  			else:
  			  $msg_part = __('Country caching has been reset to none.<br />');
  				$this->options['cache_iso_cc'] = '';
  			endif;
  			$settings_msg = $msg_part . $settings_msg;
			endif;

  		if ($settings_msg != '') :
        add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
      endif;

  		return $this->options;
    endif;

//  RETURN IF INPUT IS NOT FROM "WPSC" TAB (The WPSC tab should be the only one not sanitized at this point).
    if ($input['action'] != 'WPSC'): return $this->options; endif;


// WPSC SETTINGS TAB INPUT
  	unset($this->options['override_tab']);
		unset($this->options['override_isocodes']);
		// prepare input for processing
		$cache_iso_cc = empty($input['cache_iso_cc']) ? '' : strtoupper(trim($input['cache_iso_cc']));
		$new_mode = empty($input['caching_mode']) ? 'none' : 'WPSC';

		// user is trying to enable country caching without Supercache plugin being present!
    if ( $new_mode != 'none'  && ! CCWPSC_WPSC_PRESENT ) :
        add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ),
       		__("ERROR: WP Super Cache does not appear to be activated"),
        		'error'
       	);
       	return $this->options;
    endif;

		// user is not enabling country caching and it wasn't previously enabled
		if ( $new_mode == 'none' && $this->options['caching_mode'] == 'none') :
		  if ( $this->options['cache_iso_cc'] != $cache_iso_cc && $this->is_valid_ISO_list($cache_iso_cc) ) :
			  $this->options['cache_iso_cc'] = $cache_iso_cc;
        $settings_msg = __("The Country Codes list has been updated; HOWEVER you have NOT ENABLED country caching.") .  '.<br />';
			else :
        $settings_msg .= __("Settings have not changed - country caching is NOT enabled.<br />");
			endif;
			$settings_msg .= __("I'll take this opportunity to housekeep and remove any orphan country caching scripts. ");
		  $settings_msg .= $this->delete_wpsc_addon();
			add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), $settings_msg, 'error' );
      return $this->options;	  
		endif;

		$msg_part = '';

		// user is changing to OPTION "NONE" we are disabling country caching and need to remove the WPSC add-on script
		if ($new_mode == 'none') :
		   $delete_result = $this->delete_wpsc_addon();	
		   if ($delete_result != ''):
				  $msg_type = 'error';
			    $msg_part = $delete_result;
			 else:
			     $msg_part = __('Country caching has been disabled.<br />');
           if ( $this->options['cache_iso_cc'] != $cache_iso_cc && $this->is_valid_ISO_list($cache_iso_cc) ) :
        	     $this->options['cache_iso_cc'] = $cache_iso_cc;
           endif;
				   $this->options['caching_mode'] = 'none';
			 endif;
			 $settings_msg = $msg_part . $settings_msg;
	     wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );

		// check if user has submitted option to enable country caching, but the input comma separated Country Code list is invalid
    elseif ( $new_mode == 'WPSC'  && ! $this->is_valid_ISO_list($cache_iso_cc) ):
				$settings_msg .= __('WARNING: Settings have NOT been changed; your Country Code List entry: "') . esc_html($input['cache_iso_cc']) . __('" is invalid (list must be empty or contain 2 character alphabetic codes separated by commas).<br />');
				$msg_type = 'error';

		// user has opted for country caching "enabled" and has provided a valid list of country codes
		elseif ( $new_mode == 'WPSC') :
  		 $script_update_result = $this->ccwpsc_build_script( $cache_iso_cc);
  		 if ( empty($this->options['last_output_err']) ) :
  		    $script_update_result = $this->ccwpsc_write_script($script_update_result, $cache_iso_cc);
  		 endif;
 			 if ($script_update_result == 'Done') :
			    $this->options['cache_iso_cc'] = $cache_iso_cc;
				  $this->options['caching_mode'] = 'WPSC';
				  $msg_part = __("Settings have been updated and country caching is enabled for WPSC.<br />"); 
					if ( defined('CCWPSC_WPSC_CUSTDIR') ):
					  wp_clear_scheduled_hook( CCWPSC_CRONJOBNAME );
					elseif ( ! wp_next_scheduled( CCWPSC_CRONJOBNAME)) :
					  wp_schedule_event( time(), $this->options['cron_frequency'], CCWPSC_CRONJOBNAME );
					endif;
				 $settings_msg = $msg_part . $settings_msg;
			else:
			  $settings_msg .= $script_update_result . '<br />';
				$new_mode = $this->options['caching_mode'];  // as build of add-on script has failed, we want to keep the existing setting when updating options (below)
			endif;
    endif;

		// Country Caching has been enabled; ensure Maxmind files are installed
		if ( ! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') || ! file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat') ) :
		  if ( ! $this->save_maxmind_data() ) :
			  $settings_msg = $settings_msg . '<br />' . $this->maxmind_status['result_msg'];
				$msg_type = 'error';
			endif;
		endif;

		if ($settings_msg != '') :
      add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
    endif;
		return $this->options;
  }  	// END OF SANITIZE FUNCTION


  // delete the WPSC add-on script from all possible locations where, at some point, it could have been placed
  function delete_wpsc_addon() {
  	$result_msg = '';
  	if ( defined('CCWPSC_WPSC_CUSTDIR') && CCWPSC_WPSC_CUSTDIR != $this->options['wpsc_path'] && ! $this->remove_addon_file(CCWPSC_WPSC_CUSTDIR . CCWPSC_ADDON_SCRIPT ) ) :
  	  $result_msg = CCWPSC_WPSC_CUSTDIR . CCWPSC_ADDON_SCRIPT . ' ';
  	endif;
    if ( CCWPSC_WPSC_PRESENT && CCWPSC_WPSC_PLUGINDIR != $this->options['wpsc_path'] && ! $this->remove_addon_file(CCWPSC_WPSC_PLUGINDIR . CCWPSC_ADDON_SCRIPT ) ) :
  	  $result_msg .= CCWPSC_WPSC_PLUGINDIR  . CCWPSC_ADDON_SCRIPT . ' ';
  	endif;
    // cater for orphan script when WPSC plugin and (and associated constants) have already been deleted
    if ( ! empty($this->options['wpsc_path'])  && ! $this->remove_addon_file($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT )) :
  	  $result_msg .= $this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT;
  	endif;
  	$this->options['wpsc_path'] = '';
  
  	if ($result_msg != ''):
  	  return __('Warning: I was unable to remove the old country caching addon script(s): "') .  $result_msg . __('". You can try altering folder permissions on one of the parent directories; or delete this file yourself.<br />');
  	endif;
    return '';
  }


  function remove_addon_file($file) {
    if ( validate_file($file) === 0 && is_file($file) && ! unlink($file) ) return FALSE;
  	return TRUE;
  }


	// if CCWPSC_WPSC_CUSTDIR (defined in WPSC's config file) is a valid directory path, create the directory if it doesn't already exist, and return TRUE; otherwise return FALSE
  function validate_wpsc_custpath() {
  	$this->valid_wpsc_custdir = FALSE;
  	$this->wpsc_cust_error = '';
  	if ( defined( 'CCWPSC_WPSC_CUSTDIR' ) ) :
        if (validate_file(CCWPSC_WPSC_CUSTDIR) > 0 ) :
     		  $this->wpsc_cust_error = __('The Custom WPSC Directory path defined by CCWPSC_WPSC_CUSTDIR (') . esc_html(CCWPSC_WPSC_CUSTDIR) . __(') is not a valid path name.');
     	 elseif ( ! file_exists(CCWPSC_WPSC_CUSTDIR) ):
       		// dedicated servers may require 775 permissions - check and see what permissions have been set for other plugin directories
           $item_perms = ccwpsc_return_permissions(CCWPSC_PLUGINDIR);  // determine permissions to set when creating directory
           if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) == '7') :
              $ccwpsc_perms = 0775;
           else:
              $ccwpsc_perms = 0755;
           endif;
  			   if (mkdir(CCWPSC_WPSC_CUSTDIR, $ccwpsc_perms, true) ):
  				    $this->options['initial_message'] .= __('The custom WPSC directory defined by CCWPSC_WPSC_CUSTDIR did not exist. It HAS BEEN CREATED.');
  					  $this->valid_wpsc_custdir = TRUE;
      	   else:
  				    $this->wpsc_cust_error = __('The Custom WPSC Directory defined by CCWPSC_WPSC_CUSTDIR (') . esc_html(CCWPSC_WPSC_CUSTDIR) . __(') does not exist and I was unable to create it (this may be due to the file permissions of one of the folders in the directory path).');
  				 endif;
  			else: 
  			  $this->valid_wpsc_custdir = TRUE;
  			endif;
  	endif;
    return $this->valid_wpsc_custdir;
  }


  function ccwpsc_wpsc_status() {
    if (! CCWPSC_WPSC_PRESENT) :
  	  return __('It does not looks like your site is using WP Super Cache (WPSC)');
  	else:
  	  if (function_exists( 'wp_super_cache_text_domain' ) ) :
  		  $wpsc_running = __("It looks like your site is using WP Super Cache (WPSC). ");
  		else: $wpsc_running = __("It looks like Supercache is installed but not running. ");
  		endif;
       if (! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) ) :
  	     $geoip_used = __('Cloudflare data is being used for GeoIP	');
  		elseif ($this->maxmind_status['health'] == 'fail') :
  		   $geoip_used = __('There is a problem with GeoIP. Check GeoIP info on the Cofiguration tab.');
  		else:
  		   $geoip_used = '';
  		endif;
  		if (empty($this->options['cache_iso_cc'])) :
  		  $opto = __("<br />To fully optimize performance you should limit the countries that are individually cached.");
  		 else: $opto = '';
  		endif;
  		if ( ! defined( 'CCWPSC_WPSC_CUSTDIR' ) ) :
  		  $opto .= __("<br />You have not defined a custom add-on folder. Use of a custom folder is advised; see ") . '<a href="' . CCWPSC_DOCUMENTATION . '#whycust">' . __("why, and how to") . '</a>.';
  		endif;

  		if ($this->options['caching_mode'] == 'WPSC'):
    		if ( defined( 'CCWPSC_WPSC_CUSTDIR' ) ) :
    		  if (! $this->valid_wpsc_custdir ) :
  				  $wpsc_status = $geoip_used . '<br />' . $wpsc_running . __("<br />There is a problem with the path you defined for the CUSTOM add-on directory: ");
    				$wpsc_status .=  $this->wpsc_cust_error . '<br />';
   				  $wpsc_status .=  __("Country caching is NOT running.");
					elseif (file_exists(CCWPSC_WPSC_CUSTDIR . CCWPSC_ADDON_SCRIPT)) :
  				  $wpsc_status = $wpsc_running . __(" Country caching looks okay; the WPSC custom plugins directory includes the appropriate script.<br />");
    				$wpsc_status .= $opto . $geoip_used;
    			else:
  					$wpsc_status =  $geoip_used . '<br />' . __("Country Caching is NOT working. It looks like WPSC is running, and you have enabled country caching. HOWEVER; the add-on script is not present in WPSC's custom plugins folder.<br />" );
  					$wpsc_status .= __("Click the Submit button below to regenerate and apply the add-on script.");
    			endif;
    		else:  // WPSC is deactivated or country caching is using standard WPSC plugin dir
  			  if (! function_exists( 'wp_super_cache_text_domain' ) ) :
  				  $wpsc_status = $wpsc_running;  // notify user WPSC is deactivated
  			  elseif (file_exists(CCWPSC_WPSC_PLUGINDIR . CCWPSC_ADDON_SCRIPT)) :
  				  $wpsc_status = $wpsc_running . __("Country caching set up looks okay.") . '<br />';
  					$wpsc_status .= $opto . $geoip_used;
  				else:
  					$wpsc_status =  $geoip_used . '<br />' .__("Country Caching is NOT working. It looks like WPSC is running, and you have enabled country caching. HOWEVER; the add-on script is not present in WPSC's plugins folder.<br />" );
  					$wpsc_status .= __("Click the Submit button below to regenerate and apply the add-on script.");
  				endif;
  			endif;
  		else:  // user has not checked to enable WPSC country caching
  		  $wpsc_status =  $wpsc_running . __("N.B. You have not enabled WPSC country caching.<br />");
  		  if ( file_exists(CCWPSC_WPSC_PLUGINDIR . CCWPSC_ADDON_SCRIPT) || (defined( 'CCWPSC_WPSC_CUSTDIR' ) && file_exists(CCWPSC_WPSC_CUSTDIR  . CCWPSC_ADDON_SCRIPT)) ) :
  			  $wpsc_status .=  __('ALTHOUGH, "Enable WPSC" is not checked, the country caching script STILL EXISTS as an add-on to WPSC and country caching may still be active.<br />');
  				$wpsc_status .= __("Clicking the Submit button below should result in the add-on being deleted and resolve this problem.");
  			endif;
  		endif;
  	endif;
  	return $wpsc_status;
  }    // END OF ccwpsc_wpsc_status FUNCTION


  function ccwpsc_build_script( $country_codes = '') {
	  if ($this->options['activation_status'] != 'activating' && !function_exists( 'wp_super_cache_text_domain' )) :
		   $this->options['last_output_err']  =  '* ' . __("ERROR: WPSC caching doesn't appear to be running on your site (maybe you have de-activated it, or it isn't installed).");
			 return $this->options['last_output_err'];
		endif;
		if ( ! defined('CCWPSC_MAXMIND_DIR') ) :
		   $this->options['last_output_err']  =  '* ' . __('Error: on building the add-on script for WPSC (value for constant CCWPSC_MAXMIND_DIR not found)');
			 return $this->options['last_output_err'];
		endif;
		$template_script = CCWPSC_PLUGINDIR . 'caching_plugins/' . CCWPSC_ADDON_SCRIPT;
    $file_string = @file_get_contents($template_script); 
		if (empty($file_string)) : 
			if ( file_exists( $template_script ) ):
			  $this->options['last_output_err']  =  '*' . __('Error: unable to read the template script ("') . $template_script . __('") used to build or alter the plugin for Super Cache');		
				return $this->options['last_output_err'];
		  else:
			  $this->options['last_output_err']  = '*' . __('Error: it looks like the template script ("') . $template_script . __('") needed to build or alter the add-on to Super Cache has been deleted.');
				return $this->options['last_output_err'];
      endif;
		endif;

		unset($this->options['last_output_err']) ;
		if ( ! empty($country_codes) ) : $file_string = str_replace('$just_these = array();', '$just_these = explode(",","' . $country_codes .'");',  $file_string); endif;
		$this->options['cca_maxmind_data_dir'] = CCA_MAXMIND_DATA_DIR;
		$file_string = str_replace('ccaMaxDataDirReplace', CCA_MAXMIND_DATA_DIR, $file_string);
    $file_string = str_replace('ccwpscMaxDirReplace', CCWPSC_MAXMIND_DIR, $file_string);
    return $file_string;

  }    // END OF ccwpsc_build_script FUNCTION


 function ccwpsc_write_script( $file_string, $country_codes) {
    if ( defined('CCWPSC_WPSC_CUSTDIR') ):
		  $this->validate_wpsc_custpath();
      if (! $this->valid_wpsc_custdir ):
    	  $this->options['last_output_err'] =  '* ' . __("Sorry, either the add-on directory (defined in WPSC's config file) does not exist, or there's a problem with its path: ") . $this->wpsc_cust_error;
				return $this->options['last_output_err'];
    	else:
    	  $wpsc_plug_dir = CCWPSC_WPSC_CUSTDIR;
    	endif;
    else:
      if (CCWPSC_WPSC_PLUGINDIR):
    	  $wpsc_plug_dir = CCWPSC_WPSC_PLUGINDIR;
    	else:
    	  $this->options['last_output_err'] =  '* ' .  __("ERROR: 'WPCACHEHOME which defines WPSC and its plugin folders location, could not be found. Either you are not using WPSC or there is a problem with it's configuration.<br />NOT SAVED - you will have to re-enter your changes.");
				return $this->options['last_output_err'];
      endif;
    endif;
  	if (validate_file( $wpsc_plug_dir )!=0 || ! file_exists($wpsc_plug_dir) )  return 'Error: directory ' . $wpsc_plug_dir . ' does not exist';

		if( ! file_put_contents($wpsc_plug_dir . CCWPSC_ADDON_SCRIPT, $file_string) ) :
  		$this->options['override_tab'] = 'files';
  		$this->options['override_isocodes'] = $country_codes;
  	  $this->options['last_output_err'] =  date('d M Y h:i a; ') .  __(' unable to create/update add-on script ') . '<i>' . $wpsc_plug_dir . CCWPSC_ADDON_SCRIPT . '</i>.';
		  return "Error: writing to WPSC's plugin directory";
		endif;
    // dedicated servers may require 664 permissions - check and see what permissions have been set for othe plugin scripts
		$item_perms = ccwpsc_return_permissions(CCWPSC_CALLING_SCRIPT);
    if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) > "5" ) :
      $ccwpsc_perms = 0664;
    else:
      $ccwpsc_perms = 0644;
    endif;
  	chmod($wpsc_plug_dir . CCWPSC_ADDON_SCRIPT, $ccwpsc_perms);
		$this->options['wpsc_path'] = $wpsc_plug_dir;
  	return 'Done';
  }   // END OF ccwpsc_build_script FUNCTION


  function is_valid_ISO_list($list) {
    if ( $list != '') :
  	  $codes = explode(',' , $list);
  		foreach ($codes as $code) :
  		   if ( ! ctype_alpha($code) || strlen($code) != 2) :
     		   return FALSE;
  			 endif;
  		endforeach;	
  	endif;
		return TRUE;
	}

//  All Methods below this point are used to retreive and save Maxmind data files 

// this is the main controlling method
function save_maxmind_data($plugin_update=FALSE) {

	// intialize function
	 $max_ipv4download_url = 'http://geolite.maxmind.com/download/geoip/database/GeoLiteCountry/GeoIP.dat.gz';
	 $max_ipv6download_url = 'http://geolite.maxmind.com/download/geoip/database/GeoIPv6.dat.gz';
   $ipv4_gz = 'GeoIP.dat.gz';
   $ipv4_dat = 'GeoIP.dat';
   $cca_ipv4_file = CCA_MAXMIND_DATA_DIR . $ipv4_dat;
   $ipv6_gz = 'GeoIPv6.dat.gz';
   $ipv6_dat = 'GeoIPv6.dat';
   $cca_ipv6_file = CCA_MAXMIND_DATA_DIR . $ipv6_dat;
   $error_prefix = __("Error: Maxmind files are NOT installed. ");
	 if(empty($this->maxmind_status['ipv4_file_date'])) : $this->maxmind_status['ipv4_file_date'] = 0; endif;
	 if(empty($this->maxmind_status['ipv6_file_date']) ): $this->maxmind_status['ipv6_file_date'] = 0; endif;
	 $original_health = $this->maxmind_status['health'] = empty($this->maxmind_status['health']) ? 'ok' : $this->maxmind_status['health'];
	 $files_written_ok = FALSE;

   clearstatcache();

	 if (file_exists($cca_ipv4_file) && file_exists($cca_ipv6_file) && filesize($cca_ipv4_file) > 131072 && filesize($cca_ipv6_file) > 131072) :
     // return if an install/update is not necessary (another plugin may have recently done an update)
     if ($original_health == 'ok' && ! empty($this->maxmind_status['ipv4_file_date']) && $this->maxmind_status['ipv4_file_date'] > (time() - 3600 * 24 * 10) ): return TRUE; endif;
		 $original_health = 'ok';
  else:
	   $original_health = 'fail';
	endif;

	// re-initialize status msg
	$this->maxmind_status['result_msg'] = '';

	// create Maxmind directory if necessary
  if ( validate_file(CCA_MAXMIND_DATA_DIR) != 0 ) :  	// 0 means a valid format for a directory path
	   $this->maxmind_status['health'] = 'fail';
	   $this->maxmind_status['result_msg'] = $error_prefix . __('Constant CCA_MAXMIND_DATA_DIR contains an invalid value: "') . esc_html(CCA_MAXMIND_DATA_DIR) . '"';
	elseif ( ! file_exists(CCA_MAXMIND_DATA_DIR) ): 
	    // then this is the first download, or a new directory location has been defined
      $item_perms = ccwpsc_return_permissions(CCWPSC_PLUGINDIR);  // determine required folder permissions (e.g. for shared or dedicated server)
      if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) == '7') :
          $ccwpsc_perms = 0775;
      else:
          $ccwpsc_perms = 0755;
      endif;							
  		if ( ! @mkdir(CCA_MAXMIND_DATA_DIR, $ccwpsc_perms, true) ): 
			    $this->maxmind_status['health'] = 'fail';
				  $this->maxmind_status['result_msg'] = $error_prefix . __('Unable to create directory "') . CCA_MAXMIND_DATA_DIR . __('" This may be due to your server permission settings. See the Country Caching "Support" tab for more information.');
		  endif;
	endif;

	// if the Maxmind directory exists
	if ($this->maxmind_status['health'] == 'ok') :

    	// get and write the IPv4 Maxmind data
      if ($original_health == 'fail') : 
         $error_prefix = __("Error; unable to install the Maxmind IPv4 data file:\n");
      else:
    		 $error_prefix = __("Warning; unable to update the Maxmind IPv4 data file:\n");
    	endif;
      $ipv4_result = $this->update_dat_file($max_ipv4download_url, $ipv4_gz, $ipv4_dat, $error_prefix);
	    $temp_health = $this->maxmind_status['health']; 
			if ($ipv4_result == 'done') :
			  $ipv4_result =  'IPv4 file updated successfully.';
				$files_written_ok = TRUE;
				$this->maxmind_status['ipv4_file_date'] = time(); 
			endif;
 
       // get and write the IPv6 Maxmind data
      if ($original_health == 'fail') : 
         $error_prefix = __("Error; unable to install the Maxmind IPv6 data file:\n");
      else:
    		 $error_prefix = __("Warning; unable to update the Maxmind IPv6 data file:\n");
    	endif;
      $ipv6_result = $this->update_dat_file($max_ipv6download_url, $ipv6_gz, $ipv6_dat, $error_prefix);
 			if ($ipv6_result == 'done') :
			  $ipv6_result =  'IPv6 file updated successfully.';
				$this->maxmind_status['ipv6_file_date'] = time(); 
			else:
			  $files_written_ok = FALSE;  // overrides TRUE set by IPv4 success
			endif;
			 
			// ensure health status is set to the most critical of IP4 & IP6 file updates
			if ($temp_health == 'fail' || $this->maxmind_status['health'] == 'fail'):
			   $this->maxmind_status['health'] = 'fail';
			elseif ($temp_health == 'warn' || $this->maxmind_status['health'] == 'warn') :
			   $this->maxmind_status['health'] = 'warn';
			endif;

			$this->maxmind_status['result_msg'] = $ipv4_result . "<br />\n" . $ipv6_result;

  endif;

	if ($this->maxmind_status['health'] == 'warn' && $original_health == 'fail') : $this->maxmind_status['health'] = 'fail'; endif;
  if ($this->maxmind_status['health'] == 'ok') : $this->maxmind_status['result_msg'] .= __(" The last update was successful"); endif;
  update_option( 'cc_maxmind_status', $this->maxmind_status );
 
 
  // this function was called on plugin update the user might not open the settings form, so we'll report errors by email
  if ($plugin_update  && $this->maxmind_status['health'] == 'fail'):
     $subject = __("Error: site:") . get_bloginfo('url') . __(" unable to install Maxmind GeoIP files");
     $msg = str_replace('<br />', '' , $this->maxmind_status['result_msg']) . "\n\n" . __('Email sent by the Country Caching plugin ') . date(DATE_RFC2822);	
	  @wp_mail( get_bloginfo('admin_email'), $subject, $msg );
  endif;

	return $files_written_ok;
}  // END save_maxmind_data() 


//  This method retreives the "zips" from Maxmind and then calls other methods to do the rest of the work
function update_dat_file($file_to_upload, $zip_name, $extracted_name, $error_prefix) {

	$uploadedFile = CCA_MAXMIND_DATA_DIR . $zip_name;
	$extractedFile = CCA_MAXMIND_DATA_DIR . $extracted_name;

  // open file on server for overwrite by CURL
  if (! $fh = fopen($uploadedFile, 'wb')) :
		 $this->maxmind_status['health'] = 'warn';
		 return $error_prefix . __("Failed to fopen ") . $uploadedFile . __(" for writing: ") .  implode(' | ',error_get_last()) . "\n<br />";
  endif;
  // Get the "file" from Maxmind
  $ch = curl_init($file_to_upload);
  curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);  // identify as error if http status code >= 400
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_USERAGENT, 'a UA string'); // some servers require a non empty or specific UA
  if( !curl_setopt($ch, CURLOPT_FILE, $fh) ):
		 $this->maxmind_status['health'] = 'warn';
		 return $error_prefix . __('curl_setopt(CURLOPT_FILE) fail for: "') . $uploadedFile . '"<br /><br />' . "\n\n";
	endif;
  curl_exec($ch);
  if(curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200 ) :
  	fclose($fh);
		$curl_err = $error_prefix . __('File transfer (CURL) error: ') . curl_error($ch) . __(' for ') . $file_to_upload . ' (HTTP status ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ')';
    curl_close($ch);
		$this->maxmind_status['health'] = 'warn';
		return $curl_err;
  endif;
  curl_close($ch);
  fflush($fh);
  fclose($fh);

  if(filesize($uploadedFile) < 1) :
		$this->maxmind_status['health'] = 'warn';
		return $error_prefix . __("CURL file transfer completed but we have an empty or non-existent file to uncompress. (") . $uploadedFile . ').<br /><br />' . "\n\n";
  endif;

	$function_result = $this->gzExtractMax($uploadedFile, $extractedFile);

	if ($function_result != 'done'):  return $error_prefix . $function_result; endif;

  //  update appears to have been successful
  $this->maxmind_status['health'] = 'ok';
  return 'done';

}  // END  update_dat_file()


// extract file from gzip and write to folder
function gzExtractMax($uploadedFile, $extractedFile) {

  $buffer_size = 4096; // memory friendly bytes read at a time - good for most servers

  $fhIn = gzopen($uploadedFile, 'rb');
  if (is_resource($fhIn)) {
    $function_result = $this->backupMaxFile($extractedFile);
		if ($function_result != 'done' ) {
			$this->maxmind_status['health'] = 'warn';
			return $function_result;
    }
		$fhOut = fopen($extractedFile, 'wb');
    $writeSucceeded = TRUE;
    while(!gzeof($fhIn)) :
       $writeSucceeded = fwrite($fhOut, gzread($fhIn, $buffer_size)); // write from buffer
       if ($writeSucceeded === FALSE) break;
    endwhile;
    @fclose($fhOut);

    if ( ! $writeSucceeded ) {
			$this->maxmind_status['health'] = 'fail';
			$function_result = __('Error writing "') .  $extractedFile . '"<br />' ."\n" . __('Last reported error: ') .  implode(' | ',error_get_last());
			$function_result .= "<br />\n" . $this->revertToOld($extractedFile);
			return $function_result;
		}

  } else { 
	    $this->maxmind_status['health'] = 'warn';
		  $function_result = __('Unable to extract the file from the Maxmind gzip: ( ') . $uploadedFile . ")<br />\n" . __("Your existing data file has not been changed.");
		  return $function_result;
		}
  gzclose($fhIn);

  clearstatcache();
  if(filesize($extractedFile) < 1) {
	  $this->maxmind_status['health'] = 'fail';
	  $recoveryStatus = $this->revertToOld($extractedFile);
		$function_result = __('Failed to create a valid data file - it appears to be empty. Trying to revert to old version: ') . $recoveryStatus;
		return $function_result;
	}
	$this->maxmind_status['health'] = 'ok';
  return 'done';
}


// used to create a copy of the file (in same dir) before it is updated (replaces previous back-up)
function backupMaxFile($fileToBackup) {
  if (! file_exists($fileToBackup) || @copy($fileToBackup, $fileToBackup . '.bak') ) return 'done';
  return __('ABORTED - failed to back-up ') . $fileToBackup . __(' before replacing Maxmind file: ') .  implode(' | ',error_get_last()) . "\n" . __("<br />Your existing data file has not been changed.");
}


function revertToOld($fileToRollBack){
  $theBackup = $fileToRollBack . '.bak';
  if (! file_exists($theBackup) || filesize($theBackup) < 131072 || ! @copy($theBackup, $fileToRollBack) ) return __("NOTE: unable to revert to a previous version of ") . $fileToRollBack . ".<br />\n\n";
  $this->maxmind_status['health'] = 'warn';
  return __('It looks like we were able to revert to an old copy of the file.<br />');
}


} // END CLASS

