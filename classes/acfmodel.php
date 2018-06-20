<?php

class SyncACFModel extends SyncACFModelInterface
{
	const CPT_NAME = 'acf';

	const SYNC_CONTENT_TYPE = 'acf_form';

	public $wp_error = NULL;
	private $_acf_pro = NULL;

	public function get_model_id()
	{
		return self::MODEL_ID_ACF;								// '0' indicate ACF
	}

	/**
	 * returns the ACF database version stored in options table
	 */
	public function get_db_version()
	{
		return get_option('acf_version', FALSE);
	}

	/**
	 * Finds an existing ACF Form via Source ID or creates a new one
	 * @param int $source_form_id The Form's ID on the Source
	 * @param array $acf_data The array of Post Data used to create the Form's post entry
	 * @return int|NULL The Target's ACF Form ID if found or NULL if not found and unable to create it
	 */
	public function find_create_form($source_form_id, $acf_data)
	{
SyncDebug::log(__METHOD__."({$source_form_id}):" . __LINE__);
		$target_form_id = $this->get_form_id_from_source_id($source_form_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target_id=' . var_export($target_form_id, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' acf_data=' . var_export($acf_data, TRUE));
		$post_data = $acf_data['form_data'];
		// TODO: update post_author
		if (NULL === $target_form_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' inserting post');
			// form id not found on Target - create it
			unset($post_data['ID']);
			$res = wp_insert_post($post_data);
			if (is_wp_error($res)) {
				// TODO: error updating post for some reason - try to recover
				$this->wp_error = $res;
				return NULL;
			}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' created form on Target: #' . $res);

			$target_form_id = abs($res);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target form id #' . $target_form_id);
			// write data to spectrom_sync for future lookups
			$data = array(
				'site_key' => SyncApiController::get_instance()->source_site_key,
				'source_content_id' => $source_form_id,
				'target_content_id' => $target_form_id,
				'content_type' => self::SYNC_CONTENT_TYPE,
				'target_site_key' => SyncOptions::get('site_key'),
			);
			$sync_model = new SyncModel();
			$sync_model->save_sync_data($data);
		} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating post #' . $target_form_id);
			// form id found - update it
			$post_data['ID'] = $target_form_id;
			$res = wp_update_post($post_data);
			if (0 === $res) {
				// the post has disappeared (deleted). create a new one
				unset($post_data['ID']);
				$res = wp_insert_post($post_data);
				if (is_wp_error($res)) {
					// TODO: error updating post for some reason - try to recover
					$this->wp_error = $res;
					return NULL;
				}
				$target_form_id = abs($res);
				// update the spectrom_sync record so it can be found again
				$sync_model = new SyncModel();
				$sync_model->update(array(
					'source_content_id' => $source_form_id,
					'site_key' => SyncApiController::get_instance()->source_site_key),
					array('target_content_id' => $target_form_id));
			}
		}

		// update post meta to match Source's Form contents

		// start by building arrays holding Source and Target form data
		$source_fields = $this->filter_form_fields($acf_data['form_fields']);
		$source_rule_fields = array();
		if (isset($acf_data['form_fields']['rule']))
			$source_rule_fields = $acf_data['form_fields']['rule'];

		$target_fields = $this->load_form_fields($target_form_id);
