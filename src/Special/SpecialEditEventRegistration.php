<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use WikiMap;

class SpecialEditEventRegistration extends AbstractEventRegistrationSpecialPage {
	public const PAGE_NAME = 'EditEventRegistration';

	/** @var PermissionChecker */
	private $permissionChecker;

	/**
	 * @param IEventLookup $eventLookup
	 * @param EventFactory $eventFactory
	 * @param EditEventCommand $editEventCommand
	 * @param PermissionChecker $permissionChecker
	 * @param PolicyMessagesLookup $policyMessagesLookup
	 */
	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand,
		PermissionChecker $permissionChecker,
		PolicyMessagesLookup $policyMessagesLookup
	) {
		parent::__construct(
			self::PAGE_NAME,
			'',
			$eventLookup,
			$eventFactory,
			$editEventCommand,
			$policyMessagesLookup
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

		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		if ( !$this->permissionChecker->userCanEditRegistration( $mwAuthority, $this->eventID ) ) {
			$this->outputErrorBox( 'campaignevents-edit-not-allowed-registration' );
			return;
		}

		if ( $this->event->getDeletionTimestamp() !== null ) {
			$this->outputErrorBox( 'campaignevents-edit-error-deleted' );
			return;
		}

		$eventPage = $this->event->getPage();
		$wikiID = $eventPage->getWikiId();
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			$foreignEditURL = WikiMap::getForeignURL( $wikiID, 'Special:' . self::PAGE_NAME . "/{$this->eventID}" );
			$this->outputErrorBox( 'campaignevents-edit-page-nonlocal', $foreignEditURL, $wikiID );
			return;
		}
		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getValidationFlags(): int {
		return EventFactory::VALIDATE_SKIP_DATES_PAST;
	}
}
