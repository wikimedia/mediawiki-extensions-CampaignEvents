<?php
declare( strict_types=1 );
namespace MediaWiki\Extension\CampaignEvents\Topics;

interface ITopicRegistry {
	public const SERVICE_NAME = 'CampaignEventsTopicRegistry';

	/**
	 * Returns a plain list of topic IDs, for validation and the like.
	 * @return string[]
	 * @phan-return list<string>
	 */
	public function getAllTopics(): array;

	/**
	 * @return array<string,array<string,string>> That maps message keys to topic IDs,
	 * with category groups represented as nested array of <string,string> suitable for use
	 * in multiselect widgets.
	 */
	public function getTopicsForSelect(): array;

	/**
	 * Returns message keys for the given topic IDs.
	 * @param string[] $topicIDs
	 * @return array<string,string>
	 */
	public function getTopicMessages( array $topicIDs ): array;

}
