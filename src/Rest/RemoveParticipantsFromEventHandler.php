<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class RemoveParticipantsFromEventHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	private IEventLookup $eventLookup;
	private UnregisterParticipantCommand $unregisterParticipantCommand;

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
		$body = $this->getValidatedBody() ?? [];

		if ( is_array( $body[ 'user_ids' ] ) && !$body[ 'user_ids' ] ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-remove-participants-invalid-users-ids' ),
				400
			);
		}

		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );

		if ( $body['user_ids'] ) {
			$usersToRemove = [];
			foreach ( $body['user_ids'] as $id ) {
				$usersToRemove[] = new CentralUser( (int)$id );
			}
		} else {
			$usersToRemove = null;
		}

		$status = $this->unregisterParticipantCommand->removeParticipantsIfAllowed(
			$eventRegistration,
			$usersToRemove,
			new MWAuthorityProxy( $this->getAuthority() ),
			$body['invert_users'] ? UnregisterParticipantCommand::INVERT_USERS :
				UnregisterParticipantCommand::DO_NOT_INVERT_USERS
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
			return new UnsupportedContentTypeBodyValidator( $contentType );
		}

		return new JsonBodyValidator(
			[
				// TODO Here we should specify that each ID must be an integer. Right now this wouldn't
				// work anyway due to T305973.
				'user_ids' => [
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_DEFAULT => null
				],
				'invert_users' => [
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_DEFAULT => false,
					ParamValidator::PARAM_TYPE => 'boolean',
				],
			] + $this->getTokenParamDefinition()
		);
	}
}
