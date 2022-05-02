<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Utils;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;

class GetEventRegistrationHandler extends SimpleHandler {
	use EventIDParamTrait;

	/** @var IEventLookup */
	private $eventLookup;

	/**
	 * @param IEventLookup $eventLookup
	 */
	public function __construct(
		IEventLookup $eventLookup
	) {
		$this->eventLookup = $eventLookup;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$page = $registration->getPage();

		$respVal = [
			'id' => $registration->getID(),
			'name' => $registration->getName(),
			'event_page' => $page->getPrefixedText(),
			'event_page_wiki' => Utils::getWikiIDString( $page->getWikiId() ),
			'chat_url' => $registration->getChatURL(),
			'tracking_tool_name' => $registration->getTrackingToolName(),
			'tracking_tool_url' => $registration->getTrackingToolURL(),
			'status' => $registration->getStatus(),
			'start_time' => wfTimestamp( TS_MW, $registration->getStartTimestamp() ),
			'end_time' => wfTimestamp( TS_MW, $registration->getEndTimestamp() ),
			'type' => $registration->getType(),
			'online_meeting' => ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_ONLINE ) !== 0,
			'physical_meeting' => ( $registration->getMeetingType() & EventRegistration::MEETING_TYPE_PHYSICAL ) !== 0,
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
