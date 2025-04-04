<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;

class DeleteEventRegistrationHandler extends SimpleHandler {
	use TokenAwareHandlerTrait;
	use EventIDParamTrait;
	use FailStatusUtilTrait;

	private IEventLookup $eventLookup;
	private DeleteEventCommand $deleteEventCommand;

	public function __construct(
		IEventLookup $eventLookup,
		DeleteEventCommand $deleteEventCommand
	) {
		$this->eventLookup = $eventLookup;
		$this->deleteEventCommand = $deleteEventCommand;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	public function run( int $id ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $id );
		if ( $registration->getDeletionTimestamp() !== null ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-delete-already-deleted' ),
				404
			);
		}

		$wikiID = $registration->getPage()->getWikiId();
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-delete-event-nonlocal-error-message' )
					->params( $wikiID ),
				400
			);
		}

		$status = $this->deleteEventCommand->deleteIfAllowed( $registration, $this->getAuthority() );
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

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return $this->getTokenParamDefinition();
	}
}
