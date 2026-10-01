(() => {
  'use strict';
  // Touch: first tap names an icon, second tap activates it. Keyboard/AT clicks stay native.
  document.querySelectorAll('.fu-nav__item').forEach(link => {
    link.addEventListener('click', event => {
      if (event.pointerType !== 'touch' || event.detail === 0) return;
      if (!link.hasAttribute('data-touch-label')) {
        event.preventDefault(); event.stopImmediatePropagation();
        document.querySelectorAll('[data-touch-label]').forEach(item=>item.removeAttribute('data-touch-label'));
        link.setAttribute('data-touch-label',''); link.focus({preventScroll:true});
      } else link.removeAttribute('data-touch-label');
    });
    link.addEventListener('blur',()=>link.removeAttribute('data-touch-label'));
  });
})();
