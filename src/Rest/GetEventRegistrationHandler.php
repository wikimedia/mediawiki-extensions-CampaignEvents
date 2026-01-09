<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

class GetEventRegistrationHandler extends SimpleHandler {
	use EventIDParamTrait;

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly TrackingToolRegistry $trackingToolRegistry,
		private readonly PermissionChecker $permissionChecker,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly OrganizersStore $organizersStore,
		private readonly ParticipantsStore $participantsStore,
	) {
	}

	protected function run( int $eventID ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		if ( $registration->getDeletionTimestamp() !== null ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-get-registration-deleted' ),
				404
			);
		}
		$page = $registration->getPage();

		$trackingToolsData = [];
		foreach ( $registration->getTrackingTools() as $toolAssoc ) {
			$toolEventID = $toolAssoc->getToolEventID();
			$toolUserInfo = $this->trackingToolRegistry->getUserInfo( $toolAssoc->getToolID(), $toolEventID );
			$trackingToolsData[] = [
				'tool_id' => $toolUserInfo['user-id'],
				'tool_event_id' => $toolEventID,
			];
		}

		$wikis = $registration->getWikis();
		$participationOptions = $registration->getParticipationOptions();
		$address = $registration->getAddress();
		$respVal = [
			'id' => $registration->getID(),
			'name' => $registration->getName(),
			'event_page' => $page->getPrefixedText(),
			'event_page_wiki' => Utils::getWikiIDString( $page->getWikiId() ),
			'status' => $registration->getStatus(),
			'timezone' => $registration->getTimezone()->getName(),
			'start_time' => wfTimestamp( TS_MW, $registration->getStartLocalTimestamp() ),
			'end_time' => wfTimestamp( TS_MW, $registration->getEndLocalTimestamp() ),
			'types' => $registration->getTypes(),
			// Use the same format as the write endpoints, which rely on ParamValidator::PARAM_ALL.
			'wikis' => $wikis === EventRegistration::ALL_WIKIS ? [ '*' ] : $wikis,
			'topics' => $registration->getTopics(),
			'online_meeting' => ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_ONLINE ) !== 0,
			'inperson_meeting' => ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_IN_PERSON ) !== 0,
			// meeting_url added conditionally
			'meeting_country_code' => $address?->getCountryCode(),
			'meeting_address' => $address?->getAddressWithoutCountry(),
			'tracks_contributions' => $registration->hasContributionTracking(),
			'tracking_tools' => $trackingToolsData,
			// chat_url added conditionally
			'is_test_event' => $registration->getIsTestEvent(),
			'questions' => $registration->getParticipantQuestions(),
		];
		$this->maybeAddSensitiveDataToResponse( $respVal, $registration );

		return $this->getResponseFactory()->createJson( $respVal );
	}

	/**
	 * Conditionally adds sensitive data (URLs) to the response, same as in the UI (see
	 * {@link EventDetailsModule::getInfoColumn})
	 *
	 * @param array<string,mixed> &$response
	 * @param ExistingEventRegistration $event
	 */
	private function maybeAddSensitiveDataToResponse(
		array &$response,
		ExistingEventRegistration $event,
	): void {
		$meetingURL = $event->getMeetingURL();
		$chatURL = $event->getChatURL();

		// Absence of the URL should always be public, so set it to null explicitly.
		if ( !$meetingURL ) {
			$response['meeting_url'] = null;
		}
		if ( !$chatURL ) {
			$response['chat_url'] = null;
		}

		if ( !$meetingURL && !$chatURL ) {
			// Skip further checks.
			return;
		}

		if ( !$event->isOnLocalWiki() ) {
			return;
		}

		$performer = $this->getAuthority();
		if ( !$this->permissionChecker->userCanViewSensitiveEventData( $performer ) ) {
			return;
		}

		try {
			$centralUser = $this->centralUserLookup->newFromAuthority( $this->getAuthority() );
		} catch ( UserNotGlobalException ) {
			return;
		}

		$eventID = $event->getID();
		if (
			!$this->participantsStore->userParticipatesInEvent( $eventID, $centralUser, true ) &&
			$this->organizersStore->getEventOrganizer( $eventID, $centralUser ) === null
		) {
			return;
		}

		$response['meeting_url'] = $meetingURL;
		$response['chat_url'] = $chatURL;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
