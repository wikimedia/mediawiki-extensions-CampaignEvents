'use strict';

const assert = require( 'assert' ),
	EnableEventRegistrationPage = require( '../pageobjects/enableEventRegistration.page' ),
	UserLoginPage = require( 'wdio-mediawiki/LoginPage' );

describe( 'Enable Event Registration', function () {

	it( 'is configured correctly', async function () {
		await UserLoginPage.loginAdmin();
		await EnableEventRegistrationPage.open();
		assert( await EnableEventRegistrationPage.enableRegistration.isExisting() );
	} );

} );
