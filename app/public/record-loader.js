(() => {
    const loader = document.getElementById('record-loader');
    if (!loader) return;
    let pending;
    let safety;
    const hide = () => {
        clearTimeout(pending); clearTimeout(safety);
        loader.hidden = true; loader.setAttribute('aria-hidden', 'true');
    };
    const start = () => {
        hide();
        pending = setTimeout(() => {
            loader.hidden = false; loader.setAttribute('aria-hidden', 'false');
        }, 180);
        // Recover automatically if a navigation is cancelled or the server never completes it.
        safety = setTimeout(hide, 8000);
    };
    const navigates = (url, target, allowSameRoute = false) => {
        if (target && target !== '_self') return false;
        try {
            const next = new URL(url, location.href);
            return next.origin === location.origin
                && (allowSameRoute || next.pathname !== location.pathname || next.search !== location.search)
                && !/\/exportar(?:\/|$)|\.(?:csv|xlsx?|pdf|zip)$/i.test(next.pathname);
        } catch { return false; }
    };
    window.addEventListener('load', hide, { once: true });
    window.addEventListener('pageshow', hide);
    window.addEventListener('pagehide', hide);
    if (document.readyState !== 'complete') start();
    document.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.hasAttribute('data-no-preloader') || !navigates(link.href, link.target)) return;
        queueMicrotask(() => { if (!event.defaultPrevented) start(); });
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        const button = event.submitter;
        if (form.hasAttribute('data-no-preloader') || !navigates(button?.formAction || form.action, button?.formTarget || form.target, true)) return;
        queueMicrotask(() => { if (!event.defaultPrevented) start(); });
    });
})();
