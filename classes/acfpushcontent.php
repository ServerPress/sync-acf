<?php

class SyncACFPushContent
{
	private $_img_field_list = array();		// the list of image IDs to be processed and pushed
	private $_acf_form_list = array();		// the list of ACF form IDs to be processed and pushed

	private $_acf_field_id = NULL;			// the ACF field id, used in processing upload_image API calls

	/**
	 * Handles processing of ACF specific meta data for Push operations
	 * @param array $data The data being Pushed to the Target machine
	 * @param SyncApiRequest $apirequest Instance of the API Request object
	 * @return array The modified data
	 */
	public function process_push($data, SyncApiRequest $apirequest)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing');
		$post_id = 0;
		if (isset($data['post_id']))						// present on Push operations
			$post_id = abs($data['post_id']);
		else if (isset($data['post_data']['ID']))			// present on Pull operations
			$post_id = abs($data['post_data']['ID']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post id=' . $post_id);

		// first, build a list of the image specific meta data and the ACF form ids
		$field_model = new SyncACFFieldModel();

		$site_url = site_url();
		$apirequest->set_source_domain($site_url);

		// look for ACF metadata
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
				if (!in_array($acf_id, $this->_acf_form_list)) {
					$this->_acf_form_list[] = $acf_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' added to list: ' . implode(',', $this->_acf_form_list));
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
		foreach ($this->_acf_form_list as $acf_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding ACF form info for id #' . $acf_id);
			$acf_post_data = get_post($acf_id, ARRAY_A);
			$acf_post_meta = get_post_meta($acf_id);

			if (NULL !== $acf_post_data && 0 !== count($acf_post_meta)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . count($acf_post_meta) . ' meta fields');
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
