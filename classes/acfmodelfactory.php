<?php

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
				$acf_model = NULL;
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
	}

	abstract class SyncACFModelInterface
	{
		const MODEL_ID_ACF = '0';
		const MODEL_ID_ACF_PRO = '1';

		public function get_db_version()
		{
			return get_option('acf_version', FALSE);
		}

		abstract public function find_create_form($source_form_id, $acf_data);
		abstract public function find_form($id, $title);
		abstract public function find_form_meta(&$data, $push_content);
		abstract public function get_form_id_from_source_id($source_form_id);
		abstract public function load_form_fields($target_form_id);
		abstract public function filter_form_fields($form_data);
		abstract public function get_field_object($name);
		abstract public function get_model_id();
	}
} // class_exists

// EOF