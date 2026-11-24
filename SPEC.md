# Spec: QC round 2026-11-24 (Nicole, batch 2)

Status: draft, waiting for Carlos's go-ahead. Target release: 1.31.0.

## Objective

Close the ten edits from Nicole's QC round of 2026-11-24 for the booking widget
(Vetspire and Nectar) and the Scheduler Data page in the Vetcelerator hub.

Users: pet owners booking on clinic sites (mostly on phones), clinic staff who
read the records in the PIMS, and Vetcelerator staff who read Scheduler Data.

## Decisions already made (Carlos, 2026-11-24)

- A pet created by a booking whose appointment step then fails is kept; the
  retry reuses it.
- Adding a pet to an existing account is verified against the PRIMARY phone only.
- "Emergency phone" replaces "Alternate phone" on both platforms; on Vetspire the
  number is saved with the name "Emergency".
- A required phone must have exactly 10 digits (a leading +1 / 1 is accepted).
- Error texts start with a capital letter after "Error:".
- Loading text on the email step: "Looking up your account…".
- New details for a client already on file only fill blanks, never overwrite,
  and only when the full phone typed matches the primary phone on file.
- Species come from the PIMS; Dog / Cat / Other stays as the fallback. Built
  but not released until Nicole has coordinated with the clinics.

## Work items and acceptance criteria

### M1. Add-pet verification (item 1)

Finding: the last-4 check runs before the pet is created, but it accepts any
phone on file. On Nectar that is home and work/emergency phone of up to five
owner records sharing the email, and the pet goes to the first record. Nicole's
test client has Primary …9493 and Emergency …9494.

- Verification compares only the primary phone of the one owner record the pet
  will be added to: Nectar `phoneH`, Vetspire the `preferred` number (the first
  number when none is flagged).
- With several Nectar owner records for one email, the record whose primary
  phone matches is the one that receives the pet. No match on any: refused.
- A failed check creates nothing and changes nothing (test asserts no write
  operation was sent).
- Label: "Last 4 digits of the primary phone on file".
- Error: "We could not verify that this account is yours. Please enter the last
  4 digits of the primary phone number the clinic has on file, or call the
  clinic to add a new pet."
- Field label and Settings row: "Emergency phone".

### M2. Reason for visit always required (item 2)

- `reason` is locked required in the field registry; the Settings checkbox is
  checked and disabled; the server refuses a booking without it on both
  platforms, every form (new client, returning client, new pet) and variant.

### M3. Booking window fits a phone screen (item 3)

Finding: `max-height: 90vh` measures behind iOS browser bars. Date inputs also
overflow their column on iOS Safari and Chrome.

- The modal never extends under the browser's bars: ×, Cancel, Confirm Booking
  and "‹ Back" are reachable by scrolling inside the modal on a 390 x 660
  viewport, with all fields on and with the minimum set.
- Date inputs are the same height and width as text inputs on iOS.
- `tests/layout-mobile-e2e.js` covers both.

### M4. Loading text matches the action (item 4)

- Email step button: "Looking up your account…". "Loading available times…"
  is used only where times are loading. One shared string set, so Vetspire and
  Nectar both change.

### M5. Error messages (items 5, 6, 7)

- Sex not chosen: "Please choose your pet's sex from the dropdown options."
- Pet birth date not a usable date (incomplete, in the future, or more than 40
  years ago, e.g. 01/01/0001): "Please enter a valid date for your pet's birth."
  Checked in the form (min/max) and by the server.
- Required phone invalid: "Please enter a valid 10-digit phone number, beginning
  with the area code." Form and server both require 10 digits.

### M6. Species from the PIMS (item 8) (build, do not release)

- To verify first: Nectar `species` (does it return the online-booking allowed
  list or every species?) and whether Vetspire's API exposes the org's species
  restriction. Report to Carlos before building if either is not available.
- The "Pet type" options come from the PIMS list (cached like appointment
  types, deduplicated case-insensitively: "FELINE" and "Feline" are one).
