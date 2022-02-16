<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\MediaWikiServices;

/**
 * Global service locator for CampaignEvents services. Should only be used where DI is not possible.
 */
class CampaignEventsServices {
	/**
	 * @return CampaignsDatabaseHelper
	 */
	public static function getDatabaseHelper(): CampaignsDatabaseHelper {
		return MediaWikiServices::getInstance()->getService( CampaignsDatabaseHelper::SERVICE_NAME );
	}
}
