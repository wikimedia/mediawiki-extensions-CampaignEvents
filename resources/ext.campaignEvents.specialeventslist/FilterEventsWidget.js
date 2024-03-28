( function () {
	'use strict';

	/**
	 * Widgets that allows filtering the list of your events.
	 *
	 * @extends OO.ui.Widget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @param {jQuery} [config.$filterElements] Selection of elements on the page that should be
	 *   moved inside the widget.
	 */
	function FilterEventsWidget( config ) {
		FilterEventsWidget.super.call( this, config );

		this.filterBtn = new OO.ui.PopupButtonWidget( {
			label: mw.msg( 'campaignevents-myevents-filter-btn-label' ),
			icon: 'funnel',
			indicator: 'down',
			popup: {
				$content: config.$filterElements,
				padded: true,
				autoClose: true,
				// Prevent autoclosing when a select option (located in the default overlay)
				// is chosen.
				// eslint-disable-next-line no-jquery/no-global-selector
				$autoCloseIgnore: $( '.oo-ui-defaultOverlay' )
			}
		} );
		this.$element
			.addClass( 'ext-campaignevents-myevents-filter-widget' )
			.html( this.filterBtn.$element );
	}

	OO.inheritClass( FilterEventsWidget, OO.ui.Widget );

	module.exports = FilterEventsWidget;
}() );
