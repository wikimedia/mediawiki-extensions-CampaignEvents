( function () {
	'use strict';

	function DateTimeWidgetsEnhancer() {
		this.dateWidgets = {
			start: '.ext-campaignevents-allevents-calendar-start-field',
			end: '.ext-campaignevents-allevents-calendar-end-field'
		};
		this.dateFormat = '$!{dow|short} ${day|#} ${month|short} ${year|#}';
	}

	/**
	 * @param {jQuery} $field
	 * @return {Object|null}
	 */
	DateTimeWidgetsEnhancer.prototype.getInfusedFieldWidget = function ( $field ) {
		try {
			return OO.ui.infuse(
				$field,
				{ formatter: { format: this.dateFormat } }
			);
		} catch ( error ) {
			return null;
		}
	};

	/**
	 * @param {jQuery} $formRoot
	 */
	DateTimeWidgetsEnhancer.prototype.init = function ( $formRoot ) {
		var self = this;
		for ( var dateWidgetKey in this.dateWidgets ) {
			this.dateWidgets[ dateWidgetKey ] = this.getInfusedFieldWidget(
				$formRoot.find( this.dateWidgets[ dateWidgetKey ] )
			);
		}

		if ( !self.dateWidgets.start || !self.dateWidgets.end ) {
			return;
		}

		this.dateWidgets.start.fieldWidget.on( 'change', this.updateEndDate.bind( this ) );
		this.updateEndDate();

	};

	DateTimeWidgetsEnhancer.prototype.updateEndDate = function () {
		var newMin = this.dateWidgets.start.fieldWidget.getValueAsDate();
		this.dateWidgets.end.fieldWidget.min.setTime( newMin );
		if ( newMin > this.dateWidgets.end.fieldWidget.formatter.defaultDate ) {
			this.dateWidgets.end.fieldWidget.formatter.defaultDate = newMin;
		}
		if (
			this.dateWidgets.end.fieldWidget.getValueAsDate() &&
			newMin > this.dateWidgets.end.fieldWidget.getValueAsDate()
		) {
			this.dateWidgets.end.fieldWidget.setValue( newMin );
		}
		// Let the widget update its fields to recompute validity of the data,
		// as well as update the selectable dates in the calendar.
		// XXX We're calling a @private method here...
		this.dateWidgets.end.fieldWidget.onChange();
	};

	module.exports = new DateTimeWidgetsEnhancer();
}() );
