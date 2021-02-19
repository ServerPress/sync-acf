<?php

/**
 * Models the data and data manipulation for ACF v5.9.0+
 * @package WPSiteSync
 * @author WPSiteSync.com
 */

/**
 * Version 5.9.0+ stores ACF Forms as a Custom Post Type named 'acf-field-group' and
 * the Field Group is stored as a series of Custom Post Types named 'acf-field' with
 * a post_parent of the post ID ofthe ACF Form Group post.
 */

class SyncACF590Model extends SyncACFModelInterface
{
	const CPT_ACF_GROUP = 'acf-field-group';
	const CPT_ACF_FIELD = 'acf-field';

	public function get_model_id()
	{
		return self::MODEL_ID_ACF_590;								// '1' indicate ACF v5.9.0+
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' deprecated');
die();
		throw new Exception('deprecated');
	}

	/**
	 * Creates or updates the form based on the Target based on data passed in API request
	 * @param int $source_form_id Source sites post ID for the form/group
	 * @param array $acf_data An array of data representing the form from the Source site. Provided in API call.
	 * @return int|NULL Target site's post ID for the form on success; otherwise NULL
	 */
	public function update_form($acf_group, $acf_form)
	{
SyncDebug::log(__METHOD__."():" . __LINE__);
		$group_ids = array_keys($acf_group);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' group ids: ' . implode(',', $group_ids));

		foreach ($group_ids as $form_group_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' working on group id: ' . $form_group_id);
			$form_group = $acf_group[$form_group_id];
			$source_form_id = abs($form_group['ID']);
			$target_form_id = $this->get_form_id_from_name($form_group_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' Source id: ' . $source_form_id . ' Target id: ' . var_export($target_form_id, TRUE));
			$acf_data = $acf_form[$form_group_id];	// set to array containing list of form fields

			// four scenarios to test
			// 1. found reference in spectrom_sync table, post exists and group_## matches
			// 2. found reference in spectrom_sync table, but post ID does not exist
			// 3. no reference in spectrom_sync table, post exists and group_## matches
			// 4. no reference in spectrom_sync table, post does not exist

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' acf_data=' . var_export($acf_data, TRUE));

			$target_content_id = $this->get_form_id_from_source_id($source_form_id);	// value from spectrom_sync db
##			$target_form_id = $this->get_form_id_from_name($form_group['post_name']);				// value from search

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' content_id=' . $target_content_id . ' form_id=' . $target_form_id);

			if ($target_content_id !== $target_form_id) {
if (0 !== $target_content_id)													#!#
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' previous target_content_id value no longer valid'); #!#
				// $target_form_id is the authoritative value, we'll go with that
			}

			// TODO: update post_author

			if (0 === $target_form_id) {
				// form id not found on Target - create it
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' form not found, adding from API data');

				unset($form_group['ID']);
				$res = wp_insert_post($form_group, TRUE);
				if (is_wp_error($res)) {
					// TODO: error updating post for some reason - try to recover
					$this->wp_error = $res;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: unable to add Form Group');
//					throw new Exception('unable to add form group');
					$target_form_id = 0;
					return NULL;
				} else {
					$target_form_id = abs($res);
				}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target form id #' . $target_form_id);
			} else {
				// form id exists - update it
				$form_group['ID'] = $target_form_id;
				// TODO: update guid
				$res = wp_update_post($form_group, TRUE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updated form group res=' . var_export($res, TRUE));
				if (is_wp_error($res)) {
					$this->wp_error = $res;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: unable to update Form Group');
##					throw new Exception('unable to update form group');
##					return NULL;
				}
			}

			// write data to spectrom_sync for future lookups
			// this will update the target_content_id if it didn't match the $target_content_id from above
			$data = array(
				'site_key' => SyncApiController::get_instance()->source_site_key,
				'source_content_id' => $source_form_id,
				'target_content_id' => $target_form_id,
				'content_type' => 'post',
				'target_site_key' => SyncOptions::get('site_key'),
			);
			$this->sync_model->save_sync_data($data);

			// Form Group has been created/updated, now update the form fields

			// delete fields on Target that are not in the list from Source
			$target_fields = $this->load_form_fields($target_form_id);	// ;here;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target fields=' . var_export($target_fields, TRUE));
			foreach ($target_fields as $target_field) {
				// for this model, $field_id is the post ID of the 'acf-field' post type with a post_parent of $group_id
				$target_field_id = abs($target_field['ID']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating target field id #' . $target_field_id . '=' . $target_field['post_name']);

				$found = FALSE;
				foreach ($acf_data as $field_id => $form_field) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' searching source field id #' . $field_id . ' => ' . var_export($form_field, TRUE));
					if ($form_field['post_name'] === $target_field['post_name']) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found matching field');
						$found = TRUE;
						break;
					}
				}
				if (!$found) {
					// field was in the Target list but not in the Source list - delete it
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' Source field does not exist- deleting Target field ' . $target_field_id);
					wp_delete_post($target_field_id, TRUE);				// this will delete any postmeta as well
					$this->sync_model->remove_sync_data($target_field_id);
				}

