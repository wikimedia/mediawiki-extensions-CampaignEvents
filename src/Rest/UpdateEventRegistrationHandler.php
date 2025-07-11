<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use LogicException;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Questions\UnknownQuestionException;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use StatusValue;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class UpdateEventRegistrationHandler extends AbstractEditEventRegistrationHandler {
	use EventIDParamTrait;

	private IEventLookup $eventLookup;

	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand,
		OrganizersStore $organizersStore,
		CampaignsCentralUserLookup $centralUserLookup,
		EventQuestionsRegistry $eventQuestionsRegistry,
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		EventTypesRegistry $eventTypesRegistry,
		IEventLookup $eventLookup
	) {
		parent::__construct(
			$eventFactory,
			$permissionChecker,
			$editEventCommand,
			$organizersStore,
			$centralUserLookup,
			$eventQuestionsRegistry,
			$wikiLookup,
			$topicRegistry,
			$eventTypesRegistry,
		);
		$this->eventLookup = $eventLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings(): array {
		return $this->getIDParamSetting() + parent::getParamSettings();
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyParamSettings(): array {
		return [
			'status' => [
				static::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => EventRegistration::VALID_STATUSES,
				ParamValidator::PARAM_REQUIRED => true,
			]
		] + parent::getBodyParamSettings();
	}

	protected function getSuccessResponse( StatusValue $saveStatus ): Response {
		$warnings = $saveStatus->getMessages( 'warning' );
		if ( !$warnings ) {
			return $this->getResponseFactory()->createNoContent();
		}
		$respWarnings = [];
		foreach ( $warnings as $msg ) {
			// XXX There's no standard way to format warnings.
			$respWarnings[] = [ 'key' => $msg->getKey(), 'params' => $msg->getParams() ];
		}
		return $this->getResponseFactory()->createJson( [
			'warnings' => $respWarnings
		] );
	}

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

	protected function checkPermissions( Authority $performer ): void {
		// Nothing to check now. Deeper check will happen in EditEventCommand.
	}

	/**
	 * @inheritDoc
	 */
	protected function createEventObject( array $body ): EventRegistration {
		$existingEvent = $this->getExistingEvent();
		$participationOptions = 0;
		if ( $body['online_meeting'] ) {
			$participationOptions |= EventRegistration::PARTICIPATION_OPTION_ONLINE;
		}
		if ( $body['inperson_meeting'] ) {
			$participationOptions |= EventRegistration::PARTICIPATION_OPTION_IN_PERSON;
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

		$rawWikis = $body['wikis'] ?? [];
		$allWikis = $this->wikiLookup->getAllWikis();
		// Compare the counts, not the arrays, because order does not matter
		$wikis = count( $rawWikis ) === count( $allWikis ) ? EventRegistration::ALL_WIKIS : $rawWikis;

		return $this->eventFactory->newEvent(
			$existingEvent->getID(),
			$body['event_page'],
			$body['status'],
			$body['timezone'],
			$body['start_time'],
			$body['end_time'],
			$body['types'],
			$wikis,
			$body['topics'] ?? [],
			$body['tracking_tool_id'],
			$body['tracking_tool_event_id'],
			$participationOptions,
			$body['meeting_url'],
			$body['meeting_country'],
			null,
			$body['meeting_address'],
			$body['chat_url'],
			$body['is_test_event'],
			$participantQuestionNames,
			$existingEvent->getCreationTimestamp(),
			$existingEvent->getLastEditTimestamp(),
			$existingEvent->getDeletionTimestamp(),
			EventFactory::VALIDATE_SKIP_DATES_PAST | EventFactory::VALIDATE_SKIP_UNCHANGED_EVENT_PAGE_NAMESPACE,
			$existingEvent->getPage()
		);
	}
}