//		$target_rule_fields = array();
//		if (isset($target_fields['rule'])) {
//			$target_rule_fields = $target_fields['rule'];
//			unset($target_fields['rule']);
//		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source fields=' . var_export($source_fields, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source rules=' . var_export($source_rule_fields, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target fields=' . var_export($target_fields, TRUE));
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target rules=' . var_export($target_rule_fields, TRUE));

		// update all of the postmeta values for the form, transporting the Form to the Target
		global $wpdb;
		$items = min(count($source_fields), count($target_fields));
		for ($idx = 0; $idx < $items; ++$idx) {
			$field = array(
				'post_id' => $target_form_id,
				'meta_key' => $source_fields[$idx]['field'],
				'meta_value' => stripslashes($source_fields[$idx]['data']),
			);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating ' . $source_fields[$idx]['field']);
			$wpdb->update($wpdb->postmeta, $field, array('meta_id' => $target_fields[$idx]['meta_id']));
		}
		if (count($source_fields) > count($target_fields)) {
			// Source items greater than Target items -- add them
			for (; $idx < count($source_fields); ++$idx) {
				$field = array(
					'post_id' => $target_form_id,
					'meta_key' => $source_fields[$idx]['field'],
					'meta_value' => stripslashes($source_fields[$idx]['data']),
				);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' inserting ' . $source_fields[$idx]['field'] . ' = ' . $field['meta_value']);
				$wpdb->insert($wpdb->postmeta, $field);
			}
		} else if (count($source_fields) < count($target_fields)) {
			// Source items less than Target items -- remove them
			for (; $idx < count($target_fields); ++$idx) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' deleting ' . $target_fields[$idx]['meta_key']);
				$wpdb->delete($wpdb->postmeta, array('meta_id' => $target_fields[$idx]['meta_id']));
			}
		}

		// update the rule sets for the form

		delete_post_meta($target_form_id, 'rule');			// remove all keys
		foreach ($source_rule_fields as $rule_data) {
			add_post_meta($target_form_id, 'rule', maybe_unserialize(stripslashes($rule_data)));
		}
