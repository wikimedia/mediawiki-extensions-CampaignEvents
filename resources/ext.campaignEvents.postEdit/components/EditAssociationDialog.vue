<template>
	<cdx-dialog
		v-if="canRender"
		:title="dialogTitle"
		:use-close-button="true"
		:primary-action="primaryAction"
		:default-action="defaultAction"
		@primary="onPrimary"
	>
		<p>{{ dialogIntro }}</p>
		<cdx-select
			v-if="events.length > 1"
			v-model:selected="selectedEvent"
			:menu-items="selectOptions"
			:default-label="$i18n( 'campaignevents-postedit-dialog-select-placeholder' ).text()">
		</cdx-select>
		<!-- eslint-disable-next-line vue/no-v-html To be replaced with a Vue component after T407638 -->
		<div v-if="selectedEventGoalProgress" v-html="selectedEventGoalProgress">
		</div>
		<template #footer-text>
			<span
				v-if="footerMessageHTML"
				v-i18n-html="footerMessageHTML"
			></span>
			<span v-else>{{
				$i18n(
					'campaignevents-postedit-dialog-hide-associate-edit-dialog-before-select'
				).text()
			}}</span>
		</template>
	</cdx-dialog>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const { CdxDialog, CdxSelect } = require( './../codex.js' );

module.exports = exports = defineComponent( {
	name: 'EditAssociationDialog',
	components: { CdxDialog, CdxSelect },
	emits: [ 'associate-edit' ],
	setup( _, { emit } ) {
		const events = mw.config.get( 'wgCampaignEventsEventsForAssociation' );

		if ( !events.length ) {
			// NOTE: Vue will still try to render the component even if this error is thrown,
			// and emit warning due to missing data. So we also hide the dialog via a
			// computed property.
			throw new Error( 'Dialog should not be created when there are no events.' );
		}

		let defaultEvent, dialogTitle, dialogIntro;
		if ( events.length > 1 ) {
			defaultEvent = null;
			dialogTitle = mw.msg( 'campaignevents-postedit-dialog-title-multiple' );
			dialogIntro = mw.msg( 'campaignevents-postedit-dialog-intro-multiple' );
		} else {
			defaultEvent = events[ 0 ].id;
			dialogTitle = mw.msg(
				'campaignevents-postedit-dialog-title-single',
				events[ 0 ].name
			);
			dialogIntro = mw.msg(
				'campaignevents-postedit-dialog-intro-single',
				events[ 0 ].name
			);
		}

		const selectedEvent = ref( defaultEvent );

		const selectOptions = events.map( ( event ) => ( {
			label: event.name,
			value: event.id
		} ) );

		const primaryAction = {
			label: mw.msg( 'campaignevents-postedit-dialog-action-yes' ),
			actionType: 'progressive'
		};
		const defaultAction = {
			label: mw.msg( 'campaignevents-postedit-dialog-action-no' )
		};

		function onPrimary() {
			const selectedEventID = selectedEvent.value;
			if ( selectedEventID === null ) {
				// XXX: Prevent this (disable button) or show an error (T410099)
				return;
			}
			let selectedEventName;
			for ( const event of events ) {
				if ( event.id === selectedEventID ) {
					selectedEventName = event.name;
					break;
				}
			}
			emit( 'associate-edit', selectedEventID, selectedEventName );
		}

		return {
			events,
			dialogTitle,
			dialogIntro,
			selectOptions,
			selectedEvent,
			primaryAction,
			defaultAction,
			onPrimary
		};
	},
	computed: {
		canRender() {
			// Do not render anything if we have no events, which should ever actually happen in
			// practice. This complements the error thrown above, which on its own does not
			// prevent the component from being rendered.
			return mw.config.get( 'wgCampaignEventsEventsForAssociation' ).length > 0;
		},
		selectedEventGoalProgress() {
			const id = this.selectedEvent;
			const event = this.events.find( ( e ) => e.id === id );
			return event && event.goalProgress ? event.goalProgress : null;
		},
		/**
		 * Message with wikitext link for v-i18n-html; null when plain text is shown in template.
		 *
		 * @return {mw.Message|null}
		 */
		footerMessageHTML() {
			if ( this.events.length > 1 && this.selectedEvent === null ) {
				return null;
			}
			return mw.message(
				'campaignevents-postedit-dialog-hide-associate-edit-dialog-in-event-preferences',
				'Special:RegisterForEvent/' + this.selectedEvent
			);
		}
	}
} );
</script>
