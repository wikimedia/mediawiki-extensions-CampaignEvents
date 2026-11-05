<template>
	<li class="ext-campaignevents-worklist-card">
		<!-- How much the article could gain from being worked on, across the top of the card
			because it is what the reader is scanning for. Outside the Codex card rather than in
			its title slot: that slot sits in the text column beside the thumbnail, which would
			put the chip alongside the image instead of above it. The card's own frame is
			therefore drawn by this element. Framed as the size of the opportunity, not as a
			fault — the words carry the difference and every band looks the same, because
			grading them by colour would rank one reader's article above another's. -->
		<impact-chip
			class="ext-campaignevents-worklist-card__impact"
			:band="impactBand"
		></impact-chip>

		<!-- No `url`: the card is a plain element rather than a link, so the remove control can
			sit inside it and the title can carry the red-link class of its own. -->
		<!-- Every card carries a thumbnail slot whether or not the article has an image, so the
			titles line up down the column; Codex draws its placeholder icon where one is
			missing. -->
		<cdx-card
			class="ext-campaignevents-worklist-card__card"
			:thumbnail="image"
			force-thumbnail
		>
			<template #title>
				<!-- The server renders the link, so a page still to be created carries core's
					red-link class and an article on another wiki carries `external`, exactly as in
					the worklist table. A title the wiki cannot parse has no URL to link to, and an
					empty href would send the reader back to the page they are on. -->
				<a
					v-if="article.url"
					:class="article.classes"
					:href="article.url"
				>{{ article.title }}</a>
				<span v-else>{{ article.title }}</span>

				<!-- Positioned into the card's corner against the `position: relative` the Codex
					card already carries. It sits in this slot rather than a later one so that it
					is read straight after the article it removes. -->
				<cdx-button
					v-if="canRemove"
					class="ext-campaignevents-worklist-card__remove"
					action="destructive"
					weight="quiet"
					:aria-label="removeLabel"
					:title="removeLabel"
					@click="$emit( 'remove', article )"
				>
					<cdx-icon :icon="cdxIconTrash"></cdx-icon>
				</cdx-button>
			</template>

			<!-- Left out entirely when there is no description: the card renders its container,
				and its title-only styling, from the slot's presence. -->
			<template v-if="description" #description>
				{{ description }}
			</template>

			<!-- One slot holds everything that sits under the title, because Codex takes each of
				its slots once. The wiki is only worth naming for an article from elsewhere: two
				articles with the same title on different wikis would otherwise look identical.
				Relative to the wiki that answered the request, not the reader's. -->
			<template #supporting-text>
				<span
					v-if="!article.isLocal"
					class="ext-campaignevents-worklist-card__wiki"
				>{{ article.wikiName }}</span>
				<time
					v-if="added"
					class="ext-campaignevents-worklist-card__added"
					:datetime="addedAt"
				>{{ $i18n(
					'campaignevents-event-details-worklist-card-added',
					added
				).text() }}</time>
				<span
					v-else
					class="ext-campaignevents-worklist-card__added"
				>
					{{ $i18n( 'campaignevents-event-details-worklist-card-added-recent' ).text() }}
				</span>

				<!-- How often the article is read. Hidden entirely when the wiki cannot say: the
					card has to look complete without it. -->
				<view-count
					class="ext-campaignevents-worklist-card__views"
					:count="views ? views.count : null"
				></view-count>

				<!-- What the article most needs, from the per-element scores behind its quality
					rating. The first is shown in full and the rest summarised, so that a card with
					six weaknesses is no taller than one with a single weakness. -->
				<!-- `dir="auto"` because a signal is in the reader's language, which may run the
					other way from the card around it. -->
				<span
					v-if="signals.length"
					class="ext-campaignevents-worklist-card__signals"
					dir="auto"
				>
					<cdx-icon
						class="ext-campaignevents-worklist-card__signals-icon"
						:icon="cdxIconLightbulbOutline"
						size="small"
					></cdx-icon>{{ signalsText }}
				</span>
			</template>
		</cdx-card>
	</li>
</template>

<script>
const { computed, defineComponent } = require( 'vue' );
const { CdxButton, CdxCard, CdxIcon } = require( '../../../codex.js' );
const { ImpactChip, ViewCount } = require( 'ext.campaignEvents.cardElements' );
const { cdxIconLightbulbOutline, cdxIconTrash } = require( '../../../icons.json' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'WorklistArticleCard',
	components: { CdxButton, CdxCard, CdxIcon, ImpactChip, ViewCount },
	props: {
		article: {
			type: Object,
			required: true
		},
		canRemove: {
			type: Boolean,
			default: false
		},
		added: {
			type: String,
			default: null
		},
		addedAt: {
			type: String,
			default: null
		},
		views: {
			type: Object,
			default: null
		},
		image: {
			type: Object,
			default: null
		},
		description: {
			type: String,
			default: null
		},
		signals: {
			type: Array,
			default: () => []
		},
		impactBand: {
			type: String,
			default: null
		}
	},
	emits: [ 'remove' ],
	setup( props ) {
		// Only the first signal is written out; the rest are counted. One message rather than
		// two joined here, so that the wording between them, the word order and the plural form
		// of the count are all the translator's to decide. A lone signal is shown on its own,
		// which keeps the message free of a branch for a count that can never be anything.
		const signalsText = computed( () => {
			const first = props.signals[ 0 ] || '';
			const more = Math.max( props.signals.length - 1, 0 );
			return more ? mw.msg(
				'campaignevents-event-details-worklist-card-signals',
				first,
				mw.language.convertNumber( more )
			) : first;
		} );

		return {
			signalsText,
			removeLabel: mw.msg( 'campaignevents-worklist-table-remove-button-label' ),
			cdxIconLightbulbOutline,
			cdxIconTrash
		};
	}
} );
</script>