/*				$sync_data = $this->sync_model->get_sync_target_data($target_field_id);
				if (NULL !== $sync_data) {
					$source_field_id = abs($sync_data->source_content_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found source field id #' . $source_field_id);
					// look for the $source_field_id in the $acf_data
					$found = FALSE;
					foreach ($acf_fields as $source_field) {
						if (abs($source_field['ID']) === $source_field_id) {
							$found = TRUE;
							break;
						}
					}
					if (!$found) {
						// field was in the Target list but not in the Source list - delete it
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' Source field does not exist- deleting Target field');
						wp_delete_post($target_field_id, TRUE);				// this will delete any postmeta as well
						$this->sync_model->remove_sync_data($target_field_id);
					}
				} */
			} // foreach $target_fields

			// now add/update all of the form fields provided in API call
			foreach ($acf_data as $field_id => $form_field) {
				// form field ID from the Source site
				$source_field_id = abs($form_field['ID']);
				$form_field_name = $form_field['post_name'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' field id=' . $source_field_id . ' field name="' . $form_field_name . '"');

				$target_content_id = $this->get_form_id_from_source_id($source_field_id);
				$target_field_id = $this->get_form_id_from_name($form_field_name);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' content id=' . $target_content_id . ' field_id=' . $target_field_id);
## ;here;
				if (0 === $target_field_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' Note: cannot find Field ID from Field ID ' . $form_field_name . ' - adding');
