<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
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
	 * @return CampaignsCentralUserLookup
	 */
	public static function getCampaignsCentralUserLookup(): CampaignsCentralUserLookup {
		return MediaWikiServices::getInstance()->getService( CampaignsCentralUserLookup::SERVICE_NAME );
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

	/**
	 * @return ParticipantsStore
	 */
	public static function getParticipantsStore(): ParticipantsStore {
		return MediaWikiServices::getInstance()->getService( ParticipantsStore::SERVICE_NAME );
	}

	/**
	 * @return OrganizersStore
	 */
	public static function getOrganizersStore(): OrganizersStore {
		return MediaWikiServices::getInstance()->getService( OrganizersStore::SERVICE_NAME );
	}

	/**
	 * @return PolicyMessageLookup
	 */
	public static function getPolicyMessageLookup(): PolicyMessageLookup {
		return MediaWikiServices::getInstance()->getService( PolicyMessageLookup::SERVICE_NAME );
	}

	/**
	 * @return AddressStore
	 */
	public static function getAddressStore(): AddressStore {
		return MediaWikiServices::getInstance()->getService( AddressStore::SERVICE_NAME );
	}

	/**
	 * @return EventTimeFormatter
	 */
	public static function getEventTimeFormatter(): EventTimeFormatter {
		return MediaWikiServices::getInstance()->getService( EventTimeFormatter::SERVICE_NAME );
	}

	/**
	 * @return PageURLResolver
	 */
	public static function getPageUrlResolver(): PageURLResolver {
		return MediaWikiServices::getInstance()->getService( PageURLResolver::SERVICE_NAME );
	}

	/**
	 * @return UserLinker
	 */
	public static function getUserLinker(): UserLinker {
		return MediaWikiServices::getInstance()->getService( UserLinker::SERVICE_NAME );
	}
}
