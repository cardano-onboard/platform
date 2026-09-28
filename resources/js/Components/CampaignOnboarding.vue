<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    campaign: { type: Object, required: true },
    onboarding: { type: Object, default: () => ({}) },
    // The analysis run in flight, as the page's shared poller sees it: which phase it is
    // in, how many wallets it has read, and why it stopped if it did. Null when nothing is
    // running, and null for a run nobody dispatched through a task row, which is why every
    // reading below falls back to the stored analysis row.
    //
    // This panel keeps no timer. One poller drives every job on the page, so a campaign
    // importing codes while an analysis runs asks once rather than twice.
    task: { type: Object, default: null },
    // A claim status check started from here or from the codes table, still running. The
    // page's poller reports it and reloads this panel's pending count when it finishes.
    claimCheckRunning: { type: Boolean, default: false },
    // Rendered inside the analytics card rather than as a panel of its own, so the
    // frame and the title come from the tab around it. The toolbar stays, because the
    // export and analyze actions live there.
    embedded: { type: Boolean, default: false },
});

// The phase name the analysis job reports while it is confirming claims, and the only one
// this panel names rather than simply printing. It is written by
// App\Jobs\AnalyzeCampaignOnboarding::STAGE_CONFIRMING, which a test pins, because during
// that phase the standing description below is describing work that has not started.
const CONFIRMING_STAGE = 'Confirming outstanding claims';

// Collapsed by default, like the performance strip above it: the headline numbers
// live in the summary row, and the per-wallet table is detail you ask for.
const expanded = ref(false);
const starting = ref(false);
const checking = ref(false);

const summary = computed(() => props.onboarding?.summary ?? null);
const status = computed(() => props.onboarding?.status ?? null);
const wallets = computed(() => props.onboarding?.wallets ?? []);
const eligibleClaims = computed(() => props.onboarding?.eligible_claims ?? 0);

// Claims handed to the transaction backend that have no confirmed transaction yet. The
// analysis cannot see them, so they are the difference between what it would measure and
// what the campaign actually holds.
const pendingClaims = computed(() => props.onboarding?.pending_claims ?? 0);

// A campaign with nothing but unconfirmed claims is still worth analysing: the run
// confirms them first, and refusing would leave the operator with a button that does
// nothing and no way to find out why.
const canAnalyze = computed(() => eligibleClaims.value + pendingClaims.value > 0);

const taskStatus = computed(() => props.task?.status ?? null);
const taskActive = computed(() => ['queued', 'running'].includes(taskStatus.value));
const taskFailed = computed(() => taskStatus.value === 'failed');

// Which phase the run is in, in the words the job wrote. The stored analysis row carries
// no phase, so a run started outside this page shows the standing description alone.
const stage = computed(() => (taskActive.value ? (props.task?.stage ?? null) : null));

// Only the wallet-history phase counts its work, because it is the only phase with one
// call per wallet. The rest leave the bar indeterminate rather than invent a denominator.
const stageTotal = computed(() => (taskActive.value ? (props.task?.progress_total ?? 0) : 0));
const stageDone = computed(() => props.task?.progress_done ?? 0);
const stagePercent = computed(() =>
    stageTotal.value ? Math.min(100, Math.round((stageDone.value / stageTotal.value) * 100)) : 0,
);

// A live run wins over the stored row, because the row is only written at the boundaries
// of the run and the task is written throughout it. A failed run is not running whatever
// the row still says: a job that died before touching the analysis leaves it on 'pending',
// and without this the panel would show a bar that was never going to move.
const isRunning = computed(
    () => taskActive.value || (!taskFailed.value && ['pending', 'running'].includes(status.value)),
);
const hasResults = computed(() => ['complete', 'partial'].includes(status.value) && summary.value);

// A run that finished with holes in it. The provider did not answer for every wallet, so
// those wallets are unknown rather than measured at nothing, and the panel says so instead
// of presenting a result with wallets missing from it as a reading of the campaign.
const isPartial = computed(() => status.value === 'partial');
const unreadWallets = computed(() => props.onboarding?.unread_wallets ?? 0);
const hasFailed = computed(() => taskFailed.value || status.value === 'failed');

