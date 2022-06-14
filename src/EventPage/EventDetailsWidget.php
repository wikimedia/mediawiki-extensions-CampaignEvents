<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\EventPage;

use InvalidArgumentException;
use OOUI\IconElement;
use OOUI\LabelElement;
use OOUI\Tag;
use OOUI\Widget;

/**
 * A widget combining an icon, a label, and some text under the label, used to display more details about an event.
 */
class EventDetailsWidget extends Widget {
	use IconElement;
	use LabelElement;

	/**
	 * @inheritDoc
	 */
	public function __construct( array $config = [] ) {
		if ( !isset( $config['content'] ) || !isset( $config['label'] ) ) {
			throw new InvalidArgumentException( 'Must specify content and label' );
		}
		$content = new Tag( 'div' );
		$content->appendContent( $config['content'] );
		$content->addClasses( [ 'ext-campaignevents-eventpage-details-widget-content' ] );
		$config['content'] = $content;
		parent::__construct( $config );

		$this->initializeLabelElement( $config );
		$this->initializeIconElement( $config );

		$this->getIconElement()->setAttributes( [
			'title' => $config['label'],
		] );

		$this->addClasses( [ 'ext-campaignevents-eventpage-details-widget' ] );
		$header = new Tag( 'div' );
		$header->appendContent( $this->icon, $this->label );
		$header->addClasses( [ 'ext-campaignevents-eventpage-details-widget-header' ] );
		// Prepending because the parent constructor has already appended the content
		$this->prependContent( $header );
	}
}
