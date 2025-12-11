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

	public function __construct(
		private readonly PageEventLookup $pageEventLookup,
		private readonly ParticipantsStore $participantsStore,
		private readonly OrganizersStore $organizersStore,
		private readonly PermissionChecker $permissionChecker,
		private readonly IMessageFormatterFactory $messageFormatterFactory,
		private readonly CampaignsPageFactory $campaignsPageFactory,
		private readonly CampaignsCentralUserLookup $centralUserLookup,
		private readonly EventTimeFormatter $eventTimeFormatter,
		private readonly EventPageCacheUpdater $eventPageCacheUpdater,
		private readonly EventQuestionsRegistry $eventQuestionsRegistry,
		private readonly GroupPermissionsLookup $groupPermissionsLookup,
		private readonly Config $config,
		private readonly CountryProvider $countryProvider,
	) {
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
