<?php

/**
 * Handles API processing on the Target site by using the appropriate ACF Model class.
 * @package WPSiteSync
 * @author WPSiteSync.com
 */

if (!class_exists('SyncACFTargetApi', FALSE)) {
	class SyncACFTargetApi extends SyncInput
	{
		const OPTION_NAME = 'spectrom_sync_acf_meta_';

		private $sync_model = NULL;

		/**
		 * Check that everything is ready for us to process the Content Push operation on the Target
		 * @param array $post_data The post data for the current Push
		 * @param int $source_post_id The post ID on the Source
		 * @param int $target_post_id  The post ID on the Target
		 * @param SyncApiResponse $response The API Response instance for the current API operation
		 */
		public function pre_push_content($post_data, $source_post_id, $target_post_id, $response)
		{
			// check to see if Form Group is being edited
			$post_model = new SyncPostModel();
			if (0 !== $target_post_id && $post_model->is_post_locked($target_post_id)) {
				$user = $post_model->get_post_lock_user();
				$response->error_code(SyncACFApiRequest::ERROR_FORM_CONTENT_LOCKED, $user['user_login']);
			}
		}

		/**
		 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
		 * @param int $target_post_id The post ID being created/updated via API call
		 * @param array $post_data Post data sent via API call
		 * @param SyncApiResponse $response Response instance
		 */
		public function handle_push($target_post_id, $post_data, $response)
		{
			// TODO: move to push_complete so we know images exist

SyncDebug::log(__METHOD__."({$target_post_id}):" . __LINE__ . ' data=' . var_export($post_data, TRUE));
SyncDebug::log(__METHOD__.'() get=' . var_export($_GET, TRUE));
			// TODO: possibly move this into the pre_process() to verify form contents before storing them
			if ('push' === $this->get('action')) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post=' . SyncDebug::arr_sanitize($_POST));
//				WPSiteSync_ACF::get_instance()->load_class('acffieldmodel');
//				$field_model = new SyncACFFieldModel();
				WPSiteSync_ACF::get_instance()->load_class('acfmodelfactory');
				$acf_model = SyncACFModelFactory::get_model($this->post('acf_model_id', NULL));

				// TODO: can remove this once the ACF Pro model is implemented
				if (NULL === $acf_model) {
					$response->error_code(SyncACFApiRequest::ERROR_ACF_PRO_NOT_SUPPORTED);
					$response->send();
					return;
				}

				// if there's no ACF data in the Push content, remove 'push_complete' hook and exit
				if (!$acf_model->is_acf_sync()) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' this sync does not contain ACF data');
					remove_action('spectrom_sync_api_process', array(WPSiteSync_ACF::get_instance(), 'push_processed'), 10, 3);
					return;
				}

				// check ACF db version on Source and Target for compatability
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking db version');
				$source_db_vers = $this->post(SyncACFModelInterface::DATA_VERSION, FALSE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source_db_vers=' . $source_db_vers);
				if (FALSE === $source_db_vers || FALSE === strpos($source_db_vers, '.')) {
					$response->error_code(SyncACFApiRequest::ERROR_ACF_NOT_INITIALIZED_SOURCE);
				}
				$target_db_vers = $acf_model->get_db_version();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target_db_vers=' . $target_db_vers);
				if (FALSE === $target_db_vers) {
					$response->error_code(SyncACFApiRequest::ERROR_ACF_NOT_INITIALIZED_TARGET);
				}

				// check version information
				if ('' === $source_db_vers) {
					$response->error_code(SyncACFApiRequest::ERROR_ACF_DB_VERS_MISSING);
				} else {
					// versions have to match, even when not in strict mode
					if (0 !== version_compare($source_db_vers, $target_db_vers)) {
						$response->error_code(SyncACFApiRequest::ERROR_ACF_DB_VERS_MISMATCH);
					}
				}

				if ($response->has_errors()) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' error code: ' . $response->get_error_code());
					$response->send();
				}

				// all checks passed - we can safely proceed with processing the ACF data

				$this->sync_model = new SyncModel();
				$source_site_key = SyncApiController::get_instance()->source_site_key;

				$post_meta = $this->post_raw('post_meta');
				$acf_group = $this->post_raw(SyncACFModelInterface::DATA_GROUP);
				$acf_form = $this->post_raw(SyncACFModelInterface::DATA_FORM);
