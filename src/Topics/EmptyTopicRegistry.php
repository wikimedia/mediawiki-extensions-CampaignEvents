<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Topics;

class EmptyTopicRegistry implements ITopicRegistry {
	public function getAllTopics(): array {
		return [];
	}

	public function getTopicsForSelect(): array {
		return [];
	}

	/**
	 * @param string[] $topicIDs
	 * @return array<string,string>
	 */
	public function getTopicMessages( array $topicIDs ): array {
		return [];
	}
}
