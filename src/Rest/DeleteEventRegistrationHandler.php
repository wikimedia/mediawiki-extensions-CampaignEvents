<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;

class DeleteEventRegistrationHandler extends SimpleHandler {
	use TokenAwareHandlerTrait;
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
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	/**
	 * @param int $id
	 * @return Response
	 */
	public function run( int $id ): Response {
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $id );
		if ( $registration->getDeletionTimestamp() !== null ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-delete-already-deleted' ),
				404
			);
		}

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$status = $this->deleteEventCommand->deleteIfAllowed( $registration, $performer );
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
