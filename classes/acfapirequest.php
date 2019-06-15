<?php

/**
 * Encapsulates error messages when handling WPSiteSync API requests
 */

class SyncACFApiRequest extends SyncInput
{
	const ERROR_ASSOCIATED_POST_NOT_FOUND = 800;
	const ERROR_FORM_DECLARATION_CANNOT_BE_PUSHED = 801;
	const ERROR_NO_FORM_DATA = 802;
	const ERROR_NO_FORM_ID = 803;
	const ERROR_CANNOT_CREATE_FORM = 804;
	const ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED = 805;
	const ERROR_CANNOT_CREATE_USER = 806;
	const ERROR_ACF_NOT_INITIALIZED_SOURCE = 807;
	const ERROR_ACF_NOT_INITIALIZED_TARGET = 808;
	const ERROR_ACF_DB_VERS_MISSING = 809;
	const ERROR_ACF_DB_VERS_MISMATCH = 810;
	const ERROR_ACF_PRO_NOT_SUPPORTED = 811;

	/**
	 * Converts numeric error code to message string
	 * @param string $msg Error message
	 * @param int $code The error code to convert
	 * @return string Modified message if one of WPSiteSync ACF's error codes
	 */
	public function filter_error_code($msg, $code)
	{
		switch ($code) {
		case self::ERROR_ASSOCIATED_POST_NOT_FOUND:				$msg = __('Content that is associated with this Content has not yet been Pushed to Target. This could be a related/associated post.', 'wpsitesync-acf'); break;
		case self::ERROR_FORM_DECLARATION_CANNOT_BE_PUSHED:		$msg = __('The ACF Form associated with this Content cannot be stored on the Target site.', 'wpsitesync-acf'); break;
		case self::ERROR_NO_FORM_DATA:							$msg = __('No ACF Form data found for this Content.', 'wpsitesync-acf'); break;
		case self::ERROR_NO_FORM_ID:							$msg = __('Missing ACF Form ID.', 'wpsitesync-acf'); break;
		case self::ERROR_CANNOT_CREATE_FORM:					$msg = __('There was an error creating the ACF Form on the Target site', 'wpsitesync-acf'); break;
		case self::ERROR_RELATED_CONTENT_HAS_NOT_BEEN_SYNCED:	$msg = __('The related Post Object\'s Content has not been Synced to the Target site.', 'wpsitesync-acf'); break;
		case self::ERROR_CANNOT_CREATE_USER:					$msg = __('Cannot create related User on Target site.', 'wpsitesync-acf'); break;
		case self::ERROR_ACF_NOT_INITIALIZED_SOURCE:			$msg = __('ACF is not properly installed on Source site.', 'wpsitesync-acf'); break;
		case self::ERROR_ACF_NOT_INITIALIZED_TARGET:			$msg = __('ACF is not properly installed on Target site.', 'wpsitesync-acf'); break;
		case self::ERROR_ACF_DB_VERS_MISSING:					$msg = __('Cannot determine ACF database version. Is ACF properly installed and database updated?', 'wpsitesync-acf'); break;
		case self::ERROR_ACF_DB_VERS_MISMATCH:					$msg = __('The database for ACF on Source and Target are not compatible. Update sites so the versions match.', 'wpsitesync-acf'); break;
		case self::ERROR_ACF_PRO_NOT_SUPPORTED:					$msg = __('ACF Pro is not currently supported.', 'wpsitesync-acf'); break;
		}
		return $msg;
	}
}

// EOF
