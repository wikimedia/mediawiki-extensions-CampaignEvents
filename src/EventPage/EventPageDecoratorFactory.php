<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use Language;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use Wikimedia\Message\IMessageFormatterFactory;

class EventPageDecoratorFactory {
	public const SERVICE_NAME = 'CampaignEventsEventPageDecoratorFactory';

	private PageEventLookup $pageEventLookup;
	private ParticipantsStore $participantsStore;
	private OrganizersStore $organizersStore;
	private PermissionChecker $permissionChecker;
	private IMessageFormatterFactory $messageFormatterFactory;
	private LinkRenderer $linkRenderer;
	private CampaignsPageFactory $campaignsPageFactory;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserLinker $userLinker;
	private EventTimeFormatter $eventTimeFormatter;
	private EventPageCacheUpdater $eventPageCacheUpdater;
	private EventQuestionsRegistry $eventQuestionsRegistry;

	/**
	 * @param PageEventLookup $pageEventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param LinkRenderer $linkRenderer
	 * @param CampaignsPageFactory $campaignsPageFactory
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserLinker $userLinker
	 * @param EventTimeFormatter $eventTimeFormatter
	 * @param EventPageCacheUpdater $eventPageCacheUpdater
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 *
	 */
	public function __construct(
		PageEventLookup $pageEventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		IMessageFormatterFactory $messageFormatterFactory,
		LinkRenderer $linkRenderer,
		CampaignsPageFactory $campaignsPageFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker,
		EventTimeFormatter $eventTimeFormatter,
		EventPageCacheUpdater $eventPageCacheUpdater,
		EventQuestionsRegistry $eventQuestionsRegistry
	) {
		$this->pageEventLookup = $pageEventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->linkRenderer = $linkRenderer;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
		$this->eventTimeFormatter = $eventTimeFormatter;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
	}

	public function newDecorator(
		Language $language,
		Authority $viewingAuthority,
		OutputPage $out
	): EventPageDecorator {
		return new EventPageDecorator(
			$this->pageEventLookup,
			$this->participantsStore,
			$this->organizersStore,
			$this->permissionChecker,
			$this->messageFormatterFactory,
			$this->linkRenderer,
			$this->campaignsPageFactory,
			$this->centralUserLookup,
			$this->userLinker,
			$this->eventTimeFormatter,
			$this->eventPageCacheUpdater,
			$this->eventQuestionsRegistry,
			$language,
			$viewingAuthority,
			$out
		);
	}
}
