<?php

declare( strict_types=1 );

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecoratorFactory;
use MediaWiki\Extension\CampaignEvents\FrontendModules\FrontendModulesFactory;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWEventLookupFromPage;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Notifications\UserNotifier;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Organizers\RoleFormatter;
use MediaWiki\Extension\CampaignEvents\Pager\EventsPagerFactory;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsStore;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\Logger\LoggerFactory;
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
				$services->getUserFactory(),
				$services->getUserNameUtils()
			);
		},
	IEventStore::STORE_SERVICE_NAME => static function ( MediaWikiServices $services ): IEventStore {
		return new EventStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->get( AddressStore::SERVICE_NAME ),
			$services->get( TrackingToolUpdater::SERVICE_NAME ),
			$services->get( EventQuestionsStore::SERVICE_NAME )
		);
	},
	IEventLookup::LOOKUP_SERVICE_NAME => static function ( MediaWikiServices $services ): IEventLookup {
		return $services->get( IEventStore::STORE_SERVICE_NAME );
	},
	PermissionChecker::SERVICE_NAME => static function ( MediaWikiServices $services ): PermissionChecker {
		return new PermissionChecker(
			$services->get( OrganizersStore::SERVICE_NAME ),
			$services->get( PageAuthorLookup::SERVICE_NAME ),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->get( MWPermissionsLookup::SERVICE_NAME )
		);
	},
	CampaignsUserMailer::SERVICE_NAME => static function ( MediaWikiServices $services ): CampaignsUserMailer {
		return new CampaignsUserMailer(
			$services->getUserFactory(),
			$services->getJobQueueGroup(),
			new ServiceOptions(
				CampaignsUserMailer::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->getUserOptionsLookup()
		);
	},
	EventFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): EventFactory {
		return new EventFactory(
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->get( CampaignsPageFormatter::SERVICE_NAME ),
			$services->get( TrackingToolRegistry::SERVICE_NAME ),
			$services->get( EventQuestionsRegistry::SERVICE_NAME )
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
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->get( ParticipantAnswersStore::SERVICE_NAME )
		);
	},
	OrganizersStore::SERVICE_NAME => static function ( MediaWikiServices $services ): OrganizersStore {
		return new OrganizersStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	EditEventCommand::SERVICE_NAME => static function ( MediaWikiServices $services ): EditEventCommand {
		return new EditEventCommand(
			$services->get( IEventStore::STORE_SERVICE_NAME ),
			$services->get( IEventLookup::LOOKUP_SERVICE_NAME ),
			$services->get( OrganizersStore::SERVICE_NAME ),
			$services->get( PermissionChecker::SERVICE_NAME ),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->get( EventPageCacheUpdater::SERVICE_NAME ),
			$services->get( TrackingToolEventWatcher::SERVICE_NAME ),
			$services->get( TrackingToolUpdater::SERVICE_NAME ),
			LoggerFactory::getInstance( 'CampaignEvents' )
		);
	},
	DeleteEventCommand::SERVICE_NAME => static function ( MediaWikiServices $services ): DeleteEventCommand {
		return new DeleteEventCommand(
			$services->get( IEventStore::STORE_SERVICE_NAME ),
			$services->get( PermissionChecker::SERVICE_NAME ),
			$services->get( TrackingToolEventWatcher::SERVICE_NAME )
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
				$services->get( PermissionChecker::SERVICE_NAME ),
				$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
				$services->get( UserNotifier::SERVICE_NAME ),
				$services->get( EventPageCacheUpdater::SERVICE_NAME ),
				$services->get( TrackingToolEventWatcher::SERVICE_NAME )
			);
		},
	UnregisterParticipantCommand::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): UnregisterParticipantCommand {
			return new UnregisterParticipantCommand(
				$services->get( ParticipantsStore::SERVICE_NAME ),
				$services->get( PermissionChecker::SERVICE_NAME ),
				$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
				$services->get( EventPageCacheUpdater::SERVICE_NAME ),
				$services->get( TrackingToolEventWatcher::SERVICE_NAME )
			);
		},
	EventsPagerFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): EventsPagerFactory {
		return new EventsPagerFactory(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->get( PageURLResolver::SERVICE_NAME ),
			$services->getLinkBatchFactory()
		);
	},
	EventPageDecoratorFactory::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): EventPageDecoratorFactory {
			return new EventPageDecoratorFactory(
				$services->get( IEventLookup::LOOKUP_SERVICE_NAME ),
				$services->get( ParticipantsStore::SERVICE_NAME ),
				$services->get( OrganizersStore::SERVICE_NAME ),
				$services->get( PermissionChecker::SERVICE_NAME ),
				$services->getMessageFormatterFactory(),
				$services->getLinkRenderer(),
				$services->getTitleFormatter(),
				$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
				$services->get( UserLinker::SERVICE_NAME ),
				$services->get( EventTimeFormatter::SERVICE_NAME ),
				$services->get( EventPageCacheUpdater::SERVICE_NAME ),
				$services->get( EventQuestionsRegistry::SERVICE_NAME )
			);
		},
	CampaignEventsHookRunner::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): CampaignEventsHookRunner {
			return new CampaignEventsHookRunner( $services->getHookContainer() );
		},
	PolicyMessagesLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): PolicyMessagesLookup {
		return new PolicyMessagesLookup(
			$services->get( CampaignEventsHookRunner::SERVICE_NAME )
		);
	},
	PageURLResolver::SERVICE_NAME => static function ( MediaWikiServices $services ): PageURLResolver {
		return new PageURLResolver(
			$services->getTitleFactory()
		);
	},
	MWEventLookupFromPage::SERVICE_NAME => static function ( MediaWikiServices $services ): MWEventLookupFromPage {
		return new MWEventLookupFromPage(
			$services->get( IEventLookup::LOOKUP_SERVICE_NAME ),
			$services->getPageStore(),
			$services->getTitleFormatter()
		);
	},
	PageAuthorLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): PageAuthorLookup {
		return new PageAuthorLookup(
			$services->getRevisionStoreFactory(),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME )
		);
	},
	UserLinker::SERVICE_NAME => static function ( MediaWikiServices $services ): UserLinker {
		return new UserLinker(
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->getMessageFormatterFactory()
		);
	},
	FrontendModulesFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): FrontendModulesFactory {
		return new FrontendModulesFactory(
			$services->getMessageFormatterFactory(),
			$services->get( OrganizersStore::SERVICE_NAME ),
			$services->get( ParticipantsStore::SERVICE_NAME ),
			$services->get( PageURLResolver::SERVICE_NAME ),
			$services->get( UserLinker::SERVICE_NAME ),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->get( PermissionChecker::SERVICE_NAME ),
			$services->get( EventTimeFormatter::SERVICE_NAME ),
			$services->getUserFactory(),
			$services->get( TrackingToolRegistry::SERVICE_NAME ),
			$services->get( CampaignsUserMailer::SERVICE_NAME )
		);
	},
	AddressStore::SERVICE_NAME => static function ( MediaWikiServices $services ): AddressStore {
		return new AddressStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	TrackingToolRegistry::SERVICE_NAME => static function ( MediaWikiServices $services ): TrackingToolRegistry {
		return new TrackingToolRegistry(
			$services->getObjectFactory(),
			new ServiceOptions(
				TrackingToolRegistry::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
	EventTimeFormatter::SERVICE_NAME => static function ( MediaWikiServices $services ): EventTimeFormatter {
		return new EventTimeFormatter(
			$services->getUserOptionsLookup()
		);
	},
	UserNotifier::SERVICE_NAME => static function ( MediaWikiServices $services ): UserNotifier {
		return new UserNotifier(
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' )
		);
	},
	MWPermissionsLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): MWPermissionsLookup {
		return new MWPermissionsLookup(
			$services->getUserFactory(),
			$services->getUserNameUtils()
		);
	},
	EventPageCacheUpdater::SERVICE_NAME => static function ( MediaWikiServices $services ): EventPageCacheUpdater {
		return new EventPageCacheUpdater(
			$services->getHtmlCacheUpdater()
		);
	},
	TrackingToolEventWatcher::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): TrackingToolEventWatcher {
			return new TrackingToolEventWatcher(
				$services->get( TrackingToolRegistry::SERVICE_NAME ),
				$services->get( TrackingToolUpdater::SERVICE_NAME ),
				LoggerFactory::getInstance( 'CampaignEvents' ),
				$services->getDeferredUpdatesManager()
			);
		},
	TrackingToolUpdater::SERVICE_NAME => static function ( MediaWikiServices $services ): TrackingToolUpdater {
		return new TrackingToolUpdater(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	EventQuestionsRegistry::SERVICE_NAME => static function ( MediaWikiServices $services ): EventQuestionsRegistry {
		return new EventQuestionsRegistry(
			$services->getMainConfig()->get( 'CampaignEventsEnableWikimediaParticipantQuestions' )
		);
	},
	EventQuestionsStore::SERVICE_NAME => static function ( MediaWikiServices $services ): EventQuestionsStore {
		return new EventQuestionsStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	ParticipantAnswersStore::SERVICE_NAME => static function ( MediaWikiServices $services ): ParticipantAnswersStore {
		return new ParticipantAnswersStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	EventAggregatedAnswersStore::SERVICE_NAME => static function (
		MediaWikiServices $services
	): EventAggregatedAnswersStore {
		return new EventAggregatedAnswersStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
];
