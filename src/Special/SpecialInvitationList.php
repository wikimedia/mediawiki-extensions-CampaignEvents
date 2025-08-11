<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Special;

use LogicException;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationList;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListNotFoundException;
use MediaWiki\Extension\CampaignEvents\Invitation\InvitationListStore;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUserNotFoundException;
use MediaWiki\Extension\CampaignEvents\MWEntity\HiddenCentralUserException;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageIdentity;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;

class SpecialInvitationList extends SpecialPage {
	use InvitationFeatureAccessTrait;

	public const PAGE_NAME = 'InvitationList';

	private PermissionChecker $permissionChecker;
	private InvitationListStore $invitationListStore;
	private CampaignsCentralUserLookup $centralUserLookup;
	private UserLinker $userLinker;
	private TemplateParser $templateParser;

	private const HIGHLY_RECOMMENDED_MIN_SCORE = 70;
	public const RECOMMENDED_MIN_SCORE = 25;

	public function __construct(
		PermissionChecker $permissionChecker,
		InvitationListStore $invitationListStore,
		CampaignsCentralUserLookup $centralUserLookup,
		UserLinker $userLinker
	) {
		parent::__construct( self::PAGE_NAME );
		$this->permissionChecker = $permissionChecker;
		$this->invitationListStore = $invitationListStore;
		$this->centralUserLookup = $centralUserLookup;
		$this->userLinker = $userLinker;
		$this->templateParser = new TemplateParser( __DIR__ . '/../../templates' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->enableOOUI();
		$isEnabledAndPermitted = $this->checkInvitationFeatureAccess(
			$this->getOutput(),
			$this->getAuthority()
		);
		if ( $isEnabledAndPermitted ) {
			$this->maybeDisplayList( $par );
		}
	}

	private function maybeDisplayList( ?string $par ): void {
		if ( $par === null ) {
			$this->getOutput()->redirect(
				SpecialPage::getTitleFor( SpecialMyInvitationLists::PAGE_NAME )->getLocalURL()
			);
			return;
		}

		$listID = (int)$par;
		// For styling Html::errorBox, Html::warningBox, and Html::noticeBox
		$this->getOutput()->addModuleStyles( [
			'mediawiki.codex.messagebox.styles',
		] );
		if ( (string)$listID !== $par ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-invitation-list-invalid-id' )->parseAsBlock()
			) );
			return;
		}
		try {
			$invitationList = $this->invitationListStore->getInvitationList( $listID );
		} catch ( InvitationListNotFoundException $_ ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-invitation-list-does-not-exist' )->parseAsBlock()
			) );
			return;
		}

		$user = $this->centralUserLookup->newFromAuthority( $this->getAuthority() );
		if ( !$invitationList->getCreator()->equals( $user ) ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'campaignevents-invitation-list-not-creator' )->parseAsBlock()
			) );
			return;
		}

		$invitationListWiki = $invitationList->getWiki();
		if ( $invitationListWiki !== WikiMap::getCurrentWikiId() ) {
			$foreignListURL = WikiMap::getForeignURL(
				$invitationListWiki,
				'Special:' . self::PAGE_NAME . "/$listID"
			);

			$this->setHeaders();
			$this->getOutput()->addModuleStyles( 'mediawiki.codex.messagebox.styles' );

			$nonLocalListNotice = Html::noticeBox(
				$this->msg( 'campaignevents-invitation-list-nonlocal' )
					->params( [ $foreignListURL, WikiMap::getWikiName( $invitationListWiki ) ] )
					->parse()
			);

			$this->getOutput()->addHTML( $nonLocalListNotice );
			return;
		}

		$this->displayList( $invitationList );
	}

	private function displayList( InvitationList $list ): void {
		$out = $this->getOutput();
		$out->setPageTitleMsg(
			$this->msg( 'campaignevents-invitationlist-event' )->params( $list->getName() )
		);

		if ( $list->getStatus() === InvitationList::STATUS_PENDING ) {
			$out->addHTML( Html::noticeBox( $this->msg( 'campaignevents-invitation-list-processing' )->parse() ) );
			return;
		}

		// TODO: Load only the styles for accordions. We need a RL module for that.
		$out->addModuleStyles( [ 'codex-styles' ] );

		$invitationListUsers = $this->invitationListStore->getInvitationListUsers( $list->getListID() );
		[ $highlyRecommended, $recommended ] = self::splitUsersByScore( $invitationListUsers );
		$numEditors = count( $highlyRecommended ) + count( $recommended );

		$out->addWikiMsg(
			'campaignevents-invitation-list-intro',
			Message::numParam( $numEditors )
		);

		$noUsersWarning = '';
		if ( $numEditors > 0 ) {
			$allUsersByID = array_fill_keys( array_merge( $highlyRecommended, $recommended ), null );
			// Warm up the cache for all users, even those that don't exist or are deleted.
			$allUsernames = $this->centralUserLookup->getNamesIncludingDeletedAndSuppressed( $allUsersByID );
			// But preload links only for those who actually exist.
			$usernamesToPreload = array_filter(
				$allUsernames,
				static function ( string $name ): bool {
					return $name !== CampaignsCentralUserLookup::USER_HIDDEN &&
						$name !== CampaignsCentralUserLookup::USER_NOT_FOUND;
				}
			);
			$this->userLinker->preloadUserLinks( $usernamesToPreload );
		} else {
			$noUsersWarning = Html::warningBox( $this->msg( 'campaignevents-invitationlist-no-editors' )->parse() );
		}
		$highlyRecommendedLinks = $this->getUserLinks( $highlyRecommended );
		$highlyRecommendedLinksList = $this->formatAsList( $highlyRecommendedLinks );
		$data = [
			'noUsersWarning' => $noUsersWarning,
			'highlyRecommendedAccordion' => [
				'title' => $this->msg( 'campaignevents-invitationlist-highly-recommended' )->text(),
				'description' => $this->msg( 'campaignevents-invitationlist-highly-recommended-info' )->text(),
				'content' => $highlyRecommendedLinksList,
				'isopen' => (bool)$highlyRecommendedLinks
			],
			'recommendedAccordion' => [
				'title' => $this->msg( 'campaignevents-invitationlist-recommended' )->text(),
				'description' => $this->msg( 'campaignevents-invitationlist-recommended-info' )->text(),
				'content' => $this->formatAsList( $this->getUserLinks( $recommended ) ),
				'isopen' => !$highlyRecommendedLinks
			],
			'worklistAccordion' => [
				'title' => $this->msg( 'campaignevents-invitationlist-worklist-label' )->text(),
				'content' => $this->formatAsList( $this->getWorklistLinks( $list->getListID() ) )
			]
		];

		$template = $this->templateParser->processTemplate( 'InvitationList', $data );
		$out->addHTML( $template );
	}

	/** @return list<string> */
	private function getWorklistLinks( int $invitationListID ): array {
		$worklist = $this->invitationListStore->getWorklist( $invitationListID );
		$pagesByWiki = $worklist->getPagesByWiki();
		if ( count( $pagesByWiki ) !== 1 ) {
			throw new LogicException( 'Expected a single wiki' );
		}
		$localPages = reset( $pagesByWiki );
		$linkRenderer = $this->getLinkRenderer();
		return array_map( static fn ( PageIdentity $page ): string => $linkRenderer->makeLink( $page ), $localPages );
	}

	/**
	 * Given a list of potential invitees, group them by score range.
	 *
	 * @param array<int,int> $users
	 * @return int[][] First element is a list of highly recommended user IDs. Second element is a list of recommended
	 * user IDs.
	 * @phan-return array{0:list<int>,1:list<int>}
	 */
	private static function splitUsersByScore( array $users ): array {
		$highlyRecommended = [];
		$recommended = [];
		foreach ( $users as $userID => $score ) {
			if ( $score >= self::HIGHLY_RECOMMENDED_MIN_SCORE ) {
				$highlyRecommended[] = $userID;
			} elseif ( $score >= self::RECOMMENDED_MIN_SCORE ) {
				$recommended[] = $userID;
			}
		}

		return [ $highlyRecommended, $recommended ];
	}

	/**
	 * @param int[] $userIDs
	 * @return string[]
	 */
	private function getUserLinks( array $userIDs ): array {
		$links = [];
		foreach ( $userIDs as $userID ) {
			try {
				$links[] = $this->userLinker->generateUserLink( $this->getContext(), new CentralUser( $userID ) );
			} catch ( CentralUserNotFoundException | HiddenCentralUserException $_ ) {
				continue;
			}
		}
		return $links;
	}

	/** @param list<string> $links */
	private function formatAsList( array $links ): string {
		if ( !$links ) {
			return '';
		}

		$listContent = '';
		foreach ( $links as $link ) {
			$listContent .= Html::rawElement( 'li', [], $link );
		}

		return Html::rawElement(
			'ul',
			[
				// TODO: Replace with a proper stylesheet
				'style' => 'overflow-wrap: anywhere; list-style: none; margin: 0'
			],
			$listContent
		);
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'campaignevents';
	}
}
