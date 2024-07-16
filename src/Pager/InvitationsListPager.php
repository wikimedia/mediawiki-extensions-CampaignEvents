<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationList;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Special\SpecialGenerateInvitationList;
use MediaWiki\Extension\CampaignEvents\Special\SpecialInvitationList;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\ReverseChronologicalPager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\ButtonWidget;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use stdClass;

class InvitationsListPager extends ReverseChronologicalPager {
	private CentralUser $centralUser;
	private TemplateParser $templateParser;

	public function __construct(
		CentralUser $user,
		CampaignsDatabaseHelper $databaseHelper,
		IContextSource $context,
		LinkRenderer $linkRenderer
	) {
		$this->centralUser = $user;
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
		$this->mDb = $databaseHelper->getDBConnection( DB_REPLICA );
		parent::__construct( $context, $linkRenderer );
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $row ) {
		$container = ( new Tag() )->addClasses( [ 'ext-campaignevents-invitations-pager-row' ] );
		$linkWrapper = ( new Tag() )->appendContent(
			new HtmlSnippet(
			$this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( SpecialInvitationList::PAGE_NAME, (string)$row->ceil_id ),
				$row->ceil_name
			) )
		)->addClasses( [ 'ext-campaignevents-invitations-pager-link' ] );
		$chip = new HtmlSnippet( $this->getInfoChip( $row ) );
		return $container->appendContent( [ $linkWrapper, $chip ] );
	}

	/**
	 * @inheritDoc
	 */
	public function getQueryInfo() {
		return [
			'tables' => [ 'ce_invitation_lists', 'ce_invitation_list_users' ],
			'fields' => [
				'ceil_id',
				'ceil_name',
				'ceil_status',
				'ceil_created_at',
				'ceil_user_id',
				'ceil_editor_count' => 'COUNT (ceilu_id)'
			],
			'conds' => [
				 'ceil_wiki' => WikiMap::getCurrentWikiId(),
				 'ceil_user_id' => $this->centralUser->getCentralID()
			],
			'options' => [ 'GROUP BY' => [
				'ceil_id'
			] ],
			'join_conds' => [
				'ce_invitation_list_users' => [
					'LEFT JOIN', [
						'ceil_id=ceilu_ceil_id',
						$this->mDb->expr(
							'ceilu_score',
							'>=',
							SpecialInvitationList::RECOMMENDED_MIN_SCORE
						)
					]
				]
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
		// This index is not optimized
		return [ [ 'ceil_created_at', 'ceil_id' ] ];
	}

	/**
	 * @inheritDoc
	 */
	public function getEmptyBody() {
		$container = ( new Tag() );
		$text = new Tag( 'p' );
		$text->appendContent(
			$this->msg(
				'campaignevents-myinvitationslist-empty-text'
			)->text()
		);
		$button = new ButtonWidget(
			[
				'href' => SpecialPage::getTitleFor( SpecialGenerateInvitationList::PAGE_NAME )->getLocalURL(),
				'label' => $this->msg( 'campaignevents-myinvitationslist-generate-button' )->text(),
				'flags' => [ 'primary', 'progressive' ]
			]
		);
		$textContainer = ( new Tag() )->appendContent( [ $text, $button ] );
		return $container->appendContent( [ $textContainer ] );
	}

	/**
	 * @inheritDoc
	 */
	public function getStartBody(): string {
		if ( $this->getNumRows() ) {
			return ( new ButtonWidget(
				[
					'href' => SpecialPage::getTitleFor( SpecialGenerateInvitationList::PAGE_NAME )->getLocalURL(),
					'label' => $this->msg( 'campaignevents-myinvitationslist-add-button' )->text(),
					'icon' => 'add',
				]
			) ) . parent::getStartBody();
		}
		return parent::getStartBody();
	}

	/**
	 * @param stdClass $row
	 * @return string
	 */
	private function getInfoChip( stdClass $row ): string {
		if ( (int)$row->ceil_status === InvitationList::STATUS_PENDING ) {
			$data = [
				'iconClass' => 'notice',
				'message' => $this->msg( 'campaignevents-invitations-pager-status-processing' )->text()
			];
		} else {
			$editorCount = (int)$row->ceil_editor_count;
			$data = [
				'iconClass' => $editorCount > 0 ? 'check' : 'alert',
				'message' => $this->msg( 'campaignevents-invitations-pager-status-editors' )
					->numParams( $editorCount )
					->text()
			];
		}

		return $this->templateParser->processTemplate( 'InfoChip', $data );
	}
}
