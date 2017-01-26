<?php

class SyncACFApiRequest extends SyncInput
{
	const ERROR_ASSOCIATED_POST_NOT_FOUND = 800;
	const ERROR_FORM_DECLARATION_CANNOT_BE_PUSHED = 801;
	const ERROR_NO_FORM_DATA = 802;
	const ERROR_NO_FORM_ID = 803;
	const ERROR_CANNOT_CREATE_FORM = 804;

	public function pre_process($post_data, $source_post_id, $target_post_id, SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__."({$source_post_id}, {$target_post_id})");

		// TODO: handle all forms sent with Push operation
		$acf_data = $this->post_raw('acf_data', array());
		if (empty($acf_data) || !is_array($acf_data)) {
			$response->error_code(self::ERROR_NO_ACF_FORM_DATA);
			$response->send();
		}
		$acf_id = abs($acf_data[0]['id']);
		if (0 === $acf_id) {
			$response->error_code(self::ERROR_NO_FORM_ID);
			$response->send();
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' acf id=' . $acf_id . ' form data: ' . var_export($acf_data, TRUE));

		WPSiteSync_ACF::get_instance()->load_class('acfformmodel');
		$acf_form_model = new SyncACFFormModel();
		$target_form_id = $acf_form_model->find_create_form($source_post_id, $acf_data[0]);
		if (NULL === $target_form_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' got error: ' . var_export($acf_form_model->wp_error, TRUE));
			$response->error_code(self::ERROR_CANNOT_CREATE_FORM);
			$response->send();
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found content id #' . $target_form_id);

#		$target_form_id = $acf_form_model->get_form_id_from_source_id($source_form_id);
#		$acf_form = $acf_model->find_form($acf_id, $acf_data['post_title']);

		// for now, return error code
###		$response->error_code(self::ERROR_FORM_DECLARATION_CANNOT_BE_PUSHED);
###		$response->send();			// send response to Source and exit
	}

	/**
	 * Handles the processing of Push requests in response to an API call on the Target
	 * @param int $target_post_id The post ID of the Content on the Target
	 * @param array $post_data The array of post content information sent via the API request
	 * @param SyncApiResponse $response The response object used to reply to the API call
	 */
	public function handle_push($target_post_id, $post_data, SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__."({$target_post_id}):" . __LINE__ . ' data=' . var_export($post_data, TRUE));
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
		if ('upload_media' === $action) {
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
        '_edit_lock' => 
        array (
          0 => '1483685825:1',
        ),
        '_edit_last' => 
        array (
          0 => '1',
        ),
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