##					continue;
##					return NULL;
				}

				if ($target_content_id !== $target_field_id) {
if (0 !== $target_content_id)													#!#
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' previous target_content_id value no longer valid'); #!#
					// $target_field_id is the authoritative value, we'll go with that
				}

				if (0 === $target_field_id) {
					// field id not found on Target - create it
					unset($form_field['ID']);
					$form_field['post_parent'] = $target_form_id;		// post parent is the Group ID
					$res = wp_insert_post($form_field, TRUE);
					if (is_wp_error($res)) {
						$this->wp_error = $res;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: unable to add form field');
##						throw new Exception('unable to add form field');
						continue;
##						return NULL;
					}

					$target_field_id = abs($res);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target field id #' . $target_field_id);
				} else {
					// field id exists - update it
					$form_field['ID'] = $target_field_id;
					$form_field['post_parent'] = $target_form_id;		// post parent is the Group ID
					$res = wp_update_post($form_field, TRUE);
					if (is_wp_error($res)) {
						$this->wp_error = $res;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: unable to update form field ');
##						throw new Exception('unable to update form field');
						continue;
##						return NULL;
					}
				}

				// write data to spectrom_sync for future lookups
				$data = array(
					'site_key' => SyncApiController::get_instance()->source_site_key,
					'source_content_id' => $source_field_id,
					'target_content_id' => $target_field_id,
					'content_type' => 'post',
					'target_site_key' => SyncOptions::get('site_key'),
				);
				$this->sync_model->save_sync_data($data);
			} // foreach $acf_form
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' completed processing for ACF Form and Fields');
		} // foreach($group_ids)

		// return the found or created post ID to caller
		return $target_form_id;
	}

	/**
	 * Returns a list of the field IDs based on a parent Group ID. For this model, we return a list
	 * of the Post ID values for the form fields with the Group ID as the parent.
	 * @param int $group_id The post ID of the Form Group of found; otherwise NULL
	 */
	public function get_field_ids($group_id)
	{
		// TODO: check if used
		$args = array(
			'post_parent' => $group_id,
			'numberposts' => -1,
		);
		$children = get_children($args, ARRAY_A);
		if (0 === count($children)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no children found for parent #' . $group_id);
			return NULL;
##			throw new Exception('no chldren found for parent ' . $group_id);
		}

		$res = array();
		foreach ($children as $form_field) {
			$res[] = abs($form_field['ID']);
		}
		return $res;
	}

	/**
	 * Locates an existing ACF form via the WPSiteSync table
	 * @param int $id The Post ID of the Form to find
	 * @param string $title The post_title of the Form to find
	 */
	public function find_form($id = 0, $title = '')
	{
		## not used
		if (0 !== $id) {
			$form = get_post($id);
			if (NULL !== $form)
				return $form;
		}
		return FALSE;
	}


	/**
	 * Looks through ACF data for content ID information and Field Groups to add to API data
	 * @param array $data Array containing Push information
	 * @param SyncACFSourceApi $source_api Source API implementation instance
	 */
	public function find_form_data(&$data, $source_api)
	{
		// look in meta data for ACF form info
		$all_taxonomies = NULL;

		// build an array containing the work queue to process and seed it with
		// the postmeta from the main post
		// certain field types like 'user' can add usermeta to the work queue
		$queue = array();
		$work_obj = new stdClass();
		$work_obj->meta = &$data['post_meta'];
		$work_obj->type = 'post';
		$work_obj->desc = 'initial postmeta for ' . $data['post_data']['ID'];
		$queue[] = $work_obj;

		for (; count($queue) > 0; ) {
			$work_item = array_shift($queue);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' working on queue item: "' . $work_item->desc . '"');

			foreach ($work_item->meta as $meta_key => $meta_value) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $meta_key . ' val=' . var_export($meta_value, TRUE));
				$meta_data = count($meta_value) > 0 ? $meta_value[0] : '';
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta_data=' . var_export($meta_data, TRUE));
				if ('field_' == substr($meta_data, 0, 6) && 19 === strlen($meta_data)) {
					$meta_field = $meta_data;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta field="' . $meta_field . '"');
					// look up the ACF field description
					$acf_field_row = $this->get_field_object($meta_field);

					if (NULL === $acf_field_row || empty($acf_field_row['type'])) {
						// TODO: ^^ was- empty($acf_field['type'])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no field row or no field type');
						continue;
					}

					$data_key = substr($meta_key, 1);				// meta_key for this field's data
					$data_value = $data['post_meta'][$data_key];	// grab associated data from post_meta array
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data key=' . $data_key . ' value=' . var_export($data_value, TRUE));
					$data_value = count($data_value) > 0 ? $data_value[0] : '';
					$acf_field_data = maybe_unserialize($data_value);
//					$acf_field_data = $meta_value;		// $acf_field_row->meta_value;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' field data=' . var_export($acf_field_data, TRUE));
//					$acf_field = maybe_unserialize($acf_field_data);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' field=' . var_export($acf_field, TRUE));

					// add ACF form ids to the list
					$acf_id = abs($acf_field_row['ID']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ACF id=' . $acf_id);
					if (!in_array($acf_id, $source_api->acf_form_list)) {
						$this->acf_form_list[] = $acf_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' added to list: ' . implode(',', $this->acf_form_list));
					}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found field type: ' . $acf_field_row['type']); // TODO: was- $acf_field['type']);
					switch ($acf_field_row['type']) {
					// TODO: ^^ was- $acf_field['type']) {
					case 'file':
						$return_type = $this->get_field_return_type($meta_field);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' return type="' . $return_type . '" value=' . var_export($data_value, TRUE));
						$img_id = abs($data_value);
						$source_api->apirequest->send_media_by_id($img_id);
						break;

					case 'image':
						// add_image() to accept attachment_id
						$source_api->add_image($acf_field_row['name']);
						// TODO: ^^ was- $acf_field['name']);				// add the field name to the list of image fields
						break;

					case 'post_object':
						// verify  that the related post has already been Pushed
						$post_id = abs($acf_field_data);
						$sync_data = $this->sync_model->get_sync_data($post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id #' . $post_id . ' search results: ' . var_export($sync_data, TRUE));
						if (NULL === $sync_data) {
							// if this is NULL then the related post has not yet been Pushed
							$related_post = get_post($post_id, OBJECT);
							$name = '#' . $post_id . ' ' . $related_post->post_title;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' related content ' . $name);
							$source_api->response->error_code(SyncACFApiRequest::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED, $name);
							return;
						}
						break;

					case 'relationship':
						if (!is_array($acf_field_data)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' form data is not an array');
						} else {
							foreach ($acf_field_data as $post_id) {
								// verify each of the related posts has already been Pushed
								$post_id = abs($post_id);
								$sync_data = $this->sync_model->get_sync_data($post_id);
								if (NULL === $sync_data) {
									// if this is NULL then the related post has not yet been Pushed
									$related_post = get_post($post_id, OBJECT);
									$name = '#' . $post_id . ' ' . $related_post->post_title;
									$source_api->response->error_code(SyncACFApiRequest::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED, $name);
									return;
								}
							}
						}
						break;

					case 'taxonomy':
						if (!empty($acf_field_data)) {
							// add taxonomy data to API content
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' tax data=' . var_export($data['taxonomies'], TRUE));
							if (NULL === $all_taxonomies)		// only need to do this once
								$all_taxonomies = $this->sync_model->get_all_taxonomies();
							if (!is_array($acf_field_data))
								$acf_field_data = array($acf_field_data);
							foreach ($acf_field_data as $tax_id) {
								$tax_id = abs($tax_id);
								$term = get_term($tax_id, '', OBJECT);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' tax id #' . $tax_id . ' term=' . var_export($term, TRUE));
								if (!is_wp_error($term)) {
									$tax_name = $term->taxonomy;
									if ($all_taxonomies[$tax_name]->hierarchical) {
										// this is a hierarchical taxonomy
										$data['taxonomies']['hierarchical'][] = $term;

										// look up the full list of term parents
										$parent = $term->parent;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' parent=#' . $parent);
										while (0 !== $parent) {
											$term = get_term_by('id', $parent, $tax_name, OBJECT);
											$data['taxonomies']['lineage'][$tax_name][] = $term;
											$parent = $term->parent;
										}
									} else {
										$data['taxonomies']['flat'][] = $term_data;
									}
								} // is_wp_error()
							} // foreach
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' tax data=' . var_export($data['taxonomies'], TRUE));
						} // !empty()
						break;

					case 'user':
						$meta_name = substr($meta_key, 1);
						$user_id = isset($data['post_meta'][$meta_name][0]) ? abs($data['post_meta'][$meta_name][0]) : 0;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' looking up user ' . $user_id);
						if (0 !== $user_id) {
							$user = get_user_by('id', $user_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' user: ' . var_export($user->data, TRUE));
							if (FALSE !== $user) {
								$data[self::DATA_USER_INFO][$user_id] = $user->data;
								// usermeta
								$user_meta = get_user_meta($user_id);
								$data[self::DATA_USER_META][$user_id] = $user_meta;
								global $wpdb;
								$data[self::DATA_PREFIX] = $wpdb->base_prefix;

								// add the metadata to the work queue
								$work_obj = new stdClass();
								$work_obj->meta = &$data[self::DATA_USER_META][$user_id];
								$work_obj->desc = 'usermeta for user #' . $user_id;
								$queue[] = $work_obj;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' added to work queue. ' . count($queue) . ' items now in queue');
							}
						}
						break;

					// note: the 'page_link' type is handled on the Target by Content lookup. no need to send additional data
					} // switch ($acf_field['type']
				} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' skipping...');
				}
			} // foreach
		} // for ( ; count($queue) ; )

		// now that the data has been processed, add information on the form itself to the API data
		if (0 !== count($this->form_group)) {
			$data[self::DATA_GROUP] = $this->form_group;
//			$data[self::DATA_GROUP_ID] = $this->form_group['post_name'];
		}
		if (0 !== count($this->form_fields)) {
			$data[self::DATA_FORM] = $this->form_fields;
		}
	}

	/**
	 * Get the post ID for the form based on the Group ID value.
	 * @param string $group_id The form's Group ID. If NULL, running on the Target site, get the Group ID from POST data
	 * @return int The post ID of the Form Group if found; otherwise NULL
	 */
	public function get_form_id($group_id = NULL)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: deprecated- use get_form_id_from_name()');
