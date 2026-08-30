(function () {
  'use strict';

  // Prevent duplicate floating chat widgets from colliding with Joinchat / WhatsApp
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

  function safeErrorName(error) {
    return error && typeof error.name === 'string' ? error.name.slice(0, 64) : 'Error';
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

  function loadHubSpotGlobalTracking() {
    const trackingScriptId = 'nvx-hubspot-tracking-runtime';
    const existing = document.getElementById(trackingScriptId);

    if (!hasMarketingConsent()) {
      if (existing) existing.remove();
      return Promise.resolve();
    }

    if (existing) return Promise.resolve();

    const portalId = String(config.hubspotPortalId || '').replace(/[^0-9]/g, '');
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

  function removeAttributes(element, names) {
    if (!element || typeof element.removeAttribute !== 'function') return;
    names.forEach(function (name) {
      if (element.hasAttribute(name)) element.removeAttribute(name);
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

  /**
   * A11y layer only. Open/close and class/aria state belong to nvx-main.js
   * (same DOM ids). This module keeps inert + focus trap + Escape in sync
   * without re-implementing the menu controller.
   */
  function initMobileNavigationGovernance() {
    const nav = document.getElementById(config.mobileNavId || 'nvx-mobile-nav');
    const trigger = document.getElementById('nvx-hamburger-btn');
    const close = document.getElementById('nvx-mobile-close');
    if (!nav || !trigger) return;

    let wasOpen = false;

    function isOpen() {
      return nav.classList.contains('is-open') || nav.hasAttribute('open');
    }

    /** Prefer main.js close handler so body overflow / aria stay consistent. */
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

    // Clear inert before main.js opens the drawer (main runs on bubble phase).
    trigger.addEventListener(
      'click',
      function () {
        if (!isOpen()) setInert(nav, false);
      },
      true
    );

    new MutationObserver(synchronizeA11y).observe(nav, {
      attributes: true,
      attributeFilter: ['class', 'open']
    });

    nav.addEventListener('click', function (event) {
      const link = (event.target && event.target.closest) ? event.target.closest('a[href]') : null;
      if (!link) return;
      requestClose();
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

  /**
   * Governs the valuation modal and intercepts qualifying valuation links to open it.
   */
  function initValoracionModalGovernance() {
    const cfg = window.nvxRuntimeGovernance || {};
    const modal = document.getElementById(cfg.modalId || 'nvx-valoracion-modal');

    let lastFocus = null;
    const DEFAULT_VALORACION_PATH = '/madrid/valoracion/';
    const pageUrl = (cfg.pageUrl || DEFAULT_VALORACION_PATH).replace(/\/?$/, '/');

    let pagePath;
    try {
      pagePath = normalizePath(new URL(pageUrl, window.location.origin).pathname);
    } catch (_err) {
      pagePath = normalizePath(DEFAULT_VALORACION_PATH);
    }

    function isValoracionHref(href) {
      if (!href) return false;
      try {
        const u = new URL(href, window.location.origin);
        const path = normalizePath(u.pathname);
        if (path === pagePath) return true;
        return (
          path.indexOf(DEFAULT_VALORACION_PATH) !== -1 ||
          path.indexOf('/valoracion/') !== -1 ||
          path === '/consulta-medica/' ||
          path === '/consultamedica/'
        );
      } catch (_err) {
        return /valoraci[oó]n|consulta-medica|consultamedica/i.test(href);
      }
    }

    function closeMobileNav() {
      const mobileNav = document.getElementById(config.mobileNavId || 'nvx-mobile-nav');
      if (mobileNav && document.getElementById('nvx-mobile-close')) {
        const closeBtn = document.getElementById('nvx-mobile-close');
        if (mobileNav.classList.contains('is-open') || mobileNav.hasAttribute('open')) {
           closeBtn.click();
        }
      }
    }

    function openModal(trigger) {
      if (!modal) return;
      lastFocus = trigger || document.activeElement;
      closeMobileNav();
      try { if (!modal.open) modal.showModal(); } catch (_e) { modal.setAttribute('open', 'open'); }
      document.body.classList.add('nvx-valoracion-modal-open');
      document.body.style.overflow = 'hidden';

      // Guarantee immediate visible focus (WCAG 2.4.3 Focus Order)
      window.setTimeout(function () {
        const closeBtn = modal.querySelector('.nvx-valoracion-modal__close') || modal.querySelector('button, [tabindex="0"]');
        if (closeBtn && typeof closeBtn.focus === 'function') {
          closeBtn.focus();
        }
      }, 50);
    }

    function closeModal() {
      if (!modal) return;
      modal.close();
      document.body.classList.remove('nvx-valoracion-modal-open');
      // Don't release the scroll lock if the mobile nav is still open; it owns
      // its own overflow lock (see nvx-main.js) and would otherwise be left
      // open over a scrollable page.
      const mobileNav = document.getElementById(config.mobileNavId || 'nvx-mobile-nav');
      const mobileNavOpen = mobileNav && (mobileNav.classList.contains('is-open') || mobileNav.hasAttribute('open'));
      if (!mobileNavOpen) {
        document.body.style.overflow = '';
      }
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus();
      }
      lastFocus = null;
    }

    function shouldIntercept(el) {
      if (!el || el.tagName !== 'A') return false;
      if (el.dataset.nvxValoracionModal === '0') return false;
      if (el.classList.contains('nvx-open-valoracion-modal')) return true;
      if (el.dataset.nvxValoracionModal === '1') return true;
      if (el.id === 'nvx-header-cta' || el.id === 'nvx-footer-cta' || el.id === 'nvx-mobile-cta') return true;

      const href = el.getAttribute('href') || '';
      if (!isValoracionHref(href)) return false;
      const cls = el.className || '';
      if (
        /\bnvx-(btn|button|brand-btn)\b/.test(cls) ||
        el.closest('.nvx-cta-banner, .nvx-brand-actions, .nvx-home-hero-ctas, .nvx-cta-pair, .nvx-home-action-banner')
      ) {
        return true;
      }
      return false;
    }

    document.addEventListener('click', function (e) {
      const a = e.target && e.target.closest ? e.target.closest('a') : null;
      const button = e.target && e.target.closest ? e.target.closest('button') : null;
      const trigger = a || button;

      if (!shouldIntercept(trigger)) return;

      if (modal && typeof modal.showModal === 'function') {
        e.preventDefault();
        e.stopPropagation();
        openModal(trigger);
        return;
      }

      const formStage = document.querySelector('#nvx-hubspot-form, .nvx-form-stage, #nvx-hubspot-native-form');
      if (formStage) {
        e.preventDefault();
        e.stopPropagation();
        closeMobileNav();
        formStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
        const frame = formStage.querySelector('.hs-form-frame');
        if (frame && typeof frame.dispatchEvent === 'function') {
          frame.dispatchEvent(new Event('focusin', { bubbles: true }));
        }
      }
    }, true);

    if (modal) {
      modal.addEventListener('click', function (e) {
        // Close when clicking on backdrop or close button
        const isBackdrop = e.target.classList && e.target.classList.contains('nvx-valoracion-modal__backdrop');
        const isCloseBtn = e.target.closest && e.target.closest('[data-nvx-valoracion-modal-close]');
        if (isBackdrop || isCloseBtn) {
          e.preventDefault();
          closeModal();
        }
      });

      modal.addEventListener('close', function() {
         closeModal();
      });

      modal.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          closeModal();
          return;
        }
        if (e.key === 'Tab') {
          const focusableElements = modal.querySelectorAll('a[href], button, textarea, input[type="text"], input[type="radio"], input[type="checkbox"], select, [tabindex]:not([tabindex="-1"])');
          const first = focusableElements[0];
          const last = focusableElements[focusableElements.length - 1];
          if (e.shiftKey) {
            if (document.activeElement === first) {
              last.focus();
              e.preventDefault();
            }
          } else {
            if (document.activeElement === last) {
              first.focus();
              e.preventDefault();
            }
          }
        }
      });

    }

    window.nvxOpenValoracionModal = function () {
      if (modal) openModal(document.activeElement);
      else {
        const formStage = document.querySelector('#nvx-hubspot-form, .nvx-form-stage, #nvx-hubspot-native-form');
        if (formStage) formStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
        else window.location.href = DEFAULT_VALORACION_PATH + '#nvx-hubspot-form';
      }
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

  /**
   * Resolves the HubSpot forms script URL from configuration or an embedded form frame.
   * @return {string} The configured or derived HubSpot script URL, or an empty string when no valid portal ID is available.
   */
  function resolveHubSpotScriptUrl() {
    const configuredUrl = config.hubspotScriptUrl ? String(config.hubspotScriptUrl) : '';
    if (configuredUrl && !/\/forms\/v2\.js(?:[?#]|$)/i.test(configuredUrl)) return configuredUrl;

    const frame = document.querySelector('.hs-form-frame[data-portal-id]');
    if (!frame && !config.hubspotPortalId) {
      if (config.debug === true && typeof console !== 'undefined' && console.warn) {
        console.warn('NUVANX HubSpot: No portal ID available (config.hubspotPortalId and embedded frame missing). HubSpot forms will not load.');
      }
      return '';
    }

    // Enforce single-identity constraint: warn if multiple HubSpot frames with identity detected
    const identityFrames = document.querySelectorAll('.hs-form-frame[data-portal-id][data-form-id]');
    if (identityFrames.length > 1 && config.debug === true && typeof console !== 'undefined' && console.warn) {
      console.warn('NUVANX HubSpot: Multiple frames with HubSpot identity attributes detected (' + identityFrames.length + '). This may cause duplicate embed initialization.');
    }

    const regionStr = config.hubspotRegion || (frame ? frame.dataset.region : 'eu1');
    const region = String(regionStr || 'eu1').replace(/[^a-z0-9-]/gi, '') || 'eu1';
    const portalValue = config.hubspotPortalId || (frame ? frame.dataset.portalId : '');
    const portalId = String(portalValue || '').replace(/[^0-9]/g, '');
    if (!portalId) return '';

    // The portal embed owns declarative .hs-form-frame mounts. Loading the
    // legacy v2 factory and then calling hbspt.forms.create() on those same
    // nodes initializes the canonical form more than once.
    return 'https://js-' + region + '.hsforms.net/forms/embed/' + portalId + '.js';
  }

  function removeLegacyHubSpotV2Scripts() {
    document.querySelectorAll('script[src*="forms/v2.js"]').forEach(function (script) {
      script.remove();
    });
  }

  function applyHubSpotFrameIdentity(frame, identity) {
    if (!frame) return;
    frame.classList.add('hs-form-frame');
    if (identity.region) frame.dataset.region = identity.region;
    if (identity.portalId) frame.dataset.portalId = identity.portalId;
    if (identity.formId) frame.dataset.formId = identity.formId;
    frame.dataset.nvxHubspotLazy = '1';
    // WCAG 4.1.2: Ensure iframe has accessible title for screen readers
    if (!frame.getAttribute('title')) {
      frame.setAttribute('title', 'Formulario de contacto NUVANX - Valoración médica');
    }
  }

  function normalizeNativeHubSpotMounts() {
    const hosts = document.querySelectorAll('#nvx-hubspot-native-form, [data-nvx-hubspot-native="1"]');
    hosts.forEach(function (host) {
      const identity = {
        region: String(config.hubspotRegion || host.dataset.region || 'eu1').replace(/[^a-z0-9-]/gi, '') || 'eu1',
        portalId: String(config.hubspotPortalId || host.dataset.portalId || '').replace(/[^0-9]/g, ''),
        formId: String(config.hubspotFormId || host.dataset.formId || '').trim(),
      };

      removeAttributes(host, [
        'data-form-id',
        'data-portal-id',
        'data-region',
        'data-hs-initialized',
        'data-hs-forms-root',
        'data-nvx-hubspot-lazy',
        'aria-label',
      ]);

      const frames = Array.prototype.slice.call(host.querySelectorAll('.hs-form-frame'));
      let canonical = frames.find(function (frame) {
        return frame.parentElement === host && frame.dataset.portalId && frame.dataset.formId;
      }) || frames.find(function (frame) {
        return frame.dataset.portalId && frame.dataset.formId;
      }) || frames[0] || null;

      if (!canonical && identity.portalId && identity.formId) {
        canonical = document.createElement('div');
        canonical.className = 'hs-form-frame';
        const skeleton = host.querySelector('.nvx-skeleton-wrapper');
        if (skeleton && skeleton.nextSibling) host.insertBefore(canonical, skeleton.nextSibling);
        else host.appendChild(canonical);
      }

      if (!canonical) return;
      if (canonical.parentElement !== host) host.insertBefore(canonical, host.firstChild);
      applyHubSpotFrameIdentity(canonical, identity);

      Array.prototype.slice.call(host.querySelectorAll('.hs-form-frame')).forEach(function (frame) {
        if (frame !== canonical) frame.remove();
      });
    });
  }

  /**
   * Lazily loads HubSpot Forms when an eligible modal or page form mount is activated.
   *
   * Reuses an existing or in-progress script load, initializes available forms after loading,
   * and retries after load failures.
   */
  function initGlobalHubSpotTracking() {
    // Global tracking initialization (runs on all pages)
    // Respect persisted consent on first load; later changes use event listeners
    if (hasMarketingConsent()) {
      loadHubSpotGlobalTracking();
    }
    
    // Listen for consent changes
    document.addEventListener('cmplz_enable_category', function () {
      loadHubSpotGlobalTracking();
    });
    document.addEventListener('cmplz_status_change', function () {
      loadHubSpotGlobalTracking();
    });
  }

  function initLazyHubSpot() {
    removeLegacyHubSpotV2Scripts();
    normalizeNativeHubSpotMounts();



    const scriptUrl = resolveHubSpotScriptUrl();
    if (!scriptUrl) return;

    const modal = document.getElementById(config.modalId || 'nvx-valoracion-modal');
    const pageFrames = document.querySelectorAll(
      '.hs-form-frame[data-nvx-hubspot-lazy="1"], #nvx-hubspot-native-form .hs-form-frame, [data-nvx-hubspot-native="1"] .hs-form-frame'
    );
    const hasPageMount = config.hubspotPageMount !== false && pageFrames.length > 0;
    const hasModal = Boolean(config.modalEnabled && modal);

    if (!hasModal && !hasPageMount) return;

    let promise = null;

    function reportHubSpotError(scope, error, hookName) {
      if (config.debug !== true || !window.console || typeof window.console.warn !== 'function') return;
      const errorName = safeErrorName(error);
      if (hookName) window.console.warn('NUVANX ' + scope, hookName, errorName);
      else window.console.warn('NUVANX ' + scope, errorName);
    }

    function dispatchRuntimeError(eventName, detail) {
      try {
        document.dispatchEvent(new CustomEvent(eventName, { detail: detail }));
      } catch (error) {
        reportHubSpotError('monitoring event failed', error, eventName);
      }
    }

    function reportAttributionHookError(error, hookName) {
      reportHubSpotError('attribution hook failed', error, hookName);
      dispatchRuntimeError('nvx:attribution-hook-error', {
        hook: hookName,
        error_name: safeErrorName(error)
      });
    }

    function reportHubSpotInitError(error, formId) {
      reportHubSpotError('HubSpot form initialization failed', error);
      dispatchRuntimeError('nvx:hubspot-init-error', {
        error_name: safeErrorName(error),
        form_id: String(formId || '').slice(0, 64)
      });
    }

    /** Invoke an optional attribution hook without allowing sync or async failures to escape into HubSpot. */
    function invokeLegacyAttributionHook(hookName, form, formId) {
      try {
        const hooks = window.NUVANXGoogleAttributionLegacy;
        if (!hooks || typeof hooks[hookName] !== 'function') return;
        const result = hooks[hookName](form, formId);
        if (result && typeof result.then === 'function') {
          result.catch(function (error) {
            reportAttributionHookError(error, hookName);
          });
        }
      } catch (error) {
        reportAttributionHookError(error, hookName);
      }
    }

    let hubspotFormReady = false;

    function markHubSpotReady() {
      hubspotFormReady = true;
      syncConversionSurfaces();
    }

    function isHubSpotFormMessage(data) {
      if (!data) return false;
      if (typeof data === 'string') {
        try { data = JSON.parse(data) || {}; } catch (error) { return false; }
      }
      if (typeof data !== 'object') return false;
      const eventName = String(data.eventName || data.type || '').toLowerCase();
      return data.type === 'hsFormCallback' && (eventName === 'onformready' || eventName === 'onbeforesubmit' || eventName === 'onformsubmitted');
    }

    function isHubSpotRenderable(root) {
      if (!root) return false;
      if (root.querySelector('.hbspt-form input, .hbspt-form textarea, .hs-form input')) return true;
      if (!hubspotFormReady) return false;
      const iframes = root.querySelectorAll('iframe');
      for (let i = 0; i < iframes.length; i++) {
        const iframe = iframes[i];
        const src = String(iframe.getAttribute('src') || '').trim();
        if (!src || src === 'about:blank') continue;
        const category = String(iframe.dataset.category || '').toLowerCase();
        if (category === 'marketing' && !hasMarketingConsent()) continue;
        try {
          const host = new URL(src, window.location.href).hostname.toLowerCase();
          if (host.indexOf('hsforms') !== -1 || host.indexOf('hubspot') !== -1) return true;
        } catch (error) {
          // Ignore malformed iframe src values.
        }
      }
      return false;
    }

    function directFormIsDirty(form) {
      if (!form) return false;
      const fields = form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="checkbox"]), textarea');
      for (let i = 0; i < fields.length; i++) {
        if (String(fields[i].value || '').trim()) return true;
      }
      return false;
    }

    function syncConversionSurfaces() {
      const hosts = document.querySelectorAll('#nvx-hubspot-native-form, [data-nvx-hubspot-native="1"], #nvx-valoracion-modal-form');
      hosts.forEach(function (host) {
        const live = isHubSpotRenderable(host);
        const form = host.querySelector('[data-nvx-direct-form]');
        if (live && !directFormIsDirty(form)) host.classList.add('nvx-hubspot-is-live');
        else host.classList.remove('nvx-hubspot-is-live');
      });
    }

    function hasActiveHubSpotEmbed() {
      const scripts = document.querySelectorAll('script[src*="forms/embed/"]');
      for (let i = 0; i < scripts.length; i++) {
        const script = scripts[i];
        const type = String(script.getAttribute('type') || 'text/javascript').toLowerCase();
        if (type === 'text/plain') continue;
        if (String(script.dataset.category || '').toLowerCase() === 'marketing' && !hasMarketingConsent()) {
          continue;
        }
        return true;
      }
      return false;
    }

    function isHubSpotIframe(ifr) {
      if (!ifr || ifr.tagName !== 'IFRAME') return false;
      if (ifr.classList.contains('hs-form-iframe') || Boolean(ifr.closest('.hs-form-frame'))) {
        return true;
      }
      const rawSrc = String(ifr.getAttribute('src') || '').trim();
      const inModalContainer = Boolean(ifr.closest('#nvx-hubspot-form, #nvx-valoracion-modal'));
      if (inModalContainer && (!rawSrc || rawSrc === 'about:blank')) {
        return true;
      }
      if (!rawSrc) return false;
      try {
        const parsed = new URL(rawSrc, window.location.href);
        const host = parsed.hostname.toLowerCase();
        return host === 'hsforms.com' || host.endsWith('.hsforms.com') ||
               host === 'hsforms.net' || host.endsWith('.hsforms.net') ||
               host === 'forms.hubspot.com' || host === 'forms-eu1.hubspot.com' ||
               /^forms(-[a-z0-9]+)?\.hubspot\.com$/.test(host);
      } catch {
        return false;
      }
    }

    function enforceAccessibleIframeTitles() {
      const iframes = document.querySelectorAll(
        'iframe.hs-form-iframe, .hs-form-frame iframe, #nvx-hubspot-form iframe, #nvx-valoracion-modal iframe, iframe[src*="hsforms"], iframe[src*="hubspot"]'
      );
      iframes.forEach(function (ifr) {
        if (!isHubSpotIframe(ifr)) return;

        const currentTitle = ifr.getAttribute('title');
        // iframe has a native accessible-name mechanism via title. Keep that
        // single source of truth and remove aria-label, which Axe flags as a
        // prohibited ARIA attribute for the embedded HubSpot frame role.
        if (ifr.hasAttribute('aria-label')) ifr.removeAttribute('aria-label');
        if (!currentTitle || currentTitle === 'Form' || currentTitle.toLowerCase() === 'hubspot form' || currentTitle.toLowerCase() === 'hs-form-iframe') {
          ifr.setAttribute('title', 'Formulario de valoración médica');
        }
      });
    }

    /** Complete presentation state after the declarative HubSpot runtime loads. */
    function initializeForms() {
      normalizeNativeHubSpotMounts();
      if (modal) modal.classList.remove('nvx-valoracion-modal--embed-error');

      // Inject HubSpot identity into modal frame (presentation host only)
      const modalFrame = modal ? modal.querySelector('.hs-form-frame') : null;
      if (modalFrame && !modalFrame.dataset.portalId) {
        const region = modalConfig.hubspotRegion || config.hubspotRegion || 'eu1';
        const portalId = modalConfig.hubspotPortalId || config.hubspotPortalId || '';
        const formId = modalConfig.hubspotFormId || config.hubspotFormId || '';
        if (portalId) {
          modalFrame.dataset.region = region;
          modalFrame.dataset.portalId = portalId;
          modalFrame.dataset.formId = formId;
          modalFrame.dataset.nvxHubspotLazy = '1';
        }
      }

      enforceAccessibleIframeTitles();
      syncConversionSurfaces();
    }

    function loadHubSpot() {
      // The legacy v2 factory created window.hbspt.forms, but the new portal embed
      // (forms/embed/{portalId}.js) does not. Only short-circuit if we have the
      // script element already loaded, not just the global object.
      removeLegacyHubSpotV2Scripts();
      if (hasActiveHubSpotEmbed()) {
        initializeForms();
        return Promise.resolve();
      }
      if (promise) return promise;

      promise = new Promise(function (resolve, reject) {
        const scriptId = config.hubspotScriptId || 'nvx-hubspot-forms-runtime';
        const existing = document.getElementById(scriptId);
        if (existing) {
          if (existing.dataset.nvxLoaded === '1') {
            initializeForms();
            resolve();
            return;
          }

          existing.addEventListener('load', function () {
            existing.dataset.nvxLoaded = '1';
            initializeForms();
            resolve();
          }, { once: true });
          existing.addEventListener('error', function () {
            existing.remove();
            const fallbacks = document.querySelectorAll('.nvx-hubspot-fallback');
            fallbacks.forEach(function(fb) { fb.style.display = 'block'; });
            const skeletons = document.querySelectorAll('.nvx-skeleton-wrapper');
            skeletons.forEach(function(sk) { sk.style.display = 'none'; });
            reject(new Error('Existing HubSpot form embed failed to load.'));
          }, { once: true });
          return;
        }

        const script = document.createElement('script');
        script.id = scriptId;
        script.src = scriptUrl;
        script.async = true;
        script.addEventListener('load', function () {
          script.dataset.nvxLoaded = '1';
          initializeForms();
          resolve();
        }, { once: true });
        script.addEventListener('error', function () {
          script.remove();
          if (modal) modal.classList.add('nvx-valoracion-modal--embed-error');
          const fallbacks = document.querySelectorAll('.nvx-hubspot-fallback');
          fallbacks.forEach(function(fb) { fb.style.display = 'block'; });
          const skeletons = document.querySelectorAll('.nvx-skeleton-wrapper');
          skeletons.forEach(function(sk) { sk.style.display = 'none'; });
          reject(new Error('HubSpot form embed failed to load.'));
        }, { once: true });
        document.head.appendChild(script);
      });

      promise.catch(function (error) {
        // Allow a later retry. The modal retains its full-page fallback link.
        promise = null;
        const fallbacks = document.querySelectorAll('.nvx-hubspot-fallback');
        fallbacks.forEach(function(fb) { fb.style.display = 'block'; });
        const skeletons = document.querySelectorAll('.nvx-skeleton-wrapper');
        skeletons.forEach(function(sk) { sk.style.display = 'none'; });
        reportHubSpotError('HubSpot form runtime failed to load', error);
      });
      return promise;
    }

    if (hasModal) {
      function modalIsOpen() {
        return modal.hasAttribute('open');
      }

      new MutationObserver(function () {
        if (modalIsOpen()) loadHubSpot();
      }).observe(modal, {
        attributes: true,
        attributeFilter: ['open']
      });

      document.addEventListener(
        'click',
        function (event) {
          const link = (event.target && event.target.closest) ? event.target.closest('a') : null;
          if (!link) return;
          if (
            link.classList.contains('nvx-open-valoracion-modal') ||
            link.dataset.nvxValoracionModal === '1'
          ) {
            loadHubSpot();
          }
        },
        true
      );
    }

    if (hasPageMount) {
      // Dedicated form routes never ship an eager server-rendered HubSpot script.
      // Load on explicit intent (click/focus/CTA) or when the mount enters the viewport.
      let activated = false;
      const activate = function () {
        if (activated) return;
        activated = true;
        loadHubSpot();
      };

      pageFrames.forEach(function (frame) {
        const host =
          frame.closest('#nvx-hubspot-form, #nvx-hubspot-native-form, .nvx-hubspot-form-section, .nvx-form-stage') ||
          frame.parentElement ||
          frame;
        host.addEventListener('click', activate, { once: true, passive: true });
        host.addEventListener('focusin', activate, { once: true });

        if (typeof IntersectionObserver === 'function') {
          const observer = new IntersectionObserver(
            function (entries) {
              entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                  activate();
                  observer.disconnect();
                }
              });
            },
            { rootMargin: '120px 0px', threshold: 0.05 }
          );
          observer.observe(frame);
        }
      });

      document.addEventListener(
        'click',
        function (event) {
          const link = (event.target && event.target.closest) ? event.target.closest('a[href*="#nvx-hubspot-form"]') : null;
          if (link) activate();
        },
        true
      );
    }

    syncConversionSurfaces();
    window.addEventListener('hs-form-event:on-ready', markHubSpotReady);
    window.addEventListener('message', function (event) {
      // Security: Verify message origin to prevent potential XSS attacks
      // Only accept messages from HubSpot's domains or same origin
      if (!event.origin) return;
      try {
        var host = new URL(event.origin).hostname.toLowerCase();
        var isHubSpot = /(^|\.)((hubspot|hsforms|hs-scripts)\.com|hsforms\.net)$/.test(host) || event.origin === window.location.origin;
        if (!isHubSpot) return;
      } catch (_e) { return; }

      if (isHubSpotFormMessage(event && event.data)) markHubSpotReady();
    });

    if (typeof MutationObserver === 'function') {
      // Coalesce bursts of DOM mutations into a single title pass per frame:
      // the observer fires on every insertion across document.body, so running
      // the query synchronously each time is wasteful on form-heavy pages.
      // Schedule via requestAnimationFrame when available, but always keep a
      // setTimeout floor so the pass still runs in background/hidden tabs where
      // rAF is throttled or suspended (e.g. an automated a11y audit).
      let titlePassScheduled = false;
      const hasRaf = typeof window.requestAnimationFrame === 'function';

      new MutationObserver(function (mutations) {
        let shouldRun = false;
        for (const m of mutations) {
          if (m.type === 'childList') {
            for (const node of m.addedNodes) {
              if (node && node.nodeType === 1 && (node.tagName === 'IFRAME' || (node.querySelector && node.querySelector('iframe')))) {
                shouldRun = true;
                break;
              }
            }
            if (shouldRun) break;
          } else if (m.type === 'attributes') {
            const target = m.target;
            if (target && target.tagName === 'IFRAME' && isHubSpotIframe(target)) {
              const currentTitle = target.getAttribute('title');
              const hasAria = target.hasAttribute('aria-label');
              const isCompliant = !hasAria && currentTitle === 'Formulario de valoración médica';
              if (!isCompliant) {
                shouldRun = true;
                break;
              }
            }
          }
        }
        if (!shouldRun) return;

        if (titlePassScheduled) return;
        titlePassScheduled = true;

        let rafId = 0;
        const runTitlePass = function () {
          if (!titlePassScheduled) return;
          titlePassScheduled = false;
          if (rafId && typeof window.cancelAnimationFrame === 'function') {
            window.cancelAnimationFrame(rafId);
          }
          window.clearTimeout(timeoutId);
          enforceAccessibleIframeTitles();
          syncConversionSurfaces();
        };

        const timeoutId = window.setTimeout(runTitlePass, 100);
        if (hasRaf) rafId = window.requestAnimationFrame(runTitlePass);
      }).observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['aria-label', 'title'],
      });
    }
  }

  function initHeroVideoGovernance() {
    const video = document.getElementById('nvx-home-hero-video') || document.querySelector('.nvx-home-hero__video');
    const toggleBtn = document.getElementById('nvx-hero-video-toggle') || document.querySelector('.nvx-home-hero__video-toggle');
    if (!video) return;

    // 1. Respect prefers-reduced-motion
    const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    function handleMotionPreference(e) {
      if (e.matches) {
        video.pause();
        if (toggleBtn) {
          toggleBtn.setAttribute('aria-pressed', 'true');
          toggleBtn.setAttribute('aria-label', 'Reproducir vídeo de fondo');
          const icon = toggleBtn.querySelector('.nvx-video-toggle__icon');
          if (icon) icon.textContent = '▶';
        }
      }
    }

    if (mediaQuery.matches) {
      video.pause();
      if (toggleBtn) {
        toggleBtn.setAttribute('aria-pressed', 'true');
        toggleBtn.setAttribute('aria-label', 'Reproducir vídeo de fondo');
        const icon = toggleBtn.querySelector('.nvx-video-toggle__icon');
        if (icon) icon.textContent = '▶';
      }
    }

    if (typeof mediaQuery.addEventListener === 'function') {
      mediaQuery.addEventListener('change', handleMotionPreference);
    } else if (typeof mediaQuery.addListener === 'function') {
      mediaQuery.addListener(handleMotionPreference);
    }

    // 2. Interactive toggle button (WCAG 2.2.2 Pause/Stop/Hide)
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        if (video.paused) {
          video.play().then(function () {
            toggleBtn.setAttribute('aria-pressed', 'false');
            toggleBtn.setAttribute('aria-label', 'Pausar vídeo de fondo');
            const icon = toggleBtn.querySelector('.nvx-video-toggle__icon');
            if (icon) icon.textContent = '⏸';
          }).catch(function () {});
        } else {
          video.pause();
          toggleBtn.setAttribute('aria-pressed', 'true');
          toggleBtn.setAttribute('aria-label', 'Reproducir vídeo de fondo');
          const icon = toggleBtn.querySelector('.nvx-video-toggle__icon');
          if (icon) icon.textContent = '▶';
        }
      });
    }
  }

  function initialize() {
    initMobileNavigationGovernance();
    initValoracionModalGovernance();
    initGlobalHubSpotTracking();
    initLazyHubSpot();
    initHeroVideoGovernance();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
