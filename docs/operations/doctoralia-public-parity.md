# Doctoralia public parity runbook

Status: `EXTERNAL_PUBLIC_PARITY_OPEN`

Owner issue: #751

This runbook governs reconciliation of NUVANX Doctoralia profiles with the current website/repository service SSOT. Doctoralia is an external publication surface; it does not redefine the NUVANX clinical catalog.

## Current observed state — 2026-08-28

### Salamanca–Goya

- Doctoralia facility: `54924`.
- Registration: `CS20073`.
- Public profile: `https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser-sede-goya`.
- Two admin direction records exist for the same physical location:
  - `53333`: Goya-specific website URL, 16 editable service rows.
  - `49168`: NUVANX home URL, 7 editable service rows; exact first-seven-row subset of `53333`.
- `53333` is the stronger candidate, but canonical ownership is **not proven** until synchronization/agenda ownership is inspected.
- The main public profile still exposes `Enfermería`, `Medicina estética`, `Radiología`, `Aire acondicionado`, `Responsable sanitario: yolanda piñero` and a 13-service legacy FAQ.
- Other Doctoralia discovery surfaces already expose a different specialty composition including `Geriatría`, proving public-surface inconsistency.
- Doctoralia treatment/professional surfaces still associate Goya with HIFU, Dermapen, Fototerapia and other legacy services.
- Authenticated Doctoralia admin shows `Responsable: Javier Rivera Tejeda`; this is **OBSERVED ADMIN**, not official-register proof.

### Chamberí

- Doctoralia facility: `47595`.
- Registration: `CS20144`.
- Public profile: `https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser`.
- Full authenticated admin export remains pending.

## Canonical service projection

The top-level target set is owned by `inc/data/treatment-hub-schema.json` and must be identical across both Doctoralia clinics unless a documented operational exception exists:

1. Endolift® Facial
2. Endoláser Corporal
3. Láser CO₂ Fraccionado
4. Plataforma EXION® BTL
5. Medicina Estética Facial
6. Bioestimulación de colágeno
7. BTL EXILITE™ IPL
8. Neuromodulador
9. Ácido Hialurónico
10. Rinomodelación

Bookable subservices may exist only when backed by the current tariff/route catalog. Appointment types such as `Primera consulta gratuita` and `Consulta de revisión` are not clinical-treatment parity rows.

## Phase 1 — mandatory read-only preflight

Do not save or remove anything in this phase.

### 1. Clinic Cloud → Configuración → Sincronización Doctoralia

Inspect Goya `54924` and Chamberí `47595` and capture for every linked agenda:

- Clinic Cloud agenda ID/name;
- Doctoralia facility/direction ID;
- professional;
- specialty;
- linked services;
- integration state;
- presencial / online state;
- notification owner;
- any visible precedence/source setting (`Clinic Cloud` vs `Doctoralia`);
- last synchronization/error state where exposed.

The output must explicitly answer:

`Who is the effective owner of service publication and schedules for each location?`

### 2. Chamberí authenticated admin export

Export, without mutation:

- all direction IDs and website URLs;
- all editable service rows;
- `item_service_id` / Doctoralia type where exposed;
- internal/public name;
- price;
- duration;
- assigned calendar(s);
- `Mostrar en Doctoralia` state;
- online-booking state.

### 3. Clinic Cloud operational export

For both locations capture only relevant records from:

- Servicios;
- Especialidades;
- Usuarios;
- Asignaciones;
- Agendas.

### 4. Professional-profile service associations

For every professional assigned to Goya, inspect which services independently publish through that professional. This is mandatory because center-level service cleanup alone does not remove services that remain attached to a professional/location relation.

At minimum inspect the current Goya associations for Gosia Ledniowska Janina and any other professional exposing legacy service pages.

## Phase 2 — mutation plan after Phase 1 is complete

Do not execute this phase until the four write preconditions in `doctoralia-profiles.json` are satisfied.

### A. Resolve the duplicate Goya directions

1. Determine which of `53333` or `49168` owns the active Goya calendar/reservations/synchronization.
2. If `53333` owns the live operation and `49168` has no unique appointments/mappings, designate `53333` as the canonical Doctoralia direction.
3. Do **not** use Doctoralia's destructive `Eliminar dirección` action if it would remove agendas or appointments.
4. If duplicate retirement/merge requires Doctoralia support, request a merge/retirement of `49168` into `53333` after all unique mappings are migrated.
5. If `49168` still owns live appointments, migrate/reconcile those dependencies first; do not retire it in place.

### B. Remove legacy publication mappings, not merely free-text rows

