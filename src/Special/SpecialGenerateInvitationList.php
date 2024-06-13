<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use HTMLForm;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListGenerator;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use OOUI\MessageWidget;

class SpecialGenerateInvitationList extends FormSpecialPage {
	public const PAGE_NAME = 'GenerateInvitationList';

	private PermissionChecker $permissionChecker;

	/**
	 * @param PermissionChecker $permissionChecker
	 */
	public function __construct(
		PermissionChecker $permissionChecker
	 ) {
		parent::__construct( self::PAGE_NAME );
		$this->permissionChecker = $permissionChecker;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->outputHeader();
		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		if ( !$this->getConfig()->get( 'CampaignEventsEnableEventInvitation' ) ) {
			$out = $this->getOutput();
			$out->enableOOUI();
			$messageWidget = new MessageWidget( [
				'type' => 'notice',
				'label' => $this->msg( 'campaignevents-invitation-list-disabled' )->text()
			] );
			$out->addHTML( $messageWidget );
			return;
		}

		$this->requireNamedUser();
		if ( !$this->permissionChecker->userCanOrganizeEvents( $mwAuthority->getName() ) ) {
			$out = $this->getOutput();
			$out->enableOOUI();
			$messageWidget = new MessageWidget( [
				'type' => 'error',
				'label' => $this->msg( 'campaignevents-invitation-list-not-allowed' )->text()
			] );
			$out->addHTML( $messageWidget );
		} else {
			parent::execute( $par );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getFormFields(): array {
		return [
			'InvitationListName' => [
				'type' => 'text',
				'label-message' => 'campaignevents-generateinvitationlist-name-field-label',
				'placeholder-message' => 'campaignevents-generateinvitationlist-name-field-placeholder',
				'required' => true
			],
			'EventPage' => [
				'type' => 'title',
				'exists' => true,
				'namespace' => NS_EVENT,
				'label-message' => 'campaignevents-generateinvitationlist-event-page-field-label',
				'placeholder-message' => 'campaignevents-generateinvitationlist-event-page-field-placeholder',
				'required' => false
			],
			'ArticleList' => [
				'type' => 'textarea',
				'label-message' => 'campaignevents-generateinvitationlist-article-list-field-label',
				'placeholder-message' => 'campaignevents-generateinvitationlist-article-list-field-placeholder',
				'help-message' => [
					'campaignevents-generateinvitationlist-article-list-field-help',
					Message::numParam( InvitationListGenerator::ARTICLES_LIMIT )
				],
				'rows' => 10,
				'required' => true
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function alterForm( HTMLForm $form ): void {
		$form->setSubmitTextMsg( 'campaignevents-generateinvitationlist-submit-button-text' );
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ) {
		return Status::newGood();
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
}