return NULL;
		if (NULL === $group_id) {
			// we're on the Target. The Group ID is found within the POST data
			$input = new SyncInput();
			$acf_field_group = $input->post_raw(self::DATA_GROUP);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' field group=' . var_export($acf_field_group, TRUE));
			return abs($acf_field_group['ID']);
		}

		global $wpdb;
		$sql = "SELECT *
				FROM `{$wpdb->posts}`
				WHERE `post_name`=%s AND
					`post_status`='publish'
				LIMIT 1";

		$res = $wpdb->get_row($q = $wpdb->prepare($sql, $group_id), ARRAY_A);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sql=' . $q . ' res=' . var_export($res, TRUE));

		if (0 === count($res)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' cannot find Group ID #' . $group_id);
			return NULL;
			throw new Exception('cannot find Group ID in posts: ' . $group_id);
		}
		return abs($res['ID']);
	}

	/**
	 * Uses the SyncModel to find the given form's ID from the Source site's ID
	 * @param int $source_form_id The Post ID of the ACF Form on the Source site
	 * @return Object An object representing the spectrom_sync table record or 0 if the record is not found
	 */
	public function get_form_id_from_source_id($source_form_id)
	{
		$sync_data = $this->sync_model->get_sync_data($source_form_id, SyncApiController::get_instance()->source_site_key);
		if (NULL !== $sync_data)
			return abs($sync_data->target_content_id);
		return 0;
	}
	/**
	 * Uses $wpdb to find the given form's ID from the Source site's Group name
	 * @param string $group The group name in the form 'group_##############'
	 * @return int The post ID of the form with the given group name if found; otherwise 0
	 */
	public function get_form_id_from_name($group)
	{
		global $wpdb;
		$sql = "SELECT `ID`
				FROM `{$wpdb->posts}`
				WHERE `post_name`=%s AND
					`post_status`='publish'
				LIMIT 1";
		$q = $wpdb->prepare($sql, $group);
		$res = $wpdb->get_col($q);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sql=' . $q . ' res=' . var_export($res, TRUE));
		if (1 === count($res))
			return abs($res[0]);
		return 0;
	}

	/**
	 * Loads the Form's fields for a form by finding it's children
	 * @param int $target_form_id The Form's post ID to search for
	 * @return array|NULL The postmeta data if found or NULL if not found
	 */
	public function load_form_fields($target_form_id)
	{
		$args = array(
			'post_parent' => $target_form_id,
			'post_status' => 'any',
			'post_type' => 'acf-field',
			'numberposts' => -1,
		);
		$res = get_children($args, ARRAY_A);
		if (NULL === $res) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' cannot find form fields for #' . $target_form_id);
