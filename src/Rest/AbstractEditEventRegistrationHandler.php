<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
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
	 * @param ICampaignsAuthority $performer
	 */
	abstract protected function checkPermissions( ICampaignsAuthority $performer ): void;

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

		$performer = new MWAuthorityProxy( $this->getAuthority() );
		$this->checkPermissions( $performer );

		try {
			$event = $this->createEventObject( $body );
		} catch ( InvalidEventDataException $e ) {
			$this->exitWithStatus( $e->getStatus() );
		}

		$saveStatus = $this->editEventCommand->doEditIfAllowed( $event, $performer );

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
			'inperson_meeting' => [
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
	 * Creates an EventRegistration object with the data from the request body, with
	 * appropriate validation.
	 *
	 * @param array $body Request body data
	 * @return EventRegistration
	 * @throws InvalidEventDataException
	 */
	abstract protected function createEventObject( array $body ): EventRegistration;
}
