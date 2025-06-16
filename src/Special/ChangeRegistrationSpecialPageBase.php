<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use StatusValue;

abstract class ChangeRegistrationSpecialPageBase extends FormSpecialPage {
	private IEventLookup $eventLookup;

	protected CampaignsCentralUserLookup $centralUserLookup;
	protected ?ExistingEventRegistration $event = null;

	public function __construct(
		string $name,
		IEventLookup $eventLookup,
		CampaignsCentralUserLookup $centralUserLookup
	) {
		parent::__construct( $name );
		$this->eventLookup = $eventLookup;
		$this->centralUserLookup = $centralUserLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->requireNamedUser();
		$this->addHelpLink( 'Extension:CampaignEvents' );
		$eventExists = $this->checkEventExists( $par );
		if ( !$eventExists ) {
			return;
		}
		// For styling Html::errorBox
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
		] );
		$validationResult = $this->checkEventIsValid();
		if ( !$validationResult->isGood() ) {
			$this->setHeaders();
			foreach ( $validationResult->getMessages( 'error' ) as $error ) {
				$this->getOutput()->addHTML( Html::errorBox(
					$this->msg( $error )->escaped()
				) );
			}
			return;
		}

		$eventPage = $this->event->getPage();
		$wikiID = $eventPage->getWikiId();
		$pageName = $this instanceof SpecialCancelEventRegistration ?
			SpecialCancelEventRegistration::PAGE_NAME :
			SpecialRegisterForEvent::PAGE_NAME;
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			$foreignEditURL = WikiMap::getForeignURL(
				$wikiID, 'Special:' . $pageName . "/{$this->event->getID()}"
			);
			$message = $this instanceof SpecialCancelEventRegistration ?
				'campaignevents-cancel-page-nonlocal' :
				'campaignevents-register-page-nonlocal';
			$this->setHeaders();
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet(
					$this->msg( $message )
						->params( [
							$foreignEditURL, WikiMap::getWikiName( $wikiID )
						] )->parse()
				)
			] );
			$this->getOutput()->enableOOUI();
			$this->getOutput()->addHTML( $messageWidget );
			return;
		}

		$preconditionResult = $this->checkRegistrationPrecondition();
		if ( is_string( $preconditionResult ) ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( $preconditionResult )->escaped()
			) );
			return;
		}
		parent::execute( $par );
	}

	/**
	 * Checks whether the user can perform this action. In particular, this is used to show an error if the user
	 * is not registered for this event and they try to cancel their registration.
	 *
	 * @return string|true Error message key, or true if OK.
	 */
	protected function checkRegistrationPrecondition() {
		return true;
	}

	/**
	 * Checks whether the event is in a state which can currently accept registrations. Specifically, that it
	 * is not over, deleted or closed.
	 *
	 * @return StatusValue
	 */
	protected function checkEventIsValid(): StatusValue {
		return StatusValue::newGood();
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

	/**
	 * @inheritDoc
	 */
	protected function getMessagePrefix() {
		return '';
	}

	protected function checkEventExists( ?string $par ): bool {
		if ( $par === null ) {
			$this->setHeaders();
			$this->outputHeader();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-edit-no-event-id' )->parseAsBlock()
			) );
			$this->showEventIDForm();
			return false;
		}
		$eventID = (int)$par;
		try {
			$this->event = $this->eventLookup->getEventByID( $eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-register-error-event-not-found' )->escaped()
			) );
			return false;
		}
		return true;
	}

	private function showEventIDForm(): void {
		HTMLForm::factory(
			'ooui',
			[
				'eventId' => [
					'type' => 'int',
					'name' => 'eventId',
					'label-message' => 'campaignevents-register-event-id',
				],
			],
			$this->getContext()
		)
			->setSubmitCallback( [ $this, 'onFormSubmit' ] )
			->show();
	}

	/**
	 * @param array<string,mixed> $formData
	 */
	public function onFormSubmit( array $formData ): void {
		$eventId = $formData['eventId'];
		$title = $this->getPageTitle( $eventId ?: null );
		$url = $title->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
	}
}
