<?php

if (!class_exists('SyncACFTargetApi', FALSE)) {
	class SyncACFTargetApi extends SyncInput
	{
		/**
		 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
		 * @param int $target_post_id The post ID being created/updated via API call
		 * @param array $post_data Post data sent via API call
		 * @param SyncApiResponse $response Response instance
		 */
		public function handle_push($target_post_id, $post_data, $response)
		{
SyncDebug::log(__METHOD__."({$target_post_id}):" . __LINE__ . ' data=' . var_export($post_data, TRUE));
SyncDebug::log(__METHOD__.'() get=' . var_export($_GET, TRUE));
			// TODO: possibly move this into the pre_process() to verify form contents before storing them
			if ('push' === $this->get('action')) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post=' . var_export($_POST, TRUE));
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

				// check ACF db version on Source and Target for compatability
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking db version');
				$source_db_vers = $this->post('acf_version', FALSE); // isset($post_data['acf_version']) ? $post_data['acf_version'] : FALSE;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source_db_vers=' . $source_db_vers);
				if (FALSE === $source_db_vers || FALSE === strpos($source_db_vers, '.')) {
					$response->error_code(self::ERROR_ACF_NOT_INITIALIZED_SOURCE);
				}
				$target_db_vers = $acf_model->get_db_version();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target_db_vers=' . $target_db_vers);
				if (FALSE === $target_db_vers) {
					$response->error_code(self::ERROR_ACF_NOT_INITIALIZED_TARGET);
				}
				$source_vers = explode('.', $source_db_vers);
				$target_vers = explode('.', $target_db_vers);
				if (3 !== count($source_vers) || 3 != count($target_vers)) {
					$response->error_code(self::ERROR_ACF_DB_VERS_MISSING);
				}
				if ($source_vers[0] !== $target_vers[0] || $source_vers[1] !== $target_vers[1]) {
					$response->error_code(self::ERROR_ACF_DB_VERS_MISMATCH);
				}
				if ($response->has_errors()) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' error code: ' . $response->get_error_code());
					$response->send();
				}

				$sync_model = new SyncModel();
				$site_key = SyncApiController::get_instance()->source_site_key;

				$post_meta = $this->post_raw('post_meta');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . count($post_meta) . ' post meta entries');
				if (is_array($post_meta)) {
					foreach ($post_meta as $meta_key => $meta_value) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta key=' . $meta_key);
						$meta_data = count($meta_value) > 0 ? $meta_value[0] : '';
						if ('field_' == substr($meta_data, 0, 6) && 19 === strlen($meta_data)) {
							$meta_field = $meta_data;
							// look up the ACF field description
							$acf_field_row = $acf_model->get_field_object($meta_field); // $field_model->get_acf_object($meta_field);
							if (NULL !== $acf_field_row) {
								$acf_field_data = $acf_field_row->meta_value;
								$acf_field = maybe_unserialize($acf_field_data);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' field type=' . var_export($acf_field['type'], TRUE));
								if (empty($acf_field['type']))
									continue;

								// we have a type, do some preliminary setup
								$field_name = $acf_field['name'];
								$field_value = isset($post_meta[$field_name][0]) ? $post_meta[$field_name][0] : '';

								switch ($acf_field['type']) {
								case 'page_link':
									// TODO: lookup page- same behavior as 'post_object'?
									//break; -- fall through for now

								case 'post_object':
									// get the Source's post_id
									$post_id = abs($field_value);
									// look up the Target post_id
									$sync_data = $sync_model->get_sync_data($post_id, $site_key);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sync data=' . var_export($sync_data, TRUE));
									if (NULL === $sync_data) {
										$response->error_code(self::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED);
										$response->send();
									} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post_id#' . $target_post_id . ' updating "' . $field_name . '" from #' . $post_id . ' to ' . $sync_data->target_content_id);
										update_post_meta($target_post_id, $field_name, $sync_data->target_content_id);
									}
									break;

								case 'relationship':
									// look up related posts
									// array of post ids a:2:{i:0;s:2:"32";i:1;s:4:"1688";}
									$rel_data = stripslashes($field_value);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' rel_data=' . var_export($rel_data, TRUE));
									$relations = maybe_unserialize($rel_data);
									if (!is_array($relations)) {
										// TODO: report error that relationship data is bad??
										continue;
									}
									$save_relations = array();
									foreach ($relations as $rel_id) {
										$sync_data = $sync_model->get_sync_data($post_id, $site_key);
										if (NULL === $sync_data) {
											// TODO: indicate what data is missing
											$response->error_code(self::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED);
											$response->send();
										} else {
											// save the Target's post ID into the save array
											$save_relations[] = $sync_data->target_content_id;
										}
									}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post_id#' . $target_post_id . ' updating "' . $field_name . '" from #' . $rel_data . ' to ' . serialize($save_relations));
									update_post_meta($target_post_id, $field_name, $save_relations);
									break;

								case 'taxonomy':
									// TODO: lookup taxonomy, include in ['taxonomies'] array
									break;

								case 'user':
									$user_id = abs($field_value);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found user id ' . $user_id);
									if (0 !== $user_id) {
										$user_email = $this->_find_users_email($user_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' user info: ' . var_export($user_email, TRUE));
										if (NULL !== $user_email) {
											// search for a user on the Target with matching email address
											$target_user = get_user_by('email', $user_email);
											if (FALSE !== $target_user) {
												// found matching user, update postmeta for this field
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post_id#' . $target_post_id . ' updating "' . $field_name . '" from #' . $user_id . ' to ' . $target_user->ID);
												update_post_meta($target_post_id, $field_name, $target_user->ID);
											} else {
												// TODO: no user found with matching email - create the user
											}
										}
									}
									break;

								// note: 'image' type handled in media_processed() method
								}
							}
						} // 'field'
					} // foreach $post_meta
				} // is_array($post_meta)
			} // 'push' === action
		}

		/**
		 * Finds the user's email address within the 'acf_users' array by search for match user ID
		 * @param int $user_id The user ID to search for
		 * @return string|NULL The email address of a matching user ID or NULL if not found
		 */
		private function _find_users_email($user_id)
		{
			$acf_users = $this->post_raw('acf_users', array());
			foreach ($acf_users as $user_info) {
SyncDebug::log(__METHOD__.'() user_info=' . var_export($user_info, TRUE));
				if (abs($user_info['ID']) === $user_id)
					return $user_info['user_email'];
			}
			return NULL;
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
SyncDebug::log(__METHOD__."({$target_post_id}, {$attach_id}):" . __LINE__ . ' post= ' . var_export($_POST, TRUE));
			$field_id = $this->get('acf_field_id', NULL);
			if (NULL === $field_id && isset($_POST['acf_field_id']))
				$field_id = $_POST['acf_field_id'];
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
	}
} // class_exists

// EOF