##				$source_form_id = $acf_model->get_form_id();		// get the form's post ID

				// update the ACF Form Group and Form Fields from API data
##				$acf_model->find_create_form($source_form_id, $post_data);
				$acf_model->update_form($acf_group, $acf_form);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' forms updated');

				// build an array containing the work queue to process and seed it with
				// the postmeta from the main post
				// certain field types like 'user' can add usermeta to the work queue
				$queue = array();
				$work_obj = new stdClass();
				$work_obj->meta = $post_meta;
				$work_obj->id = $target_post_id;
				$work_obj->type = SyncACFDataManager::TYPE_POST;
				$work_obj->desc = 'initial postmeta for ' . $target_post_id;
				$queue[] = $work_obj;

				// work through all items in the queue
				while (0 !== count($queue)) {
					$work_obj = array_shift($queue);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . count($work_obj->meta) . ' meta entries for work object "' . $work_obj->desc . '"');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta=' . var_export($work_obj->meta, TRUE));
					$dm = new SyncACFDataManager($work_obj->id, $work_obj->type);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing queue item ' . $work_obj->desc);

					if (is_array($work_obj->meta)) {
						foreach ($work_obj->meta as $meta_key => $meta_value) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta key=' . $meta_key . ' value=' . var_export($meta_value, TRUE));
							$meta_data = count($meta_value) > 0 ? $meta_value[0] : '';
							if ('field_' === substr($meta_data, 0, 6) && 19 === strlen($meta_data)) {
								$meta_field = $meta_data;
								// look up the ACF field description
								$acf_field = $acf_model->get_field_object($meta_field); // $field_model->get_acf_object($meta_field);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' acf field=' . var_export($acf_field, TRUE));
								if (NULL !== $acf_field) {
									if (empty($acf_field['type']))
										continue;

									// we have a type, do some preliminary setup
									$field_name = $acf_field['name'];
									$field_value = isset($work_obj->meta[$field_name][0]) ? $work_obj->meta[$field_name][0] : '';
									$field_value = wp_unslash($field_value);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' field type=' . var_export($acf_field['type'], TRUE) . ' name=' . $field_name . ' value=' . var_export($field_value, TRUE));

									switch ($acf_field['type']) {
									case 'page_link':
										// TODO: lookup page- same behavior as 'post_object'?
										//break; -- fall through for now

									case 'file':									// ACF 5.9.0
									case 'image':									// ACF 5.9.0
									case 'post_object':
										// get the Source's post_id
										$post_id = abs($field_value);
										if (0 !== $post_id) {						// can be 0 if not required
											// look up the Target post_id
											$sync_data = $this->sync_model->get_sync_data($post_id, $source_site_key);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sync data=' . var_export($sync_data, TRUE));
//if (390 === $post_id) $sync_data = NULL;
											if (NULL === $sync_data) {
												// save this for processing during push_complete
												$this->save_meta_content($dm->get_type(), $target_post_id, $meta_data, $field_name, $post_id);
##												$response->error_code(SyncACFApiRequest::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED);
##												$response->send();
											} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post_id#' . $target_post_id . ' updating "' . $field_name . '" from #' . $post_id . ' to #' . $sync_data->target_content_id);
												$dm->set_meta($field_name, abs($sync_data->target_content_id));
##												update_post_meta($target_post_id, $field_name, $sync_data->target_content_id);
											}
										}
										break;

									case 'relationship':
										// look up related posts
										// array of post ids a:2:{i:0;s:2:"32";i:1;s:4:"1688";}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' value=' . var_export($field_value, TRUE));
										$relations = maybe_unserialize($field_value);
										if (!is_array($relations)) {
											// TODO: report error that relationship data is bad??
										} else {
											$save_relations = array();
											foreach ($relations as $rel_id) {
												$sync_data = $this->sync_model->get_sync_data($post_id, $source_site_key);
												if (NULL === $sync_data) {
													// TODO: indicate what data is missing
													$response->error_code(SyncACFApiRequest::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED);
													$response->send();
												} else {
													// save the Target's post ID into the save array
													$save_relations[] = $sync_data->target_content_id;
												}
											}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post_id#' . $target_post_id . ' updating "' . $field_name . '" from #' . $rel_data . ' to ' . serialize($save_relations));
											$dm->set_meta($field_name, $save_relations);
##											update_post_meta($target_post_id, $field_name, $save_relations);
										}
										break;

									case 'taxonomy':
										// Note: taxonomy itself has already been created in SyncApiController::_process_taxonomies()
										// only need to update the metadata
										// TODO: lookup taxonomy, include in ['taxonomies'] array
										$field_value = maybe_unserialize($field_value);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' value=' . var_export($field_value, TRUE));
										if (empty($field_value)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' taxonomy value is empty...skipping taxonomy lookup');
										} else {
											if (is_array($field_value)) {
												$multi = TRUE;
											} else {
												$multi = FALSE;
												$field_value = array($field_value);
											}
											$new_tax_data = array();
											foreach ($field_value as $tax_id) {
												$term_id = abs($tax_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' taxonomy term=' . $term_id);
												if (0 !== $term_id) {
													$sync_data = $this->sync_model->get_sync_data($term_id, $source_site_key, 'term');
													if (NULL === $sync_data) {
														// term not found
														$response->error_code(SyncACFApiRequest::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED);
														$response->send();
													} else {
														// if it's an array ($multi), then convert back to strings
														$new_tax_data[] = $multi ? strval(abs($sync_data->target_content_id)) : abs($sync_data->target_content_id);
													}
												} else {
													$response->error_code(SyncACFApiRequest::ERROR_INVALID_TERM_ID);
													$response->send();
												}
											} // foreach

											// if the entry is not intended to hold multiple values,
											// convert it back to scaler
											if (!$multi)
												$new_tax_data = $new_tax_data[0];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' writing tax data #' . ($multi ? implode(', #', $new_tax_data) : $new_tax_data) . ' to key "' . $field_name . '"');
											$dm->set_meta($field_name, $new_tax_data);
