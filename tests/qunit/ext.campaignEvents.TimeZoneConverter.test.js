'use strict';

const TimeZoneConverter = require( '../../resources/TimeZoneConverter.js' );

QUnit.module( 'CampaignEvents TimeZoneConverter.js', QUnit.newMwEnvironment( {
	beforeEach: function () {
		this.server = this.sandbox.useFakeServer();
		this.server.respondImmediately = true;
		this.userPrefStub = this.sandbox.stub( mw.user.options, 'get' )
			.withArgs( 'timecorrection' )
			.returns( 'System|60' );
		this.getValidTarget = function () {
			return $( '<div>' )
				.append(
					$( '<span>' )
						.addClass( 'ext-campaignevents-time-range' )
						.data( 'mw-start', '2023-01-01T12:00:00Z' )
						.data( 'mw-end', '2023-01-06T10:23:00Z' )
						.append( '12:00, 1 January 2023 – 10:23, 6 January 2023' )
				)
				.append(
					$( '<span>' )
						.addClass( 'ext-campaignevents-timezone' )
						.append( 'DoesNot/Exist' )
				);
		};
	}
} ) );

QUnit.test( 'Does nothing when explicit timezone preference is set', function ( assert ) {
	this.userPrefStub.returns( 'ZoneInfo|60|Europe/Rome' );
	this.sandbox.mock( TimeZoneConverter.rest ).expects( 'get' ).never();
	const $target = this.getValidTarget(),
		before = $target.prop( 'outerHTML' );
	TimeZoneConverter.convert( $target, 'some-message' );
	assert.strictEqual( $target.prop( 'outerHTML' ), before );
} );

QUnit.test( 'Does nothing when Intl is not available', function ( assert ) {
	this.sandbox.stub( Intl, 'DateTimeFormat' ).throwsException( 'Intl unavailable' );
	this.sandbox.mock( TimeZoneConverter.rest ).expects( 'get' ).never();
	const $target = this.getValidTarget(),
		before = $target.prop( 'outerHTML' );
	TimeZoneConverter.convert( $target, 'some-message' );
	assert.strictEqual( $target.prop( 'outerHTML' ), before );
} );

QUnit.test( 'Fails when given nonexisting elements', ( assert ) => {
	assert.throws(
		() => TimeZoneConverter.convert( $() ),
		/Error: No timestamp\(s\) provided/
	);
} );

QUnit.test( 'Fails when not given timestamp', ( assert ) => {
	const $target = $( '<div>' )
		.append( $( '<span>' ).addClass( 'ext-campaignevents-time-range' ).append( 'No time' ) )
		.append( $( '<span>' ).addClass( 'ext-campaignevents-timezone' ).append( 'No timezone' ) );
	assert.throws(
		() => TimeZoneConverter.convert( $target, 'some-message' ),
		/Error: No timestamp\(s\) provided/
	);
} );

QUnit.test( 'Aborts quietly upon server error', async function ( assert ) {
	this.server.respond( ( request ) => {
		request.respond(
			400,
			{ 'Content-Type': 'application/json' },
			JSON.stringify( { message: 'Invalid timestamp' } )
		);
	} );
	const $target = this.getValidTarget(),
		before = $target.prop( 'outerHTML' );
	try {
		await TimeZoneConverter.convert( $target, 'some-message' );
	} catch ( _ ) {
		assert.strictEqual( $target.prop( 'outerHTML' ), before );
	}
} );

QUnit.test( 'Succeeds when given the necessary data', async function ( assert ) {
	const localTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
	this.server.respond( ( request ) => {
		request.respond(
			200,
			{ 'Content-Type': 'application/json' },
			JSON.stringify( {
				startDateTime: '1',
				startDate: '2',
				startTime: '3',
				endDateTime: '4',
				endDate: '5',
				endTime: '6'
			} )
		);
	} );
	const $target = this.getValidTarget();
	await TimeZoneConverter.convert( $target, 'some-message' );
	assert.strictEqual( $target.text(), `(some-message: 1, 2, 3, 4, 5, 6)${ localTimezone }` );
} );

QUnit.test( 'Formats local timestamps as TS_MW', ( assert ) => {
	const testCases = [
		[ '2025-01-02T03:04:05Z', 'Europe/Paris', '20250102040405' ],
		[ '2025-01-02T03:04:05Z', 'America/New_York', '20250101220405' ],
		[ '2025-01-02T03:04:05Z', 'Asia/Kolkata', '20250102083405' ],
		[ '2025-03-14T11:22:33Z', 'America/Los_Angeles', '20250314042233' ],
		[ '2025-03-14T11:22:33Z', 'Europe/Rome', '20250314122233' ],
		[ '2025-03-14T11:22:33Z', 'Australia/Adelaide', '20250314215233' ]
	];

	for ( const testCase of testCases ) {
		const [ ts, tz, expected ] = testCase;
		assert.strictEqual(
			TimeZoneConverter.formatMwTimestamp( ts, tz ),
			expected,
			`Format ${ ts } ${ tz }`
		);
	}
} );
