<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

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
	 * @param IEventLookup $eventLookup
	 */
	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand,
		OrganizersStore $organizersStore,
		CampaignsCentralUserLookup $centralUserLookup,
		IEventLookup $eventLookup
	) {
		parent::__construct(
			$eventFactory,
			$permissionChecker,
			$editEventCommand,
			$organizersStore,
			$centralUserLookup
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
		return $this->getResponseFactory()->createNoContent();
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

		return $this->eventFactory->newEvent(
			$existingEvent->getID(),
			$body['event_page'],
			$body['chat_url'],
			// TODO MVP Add these
			null,
			null,
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
			$existingEvent->getCreationTimestamp(),
			$existingEvent->getLastEditTimestamp(),
			$existingEvent->getDeletionTimestamp(),
			EventFactory::VALIDATE_SKIP_DATES_PAST
		);
	}
}
