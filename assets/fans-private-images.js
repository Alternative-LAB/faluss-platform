(() => {
  'use strict';
  const root = document.querySelector('[data-fans-image-previews]');
  if (!root) return;
  const urls = new Set();
  let controller = new AbortController();
  let generation = 0;
  let busy = false;
  const buttons = [...root.querySelectorAll('[data-private-image]')];
  const clear = () => {
    controller.abort(); generation += 1; controller = new AbortController(); busy = false;
    urls.forEach(url => URL.revokeObjectURL(url)); urls.clear();
    buttons.forEach(button => {
      button.removeAttribute('aria-disabled');
      button.closest('.fu-private-preview')?.querySelector('[data-private-image-output]')?.replaceChildren();
    });
  };
  buttons.forEach(button => button.addEventListener('click', async () => {
    if (busy) return;
    clear(); busy = true; buttons.forEach(b => b.setAttribute('aria-disabled', 'true'));
    const current = generation; const { signal } = controller;
    const panel = button.closest('.fu-private-preview');
    const status = panel.querySelector('[role=status]');
    const output = panel.querySelector('[data-private-image-output]');
    status.textContent = 'Vérification privée de l’image…';
    let objectUrl = null;
    try {
      const url = new URL(button.dataset.privateImage, location.href);
      if (url.origin !== location.origin || url.search || url.hash) throw new Error('invalid');
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
        headers: { 'X-WP-Nonce': root.dataset.nonce } });
      if (!response.ok || response.headers.get('Content-Type')?.split(';')[0].trim() !== 'image/jpeg'
        || !response.body || Number(response.headers.get('Content-Length')) > 2097152) throw new Error('unavailable');
      const reader = response.body.getReader(); const chunks = []; let size = 0;
      try {
        while (true) {
          const part = await reader.read(); if (part.done) break;
          size += part.value.byteLength;
          if (size > 2097152 || signal.aborted || current !== generation) throw new Error('invalid');
          chunks.push(part.value);
        }
      } finally { await reader.cancel(); }
      if (signal.aborted || current !== generation || !output.isConnected) return;
      objectUrl = URL.createObjectURL(new Blob(chunks, { type: 'image/jpeg' })); urls.add(objectUrl);
      const img = document.createElement('img'); img.alt = 'Aperçu privé de votre image'; img.src = objectUrl;
      await img.decode();
      if (signal.aborted || current !== generation || !output.isConnected) return;
      if (img.naturalWidth > 1280 || img.naturalHeight > 1280) throw new Error('invalid');
      output.replaceChildren(img); objectUrl = null;
      status.textContent = 'Aperçu privé. Les octets déjà consultés ne peuvent pas être rappelés.';
    } catch (error) {
      if (!signal.aborted && current === generation) status.textContent = 'Image indisponible. Rechargez pour vérifier son état.';
    } finally {
      if (objectUrl) { URL.revokeObjectURL(objectUrl); urls.delete(objectUrl); }
      if (current === generation) { busy = false; buttons.forEach(b => b.removeAttribute('aria-disabled')); }
    }
  }));
  window.addEventListener('pagehide', clear);
  document.addEventListener('visibilitychange', () => { if (document.hidden) clear(); });
})();
