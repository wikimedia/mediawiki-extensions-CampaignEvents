<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Participants\RegisterParticipantCommand;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\ParamValidator\ParamValidator;

class RegisterForEventHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
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
		$body = $this->getValidatedBody();
		$eventRegistration = $this->getRegistrationOrThrow( $this->eventLookup, $eventID );
		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$status = $this->registerParticipantCommand->registerIfAllowed(
			$eventRegistration,
			$performer,
			$body['is_private'] ?
				RegisterParticipantCommand::REGISTRATION_PRIVATE :
				RegisterParticipantCommand::REGISTRATION_PUBLIC
		);
		if ( !$status->isGood() ) {
			$httpStatus = $status instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $status, $httpStatus );
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
			array_merge(
				$this->getTokenParamDefinition(),
				$this->getBodyParams()
			)
		);
	}

	/**
	 * @return array
	 */
	protected function getBodyParams(): array {
		return [
			'is_private' =>
				[
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'boolean',
					ParamValidator::PARAM_REQUIRED => true,
				]
		];
	}
}
