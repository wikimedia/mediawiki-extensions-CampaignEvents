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
		<div v-if="hasGoal" class="ext-campaignevents-goal-progress-card">
			<div class="ext-campaignevents-goal-progress">
				<h3 class="ext-campaignevents-goal-progress__heading">
					{{ $i18n( 'campaignevents-goal-progress-heading' ).text() }}
				</h3>
				<p class="ext-campaignevents-goal-progress__description">
					{{ selectedEventData.goalDescription }}
				</p>
				<cdx-progress-bar
					class="ext-campaignevents-goal-progress__bar"
					:value="selectedEventData.goalPercent"
					:max="100"
					:end-label="selectedEventData.goalNumericText"
					:aria-label="selectedEventData.goalNumericText"
				></cdx-progress-bar>
			</div>
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

const { defineComponent, ref, computed } = require( 'vue' );
const { CdxDialog, CdxSelect, CdxProgressBar } = require( './../codex.js' );

module.exports = exports = defineComponent( {
	name: 'EditAssociationDialog',
	components: { CdxDialog, CdxSelect, CdxProgressBar },
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

		// Do not render anything if we have no events, which should never actually happen in
		// practice. This complements the error thrown above, which on its own does not
		// prevent the component from being rendered.
		const canRender = computed( () => events.length > 0 );

		const eventsById = new Map( events.map( ( e ) => [ e.id, e ] ) );
		const selectedEventData = computed(
			() => eventsById.get( selectedEvent.value ) || null
		);

		const hasGoal = computed( () => !!( selectedEventData.value &&
			selectedEventData.value.goalPercent !== undefined ) );

		/**
		 *  Message with wikitext link for v-i18n-html; null when plain text is shown in template.
		 *
		 * @return {mw.Message|null}
		 */
		const footerMessageHTML = computed( () => {
			if ( events.length > 1 && selectedEvent.value === null ) {
				return null;
			}
			return mw.message(
				'campaignevents-postedit-dialog-hide-associate-edit-dialog-in-event-preferences',
				'Special:RegisterForEvent/' + selectedEvent.value
			);
		} );

		function onPrimary() {
			const selectedEventID = selectedEvent.value;

			if ( selectedEventID === null ) {
				// XXX: Prevent this (disable button) or show an error (T410099)
				return;
			}

			const selected = events.find( ( e ) => e.id === selectedEventID );
			const selectedEventName = selected ? selected.name : null;

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
			onPrimary,
			canRender,
			selectedEventData,
			hasGoal,
			footerMessageHTML
		};
	}
} );
</script>
