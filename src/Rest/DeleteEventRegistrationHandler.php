<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;

class DeleteEventRegistrationHandler extends SimpleHandler {
	use TokenAwareHandlerTrait;
	use EventIDParamTrait;
	use FailStatusUtilTrait;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var DeleteEventCommand */
	private $deleteEventCommand;
	/** @var UserFactory */
	protected $userFactory;

	/**
	 * @param IEventLookup $eventLookup
	 * @param DeleteEventCommand $deleteEventCommand
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		IEventLookup $eventLookup,
		DeleteEventCommand $deleteEventCommand,
		UserFactory $userFactory
	) {
		$this->eventLookup = $eventLookup;
		$this->deleteEventCommand = $deleteEventCommand;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param int $id
	 * @return Response
	 */
	public function run( int $id ): Response {
		$token = $this->getToken();
		if (
			$token !== null &&
			!$this->userFactory->newFromAuthority( $this->getAuthority() )->matchEditToken( $token )
		) {
			throw new LocalizedHttpException( $this->getBadTokenMessage(), 400 );
		}

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
