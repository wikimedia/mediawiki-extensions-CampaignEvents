<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Config\Config;
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
	private bool $eventWikisEnabled;
	private bool $eventTopicsEnabled;

	public function __construct(
		IEventLookup $eventLookup,
		TrackingToolRegistry $trackingToolRegistry,
		Config $config
	) {
		$this->eventLookup = $eventLookup;
		$this->trackingToolRegistry = $trackingToolRegistry;
		$this->eventWikisEnabled = $config->get( 'CampaignEventsEnableEventWikis' );
		$this->eventTopicsEnabled = $config->get( 'CampaignEventsEnableEventTopics' );
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
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

		$respVal = [
			'id' => $registration->getID(),
			'name' => $registration->getName(),
			'event_page' => $page->getPrefixedText(),
			'event_page_wiki' => Utils::getWikiIDString( $page->getWikiId() ),
			'chat_url' => $registration->getChatURL(),
			'tracking_tools' => $trackingToolsData,
			'status' => $registration->getStatus(),
			'timezone' => $registration->getTimezone()->getName(),
			'start_time' => wfTimestamp( TS_MW, $registration->getStartLocalTimestamp() ),
			'end_time' => wfTimestamp( TS_MW, $registration->getEndLocalTimestamp() ),
			// TODO MVP: Re-add this
			// 'type' => $registration->getType(),
			'online_meeting' => ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_ONLINE ) !== 0,
			'inperson_meeting' => ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_IN_PERSON ) !== 0,
			'meeting_url' => $registration->getMeetingURL(),
			'meeting_country' => $registration->getMeetingCountry(),
			'meeting_address' => $registration->getMeetingAddress(),
			'questions' => $registration->getParticipantQuestions(),
			'is_test_event' => $registration->getIsTestEvent(),
		];

		if ( $this->eventWikisEnabled ) {
			$wikis = $registration->getWikis();
			// Use the same format as the write endpoint, which rely on ParamValidator::PARAM_ALL.
			$respVal['wikis'] = $wikis === EventRegistration::ALL_WIKIS ? [ '*' ] : $wikis;
		}
		if ( $this->eventTopicsEnabled ) {
			$respVal['topics'] = $registration->getTopics();
		}

		return $this->getResponseFactory()->createJson( $respVal );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
