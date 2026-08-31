(function() {
	'use strict';

	var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
	var CLICK_KEYS = ['gclid', 'gbraid', 'wbraid', 'gclsrc', 'fbclid'];
	var ATTR_TTL_MS = 90 * 24 * 60 * 60 * 1000;
	var FIRST_TOUCH_KEY = 'nvx_first_touch';
	var CONVERSION_TOUCH_KEY = 'nvx_conversion_touch';
	var LEAD_SESSION_KEY = 'nvx_lead_id';
	var qa = (window.nvxConversionEvents && window.nvxConversionEvents.qa) || { is_test_lead: false, test_run_id: '' };

	function isUuidV4(value) {
		return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(String(value || ''));
	}

	function createUuidV4() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
		if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
			var bytes = new Uint8Array(16);
			window.crypto.getRandomValues(bytes);
			bytes[6] = (bytes[6] & 0x0f) | 0x40;
			bytes[8] = (bytes[8] & 0x3f) | 0x80;
			var hex = Array.from(bytes).map(function(byte) { return byte.toString(16).padStart(2, '0'); }).join('');
			return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(char) {
			var random = Math.floor(Math.random() * 16);
			var value = char === 'x' ? random : (random & 0x3) | 0x8;
			return value.toString(16);
		});
	}

	function safeSessionStorage() {
		var id = '';
		try { id = window.sessionStorage.getItem(LEAD_SESSION_KEY) || ''; } catch (_error) {}
		if (!isUuidV4(id)) {
			id = createUuidV4();
			try { window.sessionStorage.setItem(LEAD_SESSION_KEY, id); } catch (_error) {}
		}
		return id;
	}

	function urlClickSignal(url, key) {
		try {
			var parsed = new URL(url || '', window.location && window.location.href ? window.location.href : undefined);
			return parsed.searchParams.get(key) || '';
		} catch (_error) { return ''; }
	}

	function classifyChannel(utm, referrer, url) {
		if (utm.utm_source) {
			if (utm.utm_source === 'google' && utm.utm_medium === 'cpc') return 'paid_search';
			if (utm.utm_source === 'facebook' || utm.utm_source === 'instagram' || utm.utm_source === 'meta') return 'paid_social';
			if (utm.utm_medium && utm.utm_medium.indexOf('cpc') !== -1) return 'paid_search';
			if (utm.utm_medium && utm.utm_medium.indexOf('cpm') !== -1) return 'paid_display';
			return 'paid_other';
		}
		if (urlClickSignal(url, 'fbclid')) return 'paid_social';
		if (urlClickSignal(url, 'gclid') || urlClickSignal(url, 'gbraid') || urlClickSignal(url, 'wbraid')) return 'paid_search';
		if (referrer) {
			if (referrer.indexOf('google') !== -1 || referrer.indexOf('bing') !== -1) return 'organic_search';
			if (referrer.indexOf('facebook') !== -1 || referrer.indexOf('instagram') !== -1) return 'organic_social';
			var currentHost = (window.location && window.location.hostname) || '';
			if (currentHost && referrer.indexOf(currentHost) === -1) return 'referral';
			return 'internal';
		}
		return 'direct';
	}

	function hasMarketingConsent() {
		try {
			if (typeof window.cmplz_has_consent === 'function') return window.cmplz_has_consent('marketing') === true;
			if (typeof window.wp_has_consent === 'function') return window.wp_has_consent('marketing') === true;
		} catch (_error) { return false; }
		return false;
	}

	function lsGet(key) { try { return window.localStorage.getItem(key); } catch (_error) { return null; } }
	function lsSet(key, value) {
		try { window.localStorage.setItem(key, value); return true; } catch (_error) { return false; }
	}

	function readCookie(name) {
		try {
			var prefix = String(name || '') + '=';
			var parts = String(document.cookie || '').split(';');
			for (var i = 0; i < parts.length; i++) {
				var part = parts[i].trim();
				if (part.indexOf(prefix) === 0) return decodeURIComponent(part.slice(prefix.length));
			}
		} catch (_error) {}
		return '';
	}

	function cleanFbclid(value) {
		value = String(value || '').trim();
		return /^[A-Za-z0-9._~:+-]{1,512}$/.test(value) ? value : '';
	}

	function cleanMetaBrowserId(value) {
		value = String(value || '').trim();
		return /^fb\.1\.\d{10,16}\.[A-Za-z0-9._~:+-]{1,512}$/.test(value) ? value : '';
	}

	function buildFbcFromFbclid(fbclid, capturedAtMs) {
		var clickId = cleanFbclid(fbclid);
		var timestamp = Number(capturedAtMs);
		if (!clickId || !Number.isFinite(timestamp) || timestamp <= 0) return '';
		return cleanMetaBrowserId('fb.1.' + Math.trunc(timestamp) + '.' + clickId);
	}

	function readTouch(key) {
		var raw = lsGet(key);
		if (!raw) return null;
		try {
			var obj = JSON.parse(raw);
			if (obj && obj.expires_at && Date.now() > Number(obj.expires_at)) {
				try { window.localStorage.removeItem(key); } catch (_error) {}
				return null;
			}
			return obj;
		} catch (_error) { return null; }
	}

	function buildTouch() {
		var params = new URLSearchParams((window.location && window.location.search) || '');
		var utm = {};
		var clicks = {};
		var i;
		for (i = 0; i < UTM_KEYS.length; i++) {
			var utmValue = params.get(UTM_KEYS[i]);
			if (utmValue) utm[UTM_KEYS[i]] = utmValue;
		}
		for (i = 0; i < CLICK_KEYS.length; i++) {
			var clickValue = params.get(CLICK_KEYS[i]);
			if (clickValue) clicks[CLICK_KEYS[i]] = clickValue;
		}

		var href = (window.location && window.location.href) || '';
		var landing = href;
		try { var parsed = new URL(href); landing = parsed.origin + parsed.pathname; } catch (_error) {}
		var referrer = (document && document.referrer) || '';
		var channel = classifyChannel(utm, referrer, href);
		var now = Date.now();
		var inferredSource = utm.utm_source || '';
		var inferredMedium = utm.utm_medium || '';

		if (!inferredSource && clicks.fbclid) {
			inferredSource = 'meta'; inferredMedium = 'paid_social';
		} else if (!inferredSource && (clicks.gclid || clicks.gbraid || clicks.wbraid)) {
			inferredSource = 'google'; inferredMedium = 'cpc';
		} else if (!inferredSource && referrer) {
			if (referrer.indexOf('google') !== -1) { inferredSource = 'google'; inferredMedium = inferredMedium || 'organic'; }
			else if (referrer.indexOf('bing') !== -1) { inferredSource = 'bing'; inferredMedium = inferredMedium || 'organic'; }
			else if (referrer.indexOf('facebook') !== -1) { inferredSource = 'facebook'; inferredMedium = inferredMedium || 'organic'; }
			else if (referrer.indexOf('instagram') !== -1) { inferredSource = 'instagram'; inferredMedium = inferredMedium || 'organic'; }
		}

		var touch = {
			channel: channel,
			source: inferredSource,
			medium: inferredMedium,
			campaign_id: utm.utm_campaign || '',
			utm_content: utm.utm_content || '',
			utm_term: utm.utm_term || '',
			landing_url: landing,
			referrer_domain: '',
			timestamp: new Date(now).toISOString(),
			expires_at: now + ATTR_TTL_MS,
		};
		if (clicks.gclid) touch.gclid = clicks.gclid;
		if (clicks.gbraid) touch.gbraid = clicks.gbraid;
		if (clicks.wbraid) touch.wbraid = clicks.wbraid;
		if (clicks.gclsrc) touch.gclsrc = clicks.gclsrc;
		if (clicks.fbclid) touch.fbclid = cleanFbclid(clicks.fbclid);

		var fbc = cleanMetaBrowserId(readCookie('_fbc'));
		var fbp = cleanMetaBrowserId(readCookie('_fbp'));
		if (!fbc && touch.fbclid) fbc = buildFbcFromFbclid(touch.fbclid, now);
		if (fbc) touch.fbc = fbc;
		if (fbp) touch.fbp = fbp;
		if (referrer) { try { touch.referrer_domain = new URL(referrer).hostname; } catch (_error) {} }
		return touch;
	}

	function ensureHiddenField(form, name) {
		var field = form.querySelector('[name="' + name + '"]');
		if (field) return field;
		field = document.createElement('input');
		field.type = 'hidden';
		field.name = name;
		field.value = '';
		form.appendChild(field);
		return field;
	}

	function syncDirectFormMetaIdentity() {
		var form;
		try { form = document.querySelector('[data-nvx-direct-form]'); } catch (_error) { form = null; }
		if (!form) return;
		var first = readTouch(FIRST_TOUCH_KEY) || {};
		var conversion = readTouch(CONVERSION_TOUCH_KEY) || first;
		var consent = hasMarketingConsent();
		var values = consent ? {
			fbclid: conversion.fbclid || first.fbclid || '',
			fbc: conversion.fbc || first.fbc || '',
			fbp: conversion.fbp || first.fbp || '',
		} : { fbclid: '', fbc: '', fbp: '' };
		Object.keys(values).forEach(function(name) {
			ensureHiddenField(form, name).value = values[name];
		});
	}

	function captureAttribution() {
		if (!hasMarketingConsent()) { syncDirectFormMetaIdentity(); return; }
		var existingFirst = readTouch(FIRST_TOUCH_KEY);
		var touch = buildTouch();
		if (!existingFirst) lsSet(FIRST_TOUCH_KEY, JSON.stringify(touch));
		if (touch.channel !== 'internal' && touch.channel !== 'direct') lsSet(CONVERSION_TOUCH_KEY, JSON.stringify(touch));
		else if (!readTouch(CONVERSION_TOUCH_KEY)) lsSet(CONVERSION_TOUCH_KEY, JSON.stringify(touch));
		syncDirectFormMetaIdentity();
	}

	function buildFormPayload(available) {
		captureAttribution();
		available = available && typeof available.has === 'function' ? available : { has: function() { return true; } };
		var first = readTouch(FIRST_TOUCH_KEY) || {};
		var conversion = readTouch(CONVERSION_TOUCH_KEY) || {};
		var leadId = safeSessionStorage();
		var fieldMap = {
			nvx_lead_id: 'nvx_lead_id', nvx_is_test_lead: 'nvx_is_test_lead', nvx_test_run_id: 'nvx_test_run_id',
			utm_source: 'nvx_utm_source', utm_medium: 'nvx_utm_medium', utm_campaign: 'nvx_utm_campaign', utm_content: 'nvx_utm_content', utm_term: 'nvx_utm_term',
			gclid: 'nvx_google_click_id', gbraid: 'nvx_google_braid', wbraid: 'nvx_google_wbraid', gclsrc: 'nvx_google_gclsrc',
			nvx_first_source: 'nvx_first_source', nvx_first_medium: 'nvx_first_medium', nvx_first_campaign_id: 'nvx_first_campaign_id', nvx_first_referrer_domain: 'nvx_first_referrer_domain', nvx_first_landing_url: 'nvx_first_landing_url', nvx_first_timestamp: 'nvx_first_timestamp', nvx_first_channel: 'nvx_first_channel',
			nvx_conversion_channel: 'nvx_conversion_channel', nvx_conversion_source: 'nvx_conversion_source', nvx_conversion_medium: 'nvx_conversion_medium', nvx_conversion_campaign_id: 'nvx_conversion_campaign_id', nvx_conversion_landing_url: 'nvx_conversion_landing_url', nvx_conversion_timestamp: 'nvx_conversion_timestamp',
		};
		var rawValues = {
			nvx_lead_id: leadId, nvx_is_test_lead: qa.is_test_lead === true, nvx_test_run_id: qa.test_run_id || '',
			utm_source: conversion.source || first.source || '', utm_medium: conversion.medium || first.medium || '', utm_campaign: conversion.campaign_id || first.campaign_id || '', utm_content: conversion.utm_content || first.utm_content || '', utm_term: conversion.utm_term || first.utm_term || '',
			gclid: conversion.gclid || first.gclid || '', gbraid: conversion.gbraid || first.gbraid || '', wbraid: conversion.wbraid || first.wbraid || '', gclsrc: conversion.gclsrc || first.gclsrc || '',
			nvx_first_source: first.source || '', nvx_first_medium: first.medium || '', nvx_first_campaign_id: first.campaign_id || '', nvx_first_referrer_domain: first.referrer_domain || '', nvx_first_landing_url: first.landing_url || '', nvx_first_timestamp: first.timestamp || '', nvx_first_channel: first.channel || '',
			nvx_conversion_channel: conversion.channel || '', nvx_conversion_source: conversion.source || '', nvx_conversion_medium: conversion.medium || '', nvx_conversion_campaign_id: conversion.campaign_id || '', nvx_conversion_landing_url: conversion.landing_url || '', nvx_conversion_timestamp: conversion.timestamp || '',
		};

		var result = {};
		Object.keys(fieldMap).forEach(function(key) {
			var fieldName = fieldMap[key];
			if (!available.has(fieldName)) return;
			var rawValue = rawValues[key];
			result[fieldName] = key === 'nvx_is_test_lead' ? Boolean(rawValue) : rawValue;
		});
		return result;
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', captureAttribution);
	else captureAttribution();
	document.addEventListener('wp_listen_for_consent_change', captureAttribution);
	document.addEventListener('wp_consent_type_defined', captureAttribution);

	window.NUVANXAttributionContract = {
		getFirstTouch: function() { captureAttribution(); return readTouch(FIRST_TOUCH_KEY); },
		getConversionTouch: function() { captureAttribution(); return readTouch(CONVERSION_TOUCH_KEY); },
		getLeadId: safeSessionStorage,
		buildFormPayload: buildFormPayload,
		classifyChannel: classifyChannel,
		FIRST_TOUCH_KEY: FIRST_TOUCH_KEY,
		CONVERSION_TOUCH_KEY: CONVERSION_TOUCH_KEY,
		UTM_KEYS: UTM_KEYS,
		CLICK_KEYS: CLICK_KEYS,
		ATTR_TTL_MS: ATTR_TTL_MS,
	};

	window.nvxAttribution = {
		getLeadId: safeSessionStorage,
		getFirstTouch: function() { return readTouch(FIRST_TOUCH_KEY) || {}; },
		classifyChannel: classifyChannel,
		UTM_KEYS: UTM_KEYS,
		CLICK_KEYS: CLICK_KEYS,
		ATTR_TTL_MS: ATTR_TTL_MS,
	};
})();