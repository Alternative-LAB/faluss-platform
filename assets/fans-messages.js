(() => {
  'use strict';
  const root = document.querySelector('.fu-messages');
  const layout = root?.querySelector('.fu-message-layout');
  if (!layout) return;
  // Adapt this panel, never the shared navigation, to the keyboard/viewport height.
  const resize = () => {
    const viewport = window.visualViewport;
    const rail = document.querySelector('.fu-rail');
    const bottom = rail && getComputedStyle(rail).position === 'fixed' ? rail.getBoundingClientRect().height : 0;
    const top = Math.max(0, layout.getBoundingClientRect().top - (viewport?.offsetTop || 0));
    layout.style.setProperty('--fu-message-height', `${Math.max(280, (viewport?.height || innerHeight) - top - bottom - 12)}px`);
  };
  resize();
  window.addEventListener('resize', resize);
  window.visualViewport?.addEventListener('resize', resize);
  // Late font wrapping or a result notice can move the panel without a window resize.
  document.fonts.ready.then(resize);
  root.querySelectorAll('.fu-message-avatar img').forEach(img => {
    const fallback = () => { img.hidden = true; };
    img.addEventListener('error', fallback);
    if (img.complete && !img.naturalWidth) fallback();
  });
  root.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const detail = event.target.closest('details[open]');
    if (detail) { detail.open = false; detail.querySelector('summary')?.focus(); event.preventDefault(); }
  });
  // Native links/forms remain usable without JS; no polling, send interception or read receipt.
  window.addEventListener('pagehide', () => {
    window.removeEventListener('resize', resize);
    window.visualViewport?.removeEventListener('resize', resize);
  });
})();
