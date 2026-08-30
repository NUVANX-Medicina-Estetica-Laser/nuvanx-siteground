# Integration configuration governance

## HubSpot identity

HubSpot portal and form identifiers are public integration identifiers, not secrets. They are nevertheless environment identity and must not silently default to Production.

Runtime resolution is fail-closed:

1. `NVX_HUBSPOT_PORTAL_ID` / `NVX_HUBSPOT_VALORACION_FORM_ID` constants;
2. legacy `NVX_VALORACION_HS_FRAME_PORTAL_ID` / `NVX_VALORACION_HS_FRAME_FORM_ID` constants;
3. matching environment variables;
4. empty value and explicit transport failure.

Production currently provisions the legacy frame constants outside the theme. Staging and every other environment must provision their own values and pass exact-SHA acceptance.

## Google Ads conversion mirror

Google Ads is the authority for conversion action identity and status. `ads-conversion-catalog.json` is a browser-facing governed mirror, not the authoritative marketing configuration.

A change to the mirrored `send_to` must be accompanied by a live read from the owning Google Ads account confirming the conversion action ID, action name, enabled status, and event snippet. The catalog records the date and method of the last live verification. CODEOWNERS requires explicit integration-owner review.

Current live verification on 2026-08-30 confirmed account `8201489748`, conversion action `7717851061` (`Clic en teléfono o WhatsApp`), status `ENABLED`, and `send_to` `AW-18236597403/qut3CLWflOAcEJvJ8fdD`.
