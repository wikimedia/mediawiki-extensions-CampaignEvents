<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Messaging\CampaignsUserMailer;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\UnsupportedContentTypeBodyValidator;
use MediaWiki\Rest\Validator\Validator;
use Wikimedia\ParamValidator\ParamValidator;

class EmailUsersHandler extends SimpleHandler {
	use EventIDParamTrait;
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	private CampaignsUserMailer $userMailer;
	private PermissionChecker $permissionChecker;
	private ParticipantsStore $participantsStore;
	private IEventLookup $eventLookup;

	/**
	 * @param PermissionChecker $permissionChecker
	 * @param CampaignsUserMailer $userMailer
	 * @param ParticipantsStore $participantsStore
	 * @param IEventLookup $eventLookup
	 */
	public function __construct(
		PermissionChecker $permissionChecker,
		CampaignsUserMailer $userMailer,
		ParticipantsStore $participantsStore,
		IEventLookup $eventLookup
	) {
		$this->permissionChecker = $permissionChecker;
		$this->userMailer = $userMailer;
		$this->participantsStore = $participantsStore;
		$this->eventLookup = $eventLookup;
	}

	/**
	 * @param int $eventId
	 * @return Response
	 */
	public function run( int $eventId ): Response {
		$event = $this->getRegistrationOrThrow( $this->eventLookup, $eventId );
		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$params = $this->getValidatedBody() ?? [];

		if ( !$this->permissionChecker->userCanEmailParticipants( $performer, $eventId ) ) {
			// todo add more details to error message
			return $this->getResponseFactory()->createHttpError( 403 );
		}
		$userIds = $params['user_ids'] ? array_map( 'intval', $params['user_ids'] ) : [];
		$participants = $this->participantsStore->getEventParticipants(
			$eventId,
			null,
			null,
			null,
			$params['invert_users'] ? null : $userIds,
			true,
			$params['invert_users'] ? $userIds : null
		);
		if ( !$participants ) {
			return $this->getResponseFactory()->createJson( [ 'sent' => 0 ] );
		}
		$result = $this->userMailer->sendEmail(
			$this->getAuthority(),
			$participants,
			$params['subject'],
			$params['message'],
			$event
		);

		if ( !$result->isGood() ) {
			$this->exitWithStatus( $result );
		}
		$resp = $this->getResponseFactory()->createJson( [ 'sent' => $result->getValue() ] );
		$resp->setStatus( 202 );
		return $resp;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
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
			array_merge(
				$this->getBodyParams(),
				$this->getTokenParamDefinition()
			)
		);
	}

	/**
	 * @return array
	 */
	private function getBodyParams(): array {
		return [
				'user_ids' => [
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'array',
					ParamValidator::PARAM_REQUIRED => false,
				],
				'invert_users' => [
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'bool',
					ParamValidator::PARAM_REQUIRED => false,
					ParamValidator::PARAM_DEFAULT => false,
				],
				'message' => [
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => true,
				],
				'subject' => [
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => 'string',
					ParamValidator::PARAM_REQUIRED => true,
				]
			];
	}
}
