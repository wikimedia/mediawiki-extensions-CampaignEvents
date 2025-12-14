<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Participants\UnregisterParticipantCommand;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class RemoveParticipantsFromEventHandler extends SimpleHandler {
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
		$body = $this->getValidatedBody() ?? [];

		if ( is_array( $body[ 'user_ids' ] ) && !$body[ 'user_ids' ] ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-remove-participants-invalid-users-ids' ),
				400
			);
		}

		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$this->validateEventWiki( $eventRegistration );

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
			$this->getAuthority(),
			$body['invert_users'] ? UnregisterParticipantCommand::INVERT_USERS :
				UnregisterParticipantCommand::DO_NOT_INVERT_USERS
		);

		if ( !$status->isGood() ) {
			$httptStatus = $status instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $status, $httptStatus );
		}

		$removedParticipants = $status->getValue();
		return $this->getResponseFactory()->createJson( $removedParticipants );
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
		return [
			'user_ids' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_DEFAULT => null,
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'invert_users' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_DEFAULT => false,
				ParamValidator::PARAM_TYPE => 'boolean',
			],
		] + $this->getTokenParamDefinition();
	}

	private function validateEventWiki( ExistingEventRegistration $event ): void {
		$wikiID = $event->getPage()->getWikiId();
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-remove-participants-nonlocal-error-message' )
					->params( $wikiID ),
				400
			);
		}
	}
}
