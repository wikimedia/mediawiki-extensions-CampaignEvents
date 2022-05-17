<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;

class RegisterForEventHandler extends SimpleHandler {
	use EventIDParamTrait;
	use CSRFCheckTrait;
	use FailStatusUtilTrait;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var RegisterParticipantCommand */
	private $registerParticipantCommand;

	/**
	 * @param IEventLookup $eventLookup
	 * @param RegisterParticipantCommand $registerParticipantCommand
	 */
	public function __construct(
		IEventLookup $eventLookup,
		RegisterParticipantCommand $registerParticipantCommand
	) {
		$this->eventLookup = $eventLookup;
		$this->registerParticipantCommand = $registerParticipantCommand;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$this->assertCSRFSafety();

		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );
		$status = $this->registerParticipantCommand->registerIfAllowed( $eventRegistration, $user );
		if ( !$status->isGood() ) {
			$httptStatus = $status instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $status, $httptStatus );
		}
		return $this->getResponseFactory()->createJson( [
			'modified' => $status->getValue()
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
