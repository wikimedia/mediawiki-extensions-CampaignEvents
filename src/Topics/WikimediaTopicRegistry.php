<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Topics;

use MediaWiki\Extension\WikimediaMessages\ArticleTopicFiltersRegistry;

/**
 * Wikimedia-specific implementation of the topic registry, based on article topics defined in the WikimediaMessages
 * extension.
 */
class WikimediaTopicRegistry implements ITopicRegistry {
	public function getAllTopics(): array {
		return ArticleTopicFiltersRegistry::getTopicList();
	}

	public function getTopicsForSelect(): array {
		$groupedTopics = ArticleTopicFiltersRegistry::getGroupedTopics();
		$options = [];
		foreach ( $groupedTopics as $topicGroup ) {
			$curOptions = [];
			foreach ( $topicGroup['topics'] as $topic ) {
				$curOptions[$topic['msgKey']] = $topic['topicId'];
			}
			$options[$topicGroup['msgKey']] = $curOptions;
		}
		return $options;
	}

	public function getTopicMessages( array $topicIDs ): array {
		return ArticleTopicFiltersRegistry::getTopicMessages( $topicIDs );
	}
}
