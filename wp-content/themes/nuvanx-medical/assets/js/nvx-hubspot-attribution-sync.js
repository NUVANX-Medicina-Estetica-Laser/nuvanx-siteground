(function () {
	'use strict';

	var config = window.nvxConversionEvents || {};
	var FORM_ID = String((config.forms || {}).valoracion || '').toLowerCase();
	var FIRST_PARTY_FIELDS = new Set([
		'nvx_lead_id',
		'nvx_is_test_lead',
		'nvx_test_run_id',
	]);
	var HIDDEN_BOOLEAN_FIELDS = new Set([
		'nvx_is_test_lead',
	]);
	// Prefer the PHP SSOT injected before this file; keep a fallback if inline config is absent.
	var MARKETING_FIELDS = Array.isArray(window.nvxAttributionMarketingFields)
		? window.nvxAttributionMarketingFields
		: [
			'nvx_utm_source',
			'nvx_utm_medium',
			'nvx_utm_campaign',
			'nvx_utm_content',
			'nvx_utm_term',
			'nvx_google_click_id',
			'nvx_google_braid',
			'nvx_google_wbraid',
			'nvx_google_gclsrc',
			'hs_google_click_id',
			'nvx_first_source',
			'nvx_first_medium',
			'nvx_first_campaign_id',
			'nvx_first_referrer_domain',
			'nvx_first_landing_url',
			'nvx_first_timestamp',
			'nvx_first_channel',
			'nvx_conversion_channel',
			'nvx_conversion_source',
			'nvx_conversion_medium',
			'nvx_conversion_campaign_id',
			'nvx_conversion_landing_url',
			'nvx_conversion_timestamp',
			'nvx_landing_url',
			'nvx_attribution_captured_at',
			'nvx_attribution_expires_at',
		];

	function hasMarketingConsent() {
		try {
			if (typeof window.cmplz_has_consent === 'function') return window.cmplz_has_consent('marketing') === true;
			if (typeof window.wp_has_consent === 'function') return window.wp_has_consent('marketing') === true;
		} catch (_error) { return false; }
		return false;
	}

	function canonicalPropertyName(fieldName) {
		return String(fieldName || '').trim().replace(/^\d+-\d+\//, '');
	}

	function isCanonicalForm(form) {
		if (!FORM_ID || !form || typeof form.getFormId !== 'function') return false;
		try {
			return String(form.getFormId() || '').toLowerCase() === FORM_ID;
		} catch (_error) {
			return false;
		}
	}

	function fieldIndex(fields) {
		var index = new Map();
		(fields || []).forEach(function (field) {
			var actualName = field && typeof field.name === 'string' ? field.name : '';
			var propertyName = canonicalPropertyName(actualName);
			if (!propertyName) return;
			if (!index.has(propertyName) || /^\d+-\d+\//.test(actualName)) {
				index.set(propertyName, actualName);
			}
		});
		return index;
	}

	function hubSpotFieldValue(value, propertyName) {
		// nvx_is_test_lead is deliberately Hidden in the canonical HubSpot form.
		// HubSpot Forms V4 requires string[] for Hidden fields, even when the
		// underlying CRM property is a single-checkbox enumeration.
		if (HIDDEN_BOOLEAN_FIELDS.has(propertyName)) return [value === true ? 'true' : 'false'];
		// Preserve native booleans for genuine visible Single checkbox fields.
		if (typeof value === 'boolean') return value;
		// Every non-boolean field synchronized by this module is an attribution
		// property configured as Hidden in the canonical form. HubSpot V4 requires
		// the documented string[] shape for Hidden field writes.
		if (value === undefined || value === null || value === '') return [];
		return [String(value)];
	}

	function normalizedComparable(value) {
		if (Array.isArray(value)) {
			if (value.length === 0) return '';
			if (value.length === 1) return normalizedComparable(value[0]);
			return value.map(normalizedComparable).join(',');
		}
		if (value === undefined || value === null) return '';
		if (typeof value === 'boolean') return value ? 'true' : 'false';
		return String(value);
	}

	function fieldValueMatches(actual, expected) {
		return normalizedComparable(actual) === normalizedComparable(expected);
	}

	async function verifyFieldValue(form, actualName, expected) {
		if (typeof form.getFieldValue !== 'function') return null;
		for (var attempt = 0; attempt < 3; attempt += 1) {
			try {
				var actual = await form.getFieldValue(actualName);
				if (fieldValueMatches(actual, expected)) return true;
			} catch (_error) {
				return false;
			}
			if (attempt < 2) {
				await new Promise(function (resolve) { window.setTimeout(resolve, 25); });
			}
		}
		return false;
	}

	function hiddenFieldFallback(value) {
		if (Array.isArray(value)) return value;
		if (typeof value === 'boolean') return [value ? 'true' : 'false'];
		var scalar = value === undefined || value === null ? '' : String(value);
		return scalar === '' ? [] : [scalar];
	}

	async function setField(form, index, propertyName, value) {
		var actualName = index.get(propertyName);
		if (!actualName) return false;
		var expected = hubSpotFieldValue(value, propertyName);
		var primarySucceeded = false;
		try {
			// HubSpot V4 may complete field writes asynchronously. Wait for the
			// result so callers cannot read a partially synchronized lineage.
			await Promise.resolve(form.setFieldValue(actualName, expected));
			primarySucceeded = true;
		} catch (_error) {}

		// Older/mocked instances may not expose getFieldValue. Preserve the
		// documented write path, but use read-back verification whenever V4 does.
		if (typeof form.getFieldValue !== 'function') return primarySucceeded;
		if (primarySucceeded && await verifyFieldValue(form, actualName, expected)) return true;

		// HubSpot Forms V4 requires string[] for Hidden fields. Retry with the
		// Hidden-field representation only when the primary write did not survive
		// read-back. Never accept a write without verifying it.
		var fallback = hiddenFieldFallback(expected);
		try {
			await Promise.resolve(form.setFieldValue(actualName, fallback));
		} catch (_error) {
			return false;
		}
		return (await verifyFieldValue(form, actualName, expected)) === true;
	}

	async function syncForm(form) {
		if (!isCanonicalForm(form)) return false;
		if (typeof form.getFormFieldValues !== 'function' || typeof form.setFieldValue !== 'function') return false;

		var contract = window.NUVANXAttributionContract;
		if (!contract || typeof contract.buildFormPayload !== 'function') return false;

		var fields;
		try {
			fields = await form.getFormFieldValues();
		} catch (_error) {
			return false;
		}
		if (!Array.isArray(fields)) return false;

		var index = fieldIndex(fields);
		var payload;
		try {
			payload = contract.buildFormPayload(new Set(index.keys())) || {};
		} catch (_error) {
			return false;
		}

		var marketingConsent = hasMarketingConsent();
		var changed = false;

		if (!marketingConsent) {
			for (var marketingIndex = 0; marketingIndex < MARKETING_FIELDS.length; marketingIndex += 1) {
				var marketingProperty = MARKETING_FIELDS[marketingIndex];
				if (index.has(marketingProperty)) {
					changed = (await setField(form, index, marketingProperty, '')) || changed;
				}
			}
		}

		var payloadProperties = Object.keys(payload);
		for (var payloadIndex = 0; payloadIndex < payloadProperties.length; payloadIndex += 1) {
			var propertyName = payloadProperties[payloadIndex];
			if (!marketingConsent && !FIRST_PARTY_FIELDS.has(propertyName)) continue;
			changed = (await setField(form, index, propertyName, payload[propertyName])) || changed;
		}

		return changed;
	}

	function formFromEvent(event) {
		if (!window.HubSpotFormsV4 || typeof window.HubSpotFormsV4.getFormFromEvent !== 'function') return null;
		try {
			return window.HubSpotFormsV4.getFormFromEvent(event) || null;
		} catch (_error) {
			return null;
		}
	}

	function syncExistingForms() {
		if (!window.HubSpotFormsV4 || typeof window.HubSpotFormsV4.getForms !== 'function') return;
		try {
			(window.HubSpotFormsV4.getForms() || []).forEach(function (form) {
				var result = syncForm(form);
				if (result && typeof result.catch === 'function') {
					result.catch(function (_error) {});
				}
			});
		} catch (_error) {}
	}

	window.addEventListener('hs-form-event:on-ready', function (event) {
		var detail = event && event.detail ? event.detail : {};
		if (!FORM_ID || String(detail.formId || '').toLowerCase() !== FORM_ID) return;
		var form = formFromEvent(event);
		if (form) {
			var result = syncForm(form);
			if (result && typeof result.catch === 'function') {
				result.catch(function (_error) {});
			}
		}
	});

	document.addEventListener('wp_listen_for_consent_change', syncExistingForms);
	document.addEventListener('wp_consent_type_defined', syncExistingForms);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', syncExistingForms, { once: true });
	} else {
		syncExistingForms();
	}

	window.NUVANXHubSpotAttributionSync = Object.freeze({
		syncForm: syncForm,
		syncExistingForms: syncExistingForms,
		canonicalPropertyName: canonicalPropertyName,
		FIRST_PARTY_FIELDS: FIRST_PARTY_FIELDS,
		HIDDEN_BOOLEAN_FIELDS: HIDDEN_BOOLEAN_FIELDS,
		MARKETING_FIELDS: MARKETING_FIELDS,
	});
}());
