(() => {
    document.querySelectorAll('[data-auto-submit]').forEach(select => select.addEventListener('change', () => select.form.requestSubmit()));
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
        function updatePause() {
            pause.textContent = paused ? 'Reproducir' : 'Pausar';
            pause.setAttribute('aria-label', paused ? 'Reproducir banners' : 'Pausar banners');
        }
        function start() {
            clearInterval(timer);
            if (!paused && !document.hidden && !carousel.matches(':hover') && !carousel.contains(document.activeElement)) {
                timer = setInterval(() => show(current + 1), 7000);
            }
        }
        dots.forEach((dot, i) => dot.addEventListener('click', () => { show(i); start(); }));
        carousel.querySelector('[data-carousel-prev]').addEventListener('click', () => { show(current - 1); start(); });
        carousel.querySelector('[data-carousel-next]').addEventListener('click', () => { show(current + 1); start(); });
        pause.addEventListener('click', () => { paused = !paused; updatePause(); start(); });
        carousel.addEventListener('mouseenter', () => clearInterval(timer));
        carousel.addEventListener('mouseleave', start);
        carousel.addEventListener('focusin', () => clearInterval(timer));
        carousel.addEventListener('focusout', () => setTimeout(start, 0));
        document.addEventListener('visibilitychange', start);
        reduceMotion.addEventListener('change', () => { paused = reduceMotion.matches; updatePause(); start(); });
        show(0); updatePause(); start();
    }
    document.querySelectorAll('img[data-cover-image]').forEach(image => {
        const fallback = () => { image.hidden = true; image.closest('.cover').classList.add('cover-missing'); };
        image.addEventListener('error', fallback);
        if (image.complete && image.naturalWidth === 0) fallback();
    });
})();
