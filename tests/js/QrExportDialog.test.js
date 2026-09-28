import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, DOMWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import QrExportDialog from '../../resources/js/Components/QrExportDialog.vue';

/**
 * The export dialog's states.
 *
 * Rendering happens on a worker, so the dialog is a small state machine rather than a form
 * with a download button. Three things about it are easy to get wrong and expensive to get
 * wrong: an archive that already exists must not be routed through a progress panel, closing
 * must not cancel anything, and the wait must come from the campaign page's one poller
 * rather than from a timer this component started.
 */

const CAMPAIGN = { id: '01HQ1234567890ABCDEFGHIJ', name: 'Test Campaign', end_date: '2026-04-30' };

let mounted = null;

function mountDialog(props = {}) {
    // Attached to the document, because a Vuetify dialog renders into the overlay container
    // rather than inside the component. Testing it anywhere else would be testing a dialog
    // nobody can see.
    mounted = mount(QrExportDialog, {
        props: {
            modelValue: true,
            campaign: CAMPAIGN,
            claimUrl: 'https://claim.example/v1/01HQ1234567890ABCDEFGHIJ',
            codesCount: 40,
            gdAvailable: true,
            tasks: [],
            ...props,
        },
        attachTo: document.body,
        global: {
            stubs: { QrcodeVue: { template: '<div class="qr-stub" />' } },
        },
    });

    return mounted;
}

function find(name) {
    return document.querySelector(`[data-test="qr-export-${name}"]`);
}

function shows(name) {
    return find(name) !== null;
}

function panel(name) {
    const element = find(name);

    if (element === null) {
        throw new Error(`the dialog is not showing its "${name}" panel`);
    }

    return new DOMWrapper(element);
}

/** The options the component handed to router.post, and its callbacks. */
function lastPost() {
    return router.post.mock.calls.at(-1);
}

function task(overrides = {}) {
    return {
        id: 'task-1',
        type: 'qr-export',
        status: 'queued',
        stage: null,
        progress_done: 0,
        progress_total: null,
        error: null,
        result: null,
        reloads: ['qr_exports'],
        ...overrides,
    };
}

