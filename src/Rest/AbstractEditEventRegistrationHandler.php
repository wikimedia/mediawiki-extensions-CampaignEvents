<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\InvalidEventDataException;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use RuntimeException;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;
use Wikimedia\ParamValidator\TypeDef\TimestampDef;
use Wikimedia\Timestamp\TimestampFormat as TS;

abstract class AbstractEditEventRegistrationHandler extends Handler {
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	public function __construct(
		protected readonly EventFactory $eventFactory,
		protected readonly PermissionChecker $permissionChecker,
		protected readonly EditEventCommand $editEventCommand,
		private readonly OrganizersStore $organizersStore,
		protected readonly CampaignsCentralUserLookup $centralUserLookup,
		protected readonly EventQuestionsRegistry $eventQuestionsRegistry,
		protected readonly WikiLookup $wikiLookup,
		protected readonly ITopicRegistry $topicRegistry,
		private readonly EventTypesRegistry $eventTypesRegistry,
		private readonly CountryProvider $countryProvider,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken();
	}

	abstract protected function checkPermissions( Authority $performer ): void;

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$body = $this->getValidatedBody();

		$performer = $this->getAuthority();
		$this->checkPermissions( $performer );

		try {
			$event = $this->createEventObject( $body );
		} catch ( InvalidEventDataException $e ) {
			$this->exitWithStatus( $e->getStatus() );
		}

		$eventID = $event->getID();
		if ( $eventID === null ) {
			$organizerNames = [ $this->getAuthority()->getUser()->getName() ];
		} else {
			$organizers = $this->organizersStore->getEventOrganizers( $eventID );
			$organizerNames = [];
			foreach ( $organizers as $organizer ) {
				$user = $organizer->getUser();
				try {
					$organizerNames[] = $this->centralUserLookup->getUserName( $user );
				} catch ( UserNotGlobalException ) {
					// Should never happen.
					throw new RuntimeException( "Organizer in the database has no central account." );
				}
			}
		}

		$saveStatus = $this->editEventCommand->doEditIfAllowed( $event, $performer, $organizerNames );
		if ( !$saveStatus->isOK() ) {
			$httptStatus = $saveStatus instanceof PermissionStatus ? 403 : 400;
			$this->exitWithStatus( $saveStatus, $httptStatus );
		}

		return $this->getSuccessResponse( $saveStatus );
	}

	abstract protected function getSuccessResponse( StatusValue $saveStatus ): Response;

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function getBodyParamSettings(): array {
		$params = [
			'event_page' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'timezone' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'start_time' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'timestamp',
				TimestampDef::PARAM_TIMESTAMP_FORMAT => TS::MW,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'end_time' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'timestamp',
				TimestampDef::PARAM_TIMESTAMP_FORMAT => TS::MW,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'types' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => $this->eventTypesRegistry->getAllTypes(),
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => EventFactory::MAX_TYPES,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'wikis' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => $this->wikiLookup->getAllWikis(),
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => EventFactory::MAX_WIKIS,
				ParamValidator::PARAM_ALL => true,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'topics' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => $this->topicRegistry->getAllTopics(),
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => EventFactory::MAX_TOPICS,
			],
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
			'meeting_country_code' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => $this->countryProvider->getValidCountryCodes()
			],
			'meeting_address' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				StringDef::PARAM_MAX_BYTES => EventFactory::ADDRESS_MAXLENGTH_BYTES,
			],
			'tracks_contributions' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'tracking_tool_id' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'tracking_tool_event_id' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'chat_url' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'is_test_event' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
		] + $this->getTokenParamDefinition();

		return $params;
	}

	/**
	 * Creates an EventRegistration object with the data from the request body, with
	 * appropriate validation.
	 *
	 * @param array<string,mixed> $body Request body data
	 * @throws InvalidEventDataException
	 */
	abstract protected function createEventObject( array $body ): EventRegistration;
}
