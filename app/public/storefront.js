(() => {
    document.querySelectorAll('[data-auto-submit]').forEach(select => select.addEventListener('change', () => select.form.requestSubmit()));
    const storage = {
        get(key) { try { return sessionStorage.getItem(key); } catch { return null; } },
        set(key, value) { try { sessionStorage.setItem(key, value); } catch {} },
        remove(key) { try { sessionStorage.removeItem(key); } catch {} }
    };
    const catalogStateKey = 'solodiscos:catalog-position';
    document.querySelectorAll('[data-catalog-product]').forEach(link => link.addEventListener('click', () => {
        storage.set(catalogStateKey, JSON.stringify({ url: location.pathname + location.search, y: Math.round(scrollY) }));
    }));
    document.querySelectorAll('[data-catalog-return]').forEach(link => link.addEventListener('click', () => {
        try {
            const state = JSON.parse(storage.get(catalogStateKey) || 'null');
            const requested = new URL(location.href).searchParams.get('return_to');
            if (state && /^\/(?:\?[^\r\n#]*)?$/.test(state.url) && (!requested || requested === state.url)) {
                link.href = state.url;
                storage.set('solodiscos:restore-catalog', '1');
            }
        } catch {}
    }));
    const restoreCatalog = () => {
        if (!document.querySelector('[data-catalog-page]') || !storage.get('solodiscos:restore-catalog')) return;
        storage.remove('solodiscos:restore-catalog');
        try {
            const state = JSON.parse(storage.get(catalogStateKey) || 'null');
            if (!state || state.url !== location.pathname + location.search || !Number.isFinite(state.y)) return;
            requestAnimationFrame(() => {
                scrollTo({ top: Math.max(0, state.y), behavior: 'instant' });
                requestAnimationFrame(() => scrollTo({ top: Math.max(0, state.y), behavior: 'instant' }));
            });
        } catch {}
    };
    window.addEventListener('pageshow', restoreCatalog);
    if (document.readyState === 'complete') restoreCatalog();

    const carousel = document.querySelector('[data-carousel]');
    if (carousel) {
        const slides = [...carousel.querySelectorAll('[data-slide]')];
        const dots = [...carousel.querySelectorAll('[data-slide-to]')];
        const pause = carousel.querySelector('[data-carousel-pause]');
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let current = 0;
        let paused = reduceMotion.matches;
        let timer;
        function show(index) {
            current = (index + slides.length) % slides.length;
            slides.forEach((slide, i) => { slide.hidden = i !== current; });
            dots.forEach((dot, i) => dot.setAttribute('aria-current', String(i === current)));
            carousel.dataset.theme = current === 1 ? 'light' : 'dark';
        }
        function start() {
            clearInterval(timer);
            const running = !paused && !document.hidden && !carousel.querySelector(':focus-visible');
            carousel.dataset.playing = String(running);
            pause.textContent = paused ? 'Reproducir' : 'Pausar';
            pause.setAttribute('aria-label', paused ? 'Reproducir banners' : 'Pausar banners');
            pause.setAttribute('aria-pressed', String(paused));
            if (running) timer = setInterval(() => show(current + 1), 6000);
        }
        dots.forEach((dot, i) => dot.addEventListener('click', () => { show(i); start(); }));
        carousel.querySelector('[data-carousel-prev]').addEventListener('click', () => { show(current - 1); start(); });
        carousel.querySelector('[data-carousel-next]').addEventListener('click', () => { show(current + 1); start(); });
        pause.addEventListener('click', () => { paused = !paused; start(); });
        carousel.addEventListener('focusin', start);
        carousel.addEventListener('focusout', () => setTimeout(start, 0));
        document.addEventListener('visibilitychange', start);
        reduceMotion.addEventListener('change', () => { paused = reduceMotion.matches; start(); });
        show(0); start();
    }
    document.querySelectorAll('img[data-cover-image]').forEach(image => {
        const fallback = () => { image.hidden = true; image.closest('.cover').classList.add('cover-missing'); };
        image.addEventListener('error', fallback);
        if (image.complete && image.naturalWidth === 0) fallback();
    });
})();