##			throw new Exception('cannot find form fields');
		}
		return $res;
	}

	/**
	 * Finds the field name then loads all fields for the Form Group
	 * @param string $field The field id field_####
	 * @return string The Form Group id that the $field was found in or NULL if not found
	 */
	private function load_form_fields_by_name($field)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' looking for field "' . $field . '"');
		// first, check to see if the field and it's parent Form Group are already loaded
		foreach ($this->form_fields as $group_id => $field_list) {
			foreach ($field_list as $field_item) {
				if ($field_item['post_name'] === $field) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found field "' . $field . '" in Group "' . $group_id . '"');
					return $group_id;
				}
			}
		}

		// field not found - look it up
		global $wpdb;

		$postname = sanitize_key($field);
		$sql = "SELECT *
				FROM `{$wpdb->posts}`
				WHERE `post_name`=%s
				LIMIT 1";
		$field_row = $wpdb->get_row($q = $wpdb->prepare($sql, $postname), OBJECT);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' q=' . $q . ' res=' . var_export($field_row, TRUE));
		if (NULL === $field_row) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' field not found');
			return NULL;
##			throw new Exception('field not found');
		}
//		if (0 === count($field_row))
//			throw new Exception('no rows found');
		$parent = abs($field_row->post_parent);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' looking up parent ' . $parent);
		if (0 === $parent) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no parent');
			return NULL;
