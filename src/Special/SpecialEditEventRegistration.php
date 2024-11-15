<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;

class SpecialEditEventRegistration extends AbstractEventRegistrationSpecialPage {
	public const PAGE_NAME = 'EditEventRegistration';

	public function __construct(
		IEventLookup $eventLookup,
		EventFactory $eventFactory,
		EditEventCommand $editEventCommand,
		PermissionChecker $permissionChecker,
		PolicyMessagesLookup $policyMessagesLookup,
		OrganizersStore $organizersStore,
		CampaignsCentralUserLookup $centralUserLookup,
		TrackingToolRegistry $trackingToolRegistry,
		EventQuestionsRegistry $eventQuestionsRegistry,
		CampaignEventsHookRunner $hookRunner,
		PageURLResolver $pageURLResolver,
		WikiLookup $wikiLookup
	) {
		parent::__construct(
			self::PAGE_NAME,
			'',
			$eventLookup,
			$eventFactory,
			$editEventCommand,
			$policyMessagesLookup,
			$organizersStore,
			$permissionChecker,
			$centralUserLookup,
			$trackingToolRegistry,
			$eventQuestionsRegistry,
			$hookRunner,
			$pageURLResolver,
			$wikiLookup
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormMessages(): array {
		return [
			'details-section-subtitle' => 'campaignevents-edit-form-details-subtitle',
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
		if ( !$this->permissionChecker->userCanEditRegistration( $mwAuthority, $this->event ) ) {
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

			$this->setHeaders();
			$this->getOutput()->enableOOUI();

			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => new HtmlSnippet(
					$this->msg( 'campaignevents-edit-page-nonlocal' )
						->params( [
							$foreignEditURL, WikiMap::getWikiName( $wikiID )
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
	protected function getValidationFlags(): int {
		return EventFactory::VALIDATE_SKIP_DATES_PAST;
	}

	/**
	 * @inheritDoc
	 */
	protected function getShowAlways(): bool {
		return true;
	}
}
