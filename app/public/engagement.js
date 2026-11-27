(() => {
    const modal = document.querySelector('[data-disc-modal]');
    if (!modal) return;
    const status = modal.querySelector('[data-disc-status]');
    const content = modal.querySelector('[data-disc-content]');
    const image = modal.querySelector('[data-disc-image]');
    const title = modal.querySelector('#disc-modal-title');
    let pending, opener;
    const number = value => Number(value).toLocaleString('es-CL');
    const close = () => { pending?.abort(); modal.close(); opener?.focus(); };
    modal.querySelector('[data-disc-close]').addEventListener('click', close);
    modal.addEventListener('cancel', () => pending?.abort());
    modal.addEventListener('click', event => {
        const box = modal.getBoundingClientRect();
        if (event.target === modal && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) close();
    });
    document.addEventListener('click', async event => {
        const trigger = event.target.closest('[data-disc-detail]');
        if (!trigger) return;
        opener = trigger;
        pending?.abort();
        pending = new AbortController();
        const current = pending;
        title.textContent = 'Detalle del disco';
        content.hidden = true;
        image.removeAttribute('src');
        status.textContent = 'Cargando portada y clics…';
        modal.showModal();
        try {
            const response = await fetch(trigger.dataset.discDetail, {signal: current.signal, headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) throw new Error('detail');
            const data = await response.json();
            if (current !== pending || !modal.open) return;
            title.textContent = `${data.artist} · ${data.title}`;
            image.hidden = true;
            if (data.image) {
                const url = new URL(data.image, location.origin);
                if (['http:', 'https:'].includes(url.protocol)) {image.src = url.href; image.alt = `Portada de ${data.title}`; image.hidden = false;}
            }
            image.onerror = () => { image.hidden = true; };
            modal.querySelector('[data-disc-meta]').textContent = `${data.format} · ${data.scope}`;
            modal.querySelector('[data-disc-total]').textContent = `${number(data.total)} clics · ${data.from} al ${data.to}`;
            modal.querySelector('[data-disc-measurement]').textContent = data.started_at ? `Medición disponible desde ${data.started_at}. Los meses anteriores aparecen sin medición.` : 'La medición todavía no ha comenzado.';
            const months = modal.querySelector('[data-disc-months]');
            months.replaceChildren();
            const maximum = Math.max(1, ...data.months.map(month => month.clicks));
            for (const month of data.months) {
                const row = document.createElement('div'); row.className = 'engagement-month';
                const label = document.createElement('span'); label.textContent = month.month;
                const track = document.createElement('span'); track.className = 'engagement-month-track'; track.setAttribute('aria-hidden', 'true');
                const bar = document.createElement('i'); bar.style.width = `${100 * month.clicks / maximum}%`; track.append(bar);
                const count = document.createElement('strong'); count.textContent = month.measured ? number(month.clicks) : 'Sin medición';
                row.append(label, track, count); months.append(row);
            }
            status.textContent = data.image ? '' : 'Portada no disponible.';
            content.hidden = false;
        } catch (error) {
            if (error.name !== 'AbortError' && current === pending) status.textContent = 'No pudimos cargar el detalle. Cierra y vuelve a intentarlo; si tu sesión venció, ingresa nuevamente.';
        }
    });
})();