describe('QrExportDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        // The dialog renders into the document, so one test's panels would answer the next
        // test's queries if they were left there.
        mounted?.unmount();
        mounted = null;
        document.body.innerHTML = '';
    });

    it('opens on the options form', () => {
        const wrapper = mountDialog();

        expect(shows('options')).toBe(true);
        expect(shows('progress')).toBe(false);
        expect(shows('ready')).toBe(false);
    });

    it('asks the export endpoint for the settings on the form', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');

        const [url, data] = lastPost();

        expect(url).toContain('campaigns/qr-exports/store');
        expect(data).toMatchObject({ format: 'pdf', size: '1', dpi: '203', ecc: 'L' });
    });

    it('goes straight to ready when the archive was already built', async () => {
        // The cache hit. A progress panel here would be a bar for work nobody is doing, and
        // taking it away a second later reads as a render that skipped most of the job.
        const wrapper = mountDialog();

        await panel('request').trigger('click');

        const options = lastPost()[2];
        options.onSuccess({
            props: {
                flash: {
                    qr_export: {
                        status: 'ready',
                        download_url: '/campaigns/x/qr-exports/e1/download',
                    },
                },
            },
        });
        await wrapper.vm.$nextTick();

        expect(shows('progress')).toBe(false);
        expect(shows('ready')).toBe(true);
        expect(panel('download').attributes('href')).toBe(
            '/campaigns/x/qr-exports/e1/download',
        );
    });

    it('waits on the run the request started, and never starts a timer of its own', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onSuccess({
            props: { flash: { qr_export: { status: 'queued', task_id: 'task-1' } } },
        });
        await wrapper.vm.$nextTick();

        expect(panel('progress').text()).toContain('Waiting for a worker');

        // Nothing here fetched anything. The campaign page's one poller is what moves this
        // on, and a second mechanism would ask the same question twice.
        expect(router.get).not.toHaveBeenCalled();
        expect(router.reload).not.toHaveBeenCalled();
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('moves from queued to rendering to ready as the poller reports the run', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onSuccess({
            props: { flash: { qr_export: { status: 'queued', task_id: 'task-1' } } },
        });
        await wrapper.vm.$nextTick();

        await wrapper.setProps({
            tasks: [task({ status: 'running', progress_done: 10, progress_total: 40 })],
        });

        expect(panel('progress').text()).toContain('Rendering your stickers');
        expect(panel('progress').text()).toContain('10 of 40 stickers');

        await wrapper.setProps({
            tasks: [
                task({
                    status: 'complete',
                    progress_done: 40,
                    progress_total: 40,
                    result: { export_id: 'exp-9', from_storage: false },
                }),
            ],
        });

        expect(shows('progress')).toBe(false);
        expect(shows('ready')).toBe(true);
        expect(panel('download').attributes('href')).toBeTruthy();
        expect(globalThis.route).toHaveBeenCalledWith('campaigns.qr-exports.download', [
            CAMPAIGN.id,
            'exp-9',
        ]);
    });

    it('reports a run that failed, with the reason the run recorded', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onSuccess({
            props: { flash: { qr_export: { status: 'queued', task_id: 'task-1' } } },
        });
        await wrapper.vm.$nextTick();

        await wrapper.setProps({
            tasks: [task({ status: 'failed', error: 'PNG export is unavailable on that machine.' })],
        });

        expect(panel('failed').text()).toContain(
            'PNG export is unavailable on that machine.',
        );
    });

    it('reports a refusal without pretending a run was started', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onSuccess({
            props: {
                flash: {
                    qr_export: { status: 'refused', message: 'This campaign has ended.' },
                },
            },
        });
        await wrapper.vm.$nextTick();

        expect(panel('failed').text()).toContain('This campaign has ended.');
        expect(shows('progress')).toBe(false);
    });

    it('says so rather than waiting forever when the answer carries no state', async () => {
        // A redirect that lost its flash, or an older server. Sitting on a spinner for a run
        // that was never started is the one response that tells the operator nothing.
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onSuccess({ props: { flash: {} } });
        await wrapper.vm.$nextTick();

        expect(shows('failed')).toBe(true);
        expect(shows('progress')).toBe(false);
    });

    it('cancels nothing when it is closed, and shows the same run when reopened', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onSuccess({
            props: { flash: { qr_export: { status: 'queued', task_id: 'task-1' } } },
        });
        await wrapper.setProps({
            tasks: [task({ status: 'running', progress_done: 5, progress_total: 40 })],
        });

        const postsBefore = router.post.mock.calls.length;

        await wrapper.setProps({ modelValue: false });
        await wrapper.setProps({ modelValue: true });

        // Nothing was sent to the server by closing, and nothing was sent by reopening.
        expect(router.post.mock.calls.length).toBe(postsBefore);
        expect(router.delete).not.toHaveBeenCalled();

        // And the wait is still the wait, not the form again.
        expect(panel('progress').text()).toContain('Rendering your stickers');
    });

    it('picks up a render already running when it is opened from the campaign page', async () => {
        // The way back. The campaign page offers to show a running export, and what opens
        // has to be the wait rather than the form the operator already filled in.
        const wrapper = mountDialog({
            modelValue: false,
            tasks: [task({ id: 'task-7', status: 'running', progress_done: 12, progress_total: 40 })],
        });

        await wrapper.setProps({ modelValue: true });

        expect(shows('progress')).toBe(true);
        expect(panel('progress').text()).toContain('12 of 40 stickers');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('does not adopt a run that has already finished', async () => {
        // Opening the dialog to ask for a new export must not land on the last one's result.
        const wrapper = mountDialog({
            modelValue: false,
            tasks: [task({ status: 'complete', result: { export_id: 'old', from_storage: false } })],
        });

        await wrapper.setProps({ modelValue: true });

        expect(shows('options')).toBe(true);
        expect(shows('ready')).toBe(false);
    });

    it('lets the operator get back to the settings without touching the run', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onSuccess({
            props: { flash: { qr_export: { status: 'queued', task_id: 'task-1' } } },
        });
        await wrapper.vm.$nextTick();

        await panel('reconfigure').trigger('click');

        expect(shows('options')).toBe(true);
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('refuses to ask for an export of no codes', async () => {
        const wrapper = mountDialog({ codesCount: 0 });

        await panel('request').trigger('click');

        expect(router.post).not.toHaveBeenCalled();
    });

    it('reports settings the server rejected', async () => {
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        lastPost()[2].onError({ footer: 'A 1" sticker is too small for both captions.' });
        await wrapper.vm.$nextTick();

        expect(panel('failed').text()).toContain('too small for both captions');
    });

    it('asks again for the same export after one that failed', async () => {
        // A render retries itself while it has attempts left, and each attempt carries on
        // from what is already rendered. What reaches this panel has used them up, so the
        // operator's answer is one button rather than the form a second time.
        const wrapper = mountDialog();

        await panel('request').trigger('click');
        const asked = lastPost()[1];

        lastPost()[2].onSuccess({
            props: { flash: { qr_export: { status: 'queued', task_id: 'task-1' } } },
        });
        // The real router calls this when the request settles, and it is what releases the
        // button. A test that skipped it would be asserting against a dialog still posting.
        lastPost()[2].onFinish();

        await wrapper.setProps({ tasks: [task({ status: 'failed', error: 'The disk was full.' })] });

        expect(shows('retry')).toBe(true);

        await panel('retry').trigger('click');

        expect(router.post.mock.calls.length).toBe(2);
        expect(lastPost()[1]).toEqual(asked);
    });

    describe('with partners on the campaign', () => {
        const SCOPES = [
            { value: 'all', label: 'All codes', codes: 40 },
            { value: 'partner-1', label: 'Vendor A', codes: 25 },
            { value: 'unassigned', label: 'Unassigned', codes: 15 },
        ];

        it('hides the choice when there is nothing to choose between', async () => {
            // No partners on the campaign: every code is the only scope there is, and
            // grouping would produce one folder called unassigned. A control that cannot
            // change the answer is not shown doing nothing, because what makes it moot is
            // the absence of partners, which is not visible at the control.
            const wrapper = mountDialog({ scopes: [{ value: 'all', label: 'All codes', codes: 40 }] });

            expect(shows('scope')).toBe(false);
            expect(shows('grouped')).toBe(false);

            await panel('request').trigger('click');

            expect(lastPost()[1]).toMatchObject({ group: 'flat', scope: 'all' });
        });

        it('asks for one partner\'s codes, and says how many that is', async () => {
            const wrapper = mountDialog({ scopes: SCOPES });

            expect(shows('scope')).toBe(true);
            expect(panel('request').text()).toContain('40');

            await wrapper.findComponent({ name: 'VSelect' }).setValue('partner-1');

            // The count follows the choice. It is what tells an operator the stack they are
            // about to spend a render on is the stack they meant.
            expect(panel('request').text()).toContain('25');

            await panel('request').trigger('click');

            expect(lastPost()[1]).toMatchObject({ scope: 'partner-1' });
        });

        it('asks for a folder per partner when grouping is switched on', async () => {
            const wrapper = mountDialog({ scopes: SCOPES });

            await panel('grouped').find('input').setValue(true);
            await panel('request').trigger('click');

            expect(lastPost()[1]).toMatchObject({ group: 'partner', scope: 'all' });
        });

        it('will not spend a render on a partner with no codes', async () => {
            const wrapper = mountDialog({
                scopes: [
                    { value: 'all', label: 'All codes', codes: 40 },
                    { value: 'partner-2', label: 'Empty Vendor', codes: 0 },
                ],
            });

            await wrapper.findComponent({ name: 'VSelect' }).setValue('partner-2');
            await panel('request').trigger('click');

            expect(router.post).not.toHaveBeenCalled();
        });
    });
});
