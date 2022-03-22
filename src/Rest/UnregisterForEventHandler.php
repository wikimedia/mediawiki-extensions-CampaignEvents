<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Store\IEventLookup;
use MediaWiki\Rest\Response;

class UnregisterForEventHandler extends ParticipantRegistrationHandlerBase {
	use CSRFCheckTrait;

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
		parent::__construct( $eventLookup );
		$this->participantsStore = $participantsStore;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->assertCSRFSafety();

		$this->validateEventWithID( $eventID );

		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );
		$modified = $this->participantsStore->removeParticipantFromEvent( $eventID, $user );
		return $this->getResponseFactory()->createJson( [
			'modified' => $modified
		] );
	}
}
