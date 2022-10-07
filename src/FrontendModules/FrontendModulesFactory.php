<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\FrontendModules;

use MediaWiki\Extension\CampaignEvents\MWEntity\PageURLResolver;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Organizers\OrganizersStore;
use MediaWiki\Extension\CampaignEvents\Participants\ParticipantsStore;
use Wikimedia\Message\IMessageFormatterFactory;

class FrontendModulesFactory {
	public const SERVICE_NAME = 'CampaignEventsFrontendModulesFactory';

	/** @var IMessageFormatterFactory */
	private $messageFormatterFactory;
	/** @var OrganizersStore */
	private $organizersStore;
	/** @var ParticipantsStore */
	private $participantsStore;
	/** @var PageURLResolver */
	private $pageURLResolver;
	/** @var UserLinker */
	private $userLinker;

	/**
	 * @param IMessageFormatterFactory $messageFormatterFactory
	 * @param OrganizersStore $organizersStore
	 * @param ParticipantsStore $participantsStore
	 * @param PageURLResolver $pageURLResolver
	 * @param UserLinker $userLinker
	 */
	public function __construct(
		IMessageFormatterFactory $messageFormatterFactory,
		OrganizersStore $organizersStore,
		ParticipantsStore $participantsStore,
		PageURLResolver $pageURLResolver,
		UserLinker $userLinker
	) {
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->organizersStore = $organizersStore;
		$this->participantsStore = $participantsStore;
		$this->pageURLResolver = $pageURLResolver;
		$this->userLinker = $userLinker;
	}

	public function newEventDetailsModule(): EventDetailsModule {
		return new EventDetailsModule(
			$this->messageFormatterFactory,
			$this->organizersStore,
			$this->pageURLResolver,
			$this->userLinker
		);
	}

	public function newEventDetailsParticipantsModule(): EventDetailsParticipantsModule {
		return new EventDetailsParticipantsModule(
			$this->messageFormatterFactory,
			$this->userLinker,
			$this->participantsStore
		);
	}
}
