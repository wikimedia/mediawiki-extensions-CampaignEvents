<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\CampaignEvents\Event\ExistingEventRegistration;
use MediaWiki\Extension\CampaignEvents\MWEntity\CampaignsCentralUserLookup;
use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserNotGlobalException;
use MediaWiki\Extension\CampaignEvents\Permissions\PermissionChecker;
use MediaWiki\Language\Language;
use OOUI\IconWidget;
use stdClass;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * @property CampaignsCentralUserLookup $centralUserLookup
 * @property UserLinker $userLinker
 * @property ExistingEventRegistration $event
 * @property PermissionChecker $permissionChecker
 * @method IReadableDatabase getDatabase()
 * @method IContextSource getContext()
 * @method Language getLanguage()
 */
trait EventContributionsPagerTrait {

	/**
	 * Preload usernames and user page links for a result set.
	 */
	protected function preloadUserData( IResultWrapper $result ): void {
		$userNamesMap = [];
		$nonVisibleUserIDsMap = [];

		foreach ( $result as $row ) {
			// Cache visible usernames early, For visible names (the vast majority of them), we add them to the cache
			// now, so they're not looked up again later. Deleted/hidden names are not cached because we can't tell
			// which case it is (we use null for both). But they are also rare enough that we can just look them up
			// separately if needed.
			if ( $row->cec_user_name !== null ) {
				$userNamesMap[$row->cec_user_id] = $row->cec_user_name;
				$this->centralUserLookup->addNameToCache(
					(int)$row->cec_user_id,
					$row->cec_user_name
				);
			} else {
				$nonVisibleUserIDsMap[$row->cec_user_id] = null;
			}
		}

		// Reset result pointer for later consumers
		$result->seek( 0 );

		// Batch lookup deleted / suppressed users
		if ( $nonVisibleUserIDsMap ) {
			$this->centralUserLookup
				->getNamesIncludingDeletedAndSuppressed(
					$nonVisibleUserIDsMap
				);
		}

		// Preload user links for visible users
		if ( $userNamesMap ) {
			$this->userLinker->preloadUserLinks( $userNamesMap );
		}
	}

	/**
	 * Format username column with link
	 */
	private function formatUsername( stdClass $row ): string {
		$isPrivateParticipant = $row->cep_private;
		$centralUserID = (int)$row->cec_user_id;
		$centralUser = new CentralUser( $centralUserID );
		$html = '';
		if ( $isPrivateParticipant ) {
			$icon = new IconWidget( [
				'icon' => 'lock',
				'classes' => [ 'ext-campaignevents-contributions-private-participant' ],
				'title' => $this->getContext()->msg(
					'campaignevents-event-details-contributions-private-participant-tooltip'
				)->text(),
				'label' => $this->getContext()->msg(
					'campaignevents-event-details-contributions-private-participant-tooltip'
				)->text()
			] );
			$html .= $icon->toString() . ' ';
		}
		$html .= $this->userLinker->generateUserLinkWithFallback(
			$this->getContext(),
			$centralUser,
			$this->getLanguage()->getCode()
		);

		return $html;
	}

	/**
	 * Applies participant privacy filtering:
	 * - Users with permission see all participants
	 * - Others see public participants and themselves
	 * - Non-global users see public participants only
	 *
	 * @param array<string,mixed> &$queryInfo
	 */
	private function addPrivateParticipantConds( array &$queryInfo ): void {
		$authority = $this->getContext()->getAuthority();
		if ( $this->permissionChecker->userCanViewPrivateParticipants(
			$authority,
			$this->event
		) ) {
			return;
		}

		try {
			$centralId = $this->centralUserLookup
				->newFromAuthority( $authority )
				->getCentralID();

			$queryInfo['conds'][] = $this->getDatabase()->orExpr( [
				'cep.cep_private' => 0,
				'cec.cec_user_id' => $centralId,
			] );
		} catch ( UserNotGlobalException ) {
			// Non-global users can only see public participants
			$queryInfo['conds']['cep.cep_private'] = 0;
		}
	}
}
