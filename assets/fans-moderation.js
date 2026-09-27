(() => {
    'use strict';
    const panel = document.querySelector('.faluss-moderation');
    if (!panel || !window.fetch || !window.AbortController || !URL.createObjectURL) return;
    const clearAll = [];
    panel.querySelectorAll('.fm-preview').forEach(form => {
        const submit = form.querySelector('button[type="submit"]');
        const display = document.createElement('div');
        const status = document.createElement('p');
        status.setAttribute('role', 'status');
        form.append(display, status);
        let controller = null;
        let objectUrl = null;
        const clear = () => {
            if (controller) controller.abort();
            controller = null;
            display.replaceChildren();
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            submit.disabled = false;
            status.textContent = '';
        };
        clearAll.push(clear);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            clear();
            const pending = new AbortController();
            controller = pending;
            submit.disabled = true;
            status.textContent = 'Vérification de l’accès…';
            try {
                // The WordPress hidden field named "action" shadows form.action in the DOM.
                const response = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form),
                    credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: pending.signal });
                if (!response.ok || !response.headers.get('Content-Type')?.startsWith('image/png')) throw new Error('Unavailable');
                const bytes = await response.blob();
                if (controller !== pending || pending.signal.aborted) return;
                objectUrl = URL.createObjectURL(bytes);
                const image = document.createElement('img');
                image.src = objectUrl;
                image.alt = 'Image privée soumise à examen humain';
                const close = document.createElement('button');
                close.type = 'button';
                close.className = 'fm-secondary';
                close.textContent = 'Fermer l’aperçu';
                close.addEventListener('click', () => { clear(); submit.focus(); });
                display.append(image, close);
                status.textContent = 'Accès confirmé à cet instant. Recharger avant une nouvelle revue.';
            } catch (error) {
                if (controller === pending && !pending.signal.aborted) status.textContent = 'Image indisponible. Rechargez la file avant de réessayer.';
            } finally {
                if (controller === pending) submit.disabled = false;
            }
        });
    });
    const clear = () => clearAll.forEach(close => close());
    window.addEventListener('pagehide', clear);
    document.addEventListener('visibilitychange', () => { if (document.hidden) clear(); });
    panel.querySelectorAll('.fm-decision').forEach(form => form.addEventListener('submit', clear));
})();
