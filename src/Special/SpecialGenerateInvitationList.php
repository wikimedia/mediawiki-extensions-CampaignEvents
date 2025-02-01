<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListGenerator;
use MediaWiki\Extension\CampaignEvents\Invitation\WorklistParser;
use MediaWiki\Extension\CampaignEvents\MWEntity\MWAuthorityProxy;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use StatusValue;

class SpecialGenerateInvitationList extends FormSpecialPage {
	use InvitationFeatureAccessTrait;

	public const PAGE_NAME = 'GenerateInvitationList';

	private PermissionChecker $permissionChecker;
	private InvitationListGenerator $invitationListGenerator;
	private WorklistParser $worklistParser;

	/** @var int|null ID of the newly-generated list. Only set upon successful form submission. */
	private ?int $listID = null;

	public function __construct(
		PermissionChecker $permissionChecker,
		InvitationListGenerator $invitationListGenerator,
		WorklistParser $worklistParser
	) {
		parent::__construct( self::PAGE_NAME );
		$this->permissionChecker = $permissionChecker;
		$this->invitationListGenerator = $invitationListGenerator;
		$this->worklistParser = $worklistParser;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->outputHeader();
		$mwAuthority = new MWAuthorityProxy( $this->getAuthority() );
		$isEnabledAndPermitted = $this->checkInvitationFeatureAccess(
			$this->getOutput(),
			$mwAuthority
		);
		if ( $isEnabledAndPermitted ) {
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
				'filter-callback' => static fn ( $name ) => trim( (string)$name ),
				'required' => true
			],
			'EventPage' => [
				'type' => 'title',
				'exists' => true,
				'namespace' => NS_EVENT,
				'label-message' => 'campaignevents-generateinvitationlist-event-page-field-label',
				'placeholder-message' => 'campaignevents-generateinvitationlist-event-page-field-placeholder',
				'required' => false,
				'validation-callback' => function ( string $eventPage ): StatusValue {
					if ( !$eventPage ) {
						return StatusValue::newGood();
					}
					return $this->invitationListGenerator->validateEventPage(
						$eventPage,
						new MWAuthorityProxy( $this->getAuthority() )
					);
				},
			],
			'ArticleList' => [
				'type' => 'textarea',
				'label-message' => 'campaignevents-generateinvitationlist-article-list-field-label',
				'placeholder-message' => 'campaignevents-generateinvitationlist-article-list-field-placeholder',
				'help-message' => [
					'campaignevents-generateinvitationlist-article-list-field-help',
					Message::numParam( WorklistParser::ARTICLES_LIMIT )
				],
				'rows' => 10,
				'required' => true,
				'validation-callback' => function ( $worklist ): StatusValue {
					return $this->worklistParser->parseWorklist( self::makePageMapFromInput( $worklist ) );
				}
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
		$eventPage = $data['EventPage'] !== '' ? $data['EventPage'] : null;
		$worklistStatus = $this->worklistParser->parseWorklist( self::makePageMapFromInput( $data['ArticleList'] ) );
		if ( !$worklistStatus->isGood() ) {
			// This shouldn't actually happen in practice thanks to validation-callback
			return Status::wrap( $worklistStatus );
		}

		$invitationListStatus = $this->invitationListGenerator->createIfAllowed(
			$data['InvitationListName'],
			$eventPage,
			$worklistStatus->getValue(),
			new MWAuthorityProxy( $this->getAuthority() )
		);
		if ( $invitationListStatus->isGood() ) {
			$this->listID = $invitationListStatus->getValue();
		}
		return Status::wrap( $invitationListStatus );
	}

	/**
	 * @inheritDoc
	 */
	public function onSuccess(): void {
		if ( $this->listID === null ) {
			throw new RuntimeException( "List ID is unset" );
		}
		$invitationListPage = SpecialPage::getTitleFor( SpecialInvitationList::PAGE_NAME, (string)$this->listID );
		$this->getOutput()->redirect( $invitationListPage->getLocalURL() );
	}

	/**
	 * @param string $rawWorklist
	 * @return array<string,string[]> Maps wiki ID to a list of page titles.
	 */
	private static function makePageMapFromInput( string $rawWorklist ): array {
		$pageList = array_filter(
			array_map( 'trim', explode( "\n", $rawWorklist ) ),
			static fn ( $line ) => $line !== ''
		);
		return [ WikiMap::getCurrentWikiId() => $pageList ];
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
