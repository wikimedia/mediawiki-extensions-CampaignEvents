'use strict';

const { mount } = require( '@vue/test-utils' );
const DeleteContributionDialog = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/DeleteContributionDialog.vue' );

const mountDialog = ( props = {} ) => {
	const defaultProps = {
		open: true,
		eventName: 'Test Event',
		...props
	};

	return mount( DeleteContributionDialog, { props: defaultProps } );
};

describe( 'DeleteContributionDialog', () => {
	it( 'passes open prop to CdxDialog correctly', () => {
		const wrapperOpen = mountDialog( { open: true } );
		const wrapperClosed = mountDialog( { open: false } );

		expect( wrapperOpen.getComponent( { name: 'CdxDialog' } ).props( 'open' ) ).toBe( true );
		expect( wrapperClosed.getComponent( { name: 'CdxDialog' } ).props( 'open' ) ).toBe( false );
	} );

	it( 'has correct title with event name', () => {
		const wrapper = mountDialog( { eventName: 'My Test Event' } );
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );

		// Test that the title prop receives the message key with the event name parameter
		expect( cdxDialog.props( 'title' ) ).toBe( 'campaignevents-event-details-contributions-delete-title (My Test Event)' );

		// Test HTML output to ensure the argument is rendered
		expect( wrapper.html() ).toContain( 'My Test Event' );
	} );

	it( 'has correct subtitle', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		expect( cdxDialog.props( 'subtitle' ) ).toBe( 'campaignevents-event-details-contributions-delete-subtitle' );
	} );

	it( 'has correct primary action button', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		const primaryAction = cdxDialog.props( 'primaryAction' );

		expect( primaryAction.label ).toBe( '(campaignevents-event-details-contributions-delete-confirm)' );
		expect( primaryAction.actionType ).toBe( 'destructive' );
	} );

	it( 'has correct default action button', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		const defaultAction = cdxDialog.props( 'defaultAction' );

		expect( defaultAction.label ).toBe( '(campaignevents-event-details-contributions-delete-cancel)' );
		expect( defaultAction.actionType ).toBeUndefined();
	} );

	it( 'renders note message with correct text', () => {
		const wrapper = mountDialog();
		expect( wrapper.html() ).toContain( 'campaignevents-event-details-contributions-delete-note' );
	} );

	it( 'emits confirm-delete when primary action is clicked', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );

		cdxDialog.vm.$emit( 'primary' );

		const confirmDeleteEvent = wrapper.emitted( 'confirm-delete' );
		expect( confirmDeleteEvent ).toHaveLength( 1 );
	} );

	it( 'emits cancel when default action is clicked', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );

		cdxDialog.vm.$emit( 'default' );

		const cancelEvent = wrapper.emitted( 'cancel' );
		expect( cancelEvent ).toHaveLength( 1 );
	} );

	it( 'has use-close-button enabled', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		expect( cdxDialog.props( 'useCloseButton' ) ).toBe( true );
	} );

	it( 'has correct primary action configuration', () => {
		const wrapper = mountDialog();
		const primaryAction = wrapper.vm.primaryAction;

		expect( primaryAction.label ).toBe( '(campaignevents-event-details-contributions-delete-confirm)' );
		expect( primaryAction.actionType ).toBe( 'destructive' );
	} );

	it( 'has correct default action configuration', () => {
		const wrapper = mountDialog();
		const defaultAction = wrapper.vm.defaultAction;

		expect( defaultAction.label ).toBe( '(campaignevents-event-details-contributions-delete-cancel)' );
		expect( defaultAction.actionType ).toBeUndefined();
	} );

	it( 'has correct emits array', () => {
		const wrapper = mountDialog();
		expect( wrapper.vm.$options.emits ).toEqual( [ 'confirm-delete', 'cancel' ] );
	} );
} );
