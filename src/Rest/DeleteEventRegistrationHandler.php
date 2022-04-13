<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

class DeleteEventRegistrationHandler extends SimpleHandler {
	use CSRFCheckTrait;
	use EventIDParamTrait;
	use FailStatusUtilTrait;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var DeleteEventCommand */
	private $deleteEventCommand;

	/**
	 * @param IEventLookup $eventLookup
	 * @param DeleteEventCommand $deleteEventCommand
	 */
	public function __construct(
		IEventLookup $eventLookup,
		DeleteEventCommand $deleteEventCommand
	) {
		$this->eventLookup = $eventLookup;
		$this->deleteEventCommand = $deleteEventCommand;
	}

	/**
	 * @param int $id
	 * @return Response
	 */
	public function run( int $id ): Response {
		$this->assertCSRFSafety();

		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $id );
		if ( $registration->getDeletionTimestamp() !== null ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-delete-already-deleted' ),
				404
			);
		}

		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );
		$status = $this->deleteEventCommand->deleteIfAllowed( $registration, $user );
		if ( !$status->isGood() ) {
			$httptStatus = $status instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $status, $httptStatus );
		}

		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting();
	}
}
