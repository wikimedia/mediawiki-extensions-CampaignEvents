<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWUserProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;

class SpecialEditEventRegistration extends AbstractEventRegistrationSpecialPage {
	public const PAGE_NAME = 'EditEventRegistration';

	/** @var PermissionChecker */
	private $permissionChecker;

	/**
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param EditEventCommand $editEventCommand
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand,
		PermissionChecker $permissionChecker
	) {
		parent::__construct(
			self::PAGE_NAME,
			'',
			$eventLookup,
			$eventFactory,
			$editEventCommand
		);
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormMessages(): array {
		return [
			'success' => 'campaignevents-edit-success-msg',
			'form-legend' => 'campaignevents-edit-form-legend',
			'submit' => 'campaignevents-edit-form-submit',
		];
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		if ( $par === null ) {
			$this->outputErrorBox( 'campaignevents-edit-no-event-id-provided' );
			return;
		}
		$this->eventID = (int)$par;
		if ( (string)$this->eventID !== $par ) {
			$this->outputErrorBox( 'campaignevents-edit-invalid-id' );
			return;
		}
		try {
			$this->event = $this->eventLookup->getEventByID( $this->eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->outputErrorBox( 'campaignevents-edit-event-notfound' );
			return;
		}

		$mwUser = new MWUserProxy( $this->getUser(), $this->getAuthority() );
		if ( !$this->permissionChecker->userCanEditRegistration( $mwUser, $this->eventID ) ) {
			$this->outputErrorBox( 'campaignevents-edit-not-allowed-registration' );
			return;
		}

		if ( $this->event->getDeletionTimestamp() !== null ) {
			$this->outputErrorBox( 'campaignevents-edit-error-deleted' );
			return;
		}

		$eventPage = $this->event->getPage();
		if ( $eventPage->getWikiId() !== WikiAwareEntity::LOCAL ) {
			$this->outputErrorBox( 'campaignevents-edit-page-nonlocal', $eventPage->getWikiId() );
			return;
		}
		parent::execute( $par );
	}
}