// The job's own reason first. It is the more specific of the two, and the one written for
// somebody to read.
const failureReason = computed(() => props.task?.error ?? props.onboarding?.error ?? null);

// The confirmation pass is the one phase whose work the standing description gets wrong,
// because nothing has been read off the chain yet.
const isConfirming = computed(() => stage.value === CONFIRMING_STAGE);

const completedAt = computed(() => {
    if (!props.onboarding?.completed_at) return null;
    return new Date(props.onboarding.completed_at).toLocaleString();
});

// The date range this panel was asked for, and what the same stored rows say inside it.
// Nothing here is persisted: the stored result always means the whole campaign.
const scope = computed(() => props.onboarding?.scope ?? {});
const scopedSummary = computed(() => (scope.value.applied ? (scope.value.summary ?? null) : null));
const shown = computed(() => scopedSummary.value ?? summary.value);
const canScope = computed(() => (scope.value.wallets_total ?? 0) > 0);

const range = useForm({
    onboarding_from: props.onboarding?.scope?.from ?? '',
    onboarding_to: props.onboarding?.scope?.to ?? '',
});

// How many claimant wallets no windowed run has read. Shown rather than absorbed: a
// campaign half read is not a campaign where half the wallets did nothing.
const unknownWallets = computed(() => shown.value?.windows_unknown ?? 0);

// Wallets whose history no run has managed to read, so whether they were new is unknown.
const unclassifiedWallets = computed(() => shown.value?.unclassified_wallets ?? 0);

// A result with nobody in it. A range in which nobody claimed is a result rather than a
// measurement that has not happened, which is one of the states named below.
const emptyPopulation = computed(() => (shown.value?.genuine_wallets ?? 0) === 0);

// What kind of thing the figures below are. Not one question with a yes and a no: five
// states, and one condition used to choose between them, which is how a run whose provider
// was down came to be explained as a run that predates certificate reading.
//
//   never        no analysis has been stored for this campaign at all
//   legacy       an older run measured it, before delegation could be told from activity.
//                Its activation figure counted a wallet whose only act was delegating.
//   unreachable  a run of the current kind finished and the chain data provider answered
//                for none of these wallets, so what they did after claiming was not read
//   empty        measured, and no wallet falls in it. A range nobody claimed in.
//   measured     measured, with wallets in it
const measurement = computed(() => {
    if (!hasResults.value) return 'never';
    if (props.onboarding?.windowed !== true) return 'legacy';
    if (emptyPopulation.value) return 'empty';
    if (shown.value?.windows_available !== true) return 'unreachable';
    return 'measured';
});

// The follow-up questions have an answer. Anything reading this is asking whether the
// windows can be shown, which only the last state allows.
const windowed = computed(() => measurement.value === 'measured');

// Why the follow-up figures are not available, in the words of the state they are in.
// Saying a provider outage was an old run tells the operator to re-run something that
// would fail the same way, and denies that the run did what it did do.
const windowsUnknownReason = computed(() =>
    measurement.value === 'unreachable'
        ? 'The chain data provider did not answer for any of these wallets, so what they did after claiming is unknown rather than nothing.'
        : 'This result was measured before delegation could be separated from activity, so it cannot say how many wallets transacted for themselves or what they did in the first 30, 60 and 90 days.',
);

// A denominator the server may not have written, on a result stored before it was
// exported. Falling back to the wider population keeps the sentence true rather than
// printing "of undefined".
const readOf = (s, key, fallback) => (s[key] === undefined || s[key] === null ? s[fallback] : s[key]);

