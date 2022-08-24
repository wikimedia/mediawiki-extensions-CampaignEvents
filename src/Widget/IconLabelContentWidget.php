<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Widget;

use InvalidArgumentException;
use OOUI\IconElement;
use OOUI\LabelElement;
use OOUI\Tag;
use OOUI\Widget;

/**
 * A generic widget combining an icon, a label and content.
 * TODO This widget requires styles that are only loaded on Special:MyEvents and is not really reusable outside of it
 */
class IconLabelContentWidget extends Widget {
	use IconElement;
	use LabelElement;

	/**
	 * @inheritDoc
	 */
	public function __construct( array $config = [] ) {
		if ( !isset( $config['content'] ) || !isset( $config['label'] ) ) {
			throw new InvalidArgumentException( 'Must specify content and label' );
		}
		$content = new Tag();
		$content->appendContent( $config['content'] );
		$content->addClasses( [ 'ext-campaignevents-details-icon-label-content-widget' ] );

		if ( !empty( $config[ 'content_direction' ] ) ) {
			$content->setAttributes( [ 'dir' => $config[ 'content_direction' ] ] );
		}
		$config['content'] = $content;
		parent::__construct( $config );

		$this->initializeLabelElement( $config );
		$this->initializeIconElement( $config );

		$this->getIconElement()->setAttributes( [
			'title' => $config['label'],
		] );

		if ( !empty( $config['icon_classes'] ) ) {
			$this->icon->addClasses( $config['icon_classes'] );
		}

		$this->addClasses( [ 'ext-campaignevents-details-icon-label-content-widget' ] );
		$header = new Tag();
		$header->appendContent( $this->icon, $this->label );
		$header->addClasses( [ 'ext-campaignevents-details-icon-label-content-widget-header' ] );
		$this->prependContent( $header );
	}
}
