(() => {
  'use strict';
  // Touch: first tap names an icon, second tap activates it. Keyboard/AT clicks stay native.
  document.querySelectorAll('.fu-nav__item').forEach(link => {
    const reveal = () => {
      if (matchMedia('(max-width:700px)').matches) {
        const nav = link.parentElement;
        if (link.offsetLeft < nav.scrollLeft || link.offsetLeft + link.offsetWidth > nav.scrollLeft + nav.clientWidth) {
          nav.scrollLeft = link.offsetLeft - (nav.clientWidth - link.offsetWidth) / 2;
        }
      }
    };
    link.addEventListener('focus', reveal);
    if (link.classList.contains('is-active')) reveal();
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
