<?php
/*
Plugin Name: WPSiteSync for Advanced Custom Fields
Plugin URI: https://wpsitesync.com/downloads/wpsitesync-for-advanced-custom-fields/
Description: Provides extensions to WPSiteSync to allow syncing ACF Forms, images and data.
Author: WPSiteSync
Author URI: https://wpsitesync.com
Version: 1.1
Text Domain: wpsitesync-acf

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

if (!class_exists('WPSiteSync_ACF', FALSE)) {
	/*
	 * @package WPSiteSync_ACF
	 * @author Dave Jesch
	 */
	class WPSiteSync_ACF
	{
		private static $_instance = NULL;

		const PLUGIN_NAME = 'WPSiteSync for ACF';
		const PLUGIN_VERSION = '1.1';
		const PLUGIN_KEY = '00635d5481c107cdb01d1f494f012024';
		const REQUIRED_VERSION = '1.6';						// minimum version of WPSiteSync that is required
		const REQUIRED_ACF_VERSION = '1.0';					// minimum version of ACF that is required

		private $_acf_api_request = NULL;

		private function __construct()
		{
			add_action('spectrom_sync_init', array($this, 'init'));
			if (is_admin())
				add_action('wp_loaded', array($this, 'wp_loaded'));
		}

		/*
		 * retrieve singleton class instance
		 * @return instance reference to plugin
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		/**
		 * Initialize the WPSiteSync ACF plugin
		 */
		public function init()
		{
//SyncDebug::log(__METHOD__.'()');
			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);

###			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_acf', self::PLUGIN_KEY, self::PLUGIN_NAME))
###				return;

			// check for minimum WPSiteSync version
			if (is_admin() && version_compare(WPSiteSyncContent::PLUGIN_VERSION, self::REQUIRED_VERSION) < 0 && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_minimum_version'));
				add_action('admin_init', array($this, 'disable_plugin'));
				return;
			}
			if (is_admin() && !class_exists('acf') && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_requires_acf'));
				add_action('admin_init', array($this, 'disable_plugin'));
				return;
			}

//			if (is_admin())
//				SyncACFAdmin::get_instance();
###			add_filter('spectrom_sync_allowed_post_types', array($this, 'filter_post_types'));

			// TODO: move into 'spectrom_sync_api_init' callback
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2);
			add_filter('spectrom_sync_pre_push_content', array($this, 'pre_push_content'), 10, 4);
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 20, 3);
			add_action('spectrom_sync_api_process', array($this, 'push_processed'), 10, 3);
			add_action('spectrom_sync_media_processed', array($this, 'media_processed'), 10, 3);
//			add_filter('spectrom_sync_api', array($api, 'api_controller_request'), 10, 3); // called by SyncApiController

			add_filter('spectrom_sync_error_code_to_text', array($this, 'filter_error_code'), 10, 2);
