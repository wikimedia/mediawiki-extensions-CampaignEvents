<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CampaignEvents\Address\CountryProvider;
use MediaWiki\Extension\CampaignEvents\Event\PageEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsPageFactory;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Language\Language;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\GroupPermissionsLookup;
use Wikimedia\Message\IMessageFormatterFactory;

class EventPageDecoratorFactory {
	public const SERVICE_NAME = 'CampaignEventsEventPageDecoratorFactory';

	private PageEventLookup $pageEventLookup;
	private ParticipantsStore $participantsStore;
	private OrganizersStore $organizersStore;
	private PermissionChecker $permissionChecker;
	private IMessageFormatterFactory $messageFormatterFactory;
	private CampaignsPageFactory $campaignsPageFactory;
	private CampaignsCentralUserLookup $centralUserLookup;
	private EventTimeFormatter $eventTimeFormatter;
	private EventPageCacheUpdater $eventPageCacheUpdater;
	private EventQuestionsRegistry $eventQuestionsRegistry;
	private GroupPermissionsLookup $groupPermissionsLookup;
	private Config $config;
	private CountryProvider $countryProvider;

	public function __construct(
		PageEventLookup $pageEventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		IMessageFormatterFactory $messageFormatterFactory,
		CampaignsPageFactory $campaignsPageFactory,
		CampaignsCentralUserLookup $centralUserLookup,
		EventTimeFormatter $eventTimeFormatter,
		EventPageCacheUpdater $eventPageCacheUpdater,
		EventQuestionsRegistry $eventQuestionsRegistry,
		GroupPermissionsLookup $groupPermissionsLookup,
		Config $config,
		CountryProvider $countryProvider,
	) {
		$this->pageEventLookup = $pageEventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->campaignsPageFactory = $campaignsPageFactory;
		$this->centralUserLookup = $centralUserLookup;
		$this->eventTimeFormatter = $eventTimeFormatter;
		$this->eventPageCacheUpdater = $eventPageCacheUpdater;
		$this->eventQuestionsRegistry = $eventQuestionsRegistry;
		$this->groupPermissionsLookup = $groupPermissionsLookup;
		$this->config = $config;
		$this->countryProvider = $countryProvider;
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
			$this->campaignsPageFactory,
			$this->centralUserLookup,
			$this->eventTimeFormatter,
			$this->eventPageCacheUpdater,
			$this->eventQuestionsRegistry,
			$this->groupPermissionsLookup,
			$this->config,
			$this->countryProvider,
			$language,
			$viewingAuthority,
			$out
		);
	}
}
