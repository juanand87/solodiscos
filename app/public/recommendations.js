document.querySelectorAll('[data-logo-carousel]').forEach(carousel => {
    const track = carousel.querySelector('[data-logo-track]');
    const previous = carousel.querySelector('[data-logo-prev]');
    const next = carousel.querySelector('[data-logo-next]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const update = () => {
        previous.disabled = track.scrollLeft <= 1;
        next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
    };
    const move = direction => track.scrollBy({ left: direction * Math.max(180, track.clientWidth * .8), behavior: reducedMotion.matches ? 'instant' : 'smooth' });
    previous.addEventListener('click', () => move(-1));
    next.addEventListener('click', () => move(1));
    track.addEventListener('scroll', update, { passive: true });
    track.addEventListener('keydown', event => {
        if (event.target !== track || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault(); move(event.key === 'ArrowLeft' ? -1 : 1);
    });
    track.querySelectorAll('[data-store-logo]').forEach(logo => {
        const failed = () => { logo.closest('a').hidden = true; update(); };
        logo.addEventListener('error', failed);
        if (logo.complete && !logo.naturalWidth) failed();
    });
    new ResizeObserver(update).observe(track);
    update();
});
