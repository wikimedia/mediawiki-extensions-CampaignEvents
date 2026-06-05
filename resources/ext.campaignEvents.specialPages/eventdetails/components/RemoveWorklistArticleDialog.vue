<template>
	<cdx-dialog
		:title="$i18n( 'campaignevents-worklist-remove-confirm-title' ).text()"
		:use-close-button="true"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="$emit( 'confirm-delete' )"
		@default="$emit( 'cancel' )"
	>
		<p>{{ $i18n( 'campaignevents-worklist-remove-confirm-body' ).text() }}</p>
	</cdx-dialog>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxDialog } = require( '../../../codex.js' );

module.exports = exports = defineComponent( {
	name: 'RemoveWorklistArticleDialog',
	components: { CdxDialog },
	props: {
		// Disables the confirm button while a removal request is in flight.
		pending: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'confirm-delete', 'cancel' ],
	setup( props ) {
		const primaryAction = computed( () => ( {
			label: mw.msg( 'campaignevents-worklist-remove-confirm-action' ),
			actionType: 'destructive',
			disabled: props.pending
		} ) );
		const defaultAction = {
			label: mw.msg( 'campaignevents-worklist-remove-confirm-cancel' )
		};

		return {
			primaryAction,
			defaultAction
		};
	}
} );
</script>
