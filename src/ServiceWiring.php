<?php

declare( strict_types=1 );

use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecorator;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserBlockChecker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessageLookup;
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
			$services->getPageStoreFactory(),
			$services->getTitleParser(),
			$services->getTitleFormatter()
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
		return new PermissionChecker(
			$services->get( UserBlockChecker::SERVICE_NAME ),
			$services->get( OrganizersStore::SERVICE_NAME )
		);
	},
	EventFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): EventFactory {
		return new EventFactory(
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->get( CampaignsPageFormatter::SERVICE_NAME )
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
	EditEventCommand::SERVICE_NAME => static function ( MediaWikiServices $services ): EditEventCommand {
		return new EditEventCommand(
			$services->get( IEventStore::STORE_SERVICE_NAME ),
			$services->get( IEventLookup::LOOKUP_SERVICE_NAME ),
			$services->get( OrganizersStore::SERVICE_NAME ),
			$services->get( PermissionChecker::SERVICE_NAME )
		);
	},
	UserBlockChecker::SERVICE_NAME => static function ( MediaWikiServices $services ): UserBlockChecker {
		return new UserBlockChecker(
			$services->getUserFactory()
		);
	},
	DeleteEventCommand::SERVICE_NAME => static function ( MediaWikiServices $services ): DeleteEventCommand {
		return new DeleteEventCommand(
			$services->get( IEventStore::STORE_SERVICE_NAME ),
			$services->get( PermissionChecker::SERVICE_NAME )
		);
	},
	RoleFormatter::SERVICE_NAME => static function ( MediaWikiServices $services ): RoleFormatter {
		return new RoleFormatter(
			$services->getMessageFormatterFactory()
		);
	},
	RegisterParticipantCommand::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): RegisterParticipantCommand {
			return new RegisterParticipantCommand(
				$services->get( ParticipantsStore::SERVICE_NAME ),
				$services->get( PermissionChecker::SERVICE_NAME )
			);
		},
	UnregisterParticipantCommand::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): UnregisterParticipantCommand {
			return new UnregisterParticipantCommand(
				$services->get( ParticipantsStore::SERVICE_NAME ),
				$services->get( PermissionChecker::SERVICE_NAME )
			);
		},
	EventsPagerFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): EventsPagerFactory {
		return new EventsPagerFactory(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->get( PageURLResolver::SERVICE_NAME )
		);
	},
	EventPageDecorator::SERVICE_NAME => static function ( MediaWikiServices $services ): EventPageDecorator {
		return new EventPageDecorator(
			$services->get( IEventLookup::LOOKUP_SERVICE_NAME ),
			$services->get( ParticipantsStore::SERVICE_NAME ),
			$services->get( OrganizersStore::SERVICE_NAME ),
			$services->get( UserBlockChecker::SERVICE_NAME ),
			$services->get( PermissionChecker::SERVICE_NAME ),
			$services->getMessageFormatterFactory(),
			$services->getLinkRenderer(),
			$services->getTitleFormatter()
		);
	},
	CampaignEventsHookRunner::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): CampaignEventsHookRunner {
			return new CampaignEventsHookRunner( $services->getHookContainer() );
		},
	PolicyMessageLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): PolicyMessageLookup {
		return new PolicyMessageLookup(
			$services->get( CampaignEventsHookRunner::SERVICE_NAME )
		);
	},
	PageURLResolver::SERVICE_NAME => static function ( MediaWikiServices $services ): PageURLResolver {
		return new PageURLResolver(
			$services->getTitleFactory()
		);
	}
];