/*		$rule = isset($acf_data['form_fields']['rule'][0]) ? $acf_data['form_fields']['rule'][0] : NULL;
		if (NULL !== $rule) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' rule=' . var_export($rule, TRUE));
			update_post_meta($target_form_id, 'rule', maybe_unserialize(stripslashes($rule)));
		} */
		// target id #418
		// target id #424

		// return the found or created post ID to caller
		return $target_form_id;
	}

	/**
	 * Locates an existing ACF form via the WPSiteSync table
	 * @param int $id The Post ID of the Form to find
	 * @param string $title The post_title of the Form to find
	 */
	public function find_form($id = 0, $title = '')
	{
		if (0 !== $id) {
			$form = get_post($id);
			if (NULL !== $form)
				return $form;
		}
	}


	/**
	 * Looks through ACF data for image information
	 * @param array $data Array containing Push information
	 * @param ACFSourceApi $source_api Source API implementation instance
	 */
	public function find_form_meta(&$data, $source_api)
	{
		// look in meta data for ACF form info
		foreach ($data['post_meta'] as $meta_key => $meta_value) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $meta_key . ' val=' . var_export($meta_value, TRUE));
			$meta_data = count($meta_value) > 0 ? $meta_value[0] : '';
			if ('field_' == substr($meta_data, 0, 6) && 19 === strlen($meta_data)) {
				$meta_field = $meta_data;

				// look up the ACF field description
				$acf_field_row = $this->get_field_object($meta_field);
				if (NULL === $acf_field_row)
					continue;
				$acf_field_data = $acf_field_row->meta_value;
				$acf_field = maybe_unserialize($acf_field_data);
				if (empty($acf_field['type']))
					continue;

				// add ACF form ids to the list
				$acf_id = abs($acf_field_row->post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ACF id=' . $acf_id);
				if (!in_array($acf_id, $source_api->acf_form_list)) {
					$this->acf_form_list[] = $acf_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' added to list: ' . implode(',', $this->acf_form_list));
				}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found field type: ' . $acf_field['type']);
				switch ($acf_field['type']) {
				case 'image':
					$source_api->add_image($acf_field['name']);				// add the field name to the list of image fields
					break;
				case 'taxonomy':
					break;
				case 'user':
					$meta_name = substr($meta_key, 1);
					$user_id = isset($data['post_meta'][$meta_name][0]) ? abs($data['post_meta'][$meta_name][0]) : 0;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' looking up user ' . $user_id);
					if (0 !== $user_id) {
						$user = get_user_by('id', $user_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' user: ' . var_export($user->data, TRUE));
						if (FALSE !== $user)
							$data['acf_users'][] = $user->data;
					}
					break;

				// note: the 'relationship', 'post_object' and 'page_link' types are handled on the Target by Content lookup. no need to send additional data
				}
			} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' skipping ' . $meta_key);
			}
		}
	}

	/**
	 * Uses the SyncModel to find the given form's ID from the Source site's ID
	 * @param int $source_form_id The Post ID of the ACF Form on the Source site
	 * @return Object An object representing the spectrom_sync table record or NULL if the record is not found
	 */
	public function get_form_id_from_source_id($source_form_id)
	{
		$sync_model = new SyncModel();
		$sync_data = $sync_model->get_sync_data($source_form_id, SyncApiController::get_instance()->source_site_key/*NULL*/, self::SYNC_CONTENT_TYPE);
		if (NULL !== $sync_data)
			return $sync_data->target_content_id;
		return NULL;
	}

	public function create_form($data)
	{
	}

	public function update_form($data)
	{
	}

	/**
	 * Loads the Form's fields from the postmeta table
	 * @param int $target_form_id The Form's post ID to search for
	 * @return array|NULL The postmeta data if found or NULL if not found
	 */
	public function load_form_fields($target_form_id)
	{
		global $wpdb;
		$target_form_id = abs($target_form_id);
		$sql = "SELECT *
				FROM `{$wpdb->postmeta}`
				WHERE `post_id`={$target_form_id} AND `meta_key` LIKE 'field_%'";
		$res = $wpdb->get_results($sql, ARRAY_A);
SyncDebug::log(__METHOD__.'() sql=' . $sql . ' = ' . var_export($res, TRUE));
		if (NULL === $res)
			return array();
		return $res;
	}

	/**
	 * Filters the contents of the ['form_fields'] element, looking for ACF form fields
	 * @param array $form_data The data including the form fields for the ACF form
	 * @return array List of only the form fields associated with the ACF Form
	 */
	public function filter_form_fields($form_data)
	{
		$ret = array();
		foreach ($form_data as $field => $data) {
			if ('field_' === substr($field, 0, 6)) {
				$ret[] = array('field' => $field, 'data' => $data[0]);
			}
		}
		return $ret;
	}

	/**
	 * Retrieves field object for a named field within a form
	 * @param string $name The name of the field within the form
	 * @return object stdClass instance of field data or NULL if not found
	 */
	public function get_field_object($name)
	{
		global $wpdb;

		// TODO: needs post_id in WHERE clause
		$sql = "SELECT *
				FROM `{$wpdb->postmeta}`
				WHERE `meta_key`=%s
				LIMIT 1";
		$sql = $wpdb->prepare($sql, $name);
		$res = $wpdb->get_row($sql, OBJECT);
SyncDebug::log(__METHOD__.'() sql=' . $sql . ' res=' . var_export($res, TRUE));
		return $res;
	}
}

