<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventContribution;

/**
 * Object representing a contribution association between an edit and an event
 */
class EventContribution {
	/** @var int Edit flag for page creation */
	public const EDIT_FLAG_PAGE_CREATION = 1;

	/** @var int */
	private int $eventId;

	/** @var int */
	private int $userId;

	/** @var string */
	private string $wiki;

	/** @var string */
	private string $pagePrefixedtext;

	/** @var int */
	private int $pageId;

	/** @var int */
	private int $revisionId;

	/** @var int */
	private int $editFlags;

	/** @var int */
	private int $bytesDelta;

	/** @var int */
	private int $linksDelta;

	/** @var string */
	private string $timestamp;

	/** @var bool */
	private bool $deleted;

	/**
	 * Create a new edit contribution object
	 */
	public function __construct(
		int $eventId,
		int $userId,
		string $wiki,
		string $pagePrefixedtext,
		int $pageId,
		int $revisionId,
		int $editFlags,
		int $bytesDelta,
		int $linksDelta,
		string $timestamp,
		bool $deleted = false
	) {
		$this->eventId = $eventId;
		$this->userId = $userId;
		$this->wiki = $wiki;
		$this->pagePrefixedtext = $pagePrefixedtext;
		$this->pageId = $pageId;
		$this->revisionId = $revisionId;
		$this->editFlags = $editFlags;
		$this->bytesDelta = $bytesDelta;
		$this->linksDelta = $linksDelta;
		$this->timestamp = $timestamp;
		$this->deleted = $deleted;
	}

	public function getEventId(): int {
		return $this->eventId;
	}

	public function getUserId(): int {
		return $this->userId;
	}

	public function getWiki(): string {
		return $this->wiki;
	}

	public function getPagePrefixedtext(): string {
		return $this->pagePrefixedtext;
	}

	public function getPageId(): int {
		return $this->pageId;
	}

	public function getRevisionId(): int {
		return $this->revisionId;
	}

	public function getEditFlags(): int {
		return $this->editFlags;
	}

	public function getBytesDelta(): int {
		return $this->bytesDelta;
	}

	public function getLinksDelta(): int {
		return $this->linksDelta;
	}

	public function getTimestamp(): string {
		return $this->timestamp;
	}

	public function isDeleted(): bool {
		return $this->deleted;
	}

	/**
	 * Check if this edit represents a page creation.
	 *
	 * @return bool True if the edit flag indicates page creation
	 */
	public function isPageCreation(): bool {
		return ( $this->editFlags & self::EDIT_FLAG_PAGE_CREATION ) !== 0;
	}
}
