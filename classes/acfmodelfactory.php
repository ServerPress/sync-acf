<?php

/**
 * Factory to create the appropriate ACF Model object for SyncACFSourceApi and SyncACFTargetApi.
 * @package WPSiteSync
 * @author WPSiteSync.com
 */

if (!class_exists('SyncACFModelFactory', FALSE)) {
	class SyncACFModelFactory
	{
		private static $_acf_pro = NULL;				// when TRUE using ACF Pro; otherwise FALSE

		/**
		 * Constructs a instance dereived from SyncACFModelInterface
		 * @param string $model A suggested form model instance type or NULL to determine appropriate model automatically
		 */
		public static function get_model($model = NULL)
		{
			$acf_model = NULL;
			// determine if using ACF or ACF Pro and instantiate appropriate model
			if (SyncACFModelInterface::MODEL_ID_ACF_PRO === $model || (NULL === $model && self::_is_acf_pro())) {
				WPSiteSync_ACF::get_instance()->load_class('acfpromodel');
				$acf_model = new SyncACFProModel();
$acf_model = NULL;		// TODO: remove this when SyncACFProModel is implemented
			} else if (SyncACFModelInterface::MODEL_ID_ACF_590 === $model || (NULL === $model && self::_is_acf_590())) {
				WPSiteSync_ACF::get_instance()->load_class('acf590model');
				$acf_model = new SyncACF590Model();
			} else if (SyncACFModelInterface::MODEL_ID_ACF === $model || (NULL === $model && !self::_is_acf_pro())) {
				WPSiteSync_ACF::get_instance()->load_class('acfmodel');
				$acf_model = new SyncACFModel();
			}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning instance of: ' . get_class($acf_model));
			return $acf_model;
		}

		/**
		 * Determines if the data model for ACF content is "ACF" or "ACF Pro"
		 * @return boolean TRUE if content is ACF Pro style data; otherwise FALSE
		 */
		private static function _is_acf_pro()
		{
			if (NULL !== self::$_acf_pro)
				return self::$_acf_pro;

			if (class_exists('acf', FALSE)) {
				$methods = get_class_methods('acf');
				if (isset($methods['load_plugin_textdomain'])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' TRUE');
					return self::$_acf_pro = TRUE;
				}
//				$acf = acf();
//				if (isset($acf->version) && version_compare($acf->version, '5.5') >= 0) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' TRUE');
//					return self::$_acf_pro = TRUE;
//				}
			}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' FALSE');
			return self::$_acf_pro = FALSE;

####
			$acf_version = get_option('acf_version', FALSE);
			if (FALSE === $acf_version)
				return self::$_acf_pro = FALSE;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' acf version=' . $acf_version . ' compare=' . version_compare($acf_version, '5.5.14'));

			if (version_compare($acf_version, '5.5') >= 0)
				return self::$_acf_pro = TRUE;
			return self::$_acf_pro = FALSE;
//			$content = $data['post_data']['post_content'];
//			$acf_info = maybe_unserialize($content);
//			$this->_acf_pro = is_array($acf_info);
//			return self::_acf_pro;
		}

		/**
		 * Determine if the data model for ACF is >= v5.9.0. This indicates the use of the new data
		 * storage model and requires different code located in SyncACF590Model
		 * @return boolean TRUE if data model > 5.9.0; otherwise FALSE
		 */
		private static function _is_acf_590()
		{
			$vers = get_option('acf_version');
			if (version_compare($vers, '5.9.0', '>='))
				return TRUE;
			return FALSE;
		}
	}

	/**
	 * Declares the Interface used by all data model implementations
	 */

	abstract class SyncACFModelInterface
	{
		const MODEL_ID_ACF = '0';				// for ACF
		const MODEL_ID_ACF_590 = '1';			// for ACF v5.9.1+
		const MODEL_ID_ACF_PRO = '2';			// for ACF Pro

/*
 * POST data includes the following:
 *	['acf_model_id'] = the model ID used on the Source used to indicate which ACFModel to use on the Target
 *	['acf_group'] = The Group ID (form's post ID) from the Source site. This is the complete WP_Post data of the 'acf-field-group' post_type
 *	['acf_group_id'] = The Group ID name "group_5f6c0ebe10a59" for example
 *	['acf_form'] = An array of the form fields from the Source site. This is an array of WP_Post objects of the 'acf-field' post_type
 *		or an array of the post_meta data containing information on the form fields for the form Group. This data is different
 *		depending on the version of ACF on the Source.
 * ACF Group is a nested array
 *	$_POST['acf_group']['group_5f6c0ebe10a59'] = array(group entries)
 * ACF Form is a nested array
 *	$_POST['acf_form']['group_5f6c0ebe10a59'] = array of Form fields
 *		364 => array(form field entry)
 *		362 => array(form field entry)
 * ACF Users is a nested array
 *		{userid1} => array(user fields)
 * ACF User Meta is a nested array
 *		{userid1} => array(user meta fields)
 *		{userid2} => array(user meta fields)
 */
		// constants used for accessing ACF related data in the API
		const DATA_MODEL_ID = 'acf_model_id';		// $_POST entry for the Model ID
		const DATA_GROUP = 'acf_group';				// $_POST entry for the ACF Form Group information
		const DATA_GROUP_ID = 'acf_group_id';		// @deprecated $_POST entry for the ACF Form Group ID value
		const DATA_FORM = 'acf_form';				// $_POST entry for the ACF Form Fields
		const DATA_USER_INFO = 'acf_users';			// $_POST entry for user information
		const DATA_USER_META = 'acf_usermeta';		// $_POST entry for the user meta
		const DATA_PREFIX = 'acf_prefix';			// $_POST entry for the db prefix on the source
		const DATA_VERSION = 'acf_version';			// $_POST entry for the ACF version

		public $form_group = array();											// holds the Form Group Data
		public $form_fields = array();											// array of data that make up the list of form fields. contents differ depending on Model
		public $wp_error = NULL;												// holds reference to WP_Error returned from db operations
		public $sync_model = NULL;												// instance of SyncModel

		public function __construct()
		{
			$this->sync_model = new SyncModel();
		}

		public function get_db_version()
		{
			return get_option('acf_version', FALSE);
		}

		public function is_acf_sync()
		{
			if (isset($_POST[self::DATA_GROUP]) && isset($_POST[self::DATA_FORM]))
				return TRUE;
			return FALSE;
		}

		abstract public function find_create_form($source_form_id, $acf_form);	// acf 590 pro | @deprecated use update_form() and find_form()
		abstract public function update_form($acf_group, $acf_form);			// acf 590 pro | creates/updates form on Target from POST data
		abstract public function find_form($id, $title);						// acf 590 pro | finds the form from a post ID or title
		abstract public function find_form_data(&$data, $push_content);			// acf 590 pro | finds form data on Source that needs to be processed
		abstract public function get_field_ids($group_id);						// acf 590 pro | return a list of field IDs based on the parent Group ID
		abstract public function get_form_id($group_id = NULL);					// acf 590 pro | get post ID from form searching by Group ID
		abstract public function get_form_id_from_source_id($source_form_id);	// acf 590 pro | get the post ID of the form on the Target site from the Source post ID
		abstract public function load_form_fields($target_form_id);				// acf 590 pro | find all form fields on the Target from a post ID
		abstract public function filter_form_fields($form_data);				// acf 590 pro | get_field_list() gets a list of the field_## values for the form
		abstract public function get_field_object($name);						// acf 590 pro | use field_## name to get the field's object
		abstract public function get_model_id();								// acf 590 pro | get the model code for the ACF Model instance
	}
} // class_exists

// EOF
