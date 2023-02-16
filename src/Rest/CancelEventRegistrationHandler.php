<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\Validator;

class CancelEventRegistrationHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var UnregisterParticipantCommand */
	private $unregisterParticipantCommand;

	/**
	 * @param IEventLookup $eventLookup
	 * @param UnregisterParticipantCommand $unregisterParticipantCommand
	 */
	public function __construct(
		IEventLookup $eventLookup,
		UnregisterParticipantCommand $unregisterParticipantCommand
	) {
		$this->eventLookup = $eventLookup;
		$this->unregisterParticipantCommand = $unregisterParticipantCommand;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$status = $this->unregisterParticipantCommand->unregisterIfAllowed( $eventRegistration, $performer );
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

	/**
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ) {
		if ( $contentType !== 'application/json' ) {
			throw new HttpException( "Unsupported Content-Type",
				415,
				[ 'content_type' => $contentType ]
			);
		}

		return new JsonBodyValidator( $this->getTokenParamDefinition() );
	}
}
