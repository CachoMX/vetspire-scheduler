# Vetspire API — Booking Fields Reference

Required vs. optional fields for the three objects the booking flow touches
(`ClientInput`, `PatientInput`, `AppointmentInput`), what the plugin currently
collects, and what else is available to add.

> **Note:** at the GraphQL schema level almost every field is optional —
> Vetspire barely enforces anything. The "practical minimum" below is what a
> booking actually needs to be usable by the clinic.

---

## 1. Client (pet owner) — `ClientInput`

### Practical minimum
| Field | Notes |
|---|---|
| `givenName` | First name |
| `familyName` | Last name |
| `email` **or** `phoneNumbers` | At least one contact method |

### Currently collected by the plugin
- First name, last name, email, phone (all required in our form)
- Sent automatically: `primaryLocationId`, a "Created via website online scheduler" note

### Available but NOT collected (candidates to add)
| Field | What it is |
|---|---|
| `customReferralSource` / `clientReferralSourceId` | **"How did you hear about us?"** — high value for marketing attribution |
| `addresses` | Full postal address |
| `dateOfBirth` | Owner's date of birth |
| `secondaryEmail` | Alternate email |
| `businessName` | For business accounts |
| `title`, `pronouns` | Salutation / pronouns |
| `declineEmail`, `declinePhone` | Contact preferences |
| `stopReminders` | Opt out of reminders |
| `tags` | Client tags |

---

## 2. Patient (pet) — `PatientInput`

### Practical minimum
| Field | Notes |
|---|---|
| `name` | Pet's name |
| `species` | e.g. Canine / Feline |

### Currently collected by the plugin
- Pet name, species (required in our form)
- With the **"Ask for extended pet details"** toggle enabled (all optional, same single screen):
  `breed`, `sex` (MALE / FEMALE), age in years (stored as estimated `birthYear` + `isEstimatedAge`), `neutered`

### Available but NOT collected (candidates to add)
| Field | What it is |
|---|---|
| `birthDate` | Exact date of birth (instead of estimated age) |
| `color` | Coat color |
| `microchip` | Microchip number |
| `isMixed` | Mixed breed flag |
| `identification` | Other ID |
| `alerts` | Allergies / warnings the doctor should see |
| `notes` | Free-form notes on the pet |
| `vitals` | Weight and other vitals |

---

## 3. Appointment — `AppointmentInput`

### Practical minimum
| Field | Notes |
|---|---|
| `locationId` | Clinic location |
| `appointmentTypeId` | Must be `canBookOnline: true` (plugin enforces this) |
| `patientId` | Created/reused by the plugin |
| `start` | ISO-8601 datetime in the clinic's timezone |
| `duration` | Taken from the appointment type, never from the visitor |

### Currently sent by the plugin
- The 5 above, plus `providerId` / `scheduleId` (from the live-validated slot),
  `bookedOnline: true`, `sendConfirmation: true` (Vetspire emails the client),
  `reason` (the "Reason for visit" field)

### Available but NOT used (candidates to add)
| Field | What it is |
|---|---|
| `note` | Internal staff note |
| `reasonCategoryId` | Predefined reason categories |
| `visitType` | Visit type classification |
| `dropOffStart` / `dropOffEnd` | Drop-off style appointments |
| `room` | Room assignment |
| `intake` | Intake form data |
| `numberOfPets` (on `availableTimes`) | Availability check for multi-pet visits |

---

## Recommended additions (by value, not by capability)

1. **"How did you hear about us?"** (`customReferralSource`) — one optional dropdown
   (Google, Facebook, a friend, drive-by…). Highest value: cross-check the
   self-reported source against Hyros/GA4 attribution.
2. **Provider picker** — `availableTimes` already returns the provider per slot;
   let visitors filter by doctor (multi-doctor clinics).
3. **Multi-pet booking** (`numberOfPets`) — "How many pets are you bringing?"
   and validate availability for that count (Vetspire's own widget supports it).
4. **Pet alerts / allergies** (`alerts`) — optional "Anything the doctor should
   know?" stored structured instead of inside `reason`.
5. Microchip / exact birth date — only if a specific clinic asks; low pre-visit value.

**Not recommended for the online form** (pure friction, reception completes these
at the first visit): postal address, owner date of birth, pronouns, business name.

---

*Source: GraphQL introspection of `api.vetspire.com` (Aug 2026) + live booking
tests against the EasyVet org. Schema-level "required" markers are nearly absent;
practical minimums verified by creating real appointments.*
