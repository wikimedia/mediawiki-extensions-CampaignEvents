<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\CampaignEvents\Widget;

use MediaWiki\Html\Html;

/**
 * A widget combining an icon and some labelled information. The label is invisible and used as `title`
 * of the icon. The icon itself gets the "subtle" aspect (gray-ish).
 * Somewhat similar to MessageWidget, but with different semantics.
 * @note When using this widget, you should load the `ext.campaignEvents.TextWithIconWidget.less` stylesheet. If you
 * intend to use an icon that isn't already used somewhere, you need to add an explicit class definition to the
 * stylesheet, due to T388458. Icon names should follow the canonical Codex names.
 */
class TextWithIconWidget {
	/** @param-taint $rawContent exec_html */
	public static function build(
		string $icon,
		string $label,
		string $rawContent,
		array $extraClasses = []
	): string {
		$contentElement = Html::rawElement(
			'span',
			[ 'class' => 'ext-campaignevents-textwithicon-widget-content' ],
			$rawContent
		);

		// XXX: Codex's CSS-only icons have quite limited capabilities. Ideally, the label would be specified as part
		// of the element, and we wouldn't have to define all the icon classes. See T388458.
		$labelElement = Html::element(
			'span',
			[ 'class' => 'ext-campaignevents-textwithicon-widget-label' ],
			$label
		);

		$iconElement = Html::element(
			'span',
			[
				'class' => [
					"ext-campaignevents-textwithicon-widget-icon-$icon",
					'ext-campaignevents-textwithicon-widget-icon-subtle'
				],
				'title' => $label
			]
		);

		return Html::rawElement(
			'div',
			[ 'class' => [ 'ext-campaignevents-textwithicon-widget', ...$extraClasses ] ],
			$iconElement . $labelElement . $contentElement
		);
	}
}
