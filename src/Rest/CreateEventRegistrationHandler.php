<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use ApiMessage;
use InvalidArgumentException;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Store\EventStore;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use StatusValue;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\TimestampDef;

class CreateEventRegistrationHandler extends Handler {
	use CSRFCheckTrait;

	/** @var EventFactory */
	private $eventFactory;
	/** @var EventStore */
	private $eventStore;
	/** @var PermissionChecker */
	private $permissionChecker;

	/**
	 * @param EventFactory $eventFactory
	 * @param EventStore $eventStore
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		EventFactory $eventFactory,
		EventStore $eventStore,
		PermissionChecker $permissionChecker
	) {
		$this->eventFactory = $eventFactory;
		$this->eventStore = $eventStore;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->assertCSRFSafety();

		$body = $this->getValidatedParams();

		$performerAuthority = $this->getAuthority();
		$user = new MWUserProxy( $performerAuthority->getUser(), $performerAuthority );

		if ( !$this->permissionChecker->userCanCreateRegistrations( $user ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-createevent-permission-denied' ),
				403
			);
		}

		$meetingType = 0;
		if ( $body['online_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_ONLINE;
		}
		if ( $body['physical_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_PHYSICAL;
		}
		try {
			$event = $this->eventFactory->newEvent(
				null,
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

		if ( !$this->permissionChecker->userCanCreateRegistration( $user, $event->getPage() ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'campaignevents-rest-createevent-permission-denied-page' ),
				403
			);
		}

		$saveStatus = $this->eventStore->saveRegistration( $event );
		if ( !$saveStatus->isGood() ) {
			$this->exitWithStatus( $saveStatus );
		}
		// TODO Set status code 201 when we'll be able to provide a Location
		return $this->getResponseFactory()->createJson( [
			'id' => $saveStatus->getValue()
		] );
	}

	/**
	 * @param StatusValue $status
	 * @return never
	 */
	private function exitWithStatus( StatusValue $status ): void {
		$errors = $status->getErrors();
		if ( !$errors ) {
			throw new InvalidArgumentException( "Got status without errors" );
		}
		// TODO Report all errors, not just the first one.
		$apiMsg = ApiMessage::create( $errors[0] );
		throw new LocalizedHttpException( new MessageValue( $apiMsg->getKey(), $apiMsg->getParams() ), 400 );
	}

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
