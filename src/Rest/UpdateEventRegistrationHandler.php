<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\ICampaignsUser;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\User\UserFactory;
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
	 * @param UserFactory $userFactory
	 * @param IEventLookup $eventLookup
	 */
	public function __construct(
		EventFactory $eventFactory,
		PermissionChecker $permissionChecker,
		EditEventCommand $editEventCommand,
		UserFactory $userFactory,
		IEventLookup $eventLookup
	) {
		parent::__construct( $eventFactory, $permissionChecker, $editEventCommand, $userFactory );
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
	 * @inheritDoc
	 */
	protected function getEventID(): int {
		$id = $this->getValidatedParams()['id'];
		$registration = $this->getRegistrationOrThrow( $this->eventLookup, $id );
		$eventPageWikiID = $registration->getPage()->getWikiId();
		if ( $eventPageWikiID !== WikiAwareEntity::LOCAL ) {
			// TODO: This could redirect with a 3xx status code, but it's unclear how we may be able to obtain
			// the REST endpoint URL for external wikis.
			throw new LocalizedHttpException(
				MessageValue::new( 'campaignevents-edit-page-nonlocal' )->params( $eventPageWikiID ),
				400
			);
		}
		return $id;
	}

	/**
	 * @inheritDoc
	 */
	protected function checkPermissions( ICampaignsUser $user ): void {
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
	 * @return string
	 */
	protected function getEventStatus(): string {
		return $this->getValidatedBody()['status'];
	}

	/**
	 * @inheritDoc
	 */
	protected function getValidationFlags(): int {
		return EventFactory::VALIDATE_SKIP_DATES_PAST;
	}
}