- An empty or failed list falls back to Dog / Cat / Other.
- The server refuses a species that is not in the list it offered.
- Behind a setting that is off by default until Nicole confirms each clinic.
- Hub: the Nectar proxy allow-list must accept the species operation.

### M7. Fill blanks on a client already on file (item 9)

- Applies when the new-client form is used with an email already on file and
  the full phone typed equals the primary phone on file.
- Client: emergency phone, date of birth, address. Existing pet matched by
  name: sex, spayed/neutered, birth date, weight, color, microchip.
- Only empty fields are filled; a field with a value is never changed.
- Best effort after the appointment exists: a failure is logged and never
  costs the visitor the booking.
- To verify first: which update mutations Nectar and Vetspire allow (and the
  hub's allow-list for Nectar). Fields a platform cannot update are skipped
  and listed in the release notes.

### M8. Hub (item 10) (repo `vetcelerator-platform`)

10a, times. Finding: the hub already formats every time in the site's clinic
timezone. For the test site it holds Eastern while the plugin books in Pacific
(widget 6:20 PM, Nectar 6:20 PM PST, hub 9:20 PM). The plugin saves the clinic
timezone once per location and never refreshes it (`VSPS_Hub::clinic_timezone`).

- The plugin re-reads the timezone and sends the new one when it changes.
- Appointment and Created columns show the same clinic zone.

10b, "Nectar did not answer". Hypothesis, not confirmed: the site is linked in
the hub to a production clinic while the test bookings were made in Nectar's
sandbox, so every appointment comes back "not found" and the pass is refused
(`nectar_all_not_found`). Needs the hub's log line `[scheduler sync] nectar
failed` for that site.

- Confirm the cause from the logs before changing anything.
- A pass refused for "all not found" gets its own message instead of
  "did not answer".

## Out of scope

- Removing or deactivating orphan pets.
- Merging duplicate client records in the PIMS.
- Releasing M6 to clinics.

## Commands

```
php tests/harness.php
php tests/nectar-e2e.php
php tests/errors-unit.php
php tests/pet-new-unit.php
php tests/nectar-qc-unit.php
node tests/layout-mobile-e2e.js      # needs the local WP (see README)
node tests/wcag-forms-e2e.js
php bin/extract-contracts.php        # after any GraphQL change
python3 bin/build-zip.py             # release zip
```

## Project structure

- `includes/class-vsps-booking.php` booking flow and ownership check (M1, M7)
- `includes/class-vsps-nectar.php`, `class-vsps-api.php` PIMS adapters (M1, M6, M7)
- `includes/class-vsps-fields.php`, `class-vsps-rest.php` fields and validation (M2, M5)
- `includes/class-vsps-hub.php` hub push, clinic timezone (M8)
- `assets/js/scheduler.js`, `assets/css/scheduler.css` widget (M3, M4, M5)
- `contracts/` GraphQL contracts the hub checks
- `vetcelerator-platform/src/lib/scheduler`, `src/features/scheduler` hub (M6, M8)

## Code style

Follow the files as they are: WordPress PHP style with tabs, ES5 in
`scheduler.js`, strings in the `I18N` map, comments that say why. Every QC fix
carries a short note with the date, like the existing ones.

## Testing strategy

- Each item gets a failing test first: unit tests for M1, M2, M5, M7 (mocked
  PIMS writes), the mobile e2e for M3, the forms e2e for M4 and M5.
- M1 has a test with three owner records sharing one email.
- Contracts are regenerated and diffed for M1, M6, M7.
- Manual check on the Estero test site and Katy before release.

## Boundaries

- Always: verify server-side; fail closed on ownership; keep PIMS writes
  best-effort after the appointment exists.
- Ask first: any new GraphQL operation through the hub allow-list; releasing
  M6; deploying to clinic sites; any change in the hub's database.
- Never: overwrite data on file in the PIMS; show owner data to an unverified
  visitor; commit credentials.

## Build order

M2, M4, M5 (text and flags) → M3 → M1 → M8 → M7 → M6.