/*
  'acf_data' => 
  array (
    0 => 
    array (
      'id' => 1709,
      'form_data' => 
      array (
        'ID' => 1709,
        'post_author' => '1',
        'post_date' => '2016-12-28 22:01:35',
        'post_date_gmt' => '2016-12-29 06:01:35',
        'post_content' => '',
        'post_title' => 'Test Group',
        'post_excerpt' => '',
        'post_status' => 'publish',
        'comment_status' => 'closed',
        'ping_status' => 'closed',
        'post_password' => '',
        'post_name' => 'acf_test-group',
        'to_ping' => '',
        'pinged' => '',
        'post_modified' => '2016-12-28 22:01:35',
        'post_modified_gmt' => '2016-12-29 06:01:35',
        'post_content_filtered' => '',
        'post_parent' => 0,
        'guid' => 'http://sync.loc/?post_type=acf&#038;p=1709',
        'menu_order' => 0,
        'post_type' => 'acf',
        'post_mime_type' => '',
        'comment_count' => '0',
        'filter' => 'raw',
        'ancestors' => 
        array (
        ),
        'post_category' => 
        array (
        ),
        'tags_input' => 
        array (
        ),
      ),
      'form_fields' => 
      array (
        'field_5864a623b216f' => 
        array (
          0 => 'a:14:{s:3:"key";s:19:"field_5864a623b216f";s:5:"label";s:5:"Title";s:4:"name";s:6:"title ";s:4:"type";s:4:"text";s:12:"instructions";s:0:"";s:8:"required";s:1:"0";s:13:"default_value";s:0:"";s:11:"placeholder";s:0:"";s:7:"prepend";s:0:"";s:6:"append";s:0:"";s:10:"formatting";s:4:"html";s:9:"maxlength";s:0:"";s:17:"conditional_logic";a:3:{s:6:"status";s:1:"0";s:5:"rules";a:1:{i:0;a:2:{s:5:"field";s:4:"null";s:8:"operator";s:2:"==";}}s:8:"allorany";s:3:"all";}s:8:"order_no";i:0;}',
        ),
        'field_5864a650b2170' => 
        array (
          0 => 'a:11:{s:3:"key";s:19:"field_5864a650b2170";s:5:"label";s:12:"Image Object";s:4:"name";s:12:"image-object";s:4:"type";s:5:"image";s:12:"instructions";s:25:"select image from library";s:8:"required";s:1:"0";s:11:"save_format";s:6:"object";s:12:"preview_size";s:4:"full";s:7:"library";s:3:"all";s:17:"conditional_logic";a:3:{s:6:"status";s:1:"0";s:5:"rules";a:1:{i:0;a:2:{s:5:"field";s:4:"null";s:8:"operator";s:2:"==";}}s:8:"allorany";s:3:"all";}s:8:"order_no";i:1;}',
        ),
        'field_5864a684b2171' => 
        array (
          0 => 'a:11:{s:3:"key";s:19:"field_5864a684b2171";s:5:"label";s:9:"Image URL";s:4:"name";s:9:"image-url";s:4:"type";s:5:"image";s:12:"instructions";s:0:"";s:8:"required";s:1:"0";s:11:"save_format";s:3:"url";s:12:"preview_size";s:4:"full";s:7:"library";s:3:"all";s:17:"conditional_logic";a:3:{s:6:"status";s:1:"0";s:5:"rules";a:1:{i:0;a:2:{s:5:"field";s:4:"null";s:8:"operator";s:2:"==";}}s:8:"allorany";s:3:"all";}s:8:"order_no";i:2;}',
        ),
        'field_5864a697b2172' => 
        array (
          0 => 'a:11:{s:3:"key";s:19:"field_5864a697b2172";s:5:"label";s:8:"Image ID";s:4:"name";s:8:"image-id";s:4:"type";s:5:"image";s:12:"instructions";s:0:"";s:8:"required";s:1:"0";s:11:"save_format";s:2:"id";s:12:"preview_size";s:4:"full";s:7:"library";s:3:"all";s:17:"conditional_logic";a:3:{s:6:"status";s:1:"0";s:5:"rules";a:1:{i:0;a:2:{s:5:"field";s:4:"null";s:8:"operator";s:2:"==";}}s:8:"allorany";s:3:"all";}s:8:"order_no";i:3;}',
        ),
        'rule' => 
        array (
          0 => 'a:5:{s:5:"param";s:9:"post_type";s:8:"operator";s:2:"==";s:5:"value";s:4:"post";s:8:"order_no";i:0;s:8:"group_no";i:0;}',
        ),
        'position' => 
        array (
          0 => 'normal',
        ),
        'layout' => 
        array (
          0 => 'no_box',
        ),
        'hide_on_screen' => 
        array (
          0 => '',
        ),
      ),
    ),
  ),
*/

// EOF
