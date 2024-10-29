<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;

class SpecialEnableEventRegistration extends AbstractEventRegistrationSpecialPage {
	public const PAGE_NAME = 'EnableEventRegistration';

	private PageURLResolver $pageUrlResolver;

	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand,
		PolicyMessagesLookup $policyMessagesLookup,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		CampaignsCentralUserLookup $centralUserLookup,
		TrackingToolRegistry $trackingToolRegistry,
		EventQuestionsRegistry $eventQuestionsRegistry,
		CampaignEventsHookRunner $hookRunner,
		PageURLResolver $pageURLResolver
	) {
		parent::__construct(
			self::PAGE_NAME,
			PermissionChecker::ENABLE_REGISTRATIONS_RIGHT,
			$eventLookup,
			$eventFactory,
			$editEventCommand,
			$policyMessagesLookup,
			$organizersStore,
			$permissionChecker,
			$centralUserLookup,
			$trackingToolRegistry,
			$eventQuestionsRegistry,
			$hookRunner
		);
		$this->pageUrlResolver = $pageURLResolver;
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		$out = $this->getOutput();
		$session = $out->getRequest()->getSession();
		// Use session variables, as opposed to query parameters, so that the notification will only be seen once, and
		// not on every page refresh (and possibly end up in shared links etc.)
		$session->set( self::REGISTRATION_UPDATED_SESSION_KEY, 1 );
		$warningMessages = $this->saveWarningsStatus->getMessages();
		if ( $warningMessages ) {
			$warningMessagesText = array_map(
				fn ( $msg ) => $this->msg( $msg )->text(),
				$warningMessages
			);
			$session->set( self::REGISTRATION_UPDATED_WARNINGS_SESSION_KEY, $warningMessagesText );
		}
		$out->redirect( $this->pageUrlResolver->getUrl( $this->eventPage ) );
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormMessages(): array {
		return [
			'success' => 'campaignevents-enable-registration-success-msg',
			'details-section-subtitle' => 'campaignevents-edit-form-details-subtitle',
			'submit' => 'campaignevents-enable-registration-form-submit',
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getValidationFlags(): int {
		return EventFactory::VALIDATE_ALL;
	}
}
