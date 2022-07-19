<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
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
	/** @var CampaignsCentralUserLookup */
	private $centralUserLookup;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->centralUserLookup = $centralUserLookup;
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
			$respVal[] = [ 'user_id' => $this->centralUserLookup->getCentralID( $participant->getUser() ) ];
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
