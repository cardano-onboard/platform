import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PartnerPicker from '../../resources/js/Components/PartnerPicker.vue';

/**
 * The picker records who a batch of codes is being handed to.
 *
 * What is being checked here is mostly the one decision that is easy to get wrong:
 * "Unassigned" is a choice on the list, not an empty control. A blank field and a
 * deliberate Unassigned look identical to whoever is standing at the booth, and only one
 * of them is an answer.
 */

const PARTNERS = [
    { id: '01HAAAAAAAAAAAAAAAAAAAAAAA', name: 'Vendor A', kind: 'partner' },
    { id: '01HBBBBBBBBBBBBBBBBBBBBBBB', name: 'Booth Staff', kind: 'staff' },
];

function mountPicker(props = {}) {
    return mount(PartnerPicker, {
        attachTo: document.body,
        props: {
            partners: PARTNERS,
            modelValue: { id: null, name: null, kind: null },
            ...props,
        },
    });
}

/** The options render into an overlay, so the menu has to be opened before it can be read. */
async function openMenu(wrapper) {
    // Vuetify opens the menu off the control's mousedown, so a click on its own leaves it shut.
    await wrapper.find('.v-field').trigger('mousedown');
    await wrapper.find('.v-field').trigger('click');
    await new Promise((resolve) => setTimeout(resolve, 0));

    // A menu that never opened contains nothing, which would pass every absence check.
    expect(document.body.textContent).toContain('Vendor A');

    return document.body;
}

async function chooseOption(wrapper, title) {
    const body = await openMenu(wrapper);
    const option = [...body.querySelectorAll('.v-list-item')].find(
        (item) => item.textContent.trim() === title,
    );
    expect(option, `no option titled ${title}`).toBeTruthy();

    option.click();
    await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('PartnerPicker', () => {
    /**
     * One word for one thing. The codes table has a Partner column and the claims export a
     * partner field, and an operator who reads either has to be able to find the control
     * that set it. "Given to" was a second name for the same question.
     */
    it('is labelled Partner', () => {
        const wrapper = mountPicker();

        expect(wrapper.find('.v-field-label').text()).toBe('Partner');
        expect(wrapper.text()).not.toContain('Given to');
        wrapper.unmount();
    });

    it('starts on Unassigned rather than on nothing at all', () => {
        const wrapper = mountPicker();

        expect(wrapper.text()).toContain('Unassigned');
        wrapper.unmount();
    });

    it('offers Unassigned alongside the campaigns partners', async () => {
        const wrapper = mountPicker();
        const body = await openMenu(wrapper);
        const titles = [...body.querySelectorAll('.v-list-item')].map((item) => item.textContent.trim());

        expect(titles).toContain('Unassigned');
        expect(titles).toContain('Vendor A');
        expect(titles).toContain('Booth Staff');
        expect(titles).toContain('Add a partner');
        wrapper.unmount();
    });

    it('shows the partner a batch is already set to', () => {
        const wrapper = mountPicker({ modelValue: { id: PARTNERS[1].id, name: null, kind: null } });

        expect(wrapper.text()).toContain('Booth Staff');
        wrapper.unmount();
    });

    it('reports the chosen partner by id', async () => {
        const wrapper = mountPicker();

        await chooseOption(wrapper, 'Vendor A');

        expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual({
            id: PARTNERS[0].id,
            name: null,
            kind: null,
        });
        wrapper.unmount();
    });

    /**
     * Unassigned has to come back as a value the form can send, not as an absence. The
     * server stores it as null, and that null is what tells a later export that the codes
     * were generated before anyone decided who was handing them out.
     */
    it('reports Unassigned as an empty id rather than as no answer', async () => {
        const wrapper = mountPicker({ modelValue: { id: PARTNERS[0].id, name: null, kind: null } });

        await chooseOption(wrapper, 'Unassigned');

        expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual({
            id: null,
            name: null,
            kind: null,
        });
        wrapper.unmount();
    });

    it('asks for a name only once adding a partner is chosen', async () => {
        const wrapper = mountPicker();

        expect(wrapper.text()).not.toContain('Partner name');

        await chooseOption(wrapper, 'Add a partner');
        expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual({
            id: null,
            name: '',
            kind: null,
        });

        await wrapper.setProps({ modelValue: { id: null, name: '', kind: null } });
        expect(wrapper.text()).toContain('Partner name');
        wrapper.unmount();
    });

    it('reports a typed name without an id', async () => {
        const wrapper = mountPicker({ modelValue: { id: null, name: '', kind: null } });

        await wrapper.find('input[maxlength="80"]').setValue('Front Desk');

        expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual({
            id: null,
            name: 'Front Desk',
            kind: null,
        });
        wrapper.unmount();
    });

    it('offers nothing but Unassigned on a campaign with no partners yet', async () => {
        const wrapper = mount(PartnerPicker, {
            attachTo: document.body,
            props: { partners: [], modelValue: { id: null, name: null, kind: null } },
        });

        await wrapper.find('.v-field').trigger('mousedown');
        await wrapper.find('.v-field').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));

        const titles = [...document.body.querySelectorAll('.v-list-item')].map((item) =>
            item.textContent.trim(),
        );

        expect(titles).toEqual(['Unassigned', 'Add a partner']);
        wrapper.unmount();
    });
});
