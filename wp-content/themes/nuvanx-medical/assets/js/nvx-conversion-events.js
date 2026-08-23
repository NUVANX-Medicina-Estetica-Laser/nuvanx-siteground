(function () {
	'use strict';

	var config = window.nvxConversionEvents || {};
	var signalName = 'nvx_conversion_signal';
	var submissionWindowMs = 8000;
	var recentSubmissions = new Map();
	var recentFallbackConversionIds = new Map();
	var configuredCanonicalForm = String((config.forms || {}).valoracion || '').toLowerCase();
	var canonicalForm = configuredCanonicalForm || '';

	function cleanToken(value, fallback) {
		var token = String(value || '')
			.toLowerCase()
			.replace(/[^a-z0-9_-]+/g, '_');
		while (token.startsWith('_')) token = token.slice(1);
		while (token.endsWith('_')) token = token.slice(0, -1);
		token = token.slice(0, 80);
		return token || fallback || 'unknown';
	}

	function pagePath() {
		return window.location && window.location.pathname ? window.location.pathname : '/';
	}

	function regionFor(element) {
		if (!element || typeof element.closest !== 'function') return 'document';
		if (element.closest('[role="dialog"], .nvx-modal, .nvx-valoracion-modal')) return 'modal';
		if (element.closest('header, .site-header, .nvx-header')) return 'header';
		if (element.closest('footer, .site-footer, .nvx-footer')) return 'footer';
		if (element.closest('.nvx-brand-hero, .nvx-page-hero, [class*="hero"]')) return 'hero';
		if (element.closest('nav')) return 'navigation';
		return 'content';
	}

	function formContext(formId) {
		var normalized = String(formId || '').toLowerCase();
		var forms = config.forms || {};
		if (normalized && normalized === String(forms.valoracion || '').toLowerCase()) return 'valoracion';
		if (pagePath().indexOf('/madrid/valoracion/') === 0) return 'valoracion';
		return 'embedded_form';
	}

	function allowedParameters(parameters) {
		var output = {
			page_path: pagePath(),
			event_source: 'nuvanx_theme',
		};
		Object.keys(parameters || {}).forEach(function (key) {
			var value = parameters[key];
			if (value === undefined || value === null || value === '') return;
			output[cleanToken(key)] = typeof value === 'number' ? value : cleanToken(value);
		});
		return output;
	}


	function isWhatsAppHref(href) {
		return /(?:wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)/i.test(href || '');
	}

	function isJoinchatTarget(element) {
		return Boolean(element && element.closest && element.closest('.joinchat, .joinchat__button, [class*="joinchat"]'));
	}

	function emit(eventName, parameters) {
		var normalizedName = cleanToken(eventName);
		var params = allowedParameters(parameters);
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(Object.assign({ event: signalName, nvx_event_name: normalizedName }, params));
		document.dispatchEvent(new CustomEvent('nvx:conversion-event', {
			detail: Object.assign({ event_name: normalizedName }, params),
		}));
	}

	function trackClick(event) {
		var target = event.target && typeof event.target.closest === 'function'
			? event.target.closest('a, button, .joinchat, .joinchat__button')
			: null;
		if (!target) return;

		var href = target.getAttribute('href') || '';
		var dataEvent = target.dataset ? (target.dataset.gtag || '') : '';
		var common = { cta_region: regionFor(target), cta_marker: dataEvent || 'selector' };
		var isReserve = (target.matches && target.matches('[data-gtag="click-reserve"], .nvx-open-valoracion-modal'))
			|| href.indexOf('/madrid/valoracion/') !== -1;
		var isWhatsApp = (target.matches && target.matches('[data-gtag="click-whatsapp"]'))
			|| isWhatsAppHref(href)
			|| isJoinchatTarget(target);
		var isPhone = /^tel:/i.test(href);

		if (isReserve || isWhatsApp || isPhone) {
			if (pagePath().indexOf('/laser-co2-fraccionado-madrid/') !== -1) {
				emit('co2_treatment_click', Object.assign({ treatment_type: 'laser_co2' }, common));
			}
			if (pagePath().indexOf('/btl-exilite-ipl-madrid/') !== -1) {
				emit('exilite_treatment_click', Object.assign({ treatment_type: 'btl_exilite' }, common));
			}
		}

		if (isReserve) {
			emit('reserve_click', Object.assign({ contact_method: 'reservation' }, common));
			return;
		}
		if (isWhatsApp) {
			emit('whatsapp_click', Object.assign({ contact_method: 'whatsapp' }, common));
			return;
		}
		if (isPhone) {
			emit('phone_click', {
				contact_method: 'phone',
				cta_region: regionFor(target),
				cta_marker: dataEvent || 'tel_link',
			});
			return;
		}
	}

	function submissionKey(formId) {
		return cleanToken(formId, 'unknown_form') + '|' + pagePath();
	}

	function trackSuccessfulSubmission(formId, eventSource, conversionId) {
		var now = Date.now();
		var normalizedConversionId = String(conversionId || '');
		var fallbackKey = submissionKey(formId);
		var conversionKey = normalizedConversionId ? 'cid_' + normalizedConversionId : '';
		var fallbackPrevious = recentSubmissions.get(fallbackKey) || 0;
		var fallbackPreviousId = recentFallbackConversionIds.get(fallbackKey) || '';

		if (conversionKey && now - (recentSubmissions.get(conversionKey) || 0) < 3600000) return;
		if (now - fallbackPrevious < submissionWindowMs) {
			var distinctKnownConversions = Boolean(
				normalizedConversionId && fallbackPreviousId && normalizedConversionId !== fallbackPreviousId
			);
			if (!distinctKnownConversions) return;
		}

		recentSubmissions.set(fallbackKey, now);
		recentFallbackConversionIds.set(fallbackKey, normalizedConversionId);
		if (conversionKey) recentSubmissions.set(conversionKey, now);

		emit('generate_lead', {
			form_id: formId || 'unknown_form',
			form_context: formContext(formId),
			lead_source: 'hubspot_form',
			form_event_source: eventSource,
		});

		if (String(formId || '').toLowerCase() === canonicalForm) {
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push({ event: 'nvx_valoracion_success', form: 'valoracion', source: 'hubspot_native' });
		}
	}

	function isAllowedHubSpotOrigin(origin) {
		if (!origin || origin === 'null') return false;
		try {
			var host = new URL(origin).hostname.toLowerCase();
			return /(^|\.)(hubspot\.com|hsforms\.com|hsforms\.net)$/.test(host);
		} catch (_error) {
			return false;
		}
	}

	document.addEventListener('click', trackClick, true);

	window.addEventListener('hs-form-event:on-submission:success', function (event) {
		var detail = event && event.detail ? event.detail : {};
		if (window.NUVANXGoogleAttributionLegacy && typeof window.NUVANXGoogleAttributionLegacy.onBeforeFormSubmit === 'function') {
			try { window.NUVANXGoogleAttributionLegacy.onBeforeFormSubmit(null, detail.formId); } catch (_error) { /* non-fatal attribution hook */ }
		}
		var convId = '';
		if (window.HubSpotFormsV4 && typeof window.HubSpotFormsV4.getFormFromEvent === 'function') {
			try {
				var successfulForm = window.HubSpotFormsV4.getFormFromEvent(event);
				if (successfulForm && typeof successfulForm.getConversionId === 'function') convId = successfulForm.getConversionId() || '';
			} catch (_error) { /* non-fatal attribution hook */ }
		}
		trackSuccessfulSubmission(detail.formId || '', 'hubspot_form_event', convId);
		if (window.NUVANXGoogleAttributionLegacy && typeof window.NUVANXGoogleAttributionLegacy.onFormSubmitted === 'function') {
			try { window.NUVANXGoogleAttributionLegacy.onFormSubmitted(null, detail.formId); } catch (_error) { /* non-fatal attribution hook */ }
		}
	});

	window.addEventListener('message', function (event) {
		if (!isAllowedHubSpotOrigin(event.origin)) return;
		var data = event.data || {};
		if (typeof data === 'string') {
			try { data = JSON.parse(data) || {}; } catch (_error) { data = {}; }
		}
		if (typeof data !== 'object') data = {};
		if (data.type !== 'hsFormCallback' || data.eventName !== 'onFormSubmitted') return;
		var convIdMessage = data.data?.conversionId || '';
		trackSuccessfulSubmission(data.id || '', 'hubspot_post_message', convIdMessage);
	});

	window.NUVANXConversionEvents = Object.freeze({ emit: emit, trackSuccessfulSubmission: trackSuccessfulSubmission });
}());