// Headline tiles. Activity is deliberately shown as a share of NEW wallets rather than of
// everyone: an established wallet transacting again says nothing about whether this
// campaign onboarded anybody.
const tileDefinitions = [
    {
        label: 'Claimant wallets',
        color: 'primary',
        value: (s) => s.genuine_wallets,
        hint: (s) =>
            s.operator_wallets > 0
                ? `${s.operator_wallets} operator wallet(s) excluded`
                : 'Distinct wallets that claimed',
        // A count of the rows this result holds, not a share of a population something had
        // to read. Nothing can fail to have been read for it.
        population: () => null,
    },
    {
        label: 'Newly onboarded',
        color: 'success',
        value: (s) => `${s.new_pct}%`,
        hint: (s) =>
            `${s.new_wallets} of ${readOf(s, 'classified_wallets', 'genuine_wallets')} wallet(s) read had no prior on-chain history`,
        population: (s) => readOf(s, 'classified_wallets', 'genuine_wallets'),
        unknownHint: 'No wallet history was read, so whether these wallets were new is unknown.',
    },
    {
        // Named for what it counts. "Activated" was the old figure, and it counted a
        // wallet whose only act was delegating, because delegating puts the wallet in as
        // an input of its own transaction.
        label: 'New wallets that transacted',
        color: 'info',
        needsWindows: true,
        value: (s) => `${s.new_active_pct}%`,
        // The denominator this percentage was actually taken over. It is the NEW wallets a
        // windowed run read, not every wallet it read, and printing the wider number beside
        // the percentage made the tile contradict itself.
        hint: (s) =>
            `${s.new_active} of ${readOf(s, 'windows_observed_new', 'windows_observed')} new wallet(s) read sent a transaction of their own, not counting delegation`,
        population: (s) => readOf(s, 'windows_observed_new', 'windows_observed'),
    },
    {
        label: 'Delegated to a pool',
        color: 'secondary',
        value: (s) => `${s.delegated_pct}%`,
        // Both denominators, because they answer different questions and are easy to
        // confuse: established claimants often already delegated before your campaign
        // existed, so the all-claimants figure can look like an effect you produced.
        hint: (s) =>
            `${s.delegated} of ${readOf(s, 'delegation_known', 'genuine_wallets')} claimants; ` +
            `${s.new_delegated} of ${readOf(s, 'new_delegation_known', 'new_wallets')} new wallet(s)`,
        // Current delegation is its own read of its own endpoint and fails on its own. A
        // run whose history read worked and whose account read did not knows how many
        // wallets were new and nothing at all about who delegates.
        population: (s) => readOf(s, 'delegation_known', 'genuine_wallets'),
        unknownHint: 'No claimant account was read, so how many delegate is unknown.',
    },
];

const tiles = computed(() => {
    if (!shown.value) return [];

    return tileDefinitions.map((tile) => {
        // Every tile asks whether its OWN population was read. A rate over a population of
        // zero is not zero: two of these used to inherit the windows tile's answer, and a
        // run that read nothing printed "0% Newly onboarded, 0 of 0 wallet(s) read" in
        // large type.
        const population = tile.population(shown.value);
        const unread = population !== null && (population ?? 0) <= 0;

        // A tile whose answer needs certificates shows that it has none rather than a
        // percentage of nothing. Unknown and zero are different states and the panel must
        // never let one read as the other.
        const unwindowed = tile.needsWindows === true && !windowed.value;
        const unknown = unread || unwindowed;

        return {
            label: tile.label,
            color: unknown ? 'default' : tile.color,
            unknown,
            value: unknown ? 'Unknown' : tile.value(shown.value),
            hint: unwindowed
                ? `${windowsUnknownReason.value} Re-run the analysis to find out.`
                : unknown
                  ? tile.unknownHint
                  : tile.hint(shown.value),
            // The whole-campaign figure beside the scoped one. The difference between them
            // is the informative part: claims from the event and claims from a social post
            // weeks later are both real, and averaged together they describe neither.
            full:
                unknown || !scopedSummary.value || !summary.value ? null : tile.value(summary.value),
        };
    });
});

// The follow-up windows, as a list so the order they are shown in is the order the server
// wrote them and not whatever an object's keys came back as.
const windows = computed(() => (windowed.value ? (shown.value?.windows ?? []) : []));

// The wallets the retired figure counted as activated: they delegated after claiming and
// did nothing else. Worth naming, because on a campaign that asked people to delegate this
// is most of the difference between the old number and this one.
const delegationOnly = computed(() => (windowed.value ? (shown.value?.delegation_only ?? 0) : 0));

