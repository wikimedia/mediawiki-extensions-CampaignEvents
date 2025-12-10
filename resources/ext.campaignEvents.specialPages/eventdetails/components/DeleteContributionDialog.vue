<template>
	<cdx-dialog
		:title="$i18n( 'campaignevents-event-details-contributions-delete-title',
			eventName ).text()"
		:subtitle="$i18n( 'campaignevents-event-details-contributions-delete-subtitle' ).text()"
		:use-close-button="true"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="$emit( 'confirm-delete' )"
		@default="$emit( 'cancel' )"
	>
		<p>{{ $i18n( 'campaignevents-event-details-contributions-delete-note' ).text() }}</p>
	</cdx-dialog>
</template>

<script>
const { defineComponent } = require( 'vue' );
const { CdxDialog } = require( '../../../codex.js' );

module.exports = exports = defineComponent( {
	name: 'DeleteContributionDialog',
	components: { CdxDialog },
	props: {
		eventName: {
			type: String,
			required: true
		}
	},
	emits: [ 'confirm-delete', 'cancel' ],
	setup() {
		const primaryAction = {
			label: mw.msg( 'campaignevents-event-details-contributions-delete-confirm' ),
			actionType: 'destructive'
		};
		const defaultAction = {
			label: mw.msg( 'campaignevents-event-details-contributions-delete-cancel' )
		};

		return {
			primaryAction,
			defaultAction
		};
	}
} );
</script>
