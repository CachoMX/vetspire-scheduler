# Shepherd integration design

Shepherd (`shepherd.vet`) is the next practice system after Vetspire and Nectar. This document is the plugin-side design. The code in `includes/class-vsps-shepherd.php` is scaffolding: it implements `VSPS_Pims`, it is covered by `php tests/shepherd-unit.php` with mocked responses, and it does not call a live clinic unless an admin has saved keys and then uses the widget. This branch does not bump the plugin version and does not publish a release.

Source of the schemas: the public Shepherd Open API description, version **2.246.0.33** (portal last updated 6 Nov 2026), at <https://developer.shepherd.vet/> and the agent-skill snapshot `openapi.json` from that portal. Swagger UI on the API host is not anonymously readable. The timeslot *range* object is marked unpublished in that snapshot; the parser accepts the range shapes below and that shape is an open question for the first keyed call.

## How it plugs in

Same pattern as Nectar:

| Piece | Role |
|---|---|
| `VSPS_Pims` | The only surface the widget, REST layer, and `VSPS_Booking` use |
| `VSPS_Pims_Registry` | Label, `confirm` booking mode, lead time 0, which form fields Shepherd can store |
| `VSPS_Shepherd` | REST adapter. Selected only when Settings → Practice management system is Shepherd |
| Settings | Public key, private key, clinic UUID, sandbox (default) or production |

A site saved as Vetspire or Nectar never constructs this client. `vsps_api()` returns null until both keys and a UUID are present, so an empty Shepherd selection does not call the API. Sandbox is the default environment (`https://demo-open-api.shepherd.vet/pav2`). Production (`https://open-api.shepherd.vet/pav2`) is an explicit choice. There is no free-form base URL, so a saved key cannot be pointed at an arbitrary host.

## Auth and per-location keys

Every request sends:

| Header | Value |
|---|---|
| `X-Integration-Public-Key` | Secret. Shepherd compares it case-sensitively. Not a publishable client id. |
| `X-Integration-Private-Key` | Secret. Never logged, never sent to the browser. |
| `X-Clinic-Id` | Clinic UUID. Required on every endpoint except the clinic list. A malformed value is a `500` on Shepherd's side, so the plugin refuses a non-UUID locally and does not send the request. |
| `Content-Type` | `application/json` |
| `User-Agent` | `Vetcelerator-Scheduler/<version>` |

There is no OAuth refresh. Both keys can be set in `wp-config.php` as `VSPS_SHEPHERD_PUBLIC_KEY`, `VSPS_SHEPHERD_PRIVATE_KEY`, and `VSPS_SHEPHERD_CLINIC_ID` so they need not live in the database.

Encore issues a key per clinic location. This build stores that location's keys on the WordPress site, the same way a Vetspire API token is stored, and treats the clinic UUID as the widget's single location id. Redirects are disabled so the private key is not forwarded.

### Does this need a hub proxy?

