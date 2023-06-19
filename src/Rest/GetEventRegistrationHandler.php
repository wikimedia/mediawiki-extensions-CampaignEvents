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

	/** @var IEventLookup */
	private $eventLookup;
	/** @var TrackingToolRegistry */
	private TrackingToolRegistry $trackingToolRegistry;

	/**
	 * @param IEventLookup $eventLookup
	 * @param TrackingToolRegistry $trackingToolRegistry
	 */
	public function __construct(
		IEventLookup $eventLookup,
		TrackingToolRegistry $trackingToolRegistry
	) {
		$this->eventLookup = $eventLookup;
		$this->trackingToolRegistry = $trackingToolRegistry;
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
			$trackingToolsData[] = [
				'tool_id' => $this->trackingToolRegistry->dbIDtoUserID( $toolAssoc->getToolID() ),
				'tool_event_id' => $toolAssoc->getToolEventID(),
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
