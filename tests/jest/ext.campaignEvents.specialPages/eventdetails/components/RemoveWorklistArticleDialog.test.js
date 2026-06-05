'use strict';

const { mount } = require( '@vue/test-utils' );
const RemoveWorklistArticleDialog = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/RemoveWorklistArticleDialog.vue' );

const mountDialog = ( props = {} ) => {
	const defaultProps = {
		open: true,
		...props
	};

	return mount( RemoveWorklistArticleDialog, { props: defaultProps } );
};

describe( 'RemoveWorklistArticleDialog', () => {
	it( 'passes open prop to CdxDialog correctly', () => {
		const wrapperOpen = mountDialog( { open: true } );
		const wrapperClosed = mountDialog( { open: false } );

		expect( wrapperOpen.getComponent( { name: 'CdxDialog' } ).props( 'open' ) ).toBe( true );
		expect( wrapperClosed.getComponent( { name: 'CdxDialog' } ).props( 'open' ) ).toBe( false );
	} );

	it( 'has correct title', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		expect( cdxDialog.props( 'title' ) ).toBe( 'campaignevents-worklist-remove-confirm-title' );
	} );

	it( 'has correct primary action button', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		const primaryAction = cdxDialog.props( 'primaryAction' );

		expect( primaryAction.label ).toBe( '(campaignevents-worklist-remove-confirm-action)' );
		expect( primaryAction.actionType ).toBe( 'destructive' );
		expect( primaryAction.disabled ).toBe( false );
	} );

	it( 'disables the primary action when pending', () => {
		const wrapper = mountDialog( { pending: true } );
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		expect( cdxDialog.props( 'primaryAction' ).disabled ).toBe( true );
	} );

	it( 'has correct default action button', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		const defaultAction = cdxDialog.props( 'defaultAction' );

		expect( defaultAction.label ).toBe( '(campaignevents-worklist-remove-confirm-cancel)' );
		expect( defaultAction.actionType ).toBeUndefined();
	} );

	it( 'renders body message with correct text', () => {
		const wrapper = mountDialog();
		expect( wrapper.html() ).toContain( 'campaignevents-worklist-remove-confirm-body' );
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

	it( 'has correct emits array', () => {
		const wrapper = mountDialog();
		expect( wrapper.vm.$options.emits ).toEqual( [ 'confirm-delete', 'cancel' ] );
	} );
} );
