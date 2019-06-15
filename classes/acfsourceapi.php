<?php

if (!class_exists('SyncACFSourceApi', FALSE)) {
	class SyncACFSourceApi extends SyncInput
	{
		private $_acf_pro = NULL;				// when TRUE using ACF Pro; otherwise FALSE
		private $_img_field_list = array();		// the list of image IDs to be processed and pushed
		public $acf_form_list = array();		// the list of ACF form IDs to be processed and pushed

		private $_acf_field_id = NULL;			// the ACF field id, used in processing upload_image API calls

		/**
		 * Called by Source before the Content is processed. Allows for the creation of the ACF Form and can return an error if there's a problem syncing it.
		 * @param array $post_data The array of post data sent via the API call
		 * @param int $source_post_id The Post ID of the Content on the Source
		 * @param int $target_post_id The Post ID of the Content on the Target
		 * @param SyncApiResponse $response The API response object
		 */
		public function pre_process(&$post_data, $source_post_id, $target_post_id, SyncApiResponse $response)
		{
SyncDebug::log(__METHOD__."({$source_post_id}, {$target_post_id})");

			// TODO: handle all forms sent with Push operation
			$acf_data = $this->post_raw('acf_data', array());
			if (empty($acf_data) || !is_array($acf_data)) {
				return;
				// can't raise an error since not all push requests have ACF data. just abort processing instead
				$response->error_code(self::ERROR_NO_FORM_DATA);
				$response->send();
			}

			WPSiteSync_ACF::get_instance()->load_class('acfformmodel');
			$acf_form_model = new SyncACFFormModel();

			// process all forms found in the ['acf_data'] array
			foreach ($acf_data as $acf_form) {
				$acf_id = abs($acf_form['id']);
				if (0 === $acf_id) {
					$response->error_code(self::ERROR_NO_FORM_ID);
					$response->send();
				}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' acf id=' . $acf_id . ' form data: ' . var_export($acf_form, TRUE));
				// this will find an existing form and update it, or create it if not found
				$target_form_id = $acf_form_model->find_create_form($acf_id /*$source_post_id*/, $acf_form);
				if (NULL === $target_form_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' got error: ' . var_export($acf_form_model->wp_error, TRUE));
					$response->error_code(self::ERROR_CANNOT_CREATE_FORM);
					$response->send();
				}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found content id #' . $target_form_id);
			}
			return;
		}

		/**
		 * Handles pre-processing on Source of ACF specific meta data for Push operations
		 * @param array $data The data being Pushed to the Target machine
		 * @param SyncApiRequest $apirequest Instance of the API Request object
		 * @return array The modified data
		 */
		public function filter_push_content($data, SyncApiRequest $apirequest)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing');

			$post_id = 0;
			if (isset($data['post_id']))						// present on Push operations
				$post_id = abs($data['post_id']);
			else if (isset($data['post_data']['ID']))			// present on Pull operations
				$post_id = abs($data['post_data']['ID']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $post_id);

			// determine if using ACF or ACF Pro and instantiate appropriate model
			// use factory to construct a Form Model instance
			WPSiteSync_ACF::get_instance()->load_class('acfmodelfactory');
			$acf_model = SyncACFModelFactory::get_model();
			if (NULL === $acf_model) {
				$response = $apirequest->get_response();
				WPSiteSync_ACF::get_instance()->load_class('acfapirequest');
				$response->error_code(SyncACFApiRequest::ERROR_ACF_PRO_NOT_SUPPORTED);
				$response->send();
			}
			$data['acf_model_id'] = $acf_model->get_model_id();

			// 1. verify that we can detect the db version for ACF on Source
			$db_vers = $acf_model->get_db_version();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' db vers=' . var_export($db_vers, TRUE));
			if (FALSE === $db_vers || 3 !== count(explode('.', $db_vers))) {
				$response = $apirequest->get_response();
				WPSiteSync_ACF::get_instance()->load_class('acfapirequest');
				$response->error_code(SyncACFApiRequest::ERROR_ACF_NOT_INITIALIZED_SOURCE);
				// TODO: need to signal 'spectrom_sync_api_push_content' filter that processing was aborted
				$response->send();
			}
			$data['acf_version'] = $db_vers;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding db version ' . $db_vers);

			// 2. build a list of the image specific meta data and the ACF form ids
			$site_url = site_url();
			$apirequest->set_source_domain($site_url);

			// 2a. look for ACF metadata
			$acf_model->find_form_meta($data, $this);
