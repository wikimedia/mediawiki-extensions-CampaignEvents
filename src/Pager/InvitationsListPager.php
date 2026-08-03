<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Database\CampaignsDatabaseHelper;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationList;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore;
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
	private TemplateParser $templateParser;

	public function __construct(
		private readonly CentralUser $centralUser,
		private readonly InvitationListStore $invitationListStore,
		CampaignsDatabaseHelper $databaseHelper,
		IContextSource $context,
		LinkRenderer $linkRenderer
	) {
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
		$this->mDb = $databaseHelper->getReplicaConnection();
		parent::__construct( $context, $linkRenderer );
	}

	/**
	 * @inheritDoc
	 */
	public function formatRow( $row ): string {
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
	public function getQueryInfo(): array {
		return $this->invitationListStore->getQueryInfo(
			WikiMap::getCurrentWikiId(),
			$this->centralUser->getCentralID(),
			SpecialInvitationList::RECOMMENDED_MIN_SCORE
		);
	}

	/**
	 * @inheritDoc
	 * @return string[][]
	 */
	public function getIndexField(): array {
		// This index is not optimized
		return [ [ 'ceil_created_at', 'ceil_id' ] ];
	}

	/**
	 * @inheritDoc
	 */
	public function getEmptyBody(): string {
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
