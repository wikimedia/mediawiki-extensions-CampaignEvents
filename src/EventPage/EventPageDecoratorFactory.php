<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use Language;
use MediaWiki\Extension\CampaignEvents\Event\Store\IEventLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Extension\CampaignEvents\Questions\EventQuestionsRegistry;
use MediaWiki\Extension\CampaignEvents\Time\EventTimeFormatter;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Permissions\Authority;
use OutputPage;
use TitleFormatter;
use Wikimedia\Message\IMessageFormatterFactory;

class EventPageDecoratorFactory {
	public const SERVICE_NAME = 'CampaignEventsEventPageDecoratorFactory';

	private IEventLookup $eventLookup;
	private ParticipantsStore $participantsStore;
	private OrganizersStore $organizersStore;
	private PermissionChecker $permissionChecker;
	private IMessageFormatterFactory $messageFormatterFactory;
	private LinkRenderer $linkRenderer;
	private TitleFormatter $titleFormatter;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserLinker $userLinker;
	private EventTimeFormatter $eventTimeFormatter;
	private EventPageCacheUpdater $eventPageCacheUpdater;
	private EventQuestionsRegistry $eventQuestionsRegistry;

	/**
	 * @param IEventLookup $eventLookup
	 * @param ParticipantsStore $participantsStore
	 * @param OrganizersStore $organizersStore
	 * @param PermissionChecker $permissionChecker
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param LinkRenderer $linkRenderer
	 * @param TitleFormatter $titleFormatter
	 * @param CampaignsCentralUserLookup $centralUserLookup
	 * @param UserLinker $userLinker
	 * @param EventTimeFormatter $eventTimeFormatter
	 * @param EventPageCacheUpdater $eventPageCacheUpdater
	 * @param EventQuestionsRegistry $eventQuestionsRegistry
	 *
	 */
	public function __construct(
		IEventLookup $eventLookup,
		ParticipantsStore $participantsStore,
		OrganizersStore $organizersStore,
		PermissionChecker $permissionChecker,
		IMessageFormatterFactory $messageFormatterFactory,
		LinkRenderer $linkRenderer,
		TitleFormatter $titleFormatter,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker,
		EventTimeFormatter $eventTimeFormatter,
		EventPageCacheUpdater $eventPageCacheUpdater,
		EventQuestionsRegistry $eventQuestionsRegistry
	) {
		$this->eventLookup = $eventLookup;
		$this->participantsStore = $participantsStore;
		$this->organizersStore = $organizersStore;
		$this->permissionChecker = $permissionChecker;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->linkRenderer = $linkRenderer;
		$this->titleFormatter = $titleFormatter;
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
			$this->eventLookup,
			$this->participantsStore,
			$this->organizersStore,
			$this->permissionChecker,
			$this->messageFormatterFactory,
			$this->linkRenderer,
			$this->titleFormatter,
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