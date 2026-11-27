(() => {
    const launchForm=document.querySelector('[data-worker-launch]');
    if(launchForm){
        const button=launchForm.querySelector('button');const label=button.textContent;
        launchForm.addEventListener('submit',()=>{button.disabled=true;button.textContent='Iniciando…';});
        window.addEventListener('pageshow',()=>{button.disabled=false;button.textContent=label;});
    }
    const container = document.querySelector('[data-live-dashboard]');
    const status = document.getElementById('live-progress-status');
    if (!container || !status) return;
    let timer;
    let running = false;
    let stopped = false;
    let lastHtml = container.innerHTML;
    const schedule = () => { clearTimeout(timer); if (!stopped) timer = setTimeout(refresh, 3000); };

    async function refresh() {
        if (running || stopped) return;
        if (document.hidden) { schedule(); return; }
        running = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(container.dataset.progressUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            if (response.status === 401 || response.status === 419 || response.redirected) {
                stopped = true;
                status.textContent = 'La sesión terminó. Vuelve a ingresar para ver el avance.';
                return;
            }
            if (!response.ok) throw new Error('No se pudo consultar el progreso');
            const data = await response.json();
            if (typeof data.html !== 'string') throw new Error('Respuesta de progreso incompleta');
            // Keep keyboard focus on controls while somebody is using them.
            if (data.html !== lastHtml && !container.contains(document.activeElement)) {
                container.innerHTML = data.html;
                lastHtml = data.html;
            }
            status.textContent = data.active_runs > 0
                ? `Actualización en vivo · ${data.active_runs} extracción(es) pendiente(s) o en curso · cada 3 segundos`
                : 'Actualización en vivo · sin extracciones pendientes · cada 3 segundos';
        } catch (error) {
            status.textContent = 'No se pudo actualizar el avance. Reintentando automáticamente…';
        } finally {
            clearTimeout(timeout);
            running = false;
            schedule();
        }
    }
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && !stopped) { clearTimeout(timer); refresh(); }
    });
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) { stopped = false; refresh(); }
    });
    refresh();
})();
