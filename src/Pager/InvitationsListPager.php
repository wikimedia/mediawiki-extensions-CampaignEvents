<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationList;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\Special\SpecialGenerateInvitationList;
use MediaWiki\Extension\CampaignEvents\Special\SpecialInvitationList;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\ReverseChronologicalPager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use OOUI\ButtonWidget;
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
		$linkWrapper = Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-invitations-pager-link' ],
			$this->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( SpecialInvitationList::PAGE_NAME, (string)$row->ceil_id ),
				$row->ceil_name
			)
		);
		return Html::rawElement(
			'div',
			[ 'class' => 'ext-campaignevents-invitations-pager-row' ],
			$linkWrapper . $this->getInfoChip( $row )
		);
	}

	/**
	 * @inheritDoc
	 * @return array<string,mixed>
	 */
	public function getQueryInfo() {
		$ceilFields = [
			'ceil_id',
			'ceil_name',
			'ceil_status',
			'ceil_created_at',
			'ceil_user_id',
		];
		return [
			'tables' => [ 'ce_invitation_lists', 'ce_invitation_list_users' ],
			'fields' => [
				...$ceilFields,
				'list_editor_count' => 'COUNT(ceilu_id)'
			],
			'conds' => [
				 'ceil_wiki' => WikiMap::getCurrentWikiId(),
				 'ceil_user_id' => $this->centralUser->getCentralID()
			],
			'options' => [
				// We need to GROUP BY all fields to pass ONLY_FULL_GROUP_BY in MariaDB: even though `ceil_id` alone
				// uniquely determines a row, MariaDB does not detect functional dependencies:
				// https://jira.mariadb.org/browse/MDEV-11588
				'GROUP BY' => $ceilFields,
			],
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
		$text = Html::element(
			'p',
			[],
			$this->msg( 'campaignevents-myinvitationslist-empty-text' )->text()
		);
		$button = new ButtonWidget(
			[
				'href' => SpecialPage::getTitleFor( SpecialGenerateInvitationList::PAGE_NAME )->getLocalURL(),
				'label' => $this->msg( 'campaignevents-myinvitationslist-generate-button' )->text(),
				'flags' => [ 'primary', 'progressive' ]
			]
		);
		return $text . $button;
	}

	/**
	 * @inheritDoc
	 */
	public function getStartBody(): string {
		if ( $this->getNumRows() ) {
			return ( new ButtonWidget(
				[
					'href' => SpecialPage::getTitleFor( SpecialGenerateInvitationList::PAGE_NAME )->getLocalURL(),
					'label' => $this->msg( 'campaignevents-myinvitationslist-new-button' )->text(),
					'icon' => 'add',
				]
			) ) . parent::getStartBody();
		}
		return parent::getStartBody();
	}

	private function getInfoChip( stdClass $row ): string {
		if ( (int)$row->ceil_status === InvitationList::STATUS_PENDING ) {
			$data = [
				'status' => 'notice',
				'message' => $this->msg( 'campaignevents-invitations-pager-status-processing' )->text()
			];
		} else {
			$editorCount = (int)$row->list_editor_count;
			$data = [
				'status' => $editorCount > 0 ? 'success' : 'warning',
				'message' => $this->msg( 'campaignevents-invitations-pager-status-editors' )
					->numParams( $editorCount )
					->text()
			];
		}

		return $this->templateParser->processTemplate( 'InfoChip', $data );
	}
}
