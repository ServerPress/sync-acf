<?php

/**
 * Helper class to keep the postmeta and usermeta code from having to check what type of data it's updating
 */
class SyncACFDataManager
{
	private $type = NULL;

	private $owner_id = 0;

	const TYPE_POST = 'post';
	const TYPE_USER = 'user';

	/**
	 * Constructor
	 * @param int $obj_id The ID of the meta data to update. This is either a post ID or user ID depending on $type.
	 * @param string $type The type of meta data being updated. Use one of the TYPE_* constants
	 */
	public function __construct($obj_id, $type = self::TYPE_POST)
	{
		$this->owner_id = $obj_id;
		$this->set_type($type);
	}

	/**
	 * Sets the type of data that the instance will be updating
	 * @param string $type The type of meta data for the instance to update. Use on of the TYPE_* constants
	 */
	public function set_type($type)
	{
		if (!in_array($type, array(self::TYPE_POST, self::TYPE_USER)))
			throw new Exception('Invalid type: "' . $type . '" in ' . __METHOD__.'():' . __LINE__);
		$this->type = $type;
	}

	/**
	 * Sets the meta data value based on the key for the appropriate type of data the instance is handling
	 * @param string $key The meta key to update
	 * @param multi $value The value to be assigned to the meta data
	 */
	public function set_meta($key, $value)
	{
		switch ($this->type) {
		case self::TYPE_POST:
			update_post_meta($this->owner_id, $key, $value);
			break;
		case self::TYPE_USER:
			update_user_meta($this->owner_id, $key, $value);
			break;
		}
	}

	/**
	 * Gets the meta data value for the specified key for the appropriate type of data the instance is handling
	 * @param string $key The meta key to retrieve
	 * @return multi The meta value being retrieved
	 */
	public function get_meta($key)
	{
		switch ($this->type) {
		case self::TYPE_POST:
			$ret = get_post_meta($this->owner_id, $key, TRUE);
			break;
		case self::TYPE_USER:
			$ret = get_user_meta($this->owner_id, $key, TRUE);
			break;
		}
		return $ret;
	}

	/**
	 * Removes/deletes the meta data for the appropriate type of data the instance is handling
	 * @param string $key The meta key to delete
	 * @param multi $value Optional value in the case there are multiple values stored under the same key
	 */
	public function remove_meta($key, $value = '')
	{
		switch ($this->type) {
		case self::TYPE_POST:
			delete_post_meta($this->owner_id, $key, $value);
			break;
		case self::TYPE_USER:
			delete_user_meta($this->owner_id, $key, $value);
			break;
		}
	}
}

// EOF
