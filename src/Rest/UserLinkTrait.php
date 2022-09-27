<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Rest;

use MediaWiki\Extension\CampaignEvents\MWEntity\CentralUser;
use MediaWiki\Extension\CampaignEvents\MWEntity\UserLinker;
use Sanitizer;

/**
 * Helper for handlers that need to include data for linking user pages in the response.
 */
trait UserLinkTrait {
	/**
	 * @param UserLinker $userLinker
	 * @param CentralUser $centralUser
	 * @return string[]
	 * NOTE: Make sure that the user is not hidden before calling this method, or it will throw an exception.
	 * TODO: Remove this hack and replace with a proper javascript implementation of Linker::GetUserLink
	 */
	private function getUserPagePath( UserLinker $userLinker, CentralUser $centralUser ): array {
		$html = $userLinker->generateUserLink( $centralUser );
		$attribs = Sanitizer::decodeTagAttributes( $html );
		return [
			'path' => array_key_exists( 'href', $attribs ) ? $attribs['href'] : '',
			'title' => array_key_exists( 'title', $attribs ) ? $attribs['title'] : '',
			'classes' => array_key_exists( 'class', $attribs ) ? $attribs['class'] : ''
		];
	}
}
