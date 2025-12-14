<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;

class CancelEventRegistrationHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	public function __construct(
		private readonly IEventLookup $eventLookup,
		private readonly UnregisterParticipantCommand $unregisterParticipantCommand,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	protected function run( int $eventID ): Response {
		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$status = $this->unregisterParticipantCommand->unregisterIfAllowed( $eventRegistration, $this->getAuthority() );
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
	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}
}
