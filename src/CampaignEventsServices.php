<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsUserFactory;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Store\IEventStore;
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

	/**
	 * @return CampaignsPageFactory
	 */
	public static function getPageFactory(): CampaignsPageFactory {
		return MediaWikiServices::getInstance()->getService( CampaignsPageFactory::SERVICE_NAME );
	}

	/**
	 * @return CampaignsUserFactory
	 */
	public static function getCampaignsUserFactory(): CampaignsUserFactory {
		return MediaWikiServices::getInstance()->getService( CampaignsUserFactory::SERVICE_NAME );
	}

	/**
	 * @return IEventStore
	 */
	public static function getEventStore(): IEventStore {
		return MediaWikiServices::getInstance()->getService( IEventStore::STORE_SERVICE_NAME );
	}

	/**
	 * @return IEventLookup
	 */
	public static function getEventLookup(): IEventLookup {
		return MediaWikiServices::getInstance()->getService( IEventLookup::LOOKUP_SERVICE_NAME );
	}

	/**
	 * @return EventFactory
	 */
	public static function getEventFactory(): EventFactory {
		return MediaWikiServices::getInstance()->getService( EventFactory::SERVICE_NAME );
	}

	/**
	 * @return PermissionChecker
	 */
	public static function getPermissionChecker(): PermissionChecker {
		return MediaWikiServices::getInstance()->getService( PermissionChecker::SERVICE_NAME );
	}
}
