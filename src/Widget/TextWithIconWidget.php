<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Widget;

use InvalidArgumentException;
use OOUI\IconElement;
use OOUI\LabelElement;
use OOUI\Tag;
use OOUI\Widget;

/**
 * A widget combining an icon and some labelled information. The label is invisible and used as `title`
 * of the icon. Somewhat similar to MessageWidget, but with different semantics.
 * @note When using this widget, you should load the `ext.campaignEvents.TextWithIconWidget.less` stylesheet.
 */
class TextWithIconWidget extends Widget {
	use IconElement;
	use LabelElement;

	/**
	 * @inheritDoc
	 * CSS classes can be added to the icon via $config['icon_classes'].
	 */
	private function __construct( array $config = [] ) {
		if ( !isset( $config['content'] ) || !isset( $config['label'] ) ) {
			throw new InvalidArgumentException( 'Must specify content and label' );
		}
		$content = new Tag( 'span' );
		$content->appendContent( $config['content'] );
		$content->addClasses( [ 'ext-campaignevents-textwithicon-widget-content' ] );
		$config['content'] = $content;

		$config['invisibleLabel'] = true;

		parent::__construct( $config );

		$this->initializeLabelElement( $config );
		$this->initializeIconElement( $config );

		$this->getIconElement()->setAttributes( [
			'title' => $config['label'],
		] )->addClasses( $config['icon_classes'] ?? [] );

		$this->addClasses( [ 'ext-campaignevents-textwithicon-widget' ] );
		// Prepending because the parent constructor has already appended the content
		$this->prependContent( [ $this->icon, $this->label ] );
	}

	public static function build( array $config ): string {
		return ( new self( $config ) )->__toString();
	}
}
