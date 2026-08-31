# Attribution Identity v1 — Web producer

The public website owns consent-aware acquisition capture. The canonical first-party form continues to submit to HubSpot; the successful authenticated HubSpot response is then relayed to Supabase `lead-captured`.

## Meta identity

- `fbclid` is captured only while marketing consent is active.
- `fbc` prefers a real `_fbc` cookie. If the cookie does not yet exist but a real `fbclid` is present, the browser may derive the standard `fb.1.<timestamp_ms>.<fbclid>` value using the touch timestamp.
- `fbp` is read only from a real `_fbp` cookie. NUVANX never synthesizes or writes `_fbp`.
- First-party form Meta identity values are cleared when consent is absent or revoked.
- FBC/FBP are relayed to Supabase inside `conversion_attribution`; no duplicate custom HubSpot FBC/FBP properties are introduced.

## Google identity

The existing Google `CLICK_KEYS` contract remains unchanged (`gclid`, `gbraid`, `wbraid`, `gclsrc`). Meta `fbclid` is a separate `META_CLICK_KEYS` contract so Google consumers are not silently redefined.

## Channel resolution

A bare `fbclid` resolves to paid social. Bare GCLID/GBRAID/WBRAID resolves to paid search. Explicit UTM source/medium remains higher-priority evidence.