Not for this model. Nectar's proxy exists because **one** client id/secret is shared by every clinic and must not sit on a clinic's WordPress site. The hub (`POST /api/wp/v1/scheduler/nectar/graphql`, vetcelerator-platform PR #240) logs in and forwards an allowlisted GraphQL document.

Shepherd's documented partner model can also be one integration key granted many clinics, with `X-Clinic-Id` selecting the clinic. If Encore instead gives Vetcelerator **one** partner key for every Shepherd clinic, that key must not be copied onto each site, and the hub needs a **new** REST proxy. It cannot reuse the Nectar GraphQL allowlist. That proxy would:

- authenticate the site with its existing hub key (`vss_…`)
- hold the Shepherd key pair in the hub
- forward only an allowlisted set of `POST /pav2/open-api-…` paths
- never retry a `/write` (creates are not idempotent)
- keep the private key out of plugin logs

Until Encore says the key is shared, the plugin talks to Shepherd directly and **no vetcelerator-platform change is required to try the scaffold**. A platform change **is** required before a live Shepherd booking is reported to the hub (below).

## Endpoints the adapter uses

All reads and creates are `POST` with a JSON object. An empty body is not used; Shepherd returns `500` for an empty or null body. Updates are `PUT` and are full replacements. This scaffold does not update records.

| Step | Method and path | Notes |
|---|---|---|
| Connection / location | `POST /pav2/open-api-clinics` | `ids` = clinic UUID, `embed=clinicSetting,clinicAddress`. Timezone is `clinicSetting.timeZone`. |
| Appointment types | `POST /pav2/open-api-appointment-types` | Offered only when `directBookingEnabled` is true and `isDeleted` is false. `duration` is minutes, rounded up to a multiple of 5 (Shepherd rejects a start or end that is not on a 5-minute boundary). Missing duration becomes 30. |
| Providers | `POST /pav2/open-api-providers` | `appointmentTypeIds` is that type, `isArchived` false. Archived providers are dropped again locally. |
| Availability | `POST /pav2/open-api-provider-availabilities/timeslots` | Required `startDate` and `endDate` (the clinic-local day, at most 30 days, so one day is safe). `providerIds` is a comma-separated list. `filterActiveAppointments: true`. |
| Client search | `POST /pav2/open-api-clients` | `searchQuery` matches first name, last name, **and** email as a contains-search. The plugin keeps an exact email only, and skips `deleted`. |
| Client pets | `POST /pav2/open-api-patients` | `clientIds`, `isDeleted: false`, `excludeDeceased: true`, one page of 100. |
| New client | `POST /pav2/open-api-clients/write` | Not retried. |
| New patient | `POST /pav2/open-api-patients/write` | Not retried. |
| New appointment | `POST /pav2/open-api-appointments/write` | Not retried. Overlap with an existing appointment is **not** rejected by Shepherd; `VSPS_Booking` re-reads slots first. |
| Already booked | `POST /pav2/open-api-appointments` | Read only, used when the slot just disappeared, so a double submit can confirm the appointment this client already holds. |
| Species, breeds, sexes, statuses, countries, phone types, weight units | `POST /pav2/open-api-species`, `open-api-breeds`, `open-api-patient-sexes`, `open-api-patient-statuses`, `open-api-countries`, `open-api-phone-types`, `open-api-weight-units` | Cached 12 hours. Breeds: `speciesId`, `getBreedsByClinicId: true`, `isLegacy: false`, `isDeleted: false`. |
| Referral list | `POST /pav2/open-api-referral-source` | Active rows only. |

Error bodies are not one shape. The client reads a JSON string, a JSON object `.message`, plain text, or an empty body, and strips the keys and any email before logging. Reads of HTTP 502/503 are tried once. Writes, HTTP 500, HTTP 429, and timeouts are not retried. A 429 is returned to the visitor rather than blocking a PHP request until the next UTC minute.

### Timeslot ranges

`ProviderTimeSlotAvailabilityItemRESTModel` has `providerId` plus `available`, `break`, `clinicBreak`, `unavailable`, `officeClosure`, and `end`. The range type is unpublished. The parser accepts:

- a list of ranges, or one range object
- bounds named `start`/`end`, `startDate`/`endDate`, or `min`/`max`

A timestamp with `Z` or a numeric offset is converted into the clinic timezone. A timestamp with no offset is treated as clinic-local, which matches Shepherd's note that timeslots are computed in the clinic timezone. Slots are cut to the type duration, aligned to 5 minutes, and any slot that overlaps a break, clinic break, unavailability, or office closure is dropped. Slots before "now" are dropped.

`clinicSetting.timeZone` may be a Windows name (`Eastern Standard Time`). The adapter maps the common US names to IANA and otherwise uses the clinic timezone chosen in Settings.

## Field mapping

Widget values are the ones `VSPS_Booking` already passes. Shepherd ids are UUIDs. The widget species values stay `Canine`, `Feline`, and `Other`.

| Widget | Shepherd | Rule |
|---|---|---|
| First / last name | `ClientWriteModel.firstName` / `lastName` | Required. |
| Email | `email` | Exact match on lookup. `searchQuery` is not trusted alone. |
| Phone | `clientPhones[]` | Required array. Each phone needs `countryId` (United States), `phoneTypeId` (Mobile, else the first type), and `phoneNumber`. `isPrimary` on the first. |
| Alternate phone | second `clientPhones` entry | Optional. |
| Address | `clientInfo.address`, `address2`, `city`, `state`, `zipCode` | Sent only when line 1, city, state, and postal code are all present. There is no address on the client root. |
| Notes for the clinic | `additionalText` | Optional, clipped to 1000 characters. |
| How did you hear about us? | `referralSourceIds` | Only when the id is a UUID from `open-api-referral-source`. |
| Owner date of birth | — | Not on `ClientWriteModel`. The form hides it for Shepherd. |
| Pet name | `PatientWriteModel.name` | Required. |
| Species | breed's `speciesId` | There is no `speciesId` on the patient. Canine/dog and Feline/cat match the species lookup, then a breed in that species. |
| Breed | `breedId` | A typed breed must match the clinic breed name. If it does not, the pet is not created (it is not silently filed as Mixed). An empty breed may use Mixed, Mixed Breed, Unknown, or Other. |
| Sex + spayed/neutered | `sexId` | `Neutered Male` / `Spayed` style names when neutered is yes; otherwise Male / Female. Unknown when sex was not asked. Lookup ids differ by environment. |
| Age (years) | `dateOfBirth` | UTC today minus that many years, `T00:00:00Z`. Shepherd stores a date and ignores the time. Age 0 is a real answer. |
| Birth date | `dateOfBirth` | Wins over age. |
| Neither age nor birth date | — | Error. Shepherd requires `dateOfBirth`. A clinic that wants Shepherd bookings must show Age or Birth date. The scaffold does not invent a birthday. |
| Color | `color` | Optional on create (Shepherd stores `N/A` if omitted). |
| Microchip | `microchipNumber` | Clipped to 15 characters (the schema maximum). |
| Pet notes | `additionalText` | Optional. |
| Weight | `patientWeight.weight` + `weightUnitId` | lb/lbs or kg from `open-api-weight-units`. |
| Reason for visit | `visitReason` | `Vetcelerator booking: …`, clipped to 1000 characters. Becomes the SOAP initial complaint. |
| Provider / time | `providerId`, `startDate`, `endDate` | Provider is required. Instants are sent in UTC (`…Z`). End is start plus the type duration. |
| Pet | `patientIds` | A one-element array. Requires `clientId`. |
| Notifications | `sendEmailNotification`, `sendSmsNotification`, `sendFormEmailNotification` | All **false**. Shepherd's default is false; the scaffold does not message clients. |
| Appointment status | omitted | Shepherd defaults to upcoming and **rejects pending**. This is a confirmed booking, not a Nectar-style request. |

`scheduleTemplateAppointmentId` is not sent. Schedule templates describe the calendar, but the type id on the write is `appointmentTypeId` from `open-api-appointment-types`.

## What is deliberately not built

- Deposits or card capture. Shepherd's billing and payments APIs are read-oriented for partners; nothing here takes a payment. Nectar's deposit gap is the same product decision (see the audit).
- Live calls. Tests stub `wp_remote_post`. No clinic key is in the repo.
- Retries of creates, updates, or status changes.
- A hub proxy.

## Open questions for Shepherd and Encore

1. **Key model.** Is the key Encore issued for a location a clinic-scoped key that may live on that clinic's WordPress site, or one Vetcelerator partner key for every Shepherd clinic? The second needs a hub proxy before any production site is configured.
2. **Which clinics, and which UUIDs.** No key and no clinic id are in hand. Sandbox (`demo-open-api.shepherd.vet`) is the first place to try a key. Production is a separate key.
3. **Timeslot range shape.** Confirm one real `timeslots` payload: field names (`start`/`end` vs `min`/`max` vs something else) and whether the timestamps include an offset. The parser covers the likely shapes; the first keyed read should be checked before a clinic sees slots.
4. **`clinicSetting.timeZone` values** actually returned (Windows name vs IANA). The US map covers the common Windows names; anything else needs the Settings timezone.
5. **Direct booking flag.** Confirm `directBookingEnabled` is the switch clinics use for "offer this type online." Types with it false are hidden, same idea as Vetspire `canBookOnline` and Nectar `directOnlineEnabled`.
6. **Breed list.** Widget breed is free text. Shepherd needs a clinic `breedId`. Confirm each launch clinic has Mixed / Unknown (or that the form's breed list should become a dropdown of that clinic's breeds). A typed breed that does not match is rejected.
7. **Sex names.** Confirm the patient-sex lookup names (`Neutered Male`, `Spayed`, …). Ids are not portable between sandbox and production.
8. **Notifications.** Should a Shepherd booking send the clinic's email/SMS (`sendEmailNotification` / `sendSmsNotification`)? The scaffold leaves them false. Vetspire sends a confirmation email.
9. **Deposit / prepay.** No Shepherd booking field in this API subset takes a deposit. If a clinic needs one, it is a separate payments design, same decision as Nectar.
10. **Lead time and online hours.** No minimum lead time was found. The scaffold uses 0 and drops past slots. A clinic rule (24 hours, lunch blocks) would be extra.
11. **Multi-location groups.** One key and one `X-Clinic-Id` per site. A group that shares clients (`clinicIds` on searches) is not handled. Each location is its own site until that is designed.
12. **Hub booking log.** Required before any live Shepherd site pushes bookings. See below.

## vetcelerator-platform changes

None for the scaffold while keys are per location.

Before the first real Shepherd booking is delivered to the hub, `src/lib/scheduler/contract.ts` (`ingestRecordSchema`) and the plugin outbox have to agree on UUID-sized ids:

| Field | Plugin column today | Hub contract today | Shepherd value |
|---|---|---|---|
| `appointment_id` | `varchar(32)` | string, sliced to 32 | UUID, 36 characters |
| `client_id` / `vetspire_client_id` | `varchar(32)` | `text(32)` | UUID |
| `patient_id` | `varchar(32)` | `text(32)` | UUID |
| `location_id`, `appointment_type_id` | `varchar(64)` (fits) | `bigintId` (`Number(...)`, so a UUID becomes 0) | UUID |

Nectar's 24-character hex ids fit the 32-character cap, so this does not block the December Nectar launch. Shepherd ids would be truncated or zeroed. Widen those three plugin columns (a `DB_VERSION` bump, not a release by itself) and accept strings up to 64 characters for those ids in the hub. The hub schema also has no `pims` field on `ingestRecordSchema`; the plugin sends `pims`. Zod drops unknown keys, so the hub does not currently store the platform on the booking row. Adding `pims` (`vetspire` | `nectar` | `shepherd`) is what a Shepherd (and a clearer Nectar) report needs.

Status sync from Shepherd back to the hub (the Vetspire sync job) does not exist. Bookings would stay at the status recorded at create time.

A Shepherd proxy route is a separate change, and only if the answer to question 1 is a shared partner key.
