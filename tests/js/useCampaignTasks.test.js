import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount } from "@vue/test-utils";
import { defineComponent } from "vue";
import { router } from "@inertiajs/vue3";
import {
    useCampaignTasks,
    taskLabel,
} from "../../resources/js/composables/useCampaignTasks.js";

const CAMPAIGN = "01HQ1234567890ABCDEFGHIJ";

function runningTask(overrides = {}) {
    return {
        id: "task-1",
        type: "codes-import",
        status: "running",
        stage: "Creating codes",
        progress_done: 10,
        progress_total: 100,
        error: null,
        result: null,
        started_at: "2026-09-15T10:00:00+00:00",
        completed_at: null,
        reloads: ["campaign", "stats"],
        ...overrides,
    };
}

/** A component that does nothing but hold the composable, so onUnmounted has an owner. */
const Harness = defineComponent({
    props: {
        tasks: { type: Object, default: () => ({ now: null, items: [] }) },
    },
    setup(props) {
        return { ...useCampaignTasks(CAMPAIGN, () => props.tasks) };
    },
    template: "<div />",
});

function answer(tasks, now = "2026-09-15T10:00:03+00:00") {
    return { data: { now, tasks } };
}

describe("useCampaignTasks", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        window.axios = { get: vi.fn() };
        router.reload.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("asks for nothing when no work is running", async () => {
        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [] } },
        });

        await vi.advanceTimersByTimeAsync(60000);

        expect(window.axios.get).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it("polls while work is running and shows what came back", async () => {
        window.axios.get.mockResolvedValue(
            answer([runningTask({ progress_done: 60 })]),
        );

        const wrapper = mount(Harness, {
            props: {
                now: "2026-09-15T10:00:00+00:00",
                tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] },
            },
        });

        expect(wrapper.vm.active).toHaveLength(1);
        expect(window.axios.get).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(3000);

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.active[0].progress_done).toBe(60);
        wrapper.unmount();
    });

    it("asks from the server's clock rather than the browser's", async () => {
        window.axios.get.mockResolvedValue(
            answer([runningTask()], "2026-09-15T10:00:03+00:00"),
        );

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(3000);

        expect(window.axios.get.mock.calls[0][1].params.since).toBe(
            "2026-09-15T10:00:00+00:00",
        );

        await vi.advanceTimersByTimeAsync(3000);

        expect(window.axios.get.mock.calls[1][1].params.since).toBe(
            "2026-09-15T10:00:03+00:00",
        );
        wrapper.unmount();
    });

    it("refreshes exactly the props the finished job changed, once", async () => {
        window.axios.get.mockResolvedValue(
            answer([
                runningTask({
                    status: "complete",
                    progress_done: 100,
                    completed_at: "2026-09-15T10:00:02+00:00",
                    result: { codes: 100 },
                }),
            ]),
        );

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(3000);

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith({ only: ["campaign", "stats"] });

        // And it stops: nothing is running any more.
        await vi.advanceTimersByTimeAsync(60000);

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it("refreshes once for two jobs that finish together", async () => {
        window.axios.get.mockResolvedValue(
            answer([
                runningTask({
                    status: "complete",
                    completed_at: "2026-09-15T10:00:02+00:00",
                }),
                runningTask({
                    id: "task-2",
                    type: "qr-export",
                    status: "complete",
                    completed_at: "2026-09-15T10:00:02+00:00",
                    reloads: ["exports"],
                }),
            ]),
        );

        const wrapper = mount(Harness, {
            props: {
                tasks: {
                    now: "2026-09-15T10:00:00+00:00",
                    items: [runningTask(), runningTask({ id: "task-2", type: "qr-export" })],
                },
            },
        });

        await vi.advanceTimersByTimeAsync(3000);

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith({
            only: ["campaign", "stats", "exports"],
        });
        wrapper.unmount();
    });

    it("keeps a failure on screen and asks nothing further", async () => {
        window.axios.get.mockResolvedValue(
            answer([
                runningTask({
                    status: "failed",
                    error: "That file holds 20,000 codes and the limit is 10,000.",
                    completed_at: "2026-09-15T10:00:02+00:00",
                }),
            ]),
        );

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(30000);

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.failed).toHaveLength(1);
        expect(wrapper.vm.failed[0].error).toContain("the limit is 10,000");
        expect(wrapper.vm.active).toHaveLength(0);
        wrapper.unmount();
    });

    it("gives up rather than hammering an endpoint that refuses it", async () => {
        window.axios.get.mockRejectedValue({ response: { status: 403 } });

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(120000);

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.stalled).toBe(true);
        wrapper.unmount();
    });

    it("rides out a blip and keeps watching", async () => {
        window.axios.get
            .mockRejectedValueOnce({ response: { status: 500 } })
            .mockResolvedValue(answer([runningTask({ progress_done: 80 })]));

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(3000);
        expect(wrapper.vm.stalled).toBe(false);

        await vi.advanceTimersByTimeAsync(3000);
        expect(wrapper.vm.active[0].progress_done).toBe(80);
        wrapper.unmount();
    });

    it("stops asking after a quarter of an hour and offers to look again", async () => {
        window.axios.get.mockResolvedValue(answer([runningTask()]));

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(16 * 60 * 1000);

        const asked = window.axios.get.mock.calls.length;

        expect(wrapper.vm.stalled).toBe(true);

        await vi.advanceTimersByTimeAsync(5 * 60 * 1000);

        expect(window.axios.get.mock.calls.length).toBe(asked);

        // And the operator can start it again.
        wrapper.vm.resume();
        await vi.advanceTimersByTimeAsync(0);

        expect(window.axios.get.mock.calls.length).toBe(asked + 1);
        wrapper.unmount();
    });

    it("slows down after the first minute instead of asking every three seconds all day", async () => {
        window.axios.get.mockResolvedValue(answer([runningTask()]));

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(60000);
        const firstMinute = window.axios.get.mock.calls.length;

        await vi.advanceTimersByTimeAsync(60000);
        const secondMinute = window.axios.get.mock.calls.length - firstMinute;

        expect(firstMinute).toBeGreaterThan(secondMinute);
        expect(secondMinute).toBeLessThanOrEqual(7);
        wrapper.unmount();
    });

    it("picks up work that started after the page was drawn", async () => {
        window.axios.get.mockResolvedValue(answer([runningTask()]));

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [] } },
        });

        await vi.advanceTimersByTimeAsync(10000);
        expect(window.axios.get).not.toHaveBeenCalled();

        // The import form redirects back to the page, and the new prop carries the run.
        await wrapper.setProps({
            tasks: { now: "2026-09-15T10:00:10+00:00", items: [runningTask()] },
        });

        await vi.advanceTimersByTimeAsync(3000);

        expect(window.axios.get).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it("stops when the page goes away", async () => {
        window.axios.get.mockResolvedValue(answer([runningTask()]));

        const wrapper = mount(Harness, {
            props: { tasks: { now: "2026-09-15T10:00:00+00:00", items: [runningTask()] } },
        });

        await vi.advanceTimersByTimeAsync(3000);
        wrapper.unmount();

        const asked = window.axios.get.mock.calls.length;

        await vi.advanceTimersByTimeAsync(60000);

        expect(window.axios.get.mock.calls.length).toBe(asked);
    });

    it("calls each kind of work what an operator would call it", () => {
        expect(taskLabel("codes-import")).toBe("Importing codes");
        expect(taskLabel("onboarding-analysis")).toBe("Analyzing onboarding");
        // A row written before a deployment removed its job still has to be reportable.
        expect(taskLabel("something-retired")).toBe("Working");
    });

    /**
     * One request answers for every job on the page, so an import and an analysis running
     * together are two rows in one response. When they finish, each names the props its own
     * completion changed: the analysis moves the onboarding panel and nothing else, and
     * asking for the campaign as well would fetch codes, claims and a wallet balance to
     * redraw one tab.
     */
    it("refreshes each finished job's own props and no more", async () => {
        const analysis = runningTask({
            id: "task-analysis",
            type: "onboarding-analysis",
            stage: "Reading wallet history",
            reloads: ["onboarding"],
        });

        window.axios.get.mockResolvedValue(
            answer([
                {
                    ...analysis,
                    status: "complete",
                    stage: null,
                    completed_at: "2026-09-15T10:00:02+00:00",
                    result: { wallets: 40 },
                },
            ]),
        );

        const wrapper = mount(Harness, {
            props: {
                tasks: { now: "2026-09-15T10:00:00+00:00", items: [analysis] },
            },
        });

        await vi.advanceTimersByTimeAsync(3000);

        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith({ only: ["onboarding"] });

        // A second poll reporting the same finished run must not fetch it all again.
        await vi.advanceTimersByTimeAsync(10000);

        expect(router.reload).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });
});

describe("taskLabel", () => {
    it("names every kind of work the campaign page can queue", () => {
        // A type with no label of its own reads as "Working", which tells an operator
        // watching a progress bar nothing about what they are waiting for.
        expect(taskLabel("codes-import")).toBe("Importing codes");
        expect(taskLabel("qr-export")).toBe("Preparing QR export");
        expect(taskLabel("check-claims")).toBe("Checking claims");
    });

    it("falls back rather than showing a raw type name", () => {
        expect(taskLabel("something-a-later-deploy-added")).toBe("Working");
    });
});
