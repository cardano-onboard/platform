import { computed, onUnmounted, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";

/**
 * One timer for every background job on the campaign page.
 *
 * Jobs report themselves to a task row, and this asks the campaign's task endpoint what
 * those rows say. One request answers for all of them, so three running jobs are three rows
 * in one response rather than three pollers.
 *
 * It never polls the page's own endpoint. That one loads codes, claims and a wallet balance
 * before it renders anything, so asking it every few seconds turns one long import into
 * hundreds of balance calls.
 */

// Quick while somebody is watching a fresh job, slower once they have looked away.
const FAST_INTERVAL = 3000;
const SLOW_INTERVAL = 10000;
const FAST_WINDOW = 60000;

// A job still unfinished after this long is not going to be watched into finishing, so the
// timer stops and the page offers to check again. Nothing is cancelled by stopping.
const MAX_WATCH = 15 * 60 * 1000;

// Consecutive network errors before giving up. A server that is down stays down, and a page
// left open overnight should not spend the night asking.
const MAX_FAILURES = 5;

const ACTIVE = ["queued", "running"];

/** What each kind of work is called, in words an operator would use. */
const LABELS = {
    "codes-import": "Importing codes",
    "qr-export": "Preparing QR export",
    "onboarding-analysis": "Analyzing onboarding",
    "check-claims": "Checking claims",
};

export function taskLabel(type) {
    return LABELS[type] ?? "Working";
}

export function isActiveTask(task) {
    return ACTIVE.includes(task?.status);
}

/**
 * @param {string} campaignId
 * @param {() => ({now: ?string, items: Array}|undefined)} serverState
 *        The page's `tasks` prop, as a getter so a page visit that replaces it is noticed.
 */
export function useCampaignTasks(campaignId, serverState) {
    const tasks = ref([]);
    const stalled = ref(false);

    // The server's clock, not this browser's. Every poll asks for what finished since the
    // last answer, so a client clock running fast would skip the completion it is waiting
    // for.
    const since = ref(null);

    // One reload per finished run, keyed by the run and when it finished, so a re-run of the
    // same task reloads again and a repeated poll does not.
    const refreshed = new Set();

    let timer = null;
    let watchingSince = 0;
    let failures = 0;

    const active = computed(() => tasks.value.filter(isActiveTask));
    const failed = computed(() =>
        tasks.value.filter((task) => task.status === "failed"),
    );

    function byType(type) {
        return tasks.value.filter((task) => task.type === type);
    }

    function stop() {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function schedule() {
        stop();

        if (active.value.length === 0 || stalled.value) {
            return;
        }

        const elapsed = Date.now() - watchingSince;

        if (elapsed > MAX_WATCH) {
            stalled.value = true;

            return;
        }

        timer = setTimeout(
            poll,
            elapsed < FAST_WINDOW ? FAST_INTERVAL : SLOW_INTERVAL,
        );
    }

    /** Start watching, or carry on if already watching. */
    function start() {
        if (timer !== null || active.value.length === 0 || stalled.value) {
            return;
        }

        if (watchingSince === 0) {
            watchingSince = Date.now();
        }

        schedule();
    }

    /** After the timer gave up, or after a failure the operator wants retried. */
    function resume() {
        stalled.value = false;
        failures = 0;
        watchingSince = Date.now();
        poll();
    }

    async function poll() {
        timer = null;

        if (active.value.length === 0) {
            return;
        }

        let url;

        try {
            url = route("campaigns.tasks", campaignId);
        } catch (e) {
            // The route is absent from this build. Nothing to poll, and throwing here would
            // take the page down with it.
            stalled.value = true;

            return;
        }

        try {
            const { data } = await window.axios.get(url, {
                params: since.value ? { since: since.value } : {},
            });

            failures = 0;
            apply(data);
        } catch (e) {
            const status = e?.response?.status;

            // Signed out, or not allowed to see this campaign any more. Asking again would
            // only produce the same answer.
            if (status === 401 || status === 403 || status === 404) {
                stalled.value = true;

                return;
            }

            failures += 1;

            if (failures >= MAX_FAILURES) {
                stalled.value = true;

                return;
            }
        }

        schedule();
    }

    /**
     * Fold one answer into what is on screen.
     *
     * Rows the answer does not mention are left alone: a run that finished two polls ago
     * drops out of the response, and the operator should still be able to read why it
     * failed.
     */
    function apply(data) {
        if (!data || !Array.isArray(data.tasks)) {
            return;
        }

        if (data.now) {
            since.value = data.now;
        }

        const finished = [];

        const merged = tasks.value.slice();

        data.tasks.forEach((incoming) => {
            const at = merged.findIndex((task) => task.id === incoming.id);

            if (at === -1) {
                merged.push(incoming);
            } else {
                merged[at] = incoming;
            }

            if (!isActiveTask(incoming) && markRefreshed(incoming)) {
                finished.push(incoming);
            }
        });

        tasks.value = merged;

        refresh(finished);
    }

    /** True the first time this particular run is seen finished. */
    function markRefreshed(task) {
        const key = `${task.id}@${task.completed_at ?? ""}`;

        if (refreshed.has(key)) {
            return false;
        }

        refreshed.add(key);

        return true;
    }

    /**
     * One partial reload for everything that just finished.
     *
     * Each kind of job names the props its completion changes, and only those are fetched.
     * Two jobs finishing in the same answer are one request, not two.
     */
    function refresh(finished) {
        const only = [];

        finished.forEach((task) => {
            (Array.isArray(task.reloads) ? task.reloads : []).forEach(
                (prop) => {
                    if (prop && !only.includes(prop)) {
                        only.push(prop);
                    }
                },
            );
        });

        if (only.length === 0) {
            return;
        }

        router.reload({ only });
    }

    /**
     * Adopt what the server rendered with.
     *
     * The page carries its running tasks in a prop, so a reload while an import is in flight
     * picks the watch straight back up, and a redirect back from the import form starts it.
     */
    function adopt() {
        const state =
            typeof serverState === "function" ? serverState() : serverState;
        const items = Array.isArray(state?.items) ? state.items : [];

        if (state?.now && since.value === null) {
            since.value = state.now;
        }

        items.forEach((incoming) => {
            const at = tasks.value.findIndex((task) => task.id === incoming.id);

            if (at === -1) {
                tasks.value.push(incoming);
            } else if (isActiveTask(incoming)) {
                // Only where the page is telling us about a run still going. A finished row
                // already on screen carries its result, and the page prop does not.
                tasks.value[at] = incoming;
            }
        });

        if (items.length > 0) {
            stalled.value = false;
            watchingSince = Date.now();
        }

        start();
    }

    adopt();

    watch(
        () => {
            const state =
                typeof serverState === "function" ? serverState() : serverState;

            return (state?.items ?? [])
                .map((task) => `${task.id}:${task.status}`)
                .join(",");
        },
        () => adopt(),
    );

    onUnmounted(stop);

    return {
        tasks,
        active,
        failed,
        stalled,
        byType,
        start,
        stop,
        resume,
        taskLabel,
    };
}