// Wallets held out of the windows because what they did carries no time. Taken as the
// largest of the three, since a wallet held out of one window is held out of every window
// it was watched long enough for.
const untimedWallets = computed(() =>
    windows.value.reduce((most, w) => Math.max(most, w.new_untimed ?? 0), 0),
);

// The same, for delegation. Counted apart because the two events are timed apart: a wallet
// whose transaction carries no time can still have a delegation the chain timed exactly,
// and holding it out of both windows reported a reading that worked as one that did not.
const untimedDelegations = computed(() =>
    windows.value.reduce((most, w) => Math.max(most, w.new_delegation_untimed ?? 0), 0),
);

// How many new wallets each window's delegation rate was taken over. Not the activity
// denominator: a wallet is held out of a window by the event that window measures.
const delegationObservable = (w) => w.new_delegation_observable ?? w.new_observable ?? 0;

// How long the wallets here were actually watched for, as the run that read them recorded
// it. Null where no wallet has a recorded length, which is a run that watched nobody: the
// sentence is then left unsaid rather than printed over a figure taken from the reader's
// clock, which grew by a day for every day nobody re-ran the analysis.
const observationDays = computed(() => shown.value?.observation_days_avg ?? null);
const observationWallets = computed(() =>
    typeof shown.value?.observation_wallets === 'number' ? shown.value.observation_wallets : null,
);

// What the result did and did not cover. Absent from summaries written before coverage
// was recorded, which is why every field is guarded rather than assumed.
const coverage = computed(() => {
    const s = shown.value;
    if (!s || s.claims_total === undefined || s.claims_total === null) return null;
    // A share of no claims at all is not zero percent covered. There is nothing to cover,
    // and the line says nothing rather than printing a rate over an empty denominator.
    if (s.claims_total <= 0) return null;

    return {
        covered: s.claims_analyzable ?? 0,
        total: s.claims_total,
        pct: s.coverage_pct ?? 0,
        unconfirmed: s.claims_unconfirmed ?? 0,
        // A confirmed claim from an enterprise address has no stake key, so there is no
        // wallet history to read. It is not something a status check can fix.
        unkeyed: Math.max(0, (s.claims_confirmed ?? 0) - (s.claims_analyzable ?? 0)),
    };
});

function analyze() {
    starting.value = true;
    router.post(route('campaigns.analyze-onboarding', props.campaign.id), {}, {
        preserveScroll: true,
        onFinish: () => (starting.value = false),
    });
}

function checkClaims() {
    checking.value = true;
    router.post(route('campaigns.check-claims', props.campaign.id), {}, {
        preserveScroll: true,
        onFinish: () => (checking.value = false),
    });
}

function applyRange() {
    range.get(route('campaigns.show', props.campaign.id), {
        only: ['onboarding'],
        preserveScroll: true,
        preserveState: true,
    });
}

function clearRange() {
    range.onboarding_from = '';
    range.onboarding_to = '';
    applyRange();
}

function exportClaims() {
    window.location = route('campaigns.export-claims', props.campaign.id);
}
</script>

