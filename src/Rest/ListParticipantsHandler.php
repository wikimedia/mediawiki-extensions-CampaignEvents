<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;

class ListParticipantsHandler extends SimpleHandler {
	use EventIDParamTrait;

	// TODO: Implement proper pagination (T305389)
	private const RES_LIMIT = 50;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var ParticipantsStore */
	private $participantsStore;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$participants = $this->participantsStore->getEventParticipants( $eventID, self::RES_LIMIT );
		$respVal = [];
		foreach ( $participants as $participant ) {
			$respVal[] = [ 'user_id' => $participant->getUser()->getLocalID() ];
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
