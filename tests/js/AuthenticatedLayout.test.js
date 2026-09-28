import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '../../resources/js/Layouts/AuthenticatedLayout.vue';

/**
 * The account menu. The Platform Metrics item is guarded on the flag AND on the route,
 * because the route exists for everyone signed in on the hosted deployment and the gate is
 * what refuses them, while in the self-hosted build the route is not there at all.
 */
function mountLayout(user, routes = null) {
    globalThis.setAvailableRoutes(routes);

    const props = {
        auth: { user },
        errors: {},
        flash: { message: null },
        beta_banner: false,
        transaction_backend: 'phyrhose',
    };

    usePage.mockReturnValue({ props });

    return mount(AuthenticatedLayout, {
        attachTo: document.body,
        global: { mocks: { $page: { props } } },
    });
}

/** The menu renders into an overlay, so it has to be opened before anything can be read. */
async function openAccountMenu(wrapper, name) {
    const activator = wrapper.findAll('button').find((button) => button.text().includes(name));
    expect(activator).toBeTruthy();

    await activator.trigger('click');
    await new Promise((resolve) => setTimeout(resolve, 0));

    // A menu that never opened contains nothing, which would pass every absence check below.
    expect(document.body.textContent).toContain('Log Out');

    return document.body.textContent;
}

const SELF_HOSTED_ROUTES = ['dashboard', 'logout', 'login'];

afterEach(() => {
    globalThis.setAvailableRoutes(null);
    document.body.innerHTML = '';
});

describe('AuthenticatedLayout — the account menu', () => {
    it('offers an ordinary account no way into the platform metrics view', async () => {
        const wrapper = mountLayout({ name: 'Ordinary Operator', is_admin: false });

        expect(await openAccountMenu(wrapper, 'Ordinary Operator')).not.toContain('Platform Metrics');

        wrapper.unmount();
    });

    it('treats an account with no flag at all the same way', async () => {
        const wrapper = mountLayout({ name: 'Ordinary Operator' });

        expect(await openAccountMenu(wrapper, 'Ordinary Operator')).not.toContain('Platform Metrics');

        wrapper.unmount();
    });

    it('offers it to an account carrying the flag', async () => {
        const wrapper = mountLayout({ name: 'Platform Owner', is_admin: true });

        expect(await openAccountMenu(wrapper, 'Platform Owner')).toContain('Platform Metrics');

        wrapper.unmount();
    });

    it('withholds it in a build whose route table has no metrics view', async () => {
        // The self-hosted build seeds an admin account, so the flag is set there too. The
        // route is what is missing, and route() throws on a name it does not know: without
        // the route check the item would blank the whole layout rather than be absent.
        const wrapper = mountLayout({ name: 'Self Hoster', is_admin: true }, SELF_HOSTED_ROUTES);

        const menu = await openAccountMenu(wrapper, 'Self Hoster');

        expect(menu).not.toContain('Platform Metrics');
        expect(menu).toContain('Log Out');

        wrapper.unmount();
    });
});
