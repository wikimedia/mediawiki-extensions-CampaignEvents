<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\User\UserFactory;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\TimestampDef;

abstract class AbstractEditEventRegistrationHandler extends Handler {
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	/** @var EventFactory */
	protected $eventFactory;
	/** @var PermissionChecker */
	protected $permissionChecker;
	/** @var EditEventCommand */
	protected $editEventCommand;
	/** @var UserFactory */
	protected $userFactory;

	/**
	 * @param EventFactory $eventFactory
	 * @param PermissionChecker $permissionChecker
	 * @param EditEventCommand $editEventCommand
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand,
		UserFactory $userFactory
	) {
		$this->eventFactory = $eventFactory;
		$this->permissionChecker = $permissionChecker;
		$this->editEventCommand = $editEventCommand;
		$this->userFactory = $userFactory;
	}

	/**
	 * @return int|null
	 */
	abstract protected function getEventID(): ?int;

	/**
	 * @param ICampaignsUser $user
	 */
	abstract protected function checkPermissions( ICampaignsUser $user ): void;

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$body = $this->getValidatedBody();

		$token = $this->getToken();
		if (
			$token !== null &&
			!$this->userFactory->newFromAuthority( $this->getAuthority() )->matchEditToken( $token )
		) {
			throw new LocalizedHttpException( $this->getBadTokenMessage(), 400 );
		}

		$eventID = $this->getEventID();

		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );

		$this->checkPermissions( $user );

		$meetingType = 0;
		if ( $body['online_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_ONLINE;
		}
		if ( $body['physical_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_PHYSICAL;
		}

		try {
			$event = $this->eventFactory->newEvent(
				$eventID,
				$body['event_page'],
				$body['chat_url'],
				// TODO MVP Add these
				null,
				null,
				$this->getEventStatus(),
				$body['start_time'],
				$body['end_time'],
				// TODO MVP Get this from the request body
				EventRegistration::TYPE_GENERIC,
				$meetingType,
				$body['meeting_url'],
				$body['meeting_country'],
				$body['meeting_address'],
				null,
				null,
				null
			);
		} catch ( InvalidEventDataException $e ) {
			$this->exitWithStatus( $e->getStatus() );
		}

		$saveStatus = $this->editEventCommand->doEditIfAllowed( $event, $user );

		if ( !$saveStatus->isGood() ) {
			$httptStatus = $saveStatus instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $saveStatus, $httptStatus );
		}

		return $this->getSuccessResponse( $saveStatus );
	}

	/**
	 * @param StatusValue $saveStatus
	 * @return Response
	 */
	abstract protected function getSuccessResponse( StatusValue $saveStatus ): Response;

	/**
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ): BodyValidator {
		if ( $contentType !== 'application/json' ) {
			throw new HttpException( "Unsupported Content-Type",
				415,
				[ 'content_type' => $contentType ]
			);
		}

		// NOTE: The param types are not validated yet, see T305973
		return new JsonBodyValidator( $this->getBodyParams() );
	}

	/**
	 * @return array
	 */
	protected function getBodyParams(): array {
		return [
			'event_page' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'chat_url' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			/* TODO MVP: Re-add these
			'tracking_tool_name' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'tracking_tool_url' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			*/
			'start_time' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'timestamp',
				TimestampDef::PARAM_TIMESTAMP_FORMAT => TS_MW,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'end_time' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'timestamp',
				TimestampDef::PARAM_TIMESTAMP_FORMAT => TS_MW,
				ParamValidator::PARAM_REQUIRED => true,
			],
			/* TODO MVP: Re-add this
			'type' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => EventRegistration::VALID_TYPES,
				ParamValidator::PARAM_REQUIRED => true,
			],
			*/
			'online_meeting' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
			],
			'physical_meeting' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
			],
			'meeting_url' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'meeting_country' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'meeting_address' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
		] + $this->getTokenParamDefinition();
	}

	/**
	 * @return string
	 */
	abstract protected function getEventStatus(): string;
}
