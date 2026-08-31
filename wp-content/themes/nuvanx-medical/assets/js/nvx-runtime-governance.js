(function () {
  'use strict';

  // Prevent HubSpot Conversations from duplicating the canonical chat surface.
  window.hsConversationsSettings = {
    loadImmediately: false,
  };

  const config = window.nvxRuntimeGovernance || {};
  const modalConfig = window.nvxValoracionModal || {};

  function setInert(element, inert) {
    if (!element) return;
    if (inert) element.setAttribute('inert', '');
    else element.removeAttribute('inert');
  }

  function normalizePath(pathname = '') {
    let normalized = pathname;
    while (normalized.endsWith('/')) normalized = normalized.slice(0, -1);
    return normalized + '/';
  }

  function hasMarketingConsent() {
    try {
      if (typeof window.cmplz_has_consent === 'function') return window.cmplz_has_consent('marketing') === true;
      if (typeof window.wp_has_consent === 'function') return window.wp_has_consent('marketing') === true;
    } catch (_error) {
      return false;
    }
    return false;
  }

  /**
   * HubSpot global analytics remains a marketing-consent owner only.
   * Form rendering/submission is never initialized in the browser.
   */
  function loadHubSpotGlobalTracking() {
    const trackingScriptId = 'nvx-hubspot-tracking-runtime';
    const existing = document.getElementById(trackingScriptId);

    if (!hasMarketingConsent()) {
      if (existing) existing.remove();
      return Promise.resolve();
    }

    if (existing) return Promise.resolve();

    const portalId = String(modalConfig.hubspotPortalId || '').replace(/[^0-9]/g, '');
    if (!portalId) return Promise.resolve();

    return new Promise(function (resolve) {
      const script = document.createElement('script');
      script.id = trackingScriptId;
      script.src = 'https://js.hs-scripts.com/' + portalId + '.js';
      script.async = true;
      script.addEventListener('load', resolve, { once: true });
      script.addEventListener('error', function () {
        script.remove();
        resolve();
      }, { once: true });
      document.head.appendChild(script);
    });
  }

  function focusableElements(container) {
    if (!container) return [];
    return Array.prototype.slice.call(
      container.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    ).filter(function (element) {
      return !element.hasAttribute('hidden') && element.getAttribute('aria-hidden') !== 'true';
    });
  }

  /** A11y layer for the canonical mobile-navigation controller. */
  function initMobileNavigationGovernance() {
    const nav = document.getElementById(config.mobileNavId || 'nvx-mobile-nav');
    const trigger = document.getElementById('nvx-hamburger-btn');
    const close = document.getElementById('nvx-mobile-close');
    if (!nav || !trigger) return;

    let wasOpen = false;

    function isOpen() {
      return nav.classList.contains('is-open') || nav.hasAttribute('open');
    }

    function requestClose() {
      if (close && typeof close.click === 'function') {
        close.click();
        return;
      }
      setInert(nav, true);
    }

    function synchronizeA11y() {
      const open = isOpen();
      const focusWasInside = nav.contains(document.activeElement);
      setInert(nav, !open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      trigger.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');

      if (open && !wasOpen) {
        window.setTimeout(function () {
          const target = close || focusableElements(nav)[0];
          if (target && typeof target.focus === 'function') target.focus();
        }, 0);
      }

      if (!open && wasOpen && focusWasInside && typeof trigger.focus === 'function') {
        trigger.focus();
      }
      wasOpen = open;
    }

    setInert(nav, !isOpen());
    wasOpen = isOpen();

    trigger.addEventListener(
      'click',
      function () {
        if (!isOpen()) setInert(nav, false);
      },
      true
    );

    if (typeof MutationObserver === 'function') {
      new MutationObserver(synchronizeA11y).observe(nav, {
        attributes: true,
        attributeFilter: ['class', 'open']
      });
    }

    nav.addEventListener('click', function (event) {
      const link = (event.target && event.target.closest) ? event.target.closest('a[href]') : null;
      if (link) requestClose();
    });

    document.addEventListener('keydown', function (event) {
      if (!isOpen()) return;

      if (event.key === 'Escape') {
        event.preventDefault();
        requestClose();
        return;
      }

      if (event.key !== 'Tab') return;
      const focusables = focusableElements(nav);
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];

      if (!nav.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
        return;
      }

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  }

  /** Govern the first-party valoración modal and CTA routing. */
  function initValoracionModalGovernance() {
    const modal = document.getElementById(modalConfig.modalId || 'nvx-valoracion-modal');
    let lastFocus = null;
    const DEFAULT_VALORACION_PATH = '/madrid/valoracion/';
    const pageUrl = (modalConfig.pageUrl || DEFAULT_VALORACION_PATH).replace(/\/?$/, '/');

    let pagePath;
    try {
      pagePath = normalizePath(new URL(pageUrl, window.location.origin).pathname);
    } catch (_error) {
      pagePath = normalizePath(DEFAULT_VALORACION_PATH);
    }

    function isValoracionHref(href) {
      if (!href) return false;
      try {
        const url = new URL(href, window.location.origin);
        const path = normalizePath(url.pathname);
        if (path === pagePath) return true;
        return (
          path.indexOf(DEFAULT_VALORACION_PATH) !== -1 ||
          path.indexOf('/valoracion/') !== -1 ||
          path === '/consulta-medica/' ||
          path === '/consultamedica/'
        );
      } catch (_error) {
        return /valoraci[oó]n|consulta-medica|consultamedica/i.test(href);
      }
    }

    function closeMobileNav() {
      const mobileNav = document.getElementById(config.mobileNavId || 'nvx-mobile-nav');
      const closeButton = document.getElementById('nvx-mobile-close');
      if (mobileNav && closeButton && (mobileNav.classList.contains('is-open') || mobileNav.hasAttribute('open'))) {
        closeButton.click();
      }
    }

    function restoreModalState() {
      document.body.classList.remove('nvx-valoracion-modal-open');
      const mobileNav = document.getElementById(config.mobileNavId || 'nvx-mobile-nav');
      const mobileNavOpen = mobileNav && (mobileNav.classList.contains('is-open') || mobileNav.hasAttribute('open'));
      if (!mobileNavOpen) document.body.style.overflow = '';
      if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
      lastFocus = null;
    }

    function openModal(trigger) {
      if (!modal) return;
      lastFocus = trigger || document.activeElement;
      closeMobileNav();
      try {
        if (!modal.open) modal.showModal();
      } catch (_error) {
        modal.setAttribute('open', 'open');
      }
      document.body.classList.add('nvx-valoracion-modal-open');
      document.body.style.overflow = 'hidden';

      window.setTimeout(function () {
        const closeButton = modal.querySelector('.nvx-valoracion-modal__close') || modal.querySelector('button, [tabindex="0"]');
        if (closeButton && typeof closeButton.focus === 'function') closeButton.focus();
      }, 50);
    }

    function closeModal() {
      if (!modal) return;
      if (modal.open && typeof modal.close === 'function') modal.close();
      else {
        modal.removeAttribute('open');
        restoreModalState();
      }
    }

    function shouldIntercept(element) {
      if (!element || element.tagName !== 'A') return false;
      if (element.dataset.nvxValoracionModal === '0') return false;
      if (element.classList.contains('nvx-open-valoracion-modal')) return true;
      if (element.dataset.nvxValoracionModal === '1') return true;
      if (element.id === 'nvx-header-cta' || element.id === 'nvx-footer-cta' || element.id === 'nvx-mobile-cta') return true;

      const href = element.getAttribute('href') || '';
      if (!isValoracionHref(href)) return false;
      const className = element.className || '';
      return (
        /\bnvx-(btn|button|brand-btn)\b/.test(className) ||
        Boolean(element.closest('.nvx-cta-banner, .nvx-brand-actions, .nvx-home-hero-ctas, .nvx-cta-pair, .nvx-home-action-banner'))
      );
    }

    document.addEventListener('click', function (event) {
      const anchor = event.target && event.target.closest ? event.target.closest('a') : null;
      if (!shouldIntercept(anchor)) return;

      if (modal && typeof modal.showModal === 'function') {
        event.preventDefault();
        event.stopPropagation();
        openModal(anchor);
        return;
      }

      const formStage = document.querySelector('#nvx-hubspot-form, .nvx-form-stage, #nvx-valoracion-first-party-form');
      if (formStage) {
        event.preventDefault();
        event.stopPropagation();
        closeMobileNav();
        formStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }, true);

    if (modal) {
      modal.addEventListener('click', function (event) {
        const isBackdrop = event.target.classList && event.target.classList.contains('nvx-valoracion-modal__backdrop');
        const isCloseButton = event.target.closest && event.target.closest('[data-nvx-valoracion-modal-close]');
        if (isBackdrop || isCloseButton) {
          event.preventDefault();
          closeModal();
        }
      });

      modal.addEventListener('close', restoreModalState);

      modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeModal();
          return;
        }
        if (event.key !== 'Tab') return;
        const focusables = focusableElements(modal);
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          last.focus();
          event.preventDefault();
        } else if (!event.shiftKey && document.activeElement === last) {
          first.focus();
          event.preventDefault();
        }
      });
    }

    window.nvxOpenValoracionModal = function () {
      if (modal) {
        openModal(document.activeElement);
        return;
      }
      const formStage = document.querySelector('#nvx-hubspot-form, .nvx-form-stage, #nvx-valoracion-first-party-form');
      if (formStage) formStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
      else window.location.href = DEFAULT_VALORACION_PATH + '#nvx-hubspot-form';
    };
    window.nvxCloseValoracionModal = closeModal;

    const params = new URLSearchParams(window.location.search);
    if (params.get('valoracion') === 'error') {
      openModal(null);
      const errorMessage = modal ? modal.querySelector('.nvx-valoracion-direct-form__error') : null;
      if (errorMessage && typeof errorMessage.focus === 'function') {
        window.setTimeout(function () {
          errorMessage.focus();
        }, 50);
      }
    }
  }

  function initGlobalHubSpotTracking() {
    if (hasMarketingConsent()) loadHubSpotGlobalTracking();

    document.addEventListener('cmplz_enable_category', loadHubSpotGlobalTracking);
    document.addEventListener('cmplz_status_change', loadHubSpotGlobalTracking);
    document.addEventListener('wp_listen_for_consent_change', loadHubSpotGlobalTracking);
  }

  function initHeroVideoGovernance() {
    const video = document.getElementById('nvx-home-hero-video') || document.querySelector('.nvx-home-hero__video');
    const toggleButton = document.getElementById('nvx-hero-video-toggle') || document.querySelector('.nvx-home-hero__video-toggle');
    if (!video) return;

    const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    function setPausedState(paused) {
      if (!toggleButton) return;
      toggleButton.setAttribute('aria-pressed', paused ? 'true' : 'false');
      toggleButton.setAttribute('aria-label', paused ? 'Reproducir vídeo de fondo' : 'Pausar vídeo de fondo');
      const icon = toggleButton.querySelector('.nvx-video-toggle__icon');
      if (icon) icon.textContent = paused ? '▶' : '⏸';
    }

    function handleMotionPreference(event) {
      if (!event.matches) return;
      video.pause();
      setPausedState(true);
    }

    if (mediaQuery.matches) {
      video.pause();
      setPausedState(true);
    }

    if (typeof mediaQuery.addEventListener === 'function') {
      mediaQuery.addEventListener('change', handleMotionPreference);
    } else if (typeof mediaQuery.addListener === 'function') {
      mediaQuery.addListener(handleMotionPreference);
    }

    if (toggleButton) {
      toggleButton.addEventListener('click', function () {
        if (video.paused) {
          video.play().then(function () {
            setPausedState(false);
          }).catch(function () {});
        } else {
          video.pause();
          setPausedState(true);
        }
      });
    }
  }

  function initialize() {
    initMobileNavigationGovernance();
    initValoracionModalGovernance();
    initGlobalHubSpotTracking();
    initHeroVideoGovernance();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();