<?php

declare( strict_types=1 );

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Store\IEventStore;
use MediaWiki\MediaWikiServices;

// This file is actually covered by CampaignEventsServicesTest, but it's not possible to specify a path
// in @covers annotations (https://github.com/sebastianbergmann/phpunit/issues/3794)
// @codeCoverageIgnoreStart

return [
	CampaignsDatabaseHelper::SERVICE_NAME => static function ( MediaWikiServices $services ): CampaignsDatabaseHelper {
		return new CampaignsDatabaseHelper(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig()->get( 'CampaignEventsDatabaseCluster' ),
			$services->getMainConfig()->get( 'CampaignEventsDatabaseName' )
		);
	},
	CampaignsPageFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): CampaignsPageFactory {
		return new CampaignsPageFactory(
			$services->getPageStoreFactory()
		);
	},
	CampaignsCentralUserLookup::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): CampaignsCentralUserLookup {
			return new CampaignsCentralUserLookup(
				$services->getCentralIdLookup(),
				$services->getUserFactory()
			);
		},
	IEventStore::STORE_SERVICE_NAME => static function ( MediaWikiServices $services ): IEventStore {
		return new EventStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->get( CampaignsPageFactory::SERVICE_NAME )
		);
	},
	IEventLookup::LOOKUP_SERVICE_NAME => static function ( MediaWikiServices $services ): IEventLookup {
		return $services->get( IEventStore::STORE_SERVICE_NAME );
	},
	PermissionChecker::SERVICE_NAME => static function ( MediaWikiServices $services ): PermissionChecker {
		return new PermissionChecker();
	},
	EventFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): EventFactory {
		return new EventFactory(
			$services->getTitleParser(),
			$services->getInterwikiLookup(),
			$services->get( CampaignsPageFactory::SERVICE_NAME )
		);
	},
	CampaignsPageFormatter::SERVICE_NAME => static function ( MediaWikiServices $services ): CampaignsPageFormatter {
		return new CampaignsPageFormatter(
			$services->getTitleFormatter()
		);
	},
	ParticipantsStore::SERVICE_NAME => static function ( MediaWikiServices $services ): ParticipantsStore {
		return new ParticipantsStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME )
		);
	},
	OrganizersStore::SERVICE_NAME => static function ( MediaWikiServices $services ): OrganizersStore {
		return new OrganizersStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME )
		);
	},
];
