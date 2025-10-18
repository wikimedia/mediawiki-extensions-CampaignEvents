<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Hooks;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI\Tag;

class CampaignEventsHookRunner implements
	CampaignEventsGetPolicyMessageForRegistrationHook,
	CampaignEventsGetPolicyMessageForRegistrationFormHook,
	CampaignEventsRegistrationFormLoadHook,
	CampaignEventsRegistrationFormSubmitHook,
	CampaignEventsGetEventDetailsHook,
	CampaignEventsGetAllEventsTabsHook
{
	public const SERVICE_NAME = 'CampaignEventsHookRunner';

	public function __construct(
		private readonly HookContainer $hookContainer,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetPolicyMessageForRegistration( ?string &$message ) {
		return $this->hookContainer->run(
			'CampaignEventsGetPolicyMessageForRegistration',
			[ &$message ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetPolicyMessageForRegistrationForm( ?string &$message ) {
		return $this->hookContainer->run(
			'CampaignEventsGetPolicyMessageForRegistrationForm',
			[ &$message ]
		);
	}

	/**
	 * @param array<string,array<string,mixed>> &$formFields
	 * @param int|null $eventID
	 */
	public function onCampaignEventsRegistrationFormLoad( array &$formFields, ?int $eventID ): void {
		$this->hookContainer->run(
			'CampaignEventsRegistrationFormLoad',
			[ &$formFields, $eventID ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param int $eventID
	 */
	public function onCampaignEventsRegistrationFormSubmit(
		array $data,
		int $eventID
	): void {
		$this->hookContainer->run(
			'CampaignEventsRegistrationFormSubmit',
			[ $data, $eventID ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onCampaignEventsGetEventDetails(
		Tag $infoColumn,
		Tag $organizersColumn,
		int $eventID,
		bool $isOrganizer,
		OutputPage $outputPage,
		bool $isLocalWiki
	): void {
		$this->hookContainer->run(
			'CampaignEventsGetEventDetails',
			[ $infoColumn, $organizersColumn, $eventID, $isOrganizer, $outputPage, $isLocalWiki ],
			[ 'abortable' => false ]
		);
	}

	public function onCampaignEventsGetAllEventsTabs(
		SpecialPage $specialPage,
		array &$pageTabs,
		string $activeTab
	): void {
		$this->hookContainer->run(
			'CampaignEventsGetAllEventsTabs',
			[ $specialPage, &$pageTabs, $activeTab ],
			[ 'abortable' => false ]
		);
	}
}
