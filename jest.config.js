// For a detailed explanation regarding each configuration property, visit:
// https://jestjs.io/docs/en/configuration.html
'use strict';
module.exports = {
	// Automatically clear mock calls and instances between every test
	clearMocks: true,

	// Indicates whether the coverage information should be collected while executing the test
	collectCoverage: true,

	// An array of glob patterns indicating a set of files fo
	//  which coverage information should be collected
	collectCoverageFrom: [
		'resources/**/*.{js,vue}'
	],

	// The directory where Jest should output its coverage files
	coverageDirectory: 'jest-coverage',

	// An array of regexp pattern strings used to skip coverage collection
	coveragePathIgnorePatterns: [
		'/node_modules/'
	],

	// An array of file extensions your modules use
	moduleFileExtensions: [
		'js',
		'json',
		'vue'
	],

	// A map from regular expressions to module names or to arrays of module
	// names that allow to stub out resources with a single module
	moduleNameMapper: {
		'codex.js': '@wikimedia/codex'
	},

	setupFiles: [
		'./jest.setup.js'
	],

	testEnvironment: 'jsdom',

	// Options that will be passed to the testEnvironment
	testEnvironmentOptions: {
		customExportConditions: [ 'node', 'node-addons' ]
	},

	testMatch: [ '**/tests/jest/**/*.test.js' ],

	transform: {
		'.*\\.(vue)$': '<rootDir>/node_modules/@vue/vue3-jest'
	}
};
