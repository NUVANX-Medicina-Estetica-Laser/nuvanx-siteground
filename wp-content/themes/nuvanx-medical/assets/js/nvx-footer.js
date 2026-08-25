/* NUVANX — responsive footer disclosure state.
 * Native <details> keeps the footer keyboard-accessible without JavaScript.
 * On wider viewports all groups are exposed as editorial columns; on mobile
 * they return to intentionally closed accordions.
 */
(function () {
  'use strict';

  var sections = Array.prototype.slice.call(
    document.querySelectorAll('.nvx-footer__section')
  );

  if (!sections.length || !window.matchMedia) {
    return;
  }

  var desktopQuery = window.matchMedia('(min-width: 768px)');

  function syncFooterDisclosure(event) {
    var shouldOpen = event.matches;

    sections.forEach(function (section) {
      section.open = shouldOpen;
    });
  }

  syncFooterDisclosure(desktopQuery);

  if (typeof desktopQuery.addEventListener === 'function') {
    desktopQuery.addEventListener('change', syncFooterDisclosure);
  } else if (typeof desktopQuery.addListener === 'function') {
    desktopQuery.addListener(syncFooterDisclosure);
  }
}());