//			add_filter('spectrom_sync_notice_code_to_text', array($this, 'filter_notice_code'), 10, 2);
		}

		/*
		 * Return reference to asset, relative to the base plugin's /assets/ directory
		 * @param string $ref asset name to reference
		 * @return string href to fully qualified location of referenced asset
		 */
		public static function get_asset($ref)
		{
			// TODO: probably not needed any more
			$ret = plugin_dir_url(__FILE__) . 'assets/' . $ref;
			return $ret;
		}

		/**
		 * Add the ACF post type to the allowed post types
		 * @param array $post_types
		 * @return array The array of allowed post types that WPSiteSync will process
		 */
		public function filter_post_types($post_types)
		{
			$post_types[] = 'acf';
			return $post_types;
		}

		/**
		 * Load a class file on demand
		 * @param string $name Name of the class file to load
		 */
		public function load_class($name)
		{
			require_once(dirname(__FILE__) . '/classes/' . $name . '.php');
		}

		/**
		 * Called when WP is loaded so we can check if parent plugin is active.
		 */
		public function wp_loaded()
		{
			if (!class_exists('WPSiteSyncContent', FALSE) && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_requires_wpss'));
				add_action('admin_init', array($this, 'disable_plugin'));
			}
		}

		/**
		 * Displays the warning message stating that WPSiteSync is not present.
		 */
		public function notice_requires_wpss()
		{
			$install = admin_url('plugin-install.php?tab=search&s=wpsitesync');
			$activate = admin_url('plugins.php');
			$msg = sprintf(__('The <em>WPSiteSync for Advanced Custom Fields</em> plugin requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please %1$sclick here</a> to install or %2$sclick here</a> to activate.', 'wpsitesync-acf'),
						'<a href="' . $install . '">',
						'<a href="' . $activate . '">');
			$this->_show_notice($msg, 'notice-warning');
		}

		/**
		 * Displays the warning message stating that ACF is not present.
		 */
		public function notice_requires_acf()
		{
			$install = admin_url('plugin-install.php?tab=search&s=advanced+custom+fields');
			$activate = admin_url('plugins.php');
			$msg = sprintf(__('The <em>WPSiteSync for Advanced Custom Fields</em> plugin requires the Advanced Custom Fields plugin to be installed and activated. Please %1$sclick here</a> to install or %2$sclick here</a> to activate.', 'wpsitesync-acf'),
				'<a href="' . $install . '">',
				'<a href="' . $activate . '">');
			$this->_show_notice($msg, 'notice-warning');
		}

		/**
		 * Display admin notice to upgrade WPSiteSync for Content plugin
		 */
		public function notice_minimum_version()
		{
			$this->_show_notice(
				sprintf(__('WPSiteSync for Advanced Custom Fields requires version %1$s or greater of <em>WPSiteSync for Content</em> to be installed. Please <a href="2%s">click here</a> to update.', 'wpsitesync-acf'),
					self::REQUIRED_VERSION,
					admin_url('plugins.php')),
				'notice-warning');
		}

		/**
		 * Helper method to display notices
		 * @param string $msg Message to display within notice
		 * @param string $class The CSS class used on the <div> wrapping the notice
		 * @param boolean $dismissable TRUE if message is to be dismissable; otherwise FALSE.
		 */
		private function _show_notice($msg, $class = 'notice-success', $dismissable = FALSE)
		{
			echo '<div class="notice ', $class, ' ', ($dismissable ? 'is-dismissible' : ''), '">';
			echo '<p>', $msg, '</p>';
			echo '</div>';
		}

		/**
		 * Disables the plugin if WPSiteSync not installed
		 */
		public function disable_plugin()
		{
			deactivate_plugins(plugin_basename(__FILE__));
		}

		/**
		 * Check that everything is ready for us to process the Content Push operation on the Target
		 * @param array $post_data The post data for the current Push
		 * @param int $source_post_id The post ID on the Source
		 * @param int $target_post_id  The post ID on the Target
		 * @param SyncApiResponse $response The API Response instance for the current API operation
		 */
		public function pre_push_content($post_data, $source_post_id, $target_post_id, $response)
		{
SyncDebug::log(__METHOD__.'() source id=' . $source_post_id);
			$this->load_class('acfapirequest');
			$this->load_class('acfsourceapi');
			$api = new SyncACFSourceApi();
			$api->pre_process($post_data, $source_post_id, $target_post_id, $response);
			return $post_data;
		}

		/**
		 * Handles the processing of Push requests in response to an API call on the Target
		 * @param int $target_post_id The post ID of the Content on the Target
		 * @param array $post_data The array of post content information sent via the API request
		 * @param SyncApiResponse $response The response object used to reply to the API call
		 */
		public function handle_push($target_post_id, $post_data, SyncApiResponse $response)
		{
			$this->load_class('acfapirequest');
			$this->load_class('acftargetapi');
			$target_api = new SyncACFTargetApi();
			$target_api->handle_push($target_post_id, $post_data, $response);
		}

		/**
		 * API post processing callback.
		 * @param string $action The API action, something like 'push', 'media_upload', 'auth', etc.
		 * @param SyncApiResponse $response The Response object
		 * @param SyncApiController $apicontroller 
		 */
		public function push_processed($action, $response, $apicontroller)
		{
			$this->load_class('acfapirequest');
			$this->load_class('acftargetapi');
			$target_api = new SyncACFTargetApi();
			$target_api->push_processed($action, $response, $apicontroller);
		}

		/**
		 * Callback for 'spectrom_sync_media_processed', called from SyncApiController->upload_media()
		 * @param int $target_post_id The Post ID of the Content being pushed
		 * @param int $attach_id The attachment's ID
		 * @param int $media_id The media id
		 */
		public function media_processed($target_post_id, $attach_id, $media_id)
		{
			$this->load_class('acfapirequest');
			$this->load_class('acftargetapi');
			$target_api = new SyncACFTargetApi();
			$target_api->media_processed($target_post_id, $attach_id, $media_id);
		}

		/**
		 * Retieve a single copy of the SyncACFApiRequest class
		 * @return SyncACFApiRequest instance of the class
		 */
		private function _get_acf_api_request()
		{
			// TODO: used less than before- can factor this out
			if (NULL === $this->_acf_api_request) {
				$this->load_class('acfapirequest');
				$this->_acf_api_request = new SyncACFApiRequest();
			}

			return $this->_acf_api_request;
		}

		/**
		 * Callback for filtering the post data before it's sent to the Target. Here we check for image references within the meta data.
		 * @param array $data The data being Pushed to the Target machine
		 * @param SyncApiRequest $apirequest Instance of the API Request object
		 * @return array The modified data
		 */
		public function filter_push_content($data, $apirequest)
		{
SyncDebug::log(__METHOD__.'()');
###			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_acf', self::PLUGIN_KEY, self::PLUGIN_NAME))
###				return $return;

			// look for media references and call SyncApiRequest->send_media() to add media to the Push operation
			if (isset($data['post_meta'])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found post meta data: ' . var_export($data['post_meta'], TRUE));
				$this->load_class('acfapirequest');
				$this->load_class('acfsourceapi');

				$api = new SyncACFSourceApi();
				$data = $api->filter_push_content($data, $apirequest);
			}
			return $data;
		}

		/**
		 * Converts numeric error code to message string
		 * @param string $msg Error message
		 * @param int $code The error code to convert
		 * @return string Modified message if one of WPSiteSync ACF's error codes
		 */
		public function filter_error_code($msg, $code)
		{
			return $this->_get_acf_api_request()->filter_error_code($msg, $code);
		}

		/**
		 * Converts numeric notice code to message string
		 * @param string $msg Notice message
		 * @param int $code The notice code to convert
		 * @return string Modified message if one of WPSiteSync ACF's notice codes
		 */
/*		public function filter_notice_code($msg, $code)
		{
			return $this->_get_acf_api_request()->filter_notice_codes($msg, $code);
			$this->load_class('acfapirequest');
			switch ($code) {
			case SyncACFApiRequest::NOTICE_ACF:		$msg = __('Cannot Sync ACF data', 'wpsitesync-acf'); break;
			}
			return $msg;
		} */

		/**
		 * Adds the WPSiteSync ACF add-on to the list of known WPSiteSync extensions
		 * @param array $extensions The list of extensions
		 * @param boolean TRUE to force adding the extension; otherwise FALSE
		 * @return array Modified list of extensions
		 */
		public function filter_active_extensions($extensions, $set = FALSE)
		{
###			if ($set || WPSiteSyncContent::get_instance()->get_license()->check_license('sync_acf', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_acf'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
			return $extensions;
		}
	}
}

// Initialize the extension
WPSiteSync_ACF::get_instance();

// EOF