Unpublish/remove from the Goya public clinical catalog unless explicitly re-approved by the business/clinical owner:

- HIFU facial;
- HIFU corporal;
- CoolSculpting;
- Dermapen;
- Medicina Complementaria / terapias alternativas.

`Diatermia`, generic `Fototerapia`, maderoterapia and micropigmentation require explicit current-service approval before retention. Do not automatically rename generic `Fototerapia` to BTL EXILITE™ IPL.

Apply the same decision at **both levels**:

1. center/direction service publication;
2. professional ↔ Goya service associations.

If a legacy service remains attached to a professional at Goya, Doctoralia may continue surfacing it in treatment search even after the center row is removed.

### C. Publish the canonical service set

For each canonical service:

1. preserve the NUVANX public name where Doctoralia allows a custom name;
2. select the closest **verified** Doctoralia `Tipo de servicio`;
3. never map to a clinically different type merely to make the warning disappear;
4. if no correct type exists, request/await Doctoralia catalog support rather than inventing an equivalence;
5. assign the correct Goya/Chamberí calendar(s);
6. enable public visibility/online booking only where operationally bookable.

Known safe dictionary candidates already observed publicly include `Técnica Endolift` and `Láser de CO2`; all other mappings must be verified in the selector before save.

### D. Normalize Goya terminology

Reconcile current `53333` rows as follows after ownership is proven:

- generic `EXION®` → determine whether it is EXION® Face; do not assume;
- `IPL` → `REVIEW_DUPLICATE` against BTL EXILITE™ IPL;
- `Inductores de colágeno` → canonical `Bioestimulación de colágeno` terminology if the Doctoralia type supports it;
- `Toxina botulínica` → governed `Neuromodulador` public terminology where platform/policy permits;
- add missing `Rinomodelación`;
- add missing `EMFUSION®` only when represented as a bookable service/type;
- make EXION Face/Fractional/Body explicit where the platform supports those separate bookable services.

### E. Price handling

`tariff-catalog.json` is the price SSOT. Current examples:

- Endolift® ojeras: `798.60 EUR`;
- Marcación mandibular + papada: `1452.00 EUR`;
- Full Face: `1694.00 EUR`;
- abdomen + flancos: `2395.80 EUR`;
- zona sujetador + brazos: `1694.00 EUR`;
- CO₂ facial: `330.00 EUR`;
- CO₂ corporal: `450.00 EUR`;
- AH labios hidratación: `290.00 EUR`.

If Doctoralia only supports integer display pricing, record the row as `PRICE_DISPLAY_RECONCILE`; do not change the tariff SSOT to match a platform display limitation.

### F. Specialties and professionals

Do not force specialties to be identical solely for SEO. Reconcile them to the professionals actually assigned to each center.

The current public mismatch (`Radiología` on the main Goya profile vs `Geriatría` on other Doctoralia discovery surfaces) must be traced to user/specialty assignments and publication mappings before any specialty is removed.

### G. Responsable sanitario

No mutation is authorized from this workstream.

- Doctoralia admin: `Javier Rivera Tejeda` = `OBSERVED ADMIN`.
- Doctoralia primary public profile: `yolanda piñero` = `OBSERVED PUBLIC`.
- Official `legal_healthcare_responsible` for `CS20073` = `UNVERIFIED`.

Change this field only from an authoritative registry certificate/administrative record that exposes the legal role.

## Phase 3 — mandatory public acceptance

After each controlled mutation batch, validate all of the following publicly:

1. Goya primary clinic profile FAQ/services;
2. Goya specialty list and equipment;
3. Doctoralia treatment-search pages for HIFU, Dermapen, Fototerapia and any other removed legacy service;
4. each Goya professional's service list;
5. Chamberí primary clinic profile;
6. canonical services visible/bookable on both profiles where Doctoralia supports them;
7. only one effective Goya direction/location relationship is exposed after duplicate resolution;
8. NAP and website links remain unchanged/correct.

Acceptance requires exact service identity comparison, not `13 vs 13` service counts.

## Required result classification

For each row use one of:

- `KEEP_CANONICAL`
- `ADD_MISSING`
- `REMOVE_LEGACY`
- `RENAME_CANONICAL`
- `REVIEW_DUPLICATE`
- `PRICE_DISPLAY_RECONCILE`
- `LOCATION_EXCEPTION`
- `APPOINTMENT_TYPE`
- `LEGAL_VERIFY_ONLY`

Do not mark #751 complete until the public surfaces, not merely the admin editor, reflect the reconciled state.
