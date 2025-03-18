( function () {
	'use strict';

	/**
	 * Utility that watches the accordions for the ongoing and upcoming events sections, and updates
	 * a hidden input field to retain the accordion state when the form is submitted.
	 */
	function EventAccordionWatcher() {
		/* eslint-disable no-jquery/no-global-selector */
		this.$hiddenInput = $( 'input[name="wpOpenSections"]' );
		this.$ongoingAccordion = $( '.ext-campaignevents-allevents-ongoing-events' );
		this.$upcomingAccordion = $( '.ext-campaignevents-allevents-upcoming-events' );
		/* eslint-enable no-jquery/no-global-selector */
	}

	EventAccordionWatcher.prototype.setup = function () {
		const that = this;
		// Note: `this.open` gives us the state of the accordion before the click; so, for example,
		// it is true when the user closes the accordion.
		this.$ongoingAccordion.on( 'click', function () {
			that.updateHiddenInput( this.open, 'ongoing' );
		} );
		this.$upcomingAccordion.on( 'click', function () {
			that.updateHiddenInput( this.open, 'upcoming' );
		} );
	};

	EventAccordionWatcher.prototype.updateHiddenInput = function ( wasOpen, sectionName ) {
		const curOpenSections = this.$hiddenInput.val().split( ',' ),
			newOpenSections = curOpenSections,
			sectionIdx = newOpenSections.indexOf( sectionName );

		// Checking sectionIdx should be redundant, but done for defensiveness.
		if ( wasOpen && sectionIdx > -1 ) {
			newOpenSections.splice( sectionIdx, 1 );
		} else if ( !wasOpen && sectionIdx === -1 ) {
			newOpenSections.push( sectionName );
		}

		this.$hiddenInput.val( newOpenSections.join( ',' ) );
	};

	module.exports = new EventAccordionWatcher();
}() );
