<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use LogicException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsAuthority;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class UpdateEventRegistrationHandler extends AbstractEditEventRegistrationHandler {
	use EventIDParamTrait;

	/** @var IEventLookup */
	private $eventLookup;

	/**
	 * @param EventFactory $eventFactory
	 * @param PermissionChecker $permissionChecker
	 * @param EditEventCommand $editEventCommand
	 * @param OrganizersStore $organizersStore
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 * @param IEventLookup $eventLookup
	 */
	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand,
		OrganizersStore $organizersStore,
		CampaignsCentralUserLookup $centralUserLookup,
		EventQuestionsRegistry $eventQuestionsRegistry,
		IEventLookup $eventLookup
	) {
		parent::__construct(
			$eventFactory,
			$permissionChecker,
			$editEventCommand,
			$organizersStore,
			$centralUserLookup,
			$eventQuestionsRegistry
		);
		$this->eventLookup = $eventLookup;
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
	protected function getSuccessResponse( StatusValue $saveStatus ): Response {
		$warnings = $saveStatus->getErrorsByType( 'warning' );
		if ( !$warnings ) {
			return $this->getResponseFactory()->createNoContent();
		}
		$respWarnings = [];
		foreach ( $warnings as $warning ) {
			// XXX There's no standard way to format warnings.
			$respWarnings[] = [ 'key' => $warning['message'], 'params' => $warning['params'] ];
		}
		return $this->getResponseFactory()->createJson( [
			'warnings' => $respWarnings
		] );
	}

	/**
	 * @return ExistingEventRegistration
	 */
	protected function getExistingEvent(): ExistingEventRegistration {
		$id = $this->getValidatedParams()['id'];
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $id );
		$eventPageWikiID = $registration->getPage()->getWikiId();
		if ( $eventPageWikiID !== WikiAwareEntity::LOCAL ) {
			// TODO: This could redirect with a 3xx status code, but it's unclear how we may be able to obtain
			// the REST endpoint URL for external wikis (T312568).
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-rest-edit-page-nonlocal' )->params( $eventPageWikiID ),
				400
			);
		}
		return $registration;
	}

	/**
	 * @inheritDoc
	 */
	protected function checkPermissions( ICampaignsAuthority $performer ): void {
		// Nothing to check now. Deeper check will happen in EditEventCommand.
	}

	/**
	 * @inheritDoc
	 */
	protected function getBodyParams(): array {
		return array_merge(
			parent::getBodyParams(),
			[
				'status' => [
					static::PARAM_SOURCE => 'body',
					ParamValidator::PARAM_TYPE => EventRegistration::VALID_STATUSES,
					ParamValidator::PARAM_REQUIRED => true,
				]
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function createEventObject( array $body ): EventRegistration {
		$existingEvent = $this->getExistingEvent();
		$meetingType = 0;
		if ( $body['online_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_ONLINE;
		}
		if ( $body['inperson_meeting'] ) {
			$meetingType |= EventRegistration::MEETING_TYPE_IN_PERSON;
		}

		$participantQuestionNames = [];
		$currentQuestionIDs = $existingEvent->getParticipantQuestions();
		foreach ( $currentQuestionIDs as $questionID ) {
			try {
				$participantQuestionNames[] = $this->eventQuestionsRegistry->dbIDToName( $questionID );
			} catch ( UnknownQuestionException $e ) {
				// TODO This could presumably happen if a question is removed. Maybe we should just ignore it in
				// that case.
				throw new LogicException( 'Unknown question in the database', 0, $e );
			}
		}

		return $this->eventFactory->newEvent(
			$existingEvent->getID(),
			$body['event_page'],
			$body['chat_url'],
			$body['tracking_tool_id'],
			$body['tracking_tool_event_id'],
			$body['status'],
			$body['timezone'],
			$body['start_time'],
			$body['end_time'],
			// TODO MVP Get this from the request body
			EventRegistration::TYPE_GENERIC,
			$meetingType,
			$body['meeting_url'],
			$body['meeting_country'],
			$body['meeting_address'],
			$participantQuestionNames,
			$existingEvent->getCreationTimestamp(),
			$existingEvent->getLastEditTimestamp(),
			$existingEvent->getDeletionTimestamp(),
			EventFactory::VALIDATE_SKIP_DATES_PAST
		);
	}
}
