<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents;

use MediaWiki\Extension\CampaignEvents\Address\AddressStore;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
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
use MediaWiki\Extension\CampaignEvents\Questions\EventAggregatedAnswersStore;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsStore;
use MediaWiki\Extension\CampaignEvents\Questions\ParticipantAnswersStore;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolEventWatcher;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolUpdater;
use MediaWiki\MediaWikiServices;
use Psr\Container\ContainerInterface;

/**
 * Global service locator for CampaignEvents services. Should only be used where DI is not possible.
 */
class CampaignEventsServices {
	public static function getDatabaseHelper( ?ContainerInterface $services = null ): CampaignsDatabaseHelper {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignsDatabaseHelper::SERVICE_NAME );
	}

	public static function getPageFactory( ?ContainerInterface $services = null ): CampaignsPageFactory {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignsPageFactory::SERVICE_NAME );
	}

	public static function getCentralUserLookup( ?ContainerInterface $services = null ): CampaignsCentralUserLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignsCentralUserLookup::SERVICE_NAME );
	}

	public static function getEventStore( ?ContainerInterface $services = null ): IEventStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( IEventStore::STORE_SERVICE_NAME );
	}

	public static function getEventLookup( ?ContainerInterface $services = null ): IEventLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( IEventLookup::LOOKUP_SERVICE_NAME );
	}

	public static function getPermissionChecker( ?ContainerInterface $services = null ): PermissionChecker {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PermissionChecker::SERVICE_NAME );
	}

	public static function getUserMailer( ?ContainerInterface $services = null ): CampaignsUserMailer {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignsUserMailer::SERVICE_NAME );
	}

	public static function getEventFactory( ?ContainerInterface $services = null ): EventFactory {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventFactory::SERVICE_NAME );
	}

	public static function getCampaignsPageFormatter( ?ContainerInterface $services = null ): CampaignsPageFormatter {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignsPageFormatter::SERVICE_NAME );
	}

	public static function getParticipantsStore( ?ContainerInterface $services = null ): ParticipantsStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( ParticipantsStore::SERVICE_NAME );
	}

	public static function getOrganizersStore( ?ContainerInterface $services = null ): OrganizersStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( OrganizersStore::SERVICE_NAME );
	}

	public static function getEditEventCommand( ?ContainerInterface $services = null ): EditEventCommand {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EditEventCommand::SERVICE_NAME );
	}

	public static function getDeleteEventCommand( ?ContainerInterface $services = null ): DeleteEventCommand {
		return ( $services ?? MediaWikiServices::getInstance() )->get( DeleteEventCommand::SERVICE_NAME );
	}

	public static function getRoleFormatter( ?ContainerInterface $services = null ): RoleFormatter {
		return ( $services ?? MediaWikiServices::getInstance() )->get( RoleFormatter::SERVICE_NAME );
	}

	public static function getRegisterParticipantCommand(
		?ContainerInterface $services = null
	): RegisterParticipantCommand {
		return ( $services ?? MediaWikiServices::getInstance() )->get( RegisterParticipantCommand::SERVICE_NAME );
	}

	public static function getUnregisterParticipantCommand(
		?ContainerInterface $services = null
	): UnregisterParticipantCommand {
		return ( $services ?? MediaWikiServices::getInstance() )->get( UnregisterParticipantCommand::SERVICE_NAME );
	}

	public static function getEventsPagerFactory( ?ContainerInterface $services = null ): EventsPagerFactory {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventsPagerFactory::SERVICE_NAME );
	}

	public static function getEventPageDecoratorFactory(
		?ContainerInterface $services = null
	): EventPageDecoratorFactory {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventPageDecoratorFactory::SERVICE_NAME );
	}

	public static function getHookRunner( ?ContainerInterface $services = null ): CampaignEventsHookRunner {
		return ( $services ?? MediaWikiServices::getInstance() )->get( CampaignEventsHookRunner::SERVICE_NAME );
	}

	public static function getPolicyMessagesLookup( ?ContainerInterface $services = null ): PolicyMessagesLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PolicyMessagesLookup::SERVICE_NAME );
	}

	public static function getAddressStore( ?ContainerInterface $services = null ): AddressStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( AddressStore::SERVICE_NAME );
	}

	public static function getEventTimeFormatter( ?ContainerInterface $services = null ): EventTimeFormatter {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventTimeFormatter::SERVICE_NAME );
	}

	public static function getPageURLResolver( ?ContainerInterface $services = null ): PageURLResolver {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PageURLResolver::SERVICE_NAME );
	}

	public static function getPageEventLookup( ?ContainerInterface $services = null ): PageEventLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PageEventLookup::SERVICE_NAME );
	}

	public static function getPageAuthorLookup( ?ContainerInterface $services = null ): PageAuthorLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PageAuthorLookup::SERVICE_NAME );
	}

	public static function getUserLinker( ?ContainerInterface $services = null ): UserLinker {
		return ( $services ?? MediaWikiServices::getInstance() )->get( UserLinker::SERVICE_NAME );
	}

	public static function getFrontendModulesFactory( ?ContainerInterface $services = null ): FrontendModulesFactory {
		return ( $services ?? MediaWikiServices::getInstance() )->get( FrontendModulesFactory::SERVICE_NAME );
	}

	public static function getTrackingToolRegistry( ?ContainerInterface $services = null ): TrackingToolRegistry {
		return ( $services ?? MediaWikiServices::getInstance() )->get( TrackingToolRegistry::SERVICE_NAME );
	}

	public static function getUserNotifier( ?ContainerInterface $services = null ): UserNotifier {
		return ( $services ?? MediaWikiServices::getInstance() )->get( UserNotifier::SERVICE_NAME );
	}

	public static function getPermissionLookup( ?ContainerInterface $services = null ): MWPermissionsLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( MWPermissionsLookup::SERVICE_NAME );
	}

	public static function getEventPageCacheUpdater( ?ContainerInterface $services = null ): EventPageCacheUpdater {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventPageCacheUpdater::SERVICE_NAME );
	}

	public static function getTrackingToolEventWatcher(
		?ContainerInterface $services = null
	): TrackingToolEventWatcher {
		return ( $services ?? MediaWikiServices::getInstance() )->get( TrackingToolEventWatcher::SERVICE_NAME );
	}

	public static function getTrackingToolUpdater( ?ContainerInterface $services = null ): TrackingToolUpdater {
		return ( $services ?? MediaWikiServices::getInstance() )->get( TrackingToolUpdater::SERVICE_NAME );
	}

	public static function getEventQuestionsRegistry( ?ContainerInterface $services = null ): EventQuestionsRegistry {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventQuestionsRegistry::SERVICE_NAME );
	}

	public static function getEventQuestionsStore( ?ContainerInterface $services = null ): EventQuestionsStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventQuestionsStore::SERVICE_NAME );
	}

	public static function getParticipantAnswersStore( ?ContainerInterface $services = null ): ParticipantAnswersStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( ParticipantAnswersStore::SERVICE_NAME );
	}

	public static function getEventAggregatedAnswersStore(
		?ContainerInterface $services = null
	): EventAggregatedAnswersStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventAggregatedAnswersStore::SERVICE_NAME );
	}

	public static function getPotentialInviteesFinder( ?ContainerInterface $services = null ): PotentialInviteesFinder {
		return ( $services ?? MediaWikiServices::getInstance() )->get( PotentialInviteesFinder::SERVICE_NAME );
	}

	public static function getWorklistParser( ?ContainerInterface $services = null ): WorklistParser {
		return ( $services ?? MediaWikiServices::getInstance() )->get( WorklistParser::SERVICE_NAME );
	}

	public static function getInvitationListGenerator( ?ContainerInterface $services = null ): InvitationListGenerator {
		return ( $services ?? MediaWikiServices::getInstance() )->get( InvitationListGenerator::SERVICE_NAME );
	}

	public static function getInvitationListStore( ?ContainerInterface $services = null ): InvitationListStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( InvitationListStore::SERVICE_NAME );
	}

	public static function getWikiLookup( ?ContainerInterface $services = null ): WikiLookup {
		return ( $services ?? MediaWikiServices::getInstance() )->get( WikiLookup::SERVICE_NAME );
	}

	public static function getEventWikisStore( ?ContainerInterface $services = null ): EventWikisStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventWikisStore::SERVICE_NAME );
	}

	public static function getTopicRegistry( ?ContainerInterface $services = null ): ITopicRegistry {
		return ( $services ?? MediaWikiServices::getInstance() )->get( ITopicRegistry::SERVICE_NAME );
	}

	public static function getEventTopicsStore( ?ContainerInterface $services = null ): EventTopicsStore {
		return ( $services ?? MediaWikiServices::getInstance() )->get( EventTopicsStore::SERVICE_NAME );
	}
}
