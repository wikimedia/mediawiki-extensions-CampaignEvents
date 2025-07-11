<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\MWEntity;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\UserLinkRenderer;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Message\IMessageFormatterFactory;
use Wikimedia\Message\MessageValue;

/**
 * This class generates links to (global) user accounts.
 * @phan-file-suppress PhanUndeclaredMethod,UnusedPluginFileSuppression
 */
class UserLinker {
	public const SERVICE_NAME = 'CampaignEventsUserLinker';

	public const MODULE_STYLES = [
		// Needed by Linker::userLink
		'mediawiki.interface.helpers.styles',
	];

	private CampaignsCentralUserLookup $centralUserLookup;
	private IMessageFormatterFactory $messageFormatterFactory;
	private LinkBatchFactory $linkBatchFactory;
	private LinkRenderer $linkRenderer;
	private UserLinkRenderer $userLinkRenderer;

	public function __construct(
		CampaignsCentralUserLookup $centralUserLookup,
		IMessageFormatterFactory $messageFormatterFactory,
		LinkBatchFactory $linkBatchFactory,
		LinkRenderer $linkRenderer,
		UserLinkRenderer $userLinkRenderer
	) {
		$this->centralUserLookup = $centralUserLookup;
		$this->messageFormatterFactory = $messageFormatterFactory;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->linkRenderer = $linkRenderer;
		$this->userLinkRenderer = $userLinkRenderer;
	}

	/**
	 * Generates a link to the given user, if it can be found and is visible, throwing an exception otherwise.
	 *
	 * @param IContextSource $context
	 * @param CentralUser $user
	 * @return string HTML
	 * @throws CentralUserNotFoundException
	 * @throws HiddenCentralUserException
	 * @note When using this method, make sure to add self::MODULE_STYLES to the output.
	 */
	public function generateUserLink( IContextSource $context, CentralUser $user ): string {
		$name = $this->centralUserLookup->getUserName( $user );
		// HACK: Linker::userLink does not really need the user ID (T308000), so don't bother looking it up, which
		// would be too slow (T345250).
		$userIdentity = new UserIdentityValue( 1, $name );
		// TODO: Here we'll generate a red link if the account does not exist locally. Is that OK? Could we maybe
		// link to Special:CentralAuth (if CA is installed)?
		if ( method_exists( $this->linkRenderer, 'makeUserLink' ) ) {
			// New method parameters
			return $this->linkRenderer->makeUserLink( $userIdentity, $context );
		} else {
			// Legacy compatibility
			return $this->userLinkRenderer->userLink( $userIdentity, $context );
		}
	}

	/**
	 * Like ::generateUserLink, but returns placeholders instead of throwing an exception for users that
	 * cannot be found or are not visible.
	 *
	 * @param IContextSource $context
	 * @param CentralUser $user
	 * @param string $langCode Used for localizing placeholders.
	 * @return string HTML
	 *
	 * @note When using this method, make sure to add self::MODULE_STYLES to the output, and to include the
	 * ext.campaignEvents.userlinks.styles.less file as well.
	 * @note This assumes that the given central user exists, or existed in the past. As such, if the account
	 * cannot be found it will consider it as being deleted.
	 * @fixme This must be kept in sync with ParticipantsManager.getDeletedOrNotFoundParticipantElement in JS
	 */
	public function generateUserLinkWithFallback(
		IContextSource $context,
		CentralUser $user,
		string $langCode
	): string {
		try {
			return $this->generateUserLink( $context, $user );
		} catch ( CentralUserNotFoundException $_ ) {
			$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $langCode );
			return Html::element(
				'span',
				[ 'class' => 'ext-campaignevents-userlink-deleted' ],
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-userlink-deleted-user' )
				)
			);
		} catch ( HiddenCentralUserException $_ ) {
			$msgFormatter = $this->messageFormatterFactory->getTextFormatter( $langCode );
			return Html::element(
				'span',
				[ 'class' => 'ext-campaignevents-userlink-hidden' ],
				$msgFormatter->format(
					MessageValue::new( 'campaignevents-userlink-suppressed-user' )
				)
			);
		}
	}

	/**
	 * @param CentralUser $centralUser
	 * @return string[]
	 * NOTE: Make sure that the user is not hidden before calling this method, or it will throw an exception.
	 * TODO: Remove this hack and replace with a proper javascript implementation of Linker::userLink (T386821)
	 */
	public function getUserPagePath( CentralUser $centralUser ): array {
		$html = $this->generateUserLink( RequestContext::getMain(), $centralUser );
		$attribs = Sanitizer::decodeTagAttributes( $html );
		return [
			'path' => $attribs['href'] ?? '',
			'title' => $attribs['title'] ?? '',
			'classes' => $attribs['class'] ?? '',
		];
	}

	/**
	 * Preloads link data for linking to the user pages of the given users.
	 *
	 * @param string[] $usernames
	 */
	public function preloadUserLinks( array $usernames ): void {
		$lb = $this->linkBatchFactory->newLinkBatch();
		foreach ( $usernames as $username ) {
			$lb->add( NS_USER, $username );
		}
		$lb->setCaller( wfGetCaller() );
		$lb->execute();
	}
}
