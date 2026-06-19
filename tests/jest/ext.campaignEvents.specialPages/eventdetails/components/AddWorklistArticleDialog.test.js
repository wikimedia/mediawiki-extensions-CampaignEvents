'use strict';

/* global document */

const { mount } = require( '@vue/test-utils' );
const { nextTick } = require( 'vue' );
const AddWorklistArticleDialog = require( '../../../../../resources/ext.campaignEvents.specialPages/eventdetails/components/AddWorklistArticleDialog.vue' );

const ADD_BUTTON = '.ext-campaignevents-event-details-worklist-add-button';

// Captured from the stubbed search widget so tests can drive the 'choose' flow directly.
let chooseHandler;
let searchSetValue;

// Minimal stand-in for mw.widgets.TitleInputWidget: the component only uses lookupMenu.on(),
// setValue() and $element when (re)mounting the widget.
const makeSearchWidgetStub = () => {
	chooseHandler = null;
	searchSetValue = jest.fn();
	return {
		lookupMenu: {
			on: ( eventName, handler ) => {
				if ( eventName === 'choose' ) {
					chooseHandler = handler;
				}
			}
		},
		setValue: searchSetValue,
		$element: [ document.createElement( 'div' ) ]
	};
};

const mountDialog = () => {
	mw.widgets = {
		TitleInputWidget: jest.fn().mockImplementation( makeSearchWidgetStub )
	};
	return mount( AddWorklistArticleDialog );
};

// Opening is watched; the search widget is (re)mounted on the following tick, which registers
// the 'choose' handler. Two ticks: one for the watcher, one for its nextTick( mountSearchWidget ).
const openDialog = async ( wrapper ) => {
	wrapper.vm.open = true;
	await nextTick();
	await nextTick();
};

const chooseTitle = ( title ) => chooseHandler( { getData: () => title } );

describe( 'AddWorklistArticleDialog', () => {
	it( 'is closed initially', () => {
		const wrapper = mountDialog();
		expect( wrapper.vm.open ).toBe( false );
	} );

	it( 'renders the add button with an icon', () => {
		const wrapper = mountDialog();
		expect( wrapper.find( ADD_BUTTON ).exists() ).toBe( true );
		expect( wrapper.getComponent( { name: 'CdxIcon' } ).exists() ).toBe( true );
	} );

	it( 'opens the dialog when the add button is clicked', async () => {
		const wrapper = mountDialog();
		await wrapper.find( ADD_BUTTON ).trigger( 'click' );
		expect( wrapper.vm.open ).toBe( true );
	} );

	it( 'has use-close-button enabled and a progressive submit action', () => {
		const wrapper = mountDialog();
		const cdxDialog = wrapper.getComponent( { name: 'CdxDialog' } );
		expect( cdxDialog.props( 'useCloseButton' ) ).toBe( true );
		expect( wrapper.vm.primaryAction.label ).toBe( '(campaignevents-event-details-worklist-add-dialog-submit)' );
		expect( wrapper.vm.primaryAction.actionType ).toBe( 'progressive' );
	} );

	it( 'creates the title search widget and listens for its choose event when opened', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );
		expect( mw.widgets.TitleInputWidget ).toHaveBeenCalledTimes( 1 );
		expect( typeof chooseHandler ).toBe( 'function' );
	} );

	it( 'appends a chosen title to the textarea and clears the search', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( 'Moon' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon' );
		expect( searchSetValue ).toHaveBeenCalledWith( '' );
	} );

	it( 'appends multiple titles one per line', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( 'Moon' );
		chooseTitle( 'Sun' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon\nSun' );
	} );

	it( 'de-duplicates repeated titles', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( 'Moon' );
		chooseTitle( 'Moon' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon' );
	} );

	it( 'trims whitespace from chosen titles', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( '  Moon  ' );

		expect( wrapper.vm.articlesText ).toBe( 'Moon' );
	} );

	it( 'ignores empty or whitespace-only titles', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		chooseTitle( '   ' );

		expect( wrapper.vm.articlesText ).toBe( '' );
	} );

	it( 'closes the dialog on submit (saving is not implemented yet)', async () => {
		const wrapper = mountDialog();
		await openDialog( wrapper );

		wrapper.vm.onSubmit();

		expect( wrapper.vm.open ).toBe( false );
	} );
} );
