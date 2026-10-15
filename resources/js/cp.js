import SignOffPanel from './components/SignOffPanel.vue';

Statamic.booting(() => {
    Statamic.$components.register('sign_off-fieldtype', SignOffPanel);
});

// After a sign-off action, the entry page reloads in full and its toast
// ("Signed off and published.") arrives in the page's own Inertia props,
// which the Control Panel only reads on later visits. Show it here, unless
// the Control Panel has already shown toasts for this load itself.
Statamic.booted(() => {
    if ((Statamic.$config.get('toasts') ?? []).length) {
        return;
    }

    let page;

    try {
        page = JSON.parse(document.getElementById('statamic')?.dataset.page ?? 'null');
    } catch {
        return;
    }

    const toasts = page?.props?._toasts;

    if (!Array.isArray(toasts)) {
        return;
    }

    toasts.forEach(({ type, message, duration }) => {
        if (typeof Statamic.$toast[type] === 'function') {
            Statamic.$toast[type](message, duration ? { duration } : {});
        }
    });
});
