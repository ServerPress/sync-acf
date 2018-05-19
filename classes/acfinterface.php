<?php

abstract class SyncACFFormModelInterface
{
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
}

// EOF
