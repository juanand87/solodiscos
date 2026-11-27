document.querySelectorAll('[data-logo-carousel]').forEach(carousel => {
    const track = carousel.querySelector('[data-logo-track]');
    const previous = carousel.querySelector('[data-logo-prev]');
    const next = carousel.querySelector('[data-logo-next]');
    const pause = carousel.querySelector('[data-logo-pause]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let paused = reducedMotion.matches;
    let timer;
    let touching = false;
    const overflowing = () => track.scrollWidth > track.clientWidth + 2;
    const move = direction => {
        const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
        const atStart = track.scrollLeft <= 2;
        const item = track.querySelector('[data-logo-item], .store-logo-slide');
        const step = item ? item.getBoundingClientRect().width + 22 : Math.max(150, track.clientWidth * .6);
        if ((direction > 0 && atEnd) || (direction < 0 && atStart)) {
            track.scrollTo({ left: direction > 0 ? 0 : track.scrollWidth, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
        } else track.scrollBy({ left: direction * step, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
    };
    const start = () => {
        clearInterval(timer);
        previous.disabled = next.disabled = !overflowing();
        if (pause) {
            pause.disabled = !overflowing();
            pause.textContent = paused ? 'Reproducir' : 'Pausar';
            pause.setAttribute('aria-label', paused ? 'Reproducir avance de tiendas' : 'Pausar avance de tiendas');
            pause.setAttribute('aria-pressed', String(paused));
        }
        if (!paused && !document.hidden && !touching && !carousel.matches(':hover') && !carousel.querySelector(':focus-visible') && overflowing()) timer = setInterval(() => move(1), 3000);
    };
    previous.addEventListener('click', () => { move(-1); start(); });
    next.addEventListener('click', () => { move(1); start(); });
    pause?.addEventListener('click', () => { paused = !paused; start(); });
    track.addEventListener('keydown', event => {
        if (event.target !== track || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault(); move(event.key === 'ArrowLeft' ? -1 : 1);
    });
    carousel.addEventListener('mouseenter', () => clearInterval(timer));
    carousel.addEventListener('mouseleave', start);
    carousel.addEventListener('focusin', start);
    carousel.addEventListener('focusout', () => setTimeout(start, 0));
    track.addEventListener('touchstart', () => { touching = true; clearInterval(timer); }, { passive: true });
    track.addEventListener('touchend', () => { touching = false; start(); }, { passive: true });
    track.addEventListener('touchcancel', () => { touching = false; start(); }, { passive: true });
    track.querySelectorAll('[data-store-logo]').forEach(logo => {
        const failed = () => {
            if (logo.hidden) return;
            logo.hidden = true;
            const item = logo.closest('[data-logo-item], .store-logo-slide');
            if (item && !item.querySelector('.store-logo-name')) {
                const name = document.createElement('span'); name.textContent = logo.alt || item.title; item.append(name);
            }
            start();
        };
        logo.addEventListener('error', failed);
        if (logo.complete && !logo.naturalWidth) failed();
    });
    document.addEventListener('visibilitychange', start);
    reducedMotion.addEventListener('change', () => { paused = reducedMotion.matches; start(); });
    new ResizeObserver(start).observe(track);
    start();
});
