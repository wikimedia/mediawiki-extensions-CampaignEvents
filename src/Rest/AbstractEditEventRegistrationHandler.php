<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
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

abstract class AbstractEditEventRegistrationHandler extends Handler {
	use TokenAwareHandlerTrait;
	use FailStatusUtilTrait;

	protected EventFactory $eventFactory;
	protected PermissionChecker $permissionChecker;
	protected EditEventCommand $editEventCommand;
	private OrganizersStore $organizersStore;
	private CampaignsCentralUserLookup $centralUserLookup;
	protected EventQuestionsRegistry $eventQuestionsRegistry;
	protected WikiLookup $wikiLookup;
	protected ITopicRegistry $topicRegistry;

	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand,
		OrganizersStore $organizersStore,
		CampaignsCentralUserLookup $centralUserLookup,
		EventQuestionsRegistry $eventQuestionsRegistry,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry
	) {
		$this->eventFactory = $eventFactory;
		$this->permissionChecker = $permissionChecker;
		$this->editEventCommand = $editEventCommand;
		$this->organizersStore = $organizersStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->wikiLookup = $wikiLookup;
		$this->topicRegistry = $topicRegistry;
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
				} catch ( UserNotGlobalException $_ ) {
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
	 * @return array
	 */
	public function getBodyParamSettings(): array {
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
			'tracking_tool_id' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
			'tracking_tool_event_id' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
			],
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
			'meeting_country' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				StringDef::PARAM_MAX_BYTES => EventFactory::COUNTRY_MAXLENGTH_BYTES,
			],
			'meeting_address' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				StringDef::PARAM_MAX_BYTES => EventFactory::ADDRESS_MAXLENGTH_BYTES,
			],
			'is_test_event' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
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
