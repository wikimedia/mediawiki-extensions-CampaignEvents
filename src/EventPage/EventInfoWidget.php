<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use InvalidArgumentException;
use OOUI\IconElement;
use OOUI\LabelElement;
use OOUI\Tag;
use OOUI\Widget;

/**
 * A widget combining an icon and a label that is used for presenting information about an event.
 * Somewhat similar to MessageWidget, but with different semantics.
 */
class EventInfoWidget extends Widget {
	use IconElement;
	use LabelElement;

	/**
	 * @inheritDoc
	 */
	public function __construct( array $config = [] ) {
		if ( !isset( $config['content'] ) || !isset( $config['label'] ) ) {
			throw new InvalidArgumentException( 'Must specify content and label' );
		}
		$content = new Tag( 'span' );
		$content->appendContent( $config['content'] );
		$content->addClasses( [ 'ext-campaignevents-eventpage-info-widget-content' ] );
		$config['content'] = $content;

		$config['invisibleLabel'] = true;

		parent::__construct( $config );

		$this->initializeLabelElement( $config );
		$this->initializeIconElement( $config );

		$this->getIconElement()->setAttributes( [
			'title' => $config['label'],
		] )->addClasses( [ 'ext-campaignevents-eventpage-icon' ] );

		$this->addClasses( [ 'ext-campaignevents-eventpage-info-widget' ] );
		// Prepending because the parent constructor has already appended the content
		$this->prependContent( [ $this->icon, $this->label ] );
	}
}
