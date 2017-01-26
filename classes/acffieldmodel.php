<?php

class SyncACFFieldModel
{
	public function get_acf_object($name)
	{
		global $wpdb;

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