##			throw new Exception('no post parent');
		}
		$args = array(
			'post_parent' => $parent,
			'post_status' => 'any',
			'post_type' => 'acf-field',
			'numberposts' => -1,
		);

		$form_group = get_post($parent, ARRAY_A);
		$group_id = $form_group['post_name'];
		$this->form_group[$group_id] = $form_group;
		$this->form_fields[$group_id] = get_children($args, ARRAY_A);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found fields: ' . var_export($this->form_fields[$group_id], TRUE));
		return $group_id;
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
	 * @param string $name The name of the field within the form 'field_xxx'
	 * @return object stdClass instance of field data or NULL if not found
	 */
	public function get_field_object($name)
	{
		try {
			$group_id = $this->load_form_fields_by_name($name);
			if (NULL === $group_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find form field "' . $name . '"');
				return NULL;
			}
			foreach ($this->form_fields[$group_id] as $field) {
				if ($field['post_name'] === $name) {
					$obj = maybe_unserialize($field['post_content']);
					$obj['ID'] = $field['ID'];
					$obj['name'] = $field['post_excerpt'];
					$obj['title'] = $field['post_title'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning object: ' . var_export($obj, TRUE));
					return $obj;
				}
			}
		} catch (Exception $ex) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: field named "' . $name . '" not found- form missing or changed');
			// indicated that the form data does not exist on the Target yet
			return NULL;
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find field object "' . $name . '"');
##		throw new Exception('cannot find field object');
		return NULL;
	}

	/**
	 * Returns the field's return type for a given field. The return type is based on the field type.
	 * @param string $name The name of the field within the form 'field_xxx'
	 * @return string|NULL The field's return type or NULL if unable to obtain. Values can be: 'value', 'id, 'array', 'file', etc.
	 */
	public function get_field_return_type($name)
	{
		try {
			$group_id = $this->load_form_fields_by_name($name);
			if (NULL === $group_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find form field "' . $name . '"');
				return NULL;
			}
			foreach ($this->form_fields[$group_id] as $field) {
				if ($field['post_name'] === $name) {
					$content = $field['post_content'];
					$field_data = maybe_unserialize($content);
					if (!is_string($field_data)) {
						if (isset($field_data['return_format']))
							return $field_data['return_format'];
					} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' unable to unserialize field "' . $name . '"');
					}
					return NULL;
				}
			}
		} catch (Exception $ex) {
		}
	}
}

// EOF
