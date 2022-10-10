<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\MediaWikiServices;
use Psr\Container\ContainerInterface;

/**
 * Global service locator for CampaignEvents services. Should only be used where DI is not possible.
 */
class CampaignEventsServices {
	/**
	 * @param ContainerInterface|null $services
	 * @return CampaignsDatabaseHelper
	 */
	public static function getDatabaseHelper( ContainerInterface $services = null ): CampaignsDatabaseHelper {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignsDatabaseHelper::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return CampaignsCentralUserLookup
	 */
	public static function getCentralUserLookup( ContainerInterface $services = null ): CampaignsCentralUserLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignsCentralUserLookup::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return IEventStore
	 */
	public static function getEventStore( ContainerInterface $services = null ): IEventStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( IEventStore::STORE_SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return IEventLookup
	 */
	public static function getEventLookup( ContainerInterface $services = null ): IEventLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( IEventLookup::LOOKUP_SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return ParticipantsStore
	 */
	public static function getParticipantsStore( ContainerInterface $services = null ): ParticipantsStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( ParticipantsStore::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return OrganizersStore
	 */
	public static function getOrganizersStore( ContainerInterface $services = null ): OrganizersStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( OrganizersStore::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return PolicyMessagesLookup
	 */
	public static function getPolicyMessagesLookup( ContainerInterface $services = null ): PolicyMessagesLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PolicyMessagesLookup::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return AddressStore
	 */
	public static function getAddressStore( ContainerInterface $services = null ): AddressStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( AddressStore::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return EventTimeFormatter
	 */
	public static function getEventTimeFormatter( ContainerInterface $services = null ): EventTimeFormatter {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventTimeFormatter::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return PageURLResolver
	 */
	public static function getPageURLResolver( ContainerInterface $services = null ): PageURLResolver {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PageURLResolver::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return UserLinker
	 */
	public static function getUserLinker( ContainerInterface $services = null ): UserLinker {
		return ( $services ?? MediaWikiServices::getInstance() )->get( UserLinker::SERVICE_NAME );
	}

	/**
	 * @param ContainerInterface|null $services
	 * @return TrackingToolUpdater
	 */
	public static function getTrackingToolUpdater( ContainerInterface $services = null ): TrackingToolUpdater {
		return ( $services ?? MediaWikiServices::getInstance() )->get( TrackingToolUpdater::SERVICE_NAME );
	}
}
