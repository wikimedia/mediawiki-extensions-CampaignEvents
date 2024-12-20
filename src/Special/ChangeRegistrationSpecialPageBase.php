<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use StatusValue;

abstract class ChangeRegistrationSpecialPageBase extends FormSpecialPage {
	private IEventLookup $eventLookup;

	protected CampaignsCentralUserLookup $centralUserLookup;
	protected ?ExistingEventRegistration $event = null;

	/**
	 * @param string $name
	 * @param IEventLookup $eventLookup
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 */
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
		if ( $wikiID !== WikiAwareEntity::LOCAL ) {
			$foreignEditURL = WikiMap::getForeignURL(
				$wikiID, 'Special:' . SpecialRegisterForEvent::PAGE_NAME . "/{$this->event->getID()}"
			);

			$this->setHeaders();
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet(
					$this->msg( 'campaignevents-register-page-nonlocal' )
						->params( [
							$foreignEditURL, WikiMap::getWikiName( $wikiID )
						] )->parse()
				)
			] );

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

	/**
	 * @param string|null $par
	 * @return bool
	 */
	protected function checkEventExists( ?string $par ) {
		if ( $par === null ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-register-error-no-event' )->escaped()
			) );
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
}