(function () {
	'use strict';

	var attributionConfig = window.nvxConversionEvents || {};
	var forms = attributionConfig.forms || {};
	var FORM_ID = String(forms.valoracion || '').toLowerCase();
	var configEndpoint = String(attributionConfig.googleAttributionEndpoint || '');
	var ENDPOINT = /^https:\/\/[a-z0-9]+\.supabase\.co\/functions\/v1\//.test(configEndpoint)
		? configEndpoint
		: 'https://ssvvuuysgxyqvmovrlvk.supabase.co/functions/v1/google-click-attribution';
	var normalizedPath = String(window.location.pathname || '/').replace(/\/+$/, '') || '/';
	var eligiblePath = normalizedPath === '/madrid/valoracion'
		|| normalizedPath.indexOf('/endolift') === 0
		|| normalizedPath.indexOf('/endolaser') === 0
		|| normalizedPath.indexOf('/neuromoduladores') === 0
		|| normalizedPath.indexOf('/laser-co2') === 0
		|| normalizedPath.indexOf('/exion') === 0
		|| normalizedPath.indexOf('/btl-exilite') === 0
		|| normalizedPath.indexOf('/soluciones-medicas') === 0
		|| normalizedPath.indexOf('/protocolos-signature') === 0
		|| normalizedPath.indexOf('/contacto') === 0;
	var sent = false;
	var inFlight = false;
	var auditClaimed = false;
	var FIELD_MAP = {
		gclid: ['nvx_google_click_id', 'hs_google_click_id'],
		gbraid: ['nvx_google_braid'],
		wbraid: ['nvx_google_wbraid'],
		gclsrc: ['nvx_google_gclsrc'],
	};

	function cleanClickValue(value, maxLength) {
		var normalized = String(value || '').trim();
		if (!normalized || normalized.length > maxLength) return '';
		return /^[A-Za-z0-9._~:+*%/=\-]+$/.test(normalized) ? normalized : '';
	}

	function contractTouch(method) {
		var contract = window.NUVANXAttributionContract;
		if (!contract || typeof contract[method] !== 'function') return {};
		try { return contract[method]() || {}; } catch (_error) { return {}; }
	}

	function getNvxLeadId() {
		var contract = window.NUVANXAttributionContract;
		if (!contract || typeof contract.getLeadId !== 'function') return '';
		try {
			var id = String(contract.getLeadId() || '').trim().toLowerCase();
			return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(id) ? id : '';
		} catch (_error) {
			return '';
		}
	}

	function collectClickValues() {
		var params = new URLSearchParams(window.location.search || '');
		var conversion = contractTouch('getConversionTouch');
		var first = contractTouch('getFirstTouch');
		return {
			gclid: cleanClickValue(params.get('gclid') || conversion.gclid || first.gclid, 512),
			gbraid: cleanClickValue(params.get('gbraid') || conversion.gbraid || first.gbraid, 512),
			wbraid: cleanClickValue(params.get('wbraid') || conversion.wbraid || first.wbraid, 512),
			gclsrc: cleanClickValue(params.get('gclsrc') || conversion.gclsrc || first.gclsrc, 128),
		};
	}

	var clickValues = collectClickValues();

	function hasGoogleClickIdentifier(values) {
		return Boolean(values && (values.gclid || values.gbraid || values.wbraid));
	}

	function hasMarketingConsent() {
		try {
			if (typeof window.cmplz_has_consent === 'function') return window.cmplz_has_consent('marketing') === true;
			if (typeof window.wp_has_consent === 'function') return window.wp_has_consent('marketing') === true;
		} catch (_error) { return false; }
		return false;
	}

	window.NUVANXGoogleAttributionQA = Object.freeze({
		eligiblePath: eligiblePath,
		hasClickId: hasGoogleClickIdentifier(clickValues),
		clickTypes: ['gclid', 'gbraid', 'wbraid'].filter(function (key) { return Boolean(clickValues[key]); }),
		marketingConsent: hasMarketingConsent,
	});

	var isAttributionEligible = eligiblePath && hasGoogleClickIdentifier(clickValues);

	function isCanonicalForm(form) {
		if (!form || typeof form.getFormId !== 'function') return false;
		try { return String(form.getFormId() || '').toLowerCase() === FORM_ID; } catch (_error) { return false; }
	}

	function fieldCandidates(propertyName) {
		return ['0-1/' + propertyName, propertyName];
	}

	function syncOwnsCanonicalHubSpotFields() {
		return Boolean(window.NUVANXHubSpotAttributionSync && typeof window.NUVANXHubSpotAttributionSync.syncForm === 'function');
	}

	async function populateHubSpotClickFields(form) {
		if (!isAttributionEligible) return false;
		if (syncOwnsCanonicalHubSpotFields()) return false;
		if (!isCanonicalForm(form)) return false;
		if (typeof form.getFormFieldValues !== 'function' || typeof form.setFieldValue !== 'function') return false;
		var consent = hasMarketingConsent();
		var fields;
		try { fields = await form.getFormFieldValues(); } catch (_error) { return false; }
		if (!Array.isArray(fields)) return false;
		var availableNames = new Set(fields.map(function (field) {
			return field && typeof field.name === 'string' ? field.name : '';
		}).filter(Boolean));
		var modified = false;

		Object.keys(FIELD_MAP).forEach(function (param) {
			var value = consent ? clickValues[param] : '';
			if (!value && consent) return;
			FIELD_MAP[param].forEach(function (propertyName) {
				if (!consent && propertyName.indexOf('nvx_') !== 0) return;
				fieldCandidates(propertyName).forEach(function (fieldName) {
					if (!availableNames.has(fieldName)) return;
					try {
						form.setFieldValue(fieldName, value);
						modified = true;
					} catch (_error) { /* non-fatal field-sync */ }
				});
			});
		});
		return modified;
	}

	function populateExistingForms() {
		if (syncOwnsCanonicalHubSpotFields()) return;
		clickValues = collectClickValues();
		if (!window.HubSpotFormsV4 || typeof window.HubSpotFormsV4.getForms !== 'function') return;
		try {
			(window.HubSpotFormsV4.getForms() || []).forEach(function (form) { populateHubSpotClickFields(form); });
		} catch (_error) { /* non-fatal */ }
	}

	window.addEventListener('hs-form-event:on-ready', function (event) {
		var detail = event && event.detail ? event.detail : {};
		if (String(detail.formId || '').toLowerCase() !== FORM_ID) return;
		if (!window.HubSpotFormsV4 || typeof window.HubSpotFormsV4.getFormFromEvent !== 'function') return;
		try {
			var form = window.HubSpotFormsV4.getFormFromEvent(event);
			if (!syncOwnsCanonicalHubSpotFields()) {
				populateHubSpotClickFields(form);
			}
			if (window.NUVANXGoogleAttributionLegacy && typeof window.NUVANXGoogleAttributionLegacy.onFormReady === 'function') {
				try { window.NUVANXGoogleAttributionLegacy.onFormReady(form, detail.formId); } catch (_error) { /* non-fatal attribution hook */ }
			}
		} catch (_error) { /* non-fatal */ }
	});

	if (!syncOwnsCanonicalHubSpotFields()) {
		document.addEventListener('wp_listen_for_consent_change', populateExistingForms);
		document.addEventListener('wp_consent_type_defined', populateExistingForms);
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', populateExistingForms, { once: true });
		else populateExistingForms();
	}

	function canonicalLandingUrl() {
		try {
			var current = new URL(window.location.href);
			return current.origin + current.pathname;
		} catch (_error) { return ''; }
	}

	function normalizeEmail(value) {
		return String(value || '').trim().toLowerCase();
	}

	function bytesToHex(buffer) {
		return Array.from(new Uint8Array(buffer)).map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
	}

	async function sha256(value) {
		if (!window.crypto || !window.crypto.subtle || typeof TextEncoder === 'undefined') return '';
		try {
			var digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
			return bytesToHex(digest);
		} catch (_error) { return ''; }
	}

	function createSubmissionId() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
		if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
			var bytes = new Uint8Array(16);
			window.crypto.getRandomValues(bytes);
			bytes[6] = (bytes[6] & 0x0f) | 0x40;
			bytes[8] = (bytes[8] & 0x3f) | 0x80;
			var hex = Array.from(bytes).map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
			return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
		}
		return '';
	}

	function getFieldValue(fields, propertyName) {
		var names = fieldCandidates(propertyName);
		for (var index = 0; index < fields.length; index += 1) {
			var field = fields[index] || {};
			if (names.indexOf(String(field.name || '')) === -1) continue;
			if (Array.isArray(field.value)) return String(field.value[0] || '');
			return String(field.value || '');
		}
		return '';
	}

	async function buildAuditPayload(event) {
		var fields = null;
		var detail = event && event.detail ? event.detail : {};
		if (Array.isArray(detail.data)) fields = detail.data;
		else if (Array.isArray(detail.submissionValues)) fields = detail.submissionValues;

		if (!fields) {
			if (!window.HubSpotFormsV4 || typeof window.HubSpotFormsV4.getFormFromEvent !== 'function') return null;
			var form;
			try { form = window.HubSpotFormsV4.getFormFromEvent(event); } catch (_error) { return null; }
			if (!isCanonicalForm(form) || typeof form.getFormFieldValues !== 'function') return null;
			try { fields = await form.getFormFieldValues(); } catch (_error) { return null; }
		}
		if (!Array.isArray(fields) || !hasMarketingConsent()) return null;

		var email = normalizeEmail(getFieldValue(fields, 'email'));
		if (!email || email.length > 320 || email.indexOf('@') <= 0) return null;
		var emailHash = await sha256(email);
		if (!/^[0-9a-f]{64}$/.test(emailHash) || !hasMarketingConsent()) return null;
		clickValues = collectClickValues();

		return {
			submission_id: createSubmissionId() || null,
			nvx_lead_id: getNvxLeadId() || null,
			email_hash: emailHash,
			gclid: clickValues.gclid || null,
			gbraid: clickValues.gbraid || null,
			wbraid: clickValues.wbraid || null,
			gclsrc: clickValues.gclsrc || null,
			form_id: FORM_ID,
			landing_url: canonicalLandingUrl(),
		};
	}

	function claimAudit() {
		if (sent || inFlight || auditClaimed || !hasMarketingConsent()) return false;
		auditClaimed = true;
		return true;
	}

	function releaseAuditClaim() {
		auditClaimed = false;
		var pending = legacyPendingSubmission;
		if (pending && pending.successSeen && pending.emailHash && !sent && !legacyRetryTimer && pending.retryCount < 3) {
			scheduleLegacyRetry(pending, false);
		}
	}

	async function transmitAudit(payload) {
		if (sent || inFlight || !payload || !hasMarketingConsent()) return false;
		inFlight = true;
		try {
			var response = await window.fetch(ENDPOINT, {
				method: 'POST',
				mode: 'cors',
				credentials: 'omit',
				cache: 'no-store',
				referrerPolicy: 'strict-origin-when-cross-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
				keepalive: true,
			});
			if (response.ok || (response.status >= 400 && response.status < 500 && response.status !== 429)) {
				sent = true;
				return true;
			}
			return false;
		} catch (_error) {
			return false;
		} finally {
			inFlight = false;
		}
	}

	var legacyFormRoots = [];
	var legacyPendingSubmission = null;
	var legacyEmailClearTimer = null;
	var legacyRetryTimer = null;
	var legacyNativeGclidInputs = new WeakSet();

	function clearLegacyPendingSubmission(expectedPending) {
		if (expectedPending && legacyPendingSubmission !== expectedPending) return;
		legacyPendingSubmission = null;
		if (legacyEmailClearTimer) window.clearTimeout(legacyEmailClearTimer);
		if (legacyRetryTimer) window.clearTimeout(legacyRetryTimer);
		legacyEmailClearTimer = null;
		legacyRetryTimer = null;
	}

	function legacyFormRoot(formLike) {
		var root = formLike;
		try {
			if (root && root.nodeType === 1) { /* already a DOM element */ }
			else if (root && typeof root.get === 'function') root = root.get(0);
			else if (root && root.jquery && root[0]) root = root[0];
			else if (root && root[0] && root[0].nodeType === 1) root = root[0];
		} catch (_error) { return null; }
		return (root && root.nodeType === 1 && typeof root.querySelector === 'function') ? root : null;
	}

	function legacyFieldInput(root, propertyName) {
		if (!root) return null;
		for (const name of fieldCandidates(propertyName)) {
			const input = root.querySelector('[name="' + name + '"]');
			if (input) return input;
		}
		return null;
	}

	function setLegacyField(root, propertyName, value, inputArg) {
		var input = inputArg || legacyFieldInput(root, propertyName);
		if (!input) return false;
		var nextValue = String(value || '');
		try {
			if (String(input.value || '') === nextValue) {
				if (nextValue) input.setAttribute('value', nextValue);
				else input.removeAttribute('value');
				return false;
			}
			var prototype = Object.getPrototypeOf(input);
			var descriptor = prototype ? Object.getOwnPropertyDescriptor(prototype, 'value') : null;
			if (descriptor && typeof descriptor.set === 'function') descriptor.set.call(input, nextValue);
			else input.value = nextValue;
			if (nextValue) input.setAttribute('value', nextValue);
			else input.removeAttribute('value');
			input.dispatchEvent(new Event('input', { bubbles: true }));
			input.dispatchEvent(new Event('change', { bubbles: true }));
			return true;
		} catch (_error) { return false; }
	}

	function canonicalLegacyRoot(formLike, formId) {
		if (formId !== undefined && String(formId || '').toLowerCase() !== FORM_ID) return null;
		var root = legacyFormRoot(formLike);
		if (!root) return null;
		var frame = typeof root.closest === 'function' ? root.closest('[data-form-id]') : null;
		if (frame && String(frame.dataset.formId || '').toLowerCase() !== FORM_ID) return null;
		return root;
	}

	function populateLegacyClickFields(formLike, formId) {
		if (!isAttributionEligible) return false;
		var root = canonicalLegacyRoot(formLike, formId);
		if (!root) return false;
		if (legacyFormRoots.indexOf(root) === -1) legacyFormRoots.push(root);
		clickValues = collectClickValues();
		var consent = hasMarketingConsent();
		var modified = false;
		Object.keys(FIELD_MAP).forEach(function (param) {
			var value = consent ? clickValues[param] : '';
			if (!value && consent) return;
			FIELD_MAP[param].forEach(function (propertyName) {
				var input = legacyFieldInput(root, propertyName);
				if (!consent && propertyName.indexOf('nvx_') !== 0) {
					if (propertyName === 'hs_google_click_id' && input && legacyNativeGclidInputs.has(input)) {
						var cleared = setLegacyField(root, propertyName, '', input);
						modified = cleared || modified;
						if (cleared || String(input.value || '') === '') legacyNativeGclidInputs.delete(input);
					}
					return;
				}
				var wrote = setLegacyField(root, propertyName, value, input);
				modified = wrote || modified;
				if (consent && propertyName === 'hs_google_click_id' && value && input) legacyNativeGclidInputs.add(input);
			});
		});
		return modified;
	}

	function refreshLegacyForms() {
		if (!hasMarketingConsent()) clearLegacyPendingSubmission();
		legacyFormRoots = legacyFormRoots.filter(function (root) { return root ? root.isConnected : false; });
		legacyFormRoots.forEach(function (root) { populateLegacyClickFields(root); });
	}

	async function captureLegacyEmail(formLike, formId) {
		populateLegacyClickFields(formLike, formId);
		if (!hasMarketingConsent()) {
			clearLegacyPendingSubmission();
			return;
		}
		var root = canonicalLegacyRoot(formLike, formId);
		if (!root) return;
		clearLegacyPendingSubmission();
		var emailInput = legacyFieldInput(root, 'email');
		if (!emailInput) return;
		var email = normalizeEmail(emailInput.value);
		if (!email || email.length > 320 || email.indexOf('@') <= 0) return;

		var pending = {
			root: root,
			submissionId: createSubmissionId(),
			nvxLeadId: getNvxLeadId(),
			emailHash: '',
			retryCount: 0,
			successSeen: false,
		};
		legacyPendingSubmission = pending;
		legacyEmailClearTimer = window.setTimeout(function () { clearLegacyPendingSubmission(pending); }, 30000);

		var emailHash = await sha256(email);
		email = '';
		if (legacyPendingSubmission !== pending) return;
		if (!/^[0-9a-f]{64}$/.test(emailHash) || !hasMarketingConsent()) {
			clearLegacyPendingSubmission(pending);
			return;
		}
		pending.emailHash = emailHash;
		if (pending.successSeen) transmitLegacySuccess(pending.root, FORM_ID);
	}

	function scheduleLegacyRetry(pendingArg, consumeBudget) {
		var pending = pendingArg || legacyPendingSubmission;
		if (!pending || legacyPendingSubmission !== pending || legacyRetryTimer || sent) return;
		if (!pending.successSeen || !pending.emailHash) return;
		var shouldConsume = consumeBudget !== false;
		if (shouldConsume && pending.retryCount >= 3) return;
		if (shouldConsume) pending.retryCount += 1;
		var delay = shouldConsume ? pending.retryCount * 1000 : 250;
		legacyRetryTimer = window.setTimeout(function () {
			legacyRetryTimer = null;
			if (legacyPendingSubmission !== pending) return;
			transmitLegacySuccess(pending.root, FORM_ID);
		}, delay);
	}

	async function transmitLegacySuccess(formLike, formId) {
		if (!isAttributionEligible) return false;
		var pending = legacyPendingSubmission;
		if (!pending) return;
		if (formLike !== undefined) {
			var root = canonicalLegacyRoot(formLike, formId);
			if (!root || root !== pending.root) return;
			pending.successSeen = true;
		} else if (!pending.successSeen) {
			legacyFormRoots = legacyFormRoots.filter(function (root) { return root ? root.isConnected : false; });
			if (legacyFormRoots.length !== 1 || legacyFormRoots[0] !== pending.root) return;
			pending.successSeen = true;
		}
		if (!hasMarketingConsent()) {
			clearLegacyPendingSubmission(pending);
			return;
		}
		if (sent) {
			clearLegacyPendingSubmission(pending);
			return;
		}
		if (!pending.emailHash || !claimAudit()) return;
		try {
			if (legacyPendingSubmission !== pending || !hasMarketingConsent()) return;
			clickValues = collectClickValues();
			var terminal = await transmitAudit({
				submission_id: pending.submissionId || null,
				nvx_lead_id: pending.nvxLeadId || getNvxLeadId() || null,
				email_hash: pending.emailHash,
				gclid: clickValues.gclid || null,
				gbraid: clickValues.gbraid || null,
				wbraid: clickValues.wbraid || null,
				gclsrc: clickValues.gclsrc || null,
				form_id: FORM_ID,
				landing_url: canonicalLandingUrl(),
			});
			if (terminal || sent) clearLegacyPendingSubmission(pending);
			else scheduleLegacyRetry(pending, true);
		} finally {
			releaseAuditClaim();
		}
	}

	function isTrustedHubSpotOrigin(origin) {
		if (!origin || origin === 'null') return false;
		try {
			var url = new URL(origin);
			if (url.protocol !== 'https:' || url.port) return false;
			var host = url.hostname.toLowerCase();
			return /(^|\.)(hubspot\.com|hsforms\.com|hsforms\.net)$/.test(host);
		} catch (_error) { return false; }
	}

	window.NUVANXGoogleAttributionLegacy = Object.freeze({
		onFormReady: function (formLike, formId) { populateLegacyClickFields(formLike, formId); },
		onBeforeFormSubmit: function (formLike, formId) { return captureLegacyEmail(formLike, formId); },
		onFormSubmitted: function (formLike, formId) { return transmitLegacySuccess(formLike, formId); },
	});

	document.addEventListener('wp_listen_for_consent_change', refreshLegacyForms);
	document.addEventListener('wp_consent_type_defined', refreshLegacyForms);

	window.addEventListener('message', function (event) {
		if (!isTrustedHubSpotOrigin(event.origin)) return;
		var data = event.data || {};
		if (typeof data === 'string') {
			try { data = JSON.parse(data) || {}; } catch (_error) { data = {}; }
		}
		if (typeof data !== 'object' || data === null) data = {};
		if (data.type !== 'hsFormCallback' || data.eventName !== 'onFormSubmitted') return;
		if (String(data.id || '').toLowerCase() !== FORM_ID) return;
		transmitLegacySuccess();
	});

	window.addEventListener('hs-form-event:on-submission:success', async function (event) {
		if (!isAttributionEligible) return;
		var detail = event && event.detail ? event.detail : {};
		if (String(detail.formId || '').toLowerCase() !== FORM_ID) return;
		if (!claimAudit()) return;
		try {
			var payload = await buildAuditPayload(event);
			if (!payload || !hasMarketingConsent()) return;
			await transmitAudit(payload);
		} finally {
			releaseAuditClaim();
		}
	});
}());