/*
###			moved to SyncACFFormModel->find_form_meta()
			foreach ($data['post_meta'] as $meta_key => $meta_value) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $meta_key . ' val=' . var_export($meta_value, TRUE));
				$meta_data = count($meta_value) > 0 ? $meta_value[0] : '';
				if ('field_' == substr($meta_data, 0, 6) && 19 === strlen($meta_data)) {
					$meta_field = $meta_data;

					// look up the ACF field description
					$acf_field_row = $field_model->get_acf_object($meta_field);
					if (NULL === $acf_field_row)
						continue;
					$acf_field_data = $acf_field_row->meta_value;
					$acf_field = maybe_unserialize($acf_field_data);
					if (empty($acf_field['type']))
						continue;

					// add ACF form ids to the list
					$acf_id = abs($acf_field_row->post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ACF id=' . $acf_id);
					if (!in_array($acf_id, $this->acf_form_list)) {
						$this->acf_form_list[] = $acf_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' added to list: ' . implode(',', $this->acf_form_list));
					}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found field type: ' . $acf_field['type']);
					switch ($acf_field['type']) {
					case 'image':
						$this->_img_field_list[] = $acf_field['name'];				// add the field name to the list of image fields
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
###
*/

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' image fields: ' . implode(', ', $this->_img_field_list));
			add_filter('spectrom_sync_upload_media_fields', array($this, 'filter_upload_media_fields'), 10, 1);

			// look through the list of fields and add images to the Push operation
			// TODO: can this be moved up into the 'image' case above?
			foreach ($this->_img_field_list as $field_name) {
				$this->_acf_field_id = $field_name;
				$attach_id = $data['post_meta'][$field_name][0];
				$img = wp_get_attachment_image_src($attach_id, 'full', FALSE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' attach id=' . $attach_id . ' img=' . var_export($img, TRUE));
				if (FALSE !== $img) {
					$url = $img[0];
					$apirequest->send_media($url, $post_id, 0, $attach_id);
				}
			}

			// look through the list of ACF forms and include data for those
			$data = $this->_add_form_data($data);

			return $data;
		}

		/**
		 * Adds an image reference to the list being assembled for Pushing to the Target
		 * @param string $img The name of the image to add to the list
		 */
		public function add_image($img)
		{
			$this->_img_field_list[] = $img;
		}

		/**
		 * Callback used to add the ACF Field ID to the data being sent with an image upload
		 * @param array $fields An array of data fields being sent with the image in an 'upload_media' API call
		 * @return array The modified media data, with the ACF field id included
		 */
		public function filter_upload_media_fields($fields)
		{
SyncDebug::log(__METHOD__.'() setting field id: ' . $this->_acf_field_id);
			$fields['acf_field_id'] = $this->_acf_field_id;
			return $fields;
		}

		/**
		 * Adds the information for the ACF Forms to the post data being sent to the Target
		 * @param array $data The data being sent for the Push request to the Target
		 * @return array The modified data array with ACF Form Data added to it
		 */
		private function _add_form_data($data)
		{
			$data['acf_data'] = array();
			foreach ($this->acf_form_list as $acf_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding ACF form info for id #' . $acf_id);
				$acf_post_data = get_post($acf_id, ARRAY_A);
				$acf_post_meta = get_post_meta($acf_id);

				if (NULL !== $acf_post_data && 0 !== count($acf_post_meta)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . count($acf_post_meta) . ' meta fields');
					// remove any locks
					unset($acf_post_meta['_edit_lock']);
					unset($acf_post_meta['_edit_last']);

					$acf_data = array(
						'id' => $acf_id,
						'form_data' => $acf_post_data,
						'form_fields' => $acf_post_meta,
					);
					$data['acf_data'][] = $acf_data;
				}
			}
			return $data;
		}
	}
}

// EOF
