<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks\Handlers;

use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;

/**
 * Enforces who may edit worklist pages, at the core permission level.
 *
 * The check applies to any page that uses the worklist content model, regardless of whether it is
 * associated with an event, and regardless of how the edit is made (REST endpoint or a direct
 * wikipage edit). For the MVP, only logged-in (named) users may edit worklist pages.
 */
class WorklistPageHandler implements GetUserPermissionsErrorsHook {

	/**
	 * @inheritDoc
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		if ( $action !== 'edit' && $action !== 'create' ) {
			return true;
		}
		if ( $title->getContentModel() !== CONTENT_MODEL_WORKLIST ) {
			return true;
		}
		if ( !$user->isNamed() ) {
			$result = 'campaignevents-worklist-edit-permission-denied';
			return false;
		}
		return true;
	}
}
