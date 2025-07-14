<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

class GetEventRegistrationHandler extends SimpleHandler {
	use EventIDParamTrait;

	private IEventLookup $eventLookup;
	private TrackingToolRegistry $trackingToolRegistry;

	public function __construct(
		IEventLookup $eventLookup,
		TrackingToolRegistry $trackingToolRegistry,
	) {
		$this->eventLookup = $eventLookup;
		$this->trackingToolRegistry = $trackingToolRegistry;
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
			'tracking_tools' => $trackingToolsData,
			'online_meeting' => ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_ONLINE ) !== 0,
			'inperson_meeting' => ( $participationOptions & EventRegistration::PARTICIPATION_OPTION_IN_PERSON ) !== 0,
			'meeting_url' => $registration->getMeetingURL(),
			'meeting_country' => $address ? $address->getCountry() : null,
			'meeting_country_code' => $address !== null ? $address->getCountryCode() : null,
			'meeting_address' => $address ? $address->getAddressWithoutCountry() : null,
			'chat_url' => $registration->getChatURL(),
			'is_test_event' => $registration->getIsTestEvent(),
			'questions' => $registration->getParticipantQuestions(),
		];

		return $this->getResponseFactory()->createJson( $respVal );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