<template>
    <v-card :class="embedded ? '' : 'mb-4'" rounded="lg" :border="!embedded" :flat="embedded">
        <v-toolbar color="transparent" density="comfortable">
            <v-icon v-if="!embedded" class="ms-4 me-2" icon="mdi-account-multiple-check-outline"></v-icon>
            <v-toolbar-title v-if="!embedded" class="text-subtitle-1 font-weight-medium">Onboarding</v-toolbar-title>
            <v-spacer></v-spacer>

            <v-btn
                v-if="eligibleClaims > 0"
                variant="tonal"
                color="secondary"
                size="small"
                class="me-2"
                prepend-icon="mdi-file-delimited-outline"
                @click="exportClaims"
            >
                Export claims
                <v-tooltip activator="parent" location="top">
                    Download every claimed address as CSV, with its onboarding classification
                </v-tooltip>
            </v-btn>

            <v-btn
                v-if="canAnalyze"
                variant="tonal"
                color="primary"
                size="small"
                class="me-2"
                :loading="starting || isRunning"
                prepend-icon="mdi-chart-timeline-variant"
                @click="analyze"
            >
                {{ hasResults ? 'Re-run' : 'Analyze' }}
            </v-btn>

            <v-btn
                v-if="hasResults"
                :icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                variant="text"
                class="me-2"
                @click="expanded = !expanded"
            ></v-btn>
        </v-toolbar>

        <!-- The gap, before the analysis runs rather than after it has quietly excluded
             them. A claim gains its transaction hash when its status is checked, and
             status checking is not automatic, so a campaign that finished at a venue
             yesterday can hold claims the analysis cannot see. -->
        <v-card-text v-if="pendingClaims > 0 && !isRunning" class="pt-0">
            <v-alert type="info" variant="tonal" density="comfortable">
                <div>
                    {{ pendingClaims }} claim(s) have no confirmed transaction yet, so the analysis
                    cannot see them. Running it checks them first and includes whichever confirm.
                </div>
                <div class="mt-2">
                    <v-btn
                        size="small"
                        variant="tonal"
                        prepend-icon="mdi-reload"
                        :loading="checking || claimCheckRunning"
                        @click="checkClaims"
                    >
                        Check claims
                    </v-btn>
                </div>
            </v-alert>
        </v-card-text>

        <v-card-text v-if="!canAnalyze" class="text-medium-emphasis pt-0">
            Onboarding analysis becomes available once this campaign has taken its first
            claim. It reads public chain data to tell you how many claimants were new wallets
            and how many went on to transact for themselves.
        </v-card-text>

        <!-- What the run is doing, not merely that it is doing something. The job names
             each phase as it reaches it and the page's shared poller brings that here, so
             a run that spends four minutes reading wallet history says so. -->
        <v-card-text v-else-if="isRunning" class="pt-0">
            <v-progress-linear
                :indeterminate="!stageTotal"
                :model-value="stagePercent"
                color="primary"
                class="mb-3"
                rounded
            ></v-progress-linear>
            <div v-if="stage" class="mb-1 d-flex align-center ga-2 flex-wrap">
                <strong>{{ stage }}</strong>
                <span v-if="stageTotal" class="text-caption text-medium-emphasis">
                    {{ stageDone }} of {{ stageTotal }}
                </span>
            </div>
            <span v-if="isConfirming" class="text-medium-emphasis">
                Confirming {{ pendingClaims }} claim(s) with the transaction backend before
                measuring anything, so the result is not a percentage of whichever claims
                happened to have been checked. The analysis follows.
            </span>
            <span v-else class="text-medium-emphasis">
                Reading chain history for {{ eligibleClaims }} claim(s). This makes about one
                query per wallet, so it can take a few minutes. Results appear here when it
                finishes — you can leave the page.
            </span>
        </v-card-text>

        <v-card-text v-else-if="hasFailed" class="pt-0">
            <v-alert type="warning" variant="tonal" density="comfortable">
                The last analysis did not finish. {{ failureReason }}
            </v-alert>
        </v-card-text>

        <v-card-text v-else-if="!hasResults" class="text-medium-emphasis pt-0">
            This campaign has {{ eligibleClaims }} confirmed claim(s). Run the analysis to see
            how many of those wallets were new to Cardano, and how many have since transacted
            on their own.
        </v-card-text>

        <template v-else>
            <v-card-text class="pt-0">
                <!-- A campaign that exists to serve one event keeps collecting claims after
                     it: spare cards, a social post weeks later. Measuring them together
                     makes the event result unobtainable. -->
                <v-row v-if="canScope" dense align="center" class="mb-1">
                    <v-col cols="6" sm="3">
                        <v-text-field
                            v-model="range.onboarding_from"
                            type="date"
                            label="From"
                            :min="scope.bounds?.first"
                            :max="scope.bounds?.last"
                            density="compact"
                            variant="outlined"
                            hide-details
                        ></v-text-field>
                    </v-col>
                    <v-col cols="6" sm="3">
                        <v-text-field
                            v-model="range.onboarding_to"
                            type="date"
                            label="To"
                            :min="scope.bounds?.first"
                            :max="scope.bounds?.last"
                            density="compact"
                            variant="outlined"
                            hide-details
                        ></v-text-field>
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-btn
                            size="small"
                            variant="tonal"
                            color="primary"
                            prepend-icon="mdi-calendar-range"
                            @click="applyRange"
                        >
                            Apply range
                        </v-btn>
                        <v-btn
                            v-if="scope.applied"
                            size="small"
                            variant="text"
                            class="ms-2"
                            @click="clearRange"
                        >
                            Whole campaign
                        </v-btn>
                    </v-col>
                </v-row>

                <v-alert
                    v-if="scope.error"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mb-3"
                >
                    {{ scope.error }} Showing the whole campaign.
                </v-alert>

                <!-- A run the chain data provider did not answer in full. The wallets it
                     could not read are unknown, and printing them as wallets that did
                     nothing is exactly the confusion this panel exists to prevent. -->
                <v-alert
                    v-if="isPartial"
                    type="warning"
                    variant="tonal"
                    density="comfortable"
                    class="mb-3"
                >
                    The last run could not read chain data for
                    {{ unreadWallets }} wallet(s). They are unknown here rather than counted
                    as wallets that did nothing, and the figures below describe the wallets
                    that were read. Re-run the analysis to fill them in.
                </v-alert>

                <div v-if="scopedSummary" class="text-caption text-medium-emphasis mb-3">
                    Scoped to claims<span v-if="scope.from"> from {{ scope.from }}</span
                    ><span v-if="scope.to"> up to and including {{ scope.to }}</span>:
                    {{ scopedSummary.genuine_wallets }} of {{ summary.genuine_wallets }} claimant
                    wallet(s). Each tile shows the range, with the whole campaign beneath it.
                    <span v-if="scope.wallets_undated > 0">
                        {{ scope.wallets_undated }} wallet(s) have no claim date recorded and fall
                        in no range.
                    </span>
                </div>

                <!-- Nobody claimed in this range. That is an answer, and it is not the
                     same answer as a measurement that has not been taken: re-running the
                     analysis would change nothing. -->
                <v-alert
                    v-if="emptyPopulation"
                    type="info"
                    variant="tonal"
                    density="comfortable"
                >
                    <span v-if="scopedSummary">
                        No claimant wallets fall in this range, so there is nothing to
                        measure in it. The campaign has {{ summary.genuine_wallets }} claimant
                        wallet(s) in total.
                    </span>
                    <span v-else>
                        This analysis found no claimant wallets to measure.
                    </span>
                </v-alert>

                <template v-else>
                <v-row dense>
                    <v-col v-for="tile in tiles" :key="tile.label" cols="12" sm="6" md="3">
                        <v-card variant="tonal" :color="tile.color" rounded="lg" class="h-100">
                            <v-card-item>
                                <div
                                    class="text-h5 font-weight-medium"
                                    :class="tile.unknown ? 'text-medium-emphasis' : ''"
                                >
                                    {{ tile.value }}
                                </div>
                                <div class="text-caption font-weight-medium">{{ tile.label }}</div>
                                <div class="text-caption text-medium-emphasis mt-1">{{ tile.hint }}</div>
                                <div v-if="tile.full !== null" class="text-caption text-medium-emphasis mt-1">
                                    Whole campaign: {{ tile.full }}
                                </div>
                            </v-card-item>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- Two different reasons the follow-up figures are missing, and they are
                     not interchangeable. A stored result from before certificates were read
                     cannot say who transacted and who merely delegated, because a
                     delegation puts the wallet into its own transaction as an input. A run
                     the provider would not answer could have separated them and never got
                     the chance. Telling the operator the second is the first sends them to
                     re-run something that will fail the same way. -->
                <v-alert
                    v-if="!windowed"
                    type="info"
                    variant="tonal"
                    density="comfortable"
                    class="mt-3"
                >
                    {{ windowsUnknownReason }} Re-run the analysis to find out.
                </v-alert>

                <template v-else>
                    <!-- Three columns that are not the same question. "Not yet" is a wallet
                         that claimed too recently to have a window this long, and counting
                         it as a wallet that did nothing would report the campaign as having
                         failed at something it has not been given time to do. -->
                    <div class="text-caption font-weight-medium mt-4 mb-1">
                        New wallets, by how long after claiming
                    </div>
                    <v-table density="compact" class="mb-2">
                        <thead>
                            <tr>
                                <th>Window</th>
                                <th class="text-right">Observable</th>
                                <th class="text-right">Too recent</th>
                                <th class="text-right">No timestamp</th>
                                <th class="text-right">Transacted</th>
                                <th class="text-right">Delegated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in windows" :key="w.days">
                                <td>{{ w.days }} days</td>
                                <td class="text-right">{{ w.new_observable }}</td>
                                <td class="text-right">{{ w.new_not_yet }}</td>
                                <td class="text-right">{{ w.new_untimed ?? 0 }}</td>
                                <!-- A rate over nobody is not zero percent. A window no
                                     wallet has been watched long enough for says so. -->
                                <td class="text-right">
                                    <template v-if="w.new_observable > 0">
                                        {{ w.new_active }}
                                        <span class="text-medium-emphasis">({{ w.new_active_pct }}%)</span>
                                    </template>
                                    <span v-else class="text-medium-emphasis">Unknown</span>
                                </td>
                                <!-- With its own denominator, which is not the one two
                                     columns to the left: delegation is timed separately, so
                                     a wallet held out of the activity window can be inside
                                     this one. -->
                                <td class="text-right">
                                    <template v-if="delegationObservable(w) > 0">
                                        {{ w.new_delegated }} of {{ delegationObservable(w) }}
                                        <span class="text-medium-emphasis">({{ w.new_delegated_pct }}%)</span>
                                    </template>
                                    <span v-else class="text-medium-emphasis">Unknown</span>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>

                    <div class="text-caption text-medium-emphasis">
                        <span v-if="delegationOnly > 0">
                            {{ delegationOnly }} wallet(s) delegated after claiming and did
                            nothing else. Delegating puts a wallet into its own transaction,
                            so these are not counted as having transacted.
                        </span>
                        <span v-else>
                            No wallet delegated after claiming without also transacting.
                        </span>
                        <span v-if="unknownWallets > 0">
                            {{ unknownWallets }} wallet(s) have not been read since this
                            measurement existed, so they are in none of the figures above.
                        </span>
                        <span v-if="untimedWallets > 0">
                            {{ untimedWallets }} wallet(s) transacted at a time the chain
                            query did not report, so they are held out of the activity
                            windows instead of counted as having done nothing in them.
                        </span>
                        <span v-if="untimedDelegations > 0">
                            {{ untimedDelegations }} wallet(s) delegated at a time the chain
                            query did not report, so they are held out of the delegation
                            windows for the same reason.
                        </span>
                        <span v-if="unclassifiedWallets > 0">
                            {{ unclassifiedWallets }} wallet(s) could not be read at all, so
                            whether they were new is unknown.
                        </span>
                    </div>
                </template>

                <!-- The window is not a footnote. A rate of anything without the time it
                     was measured over is not a number anyone can act on, and a window over
                     wallets nobody watched is not a window: it used to fall back to the
                     time elapsed since the claim, which grew by a day for every day nobody
                     re-ran anything and described the reader rather than the campaign. -->
                <div class="text-caption text-medium-emphasis mt-3">
                    <span v-if="observationDays !== null">
                        Measured over roughly {{ observationDays }} day(s) since claim on
                        average<span v-if="observationWallets !== null">, across
                        {{ observationWallets }} wallet(s) a run watched</span
                        ><span v-if="completedAt">, analyzed {{ completedAt }}</span>.
                    </span>
                    <span v-else>
                        No wallet here has a recorded length of observation, so there is no
                        window these figures were measured over<span v-if="completedAt">;
                        last analyzed {{ completedAt }}</span>.
                    </span>
                    A wallet counts as having transacted only when it sends a transaction
                    itself, not when it receives one, and not when all it sent was its own
                    stake certificate.
                </div>

                <!-- Coverage, with the result rather than in place of it. Percentages of the
                     claims that happened to be confirmed read exactly like percentages of
                     the campaign, and nothing else on this page tells them apart. -->
                <div v-if="coverage" class="text-caption text-medium-emphasis mt-1">
                    Covers {{ coverage.covered }} of {{ coverage.total }} claim(s),
                    {{ coverage.pct }}%.
                    <span v-if="coverage.unconfirmed > 0">
                        {{ coverage.unconfirmed }} claim(s) had no confirmed transaction when this
                        ran and are not in these numbers.
                    </span>
                    <span v-if="coverage.unkeyed > 0">
                        {{ coverage.unkeyed }} confirmed claim(s) came from an address with no stake
                        key, which has no wallet history to read.
                    </span>
                </div>
                </template>
            </v-card-text>

            <v-expand-transition>
                <div v-if="expanded">
                    <v-divider></v-divider>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Wallet</th>
                                <th>Classification</th>
                                <th class="text-right">Own txs</th>
                                <th class="text-right">Days to first</th>
                                <th>Delegated after claim</th>
                                <th>Delegated now</th>
                                <th class="text-right">Prior txs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="wallet in wallets" :key="wallet.stake_key">
                                <td class="text-caption font-monospace">
                                    {{ wallet.stake_key_short }}
                                    <v-chip v-if="wallet.is_operator" size="x-small" class="ms-1" variant="tonal">
                                        operator
                                    </v-chip>
                                </td>
                                <!-- A wallet whose history no run could read is neither
                                     new nor established. Filing it as established is the
                                     mistake this column exists to avoid. -->
                                <td>
                                    <v-chip
                                        v-if="wallet.is_new === null"
                                        color="default"
                                        size="small"
                                        variant="tonal"
                                    >
                                        unknown
                                    </v-chip>
                                    <v-chip
                                        v-else
                                        :color="wallet.is_new ? 'success' : 'default'"
                                        size="small"
                                        variant="tonal"
                                    >
                                        {{ wallet.is_new ? 'new' : 'established' }}
                                    </v-chip>
                                </td>
                                <!-- A dash where no windowed run has read this wallet, and a
                                     0 where one has and it did nothing. The two are not the
                                     same fact and must not share a cell. -->
                                <td class="text-right">
                                    <span v-if="!wallet.windowed" class="text-medium-emphasis">unknown</span>
                                    <span v-else>{{ wallet.activity_count }}</span>
                                </td>
                                <td class="text-right">
                                    <span v-if="wallet.first_activity_days === null" class="text-medium-emphasis">
                                        —
                                    </span>
                                    <span v-else>{{ wallet.first_activity_days }}</span>
                                </td>
                                <td>
                                    <span v-if="!wallet.windowed" class="text-caption text-medium-emphasis">
                                        unknown
                                    </span>
                                    <span v-else-if="wallet.first_delegation_days !== null" class="text-caption">
                                        day {{ wallet.first_delegation_days }}
                                    </span>
                                    <v-icon v-else icon="mdi-minus" color="disabled" size="small"></v-icon>
                                </td>
                                <td>
                                    <span v-if="wallet.delegated === null" class="text-caption text-medium-emphasis">
                                        unknown
                                    </span>
                                    <v-icon
                                        v-else
                                        :icon="wallet.delegated ? 'mdi-check-circle' : 'mdi-minus'"
                                        :color="wallet.delegated ? 'secondary' : 'disabled'"
                                        size="small"
                                    ></v-icon>
                                </td>
                                <td class="text-right">
                                    <span v-if="wallet.prior_tx_count === null" class="text-medium-emphasis">
                                        unknown
                                    </span>
                                    <span v-else>{{ wallet.prior_tx_count }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </div>
            </v-expand-transition>
        </template>
    </v-card>
</template>
