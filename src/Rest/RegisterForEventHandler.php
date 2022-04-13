<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use Wikimedia\Message\MessageValue;

class RegisterForEventHandler extends ParticipantRegistrationHandlerBase {
	use CSRFCheckTrait;

	/** @var PermissionChecker */
	private $permissionChecker;
	/** @var ParticipantsStore */
	private $participantsStore;

	/**
	 * @param PermissionChecker $permissionChecker
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 */
	public function __construct(
		PermissionChecker $permissionChecker,
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore
	) {
		parent::__construct( $eventLookup );
		$this->permissionChecker = $permissionChecker;
		$this->participantsStore = $participantsStore;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->assertCSRFSafety();

		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );

		if ( !$this->permissionChecker->userCanRegisterForEvents( $user ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-permission-denied' ),
				403
			);
		}

		$this->validateEventWithID( $eventID );

		$modified = $this->participantsStore->addParticipantToEvent( $eventID, $user );
		return $this->getResponseFactory()->createJson( [
			'modified' => $modified
		] );
	}

	/**
	 * @inheritDoc
	 */
	protected function doAdditionalEventValidation( EventRegistration $eventRegistration ): void {
		if ( $eventRegistration->getStatus() !== EventRegistration::STATUS_OPEN ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-register-event-not-open' ),
				400
			);
		}
	}
}
