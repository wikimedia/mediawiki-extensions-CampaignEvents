<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Config\Config;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\CampaignEvents\Event\EditEventCommand;
use MediaWiki\Extension\CampaignEvents\Event\EventFactory;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesFormatter;
use MediaWiki\Extension\CampaignEvents\Event\EventTypesRegistry;
use MediaWiki\Extension\CampaignEvents\Event\Store\EventNotFoundException;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\Hooks\CampaignEventsHookRunner;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\WikiLookup;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\PolicyMessagesLookup;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Topics\ITopicRegistry;
use MediaWiki\Extension\CampaignEvents\TrackingTool\TrackingToolRegistry;
use MediaWiki\HTMLForm\HTMLForm;
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
		WikiLookup $wikiLookup,
		ITopicRegistry $topicRegistry,
		Config $wikiConfig,
		EventTypesFormatter $eventTypesFormatter,
		EventTypesRegistry $eventTypesRegistry
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
			$wikiLookup,
			$topicRegistry,
			$wikiConfig,
			$eventTypesFormatter,
			$eventTypesRegistry
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
			$this->outputErrorBox( 'campaignevents-edit-no-event-id' );
			$this->showEventIDForm();
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

		if ( !$this->permissionChecker->userCanEditRegistration( $this->getAuthority(), $this->event ) ) {
			$this->outputErrorBox( 'campaignevents-edit-not-allowed-registration' );
			return;
		}

		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	protected function getValidationFlags(): int {
		return EventFactory::VALIDATE_SKIP_DATES_PAST | EventFactory::VALIDATE_SKIP_UNCHANGED_EVENT_PAGE_NAMESPACE;
	}

	/**
	 * @inheritDoc
	 */
	protected function getShowAlways(): bool {
		return true;
	}

	protected function showEventIDForm(): void {
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

	public function onFormSubmit( array $formData ): void {
		$eventId = $formData['eventId'];
		$title = $this->getPageTitle( $eventId ?: null );
		$url = $title->getFullUrlForRedirect();
		$this->getOutput()->redirect( $url );
	}
}
