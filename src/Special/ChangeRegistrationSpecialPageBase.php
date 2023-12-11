<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;

abstract class ChangeRegistrationSpecialPageBase extends FormSpecialPage {
	/** @var IEventLookup */
	private $eventLookup;

	/** @var CampaignsCentralUserLookup */
	protected $centralUserLookup;
	/** @var ExistingEventRegistration|null */
	protected $event;

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
		if ( $par === null ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-register-error-no-event' )->escaped()
			) );
			return;
		}
		$eventID = (int)$par;
		try {
			$this->event = $this->eventLookup->getEventByID( $eventID );
		} catch ( EventNotFoundException $_ ) {
			$this->setHeaders();
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-register-error-event-not-found' )->escaped()
			) );
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
}
