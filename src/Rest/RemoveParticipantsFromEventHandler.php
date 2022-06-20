<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class RemoveParticipantsFromEventHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	/** @var IEventLookup */
	private $eventLookup;
	/** @var UnregisterParticipantCommand */
	private $unregisterParticipantCommand;
	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param IEventLookup $eventLookup
	 * @param UnregisterParticipantCommand $unregisterParticipantCommand
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		IEventLookup $eventLookup,
		UnregisterParticipantCommand $unregisterParticipantCommand,
		UserFactory $userFactory
	) {
		$this->eventLookup = $eventLookup;
		$this->unregisterParticipantCommand = $unregisterParticipantCommand;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param int $eventID
	 * @return Response
	 */
	protected function run( int $eventID ): Response {
		$body = $this->getValidatedBody();

		$token = $this->getToken();
		if (
			$token !== null &&
			!$this->userFactory->newFromAuthority( $this->getAuthority() )->matchEditToken( $token )
		) {
			throw new LocalizedHttpException( $this->getBadTokenMessage(), 400 );
		}

		if ( is_array( $body[ 'user_ids' ] ) && !$body[ 'user_ids' ] ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-remove-participants-invalid-users-ids' ),
				400
			);
		}

		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );
		$status = $this->unregisterParticipantCommand->removeParticipantsIfAllowed(
			$eventRegistration,
			$body[ 'user_ids' ],
			$user
		);

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

		return new JsonBodyValidator(
			[ 'user_ids' => [ static::PARAM_SOURCE => 'body', ParamValidator::PARAM_DEFAULT => null ] ]
		);
	}
}
