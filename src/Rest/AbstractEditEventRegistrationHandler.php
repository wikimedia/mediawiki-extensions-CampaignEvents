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
use MediaWiki\Rest\Response;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\BodyValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use MediaWiki\Rest\Validator\Validator;
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

	/**
	 * @param EventFactory $eventFactory
	 * @param PermissionChecker $permissionChecker
	 * @param EditEventCommand $editEventCommand
	 */
	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand
	) {
		$this->eventFactory = $eventFactory;
		$this->permissionChecker = $permissionChecker;
		$this->editEventCommand = $editEventCommand;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
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
			// TODO MVP: Add tracking tool
			'timezone' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
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
