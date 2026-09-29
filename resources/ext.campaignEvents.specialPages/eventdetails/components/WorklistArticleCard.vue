<template>
	<li class="ext-campaignevents-worklist-card">
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

			<!-- The wiki is only worth naming for an article from elsewhere: two articles with the
				same title on different wikis would otherwise look identical. Relative to the wiki
				that answered the request, not the reader's. -->
			<template
				v-if="!article.isLocal || added || viewsText"
				#supporting-text
			>
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

				<!-- How often the article is read. Hidden entirely when the wiki cannot say: the
					card has to look complete without it. The figure is shown bare, as the design
					has it, so the icon rather than the text says what the number counts. -->
				<span v-if="viewsText" class="ext-campaignevents-worklist-card__views">
					<cdx-icon
						class="ext-campaignevents-worklist-card__views-icon"
						:icon="cdxIconChartLine"
						:icon-label="viewsLabel"
						size="small"
					></cdx-icon>{{ viewsText }}
				</span>
			</template>
		</cdx-card>
	</li>
</template>

<script>
const { computed, defineComponent } = require( 'vue' );
const { CdxButton, CdxCard, CdxIcon } = require( '../../../codex.js' );
const { cdxIconChartLine, cdxIconTrash } = require( '../../../icons.json' );

/** Counts of a thousand or more are shortened; the suffix is a message, not a letter in code. */
const THOUSAND = 1000;
const MILLION = 1000000;

/**
 * The view count as the reader sees it: shortened past a thousand, in their own digits.
 *
 * @param {number} count
 * @return {string}
 */
function shorten( count ) {
	if ( count < THOUSAND ) {
		return mw.language.convertNumber( count );
	}
	// The unit is chosen from the rounded figure rather than the raw count, so a count that
	// rounds up into the next unit is shown in that unit: 999,600 reads as 1M, not 1000k.
	const thousands = Math.round( count / THOUSAND );
	if ( thousands < THOUSAND ) {
		return mw.msg(
			'campaignevents-event-details-worklist-card-views-thousands',
			mw.language.convertNumber( thousands )
		);
	}
	return mw.msg(
		'campaignevents-event-details-worklist-card-views-millions',
		mw.language.convertNumber( Math.round( count / MILLION ) )
	);
}

// @vue/component
module.exports = exports = defineComponent( {
	name: 'WorklistArticleCard',
	components: { CdxButton, CdxCard, CdxIcon },
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
		}
	},
	emits: [ 'remove' ],
	setup( props ) {
		const viewsText = computed( () => props.views ? shorten( props.views.count ) : '' );

		return {
			viewsText,
			// The figure beside it is a bare number, so the icon is what names it. Labelled
			// rather than hidden, or a screen reader would read "20k" with nothing saying what
			// 20k counts.
			viewsLabel: mw.msg( 'campaignevents-event-details-worklist-card-views-label' ),
			cdxIconChartLine,
			removeLabel: mw.msg( 'campaignevents-worklist-table-remove-button-label' ),
			cdxIconTrash
		};
	}
} );
</script>
