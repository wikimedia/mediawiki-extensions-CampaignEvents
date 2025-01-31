<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\DeleteEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;

class SpecialDeleteEventRegistration extends FormSpecialPage {

	public const PAGE_NAME = 'DeleteEventRegistration';

	private IEventLookup $eventLookup;
	private DeleteEventCommand $deleteEventCommand;
	private PermissionChecker $permissionChecker;

	private ?ExistingEventRegistration $event;

	public function __construct(
		IEventLookup $eventLookup,
		DeleteEventCommand $deleteEventCommand,
		PermissionChecker $permissionChecker
	) {
		parent::__construct( self::PAGE_NAME );
		$this->eventLookup = $eventLookup;
		$this->deleteEventCommand = $deleteEventCommand;
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->addHelpLink( 'Extension:CampaignEvents' );
		if ( $par === null ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-delete-error-no-event' )->escaped()
			) );
			return;
		}
		$eventID = (int)$par;
		try {
			$this->event = $this->eventLookup->getEventByID( $eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-delete-error-event-not-found' )->escaped()
			) );
			return;
		}

		if ( $this->event->getDeletionTimestamp() !== null ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-delete-error-already-deleted' )->escaped()
			) );
			return;
		}

		$eventPage = $this->event->getPage();
		$wikiID = $eventPage->getWikiId();
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			$foreignDeleteURL = WikiMap::getForeignURL(
				$wikiID, 'Special:' . self::PAGE_NAME . "/{$eventID}"
			);

			$this->setHeaders();
			$this->getOutput()->enableOOUI();
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet(
					$this->msg( 'campaignevents-delete-registration-page-nonlocal' )
						->params( [
							$foreignDeleteURL, WikiMap::getWikiName( $wikiID )
						] )->parse()
				)
			] );

			$this->getOutput()->addHTML( $messageWidget );
			return;
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	public function userCanExecute( User $user ): bool {
		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		return $this->permissionChecker->userCanDeleteRegistration( $mwAuthority, $this->event );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		return [
			'Confirm' => [
				'type' => 'info',
				'default' => $this->msg( 'campaignevents-delete-confirmation-text' )->text(),
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setSubmitDestructive();
		$form->setSubmitTextMsg( 'campaignevents-delete-submit-btn' );
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ): Status {
		$performer = new MWAuthorityProxy( $this->getAuthority() );
		return Status::wrap( $this->deleteEventCommand->deleteIfAllowed( $this->event, $performer ) );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$this->getOutput()->addHTML( Html::successBox(
			$this->msg( 'campaignevents-delete-success' )->escaped()
		) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'campaignevents';
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites(): bool {
		return true;
	}
}