##											update_post_meta($target_post_id, $field_name, $new_tax_data);
										} // empty($field_value)
										break;

									case 'user':
										$user_id = abs($field_value);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found user id ' . $user_id);
										if (0 !== $user_id) {
											// first, update the meta data
											$user_email = $this->_find_users_email($user_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' user info: ' . var_export($user_email, TRUE));
											if (NULL !== $user_email) {
												// search for a user on the Target with matching email address
												$target_user = get_user_by('email', $user_email);
												$target_user_id = 0;
												if (FALSE !== $target_user) {
													// found matching user, update postmeta for this field
													$target_user_id = abs($target_user->ID);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post_id#' . $target_post_id . ' updating "' . $field_name . '" from #' . $user_id . ' to ' . $target_user_id);
													$dm->set_meta($field_name, $target_user_id);
##													update_post_meta($target_post_id, $field_name, $target_user->ID);
												} else {
													// TODO: no user found with matching email - create the user
													$api_user_list = $this->post_raw(SyncACFModelInterface::DATA_USER_INFO, array());
													if (!isset($api_user_list[$user_id])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: user ID ' . $user_id . ' not found in API data');
														$this->response->error_code(SyncACFApiRequest::ERROR_MISSING_USER_ID, $user_id);
														return;
													}
													$api_user_data = $api_user_list[$user_id];
													$new_user_id = wp_create_user($api_user_data['user_login'], $api_user_data['user_pass'],
														$api_user_data['user_email']);
													if (is_wp_error($new_user_id)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' error creating Target user: ' . var_export($new_user_id, TRUE));
													} else {
														// if user successfully created, update value so $queue can be updated
														$target_user_id = abs($new_user_id);
														// write data for future reference
														$data = array(
															'site_key' => SyncApiController::get_instance()->source_site_key,
															'source_content_id' => $user_id,
															'target_content_id' => $target_user_id,
															'content_type' => 'user',
															'target_site_key' => SyncOptions::get('site_key'),
														);
														$this->sync_model->save_sync_data($data);
													}
												}
											} // NULL !== $user_email

											$api_user_meta = $this->post_raw(SyncACFModelInterface::DATA_USER_META, array());
											if (!isset($api_user_meta[$user_id])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: meta data for user ID ' . $user_id . ' not found in API data');
												$this->response->error_code(SyncACFApiRequest::ERROR_MISSING_USER_META_DATA, $user_id);
												return;
											}

											// second, add meta data to work queue
											if (0 !== $target_user_id) {
												$new_work_obj = new stdClass();
												// this uses Source user ID, since that's how the API data is presented
												$new_work_obj->meta = $api_user_meta[$user_id];
												$new_work_obj->id = $target_user_id;
												$new_work_obj->type = SyncACFDataManager::TYPE_USER;
												$new_work_obj->desc = 'usermeta for ' . $user_id;
												$queue[] = $new_work_obj;
											}
										} // 0 !== user_id
										break;
									} // switch
								} // NULL !== $acf_field_row
							} // 'field'

							// special handling for user capabilities
							if (SyncACFDataManager::TYPE_USER === $work_obj->type &&
								'_capabilities' === substr($meta_key, -13)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' working on usermeta capabilitis: ' . var_export($meta_data, TRUE));
								global $wpdb;
								if ($this->post(SyncACFModelInterface::DATA_PREFIX) !== $wpdb->base_prefix) {
									// only need to update meta if the prefixes are different
									$new_key = $wpdb->base_prefix . '_capabilities';
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating "' . $meta_key . '" to "' . $new_key . '"');
									$dm->set_meta($new_key, maybe_unserialize($meta_data));
								}
							}
						} // foreach $post_meta
					} // is_array($post_meta)
				} // while()
			} // 'push' === action
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' completed');
		}

		/**
		 * Finds the user's email address within the 'acf_users' array by search for match user ID
		 * @param int $user_id The user ID to search for
		 * @return string|NULL The email address of a matching user ID or NULL if not found
		 */
		private function _find_users_email($search_id)
		{
			$acf_users = $this->post_raw(SyncACFModelInterface::DATA_USER_INFO, array());
			foreach ($acf_users as $user_id => $user_info) {
SyncDebug::log(__METHOD__.'() user id=' . $user_id . ' info=' . var_export($user_info, TRUE));
				if (abs($user_info['ID']) === $search_id)
					return $user_info['user_email'];
			}
			return NULL;
		}

		private function _update_user($source_user_id, $user_info, $user_meta)
		{
// TODO: still needed?
			$target_user_id = 0;
			$sync_data = $this->sync_model->get_sync_data($user_id, $this->source_site_key, 'user');
			if (NULL === $sync_data) {
				// not found. need to create
				$res = wp_create_user($user_info['user_login'], $user_info['user_pass'], $user_info['user_email']);
				if (FALSE !== $res) {
					$target_user_id = $res->ID;
				}
			} else {
			}
//				get_sync_data($post_id, $source_site_key);
		}

		/**
		 * API post processing callback.
		 * @param string $action The API action, something like 'push', 'media_upload', 'auth', etc.
		 * @param SyncApiResponse $response The Response object
		 * @param SyncApiController $apicontroller
		 */
		public function push_processed($action, $response, $apicontroller)
		{
SyncDebug::log(__METHOD__."('{$action}'...):" . __LINE__);
			if ('push' === $action) {
				// the Push operation has been handled
				// check relational data items
			}
		}

		/**
		 * Callback for 'spectrom_sync_media_processed', called from SyncApiController->upload_media()
		 * @param int $target_post_id The Post ID of the Content being pushed
		 * @param int $attach_id The media's Post ID
		 */
		public function media_processed($target_post_id, $attach_id, $media_id)
		{
SyncDebug::log(__METHOD__."({$target_post_id}, {$attach_id}):" . __LINE__ . ' post= ' . SyncDebug::arr_sanitize($_POST));
			$field_id = $this->get('acf_field_id', NULL);
			$post_field_id = $this->post('acf_field_id', NULL);
			if (NULL === $field_id && NULL !== $post_field_id)
				$field_id = $post_field_id;
			$old_attach_id = $this->get_int('attach_id', 0);
			if (0 === $old_attach_id)
				$old_attach_id = abs($_POST['attach_id']);

###		$api_controller = SyncApiController::get_instance();
###		$site_key = $api_controller->source_site_key;

###		$model = new SyncModel();
###		$image_data = $model->get_sync_data(160, $site_key, 'media');

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $target_post_id . ' old_attach_id=' . $old_attach_id . ' attach_id=' . $attach_id . ' field_id=' . var_export($field_id, TRUE));
			if (/*NULL !== $image_data && */ 0 !== $attach_id && !empty($field_id)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . " update_post_meta({$target_post_id}, {$field_id}, {$attach_id}, {$old_attach_id})");
				update_post_meta($target_post_id, $field_id, $attach_id, $old_attach_id);
			}
		}

		/**
		 * Handle 'push_complete' API calls.
		 * @param int $source_post_id Post ID on the Source site.
		 * @param int $target_post_id Post ID on the Target site.
		 * @param SyncApiResponse $response The response object for API results
		 */
		public function push_complete($source_post_id, $target_post_id, $response)
		{
// need:
// source site key
// acf_model
SyncDebug::log(__METHOD__."({$source_post_id}, {$target_post_id}):" . __LINE__);
			$data = $this->get_meta_content($target_post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta data=' . var_export($data, TRUE));

			if (NULL === $this->sync_model)
				$this->sync_model = new SyncModel();

			$source_site_key = $data['source_site_key'];
			$acf_model = SyncACFModelFactory::get_model($data['acf_model']);

			foreach ($data as $field_id => $entry) {
				if ('field_' === substr($field_id, 0, 6) && 19 === strlen($field_id)) {
					$dm = new SyncACFDataManager($entry->owner_id, $entry->type);

					$acf_field = $this->acf_model->get_field_object($entry->field_name);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' acf_field=' . var_export($acf_field, TRUE));

					switch ($acf_field['type']) {
					case 'file':									// ACF 5.9.0
					case 'image':									// ACF 5.9.0
					case 'post_object':
						$sync_data = $this->sync_model->get_sync_data($entry->meta_value, $source_site_key);
						if (NULL !== $sync_data) {
							$dm->set_meta($entry->meta_key, abs($sync_data->target_content_id));
						} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find post id #' . $entry->meta_value);
						}
						break;

					// Note: no need to process users- all user data is included in intitial push operation

					default:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: unrecognized field type "' . $acf_field['type'] . '" for field: ' . $field_id);
						break;
					}
				} // if ('field_')
			} // foreach
			$this->remove_meta_content($target_post_id);
		}

		/**
		 * Saves meta content for later processing during 'push_complete' API call
		 * @param string $type Type of meta data. One of 'post' or 'user'
		 * @param int $target_post_id Post ID being updated via Push operation.
		 * @param string $field_name The name of the ACF field, 'field_###'
		 * @param string $meta_key The meta_key value
		 * @param multi $field_value The value of the meta field that is to be adjusted, such as a Post ID or User ID
		 */
		private function save_meta_content($type, $target_post_id, $field_name, $meta_key, $field_value)
		{
//	$post_id, $field_name, $field_val)
			$entry = new stdClass();
			$entry->type = $type;
			$entry->post_id = $target_post_id;
			$entry->field_name = $field_name;
			$entry->meta_key = $meta_key;
			$entry->meta_value = $meta_value;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' saving meta content: ' . var_export($entry, TRUE));

			$option_name = self::OPTION_NAME . $target_post_id;
			$data = get_option($option_name, array());
			if (!isset($data['model'])) {
				$data['model'] = $this->post('acf_model_id');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding acf model id: ' . $data['model']);
			}
			if (!isset($data['source_site_key'])) {
				$data['source_site_key'] = SyncApiController::get_instance()->source_site_key;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source site key: ' . $data['source_site_key']);
			}

			$data[$field_name] = $entry;

			update_option($option_name, $data);
		}

		private function get_meta_content($target_post_id)
		{
			$option_name = self::OPTION_NAME . $target_post_id;
			$data = get_option($option_name, array());
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' retrieved stored meta content: ' . var_export($data, TRUE));
			return $data;
		}

		private function remove_meta_content($target_post_id)
		{
			$option_name = self::OPTION_NAME . $target_post_id;
			delete_option($option_name);
		}
	}
} // class_exists

// EOF