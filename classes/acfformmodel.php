<?php

class SyncACFFormModel
{
	const CPT_NAME = 'acf';

	const SYNC_CONTENT_TYPE = 'acf_form';

	public $wp_error = NULL;

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
				// TODO: content deleted by wp_spectrom_sync record exists - try to recover
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
			if (0 === $res)
				return NULL;
		}
		// update post meta to match Source's Form contents
		$source_fields = $this->filter_form_fields($acf_data['form_fields']);
		$target_fields = $this->load_form_fields($target_form_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source=' . var_export($source_fields, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target=' . var_export($target_fields, TRUE));

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
		$rule = isset($acf_data['form_fields']['rule'][0]) ? $acf_data['form_fields']['rule'][0] : NULL;
		if (NULL !== $rule) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' rule=' . var_export($rule, TRUE));
			update_post_meta($target_form_id, 'rule', maybe_unserialize(stripslashes($rule)));
		}
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
}
