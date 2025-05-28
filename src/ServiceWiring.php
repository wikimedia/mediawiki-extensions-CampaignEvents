<?php

declare( strict_types=1 );

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
use MediaWiki\Extension\CampaignEvents\CampaignEventsServices;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventTopicsStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventWikisStore;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventStore;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageCacheUpdater;
use MediaWiki\Extension\CampaignEvents\EventPage\EventPageDecoratorFactory;
use MediaWiki\Extension\CampaignEvents\FrontendModules\FrontendModulesFactory;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListGenerator;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore;
use MediaWiki\Extension\CampaignEvents\Invitation\PotentialInviteesFinder;
use MediaWiki\Extension\CampaignEvents\Invitation\WorklistParser;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFormatter;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWPermissionsLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageAuthorLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
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
use MediaWiki\Extension\CampaignEvents\Topics\EmptyTopicRegistry;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\Topics\WikimediaTopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

// This file is actually covered by CampaignEventsServicesTest, but it's not possible to specify a path
// in @covers annotations (https://github.com/sebastianbergmann/phpunit/issues/3794)
// @codeCoverageIgnoreStart
return [
	CampaignsDatabaseHelper::SERVICE_NAME => static function ( MediaWikiServices $services ): CampaignsDatabaseHelper {
		return new CampaignsDatabaseHelper(
			$services->getDBLoadBalancerFactory()
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
			$services->get( EventQuestionsStore::SERVICE_NAME ),
			$services->get( EventWikisStore::SERVICE_NAME ),
			$services->get( EventTopicsStore::SERVICE_NAME ),
			$services->getMainWANObjectCache()
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
			$services->getCentralIdLookup(),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->getUserOptionsLookup(),
			$services->getMessageFormatterFactory()->getTextFormatter(
				$services->getContentLanguageCode()->toString()
			),
			$services->get( PageURLResolver::SERVICE_NAME ),
			$services->getEmailUserFactory(),
			RequestContext::getMain()
		);
	},
	EventFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): EventFactory {
		return new EventFactory(
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->get( CampaignsPageFormatter::SERVICE_NAME ),
			$services->get( TrackingToolRegistry::SERVICE_NAME ),
			$services->get( EventQuestionsRegistry::SERVICE_NAME ),
			$services->get( WikiLookup::SERVICE_NAME ),
			$services->get( ITopicRegistry::SERVICE_NAME ),
			$services->get( EventTypesRegistry::SERVICE_NAME ),
			$services->get( CampaignEventsServices::CAMPAIGN_EVENTS_CONFIGURATION )
				->get( 'CampaignEventsEventNamespaces' )
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
			LoggerFactory::getInstance( 'CampaignEvents' ),
			$services->get( ParticipantAnswersStore::SERVICE_NAME ),
			$services->get( EventAggregatedAnswersStore::SERVICE_NAME ),
			$services->get( PageEventLookup::SERVICE_NAME )
		);
	},
	DeleteEventCommand::SERVICE_NAME => static function ( MediaWikiServices $services ): DeleteEventCommand {
		return new DeleteEventCommand(
			$services->get( IEventStore::STORE_SERVICE_NAME ),
			$services->get( PermissionChecker::SERVICE_NAME ),
			$services->get( TrackingToolEventWatcher::SERVICE_NAME ),
			$services->get( EventPageCacheUpdater::SERVICE_NAME )
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
			$services->getLinkBatchFactory(),
			$services->get( UserLinker::SERVICE_NAME ),
			$services->get( OrganizersStore::SERVICE_NAME ),
			$services->getUserOptionsLookup(),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->get( WikiLookup::SERVICE_NAME ),
			$services->get( EventWikisStore::SERVICE_NAME ),
			$services->get( ITopicRegistry::SERVICE_NAME ),
			$services->get( EventTopicsStore::SERVICE_NAME )
		);
	},
	EventPageDecoratorFactory::SERVICE_NAME =>
		static function ( MediaWikiServices $services ): EventPageDecoratorFactory {
			return new EventPageDecoratorFactory(
				$services->get( PageEventLookup::SERVICE_NAME ),
				$services->get( ParticipantsStore::SERVICE_NAME ),
				$services->get( OrganizersStore::SERVICE_NAME ),
				$services->get( PermissionChecker::SERVICE_NAME ),
				$services->getMessageFormatterFactory(),
				$services->getLinkRenderer(),
				$services->get( CampaignsPageFactory::SERVICE_NAME ),
				$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
				$services->get( UserLinker::SERVICE_NAME ),
				$services->get( EventTimeFormatter::SERVICE_NAME ),
				$services->get( EventPageCacheUpdater::SERVICE_NAME ),
				$services->get( EventQuestionsRegistry::SERVICE_NAME ),
				$services->get( WikiLookup::SERVICE_NAME ),
				$services->get( ITopicRegistry::SERVICE_NAME ),
				$services->getGroupPermissionsLookup(),
				// Pass whole config so the value is lazy loaded when needed for performance
				$services->get( CampaignEventsServices::CAMPAIGN_EVENTS_CONFIGURATION )
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
	PageEventLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): PageEventLookup {
		return new PageEventLookup(
			$services->get( IEventLookup::LOOKUP_SERVICE_NAME ),
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->getTitleFactory(),
			ExtensionRegistry::getInstance()->isLoaded( 'Translate' )
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
			$services->getMessageFormatterFactory(),
			$services->getLinkBatchFactory(),
			$services->getLinkRenderer(),
			$services->getUserLinkRenderer()
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
			$services->get( CampaignsUserMailer::SERVICE_NAME ),
			$services->get( ParticipantAnswersStore::SERVICE_NAME ),
			$services->get( EventAggregatedAnswersStore::SERVICE_NAME ),
			$services->get( EventQuestionsRegistry::SERVICE_NAME ),
			$services->get( CampaignEventsHookRunner::SERVICE_NAME ),
			$services->get( WikiLookup::SERVICE_NAME ),
			$services->get( ITopicRegistry::SERVICE_NAME )
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
				LoggerFactory::getInstance( 'CampaignEvents' )
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
	PotentialInviteesFinder::SERVICE_NAME => static function ( MediaWikiServices $services ): PotentialInviteesFinder {
		return new PotentialInviteesFinder(
			$services->getRevisionStoreFactory(),
			$services->getConnectionProvider(),
			$services->getNameTableStoreFactory(),
			$services->getUserOptionsLookup()
		);
	},
	WorklistParser::SERVICE_NAME => static function ( MediaWikiServices $services ): WorklistParser {
		return new WorklistParser(
			$services->getPageStoreFactory()
		);
	},
	InvitationListGenerator::SERVICE_NAME => static function ( MediaWikiServices $services ): InvitationListGenerator {
		return new InvitationListGenerator(
			$services->get( PermissionChecker::SERVICE_NAME ),
			$services->get( CampaignsPageFactory::SERVICE_NAME ),
			$services->get( PageEventLookup::SERVICE_NAME ),
			$services->get( OrganizersStore::SERVICE_NAME ),
			$services->get( CampaignsCentralUserLookup::SERVICE_NAME ),
			$services->get( InvitationListStore::SERVICE_NAME ),
			$services->getJobQueueGroup()
		);
	},
	InvitationListStore::SERVICE_NAME => static function ( MediaWikiServices $services ): InvitationListStore {
		return new InvitationListStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME ),
			$services->getPageStoreFactory()
		);
	},
	WikiLookup::SERVICE_NAME => static function ( MediaWikiServices $services ): WikiLookup {
		global $wgConf;
		return new WikiLookup(
			$wgConf,
			$services->getMainWANObjectCache(),
			RequestContext::getMain(),
			RequestContext::getMain()->getLanguage()->getCode()
		);
	},
	EventWikisStore::SERVICE_NAME => static function ( MediaWikiServices $services ): EventWikisStore {
		return new EventWikisStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	ITopicRegistry::SERVICE_NAME => static function (): ITopicRegistry {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'WikimediaMessages' ) ) {
			return new WikimediaTopicRegistry();
		}
		return new EmptyTopicRegistry();
	},
	EventTopicsStore::SERVICE_NAME => static function ( MediaWikiServices $services ): EventTopicsStore {
		return new EventTopicsStore(
			$services->get( CampaignsDatabaseHelper::SERVICE_NAME )
		);
	},
	CampaignEventsServices::CAMPAIGN_EVENTS_CONFIGURATION => static function ( MediaWikiServices $services ): Config{
		if ( ExtensionRegistry::getInstance()->isLoaded( 'CommunityConfiguration' ) ) {
			 return $services->getService( 'CommunityConfiguration.MediaWikiConfigReader' );
		} else {
			return $services->getMainConfig();
		}
	},
	EventTypesRegistry::SERVICE_NAME => static function ( MediaWikiServices $services ): EventTypesRegistry {
		return new EventTypesRegistry();
	},
];
