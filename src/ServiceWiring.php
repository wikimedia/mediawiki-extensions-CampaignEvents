<?php

declare( strict_types=1 );

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsUserFactory;
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
	CampaignsUserFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): CampaignsUserFactory {
		return new CampaignsUserFactory(
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
];
