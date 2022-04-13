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
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\TimestampDef;

abstract class AbstractEventRegistrationHandler extends Handler {
	use CSRFCheckTrait;
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
	 * @param array $body
	 * @return int|null
	 */
	abstract protected function getEventID( array $body ): ?int;

	/**
	 * @param ICampaignsUser $user
	 */
	abstract protected function checkPermissions( ICampaignsUser $user ): void;

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->assertCSRFSafety();

		$body = $this->getValidatedParams();

		$eventID = $this->getEventID( $body );

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
				$body['name'],
				$body['event_page'],
				$body['chat_url'],
				$body['tracking_tool_name'],
				$body['tracking_tool_url'],
				EventRegistration::STATUS_OPEN,
				$body['start_time'],
				$body['end_time'],
				$body['type'],
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
	public function getParamSettings(): array {
		return [
			'name' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'event_page' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'chat_url' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'tracking_tool_name' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'tracking_tool_url' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'start_time' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'timestamp',
				TimestampDef::PARAM_TIMESTAMP_FORMAT => TS_MW,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'end_time' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'timestamp',
				TimestampDef::PARAM_TIMESTAMP_FORMAT => TS_MW,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'type' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => EventRegistration::VALID_TYPES,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'online_meeting' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'boolean',
			],
			'physical_meeting' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'boolean',
			],
			'meeting_url' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'meeting_country' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'meeting_address' => [
				static::PARAM_SOURCE => 'post',
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}
}
