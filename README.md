# Vetspire Scheduler — WordPress Plugin

Embeddable appointment scheduler powered by the [Vetspire API](https://developer.vetspire.com).
Shows live available times and completes the booking **on-site**, so analytics attribution
(Hyros / GTM / GA4) is preserved instead of losing the visitor to an external booking page.

Built to be reused across multiple clinics: each site configures its own Vetspire API key.

## How it works

```
Visitor ──> Widget (JS) ──> WP REST proxy (PHP, cached) ──> Vetspire GraphQL API
                                   │
                                   └── the admin API token NEVER reaches the browser
```

- **Availability**: `availableTimes` query per date, filtered to appointment types with
  `canBookOnline: true`, cached in transients (default 5 min).
- **Booking**: dedupe client by email (`clients(filters:{email})`) → reuse or `createClient`
  → reuse patient by name or `createPatient` → `createAppointment` with `bookedOnline: true`
  and `sendConfirmation: true` (Vetspire emails the client the confirmation).
- **Times & timezones**: slot times are clinic-local; the plugin converts them using the
  location's `timezone` from the API before sending the ISO8601 `start` (verified E2E:
  10:30 America/New_York stored as 14:30Z).

## Install

1. Copy this folder to `wp-content/plugins/vetspire-scheduler/` (or zip it and upload).
2. Activate, then go to **Settings → Vetspire Scheduler**:
   - Paste the clinic's Vetspire API key (Vetspire → Admin → API Keys), or better, define
     `VSPS_API_TOKEN` in `wp-config.php` so it never lives in the DB.
   - Click **Test Connection** — it lists the org's locations with their IDs.
   - Set the default Location ID (and optionally the Allowed Location IDs allow-list —
     once set, only those locations can be queried or booked from this site).
3. Drop the shortcode in any page/builder module:

```
[vetspire_scheduler location_id="23539" days="7"]
```

### Shortcode attributes

| Attribute | Default | Purpose |
|---|---|---|
| `location_id` | settings default | Vetspire location to show |
| `days` | `7` | days of availability shown (max 14) |
| `appointment_type_ids` | all bookable | comma-separated whitelist, e.g. `"5541"` for wellness-only |
| `mode` | `book` | `book` = on-site booking modal; `link` = clicking a slot goes to `link_url` |
| `link_url` | — | external booking URL for `mode="link"` (Option-2-lite / phased rollout) |
| `title` | Book an Appointment | widget heading |

## Analytics events

When enabled (Settings), every step pushes to `window.dataLayer` (GTM/Hyros/GA4-ready)
and also fires DOM `CustomEvent`s (`vetspire:*`) for custom listeners:

| Event | When | Payload |
|---|---|---|
| `vsps_widget_view` | widget rendered | location_id |
| `vsps_slot_selected` | visitor clicks a time | location, type, date, time |
| `vsps_booking_submitted` | form submitted | location, type, date, time |
| `vsps_booking_completed` | Vetspire confirmed | + appointment_id |
| `vsps_booking_failed` | error | status |

Because booking happens on-domain, the Hyros universal script also captures the email/phone
typed into the form → full ad-to-appointment attribution.

## Security

- Admin token is only used server-side (`wp_remote_post`); REST responses expose no Vetspire IDs
  beyond the appointment id. Never ship a `.env` inside the plugin folder (the bundled
  `.htaccess` denies dotfiles as a backstop, and `.gitignore` excludes it).
- **Server-side slot re-validation:** `/book` never trusts the client. The appointment type must
  be `canBookOnline` at that location, duration comes from the type definition, and the
  date/time must match live `availableTimes` (provider/schedule are taken from the matched
  slot). Forged requests get a 409.
- **Location allow-list:** when a default/allowed location is configured, requests for any other
  location in the same Vetspire org are rejected (403).
- Public `/book` endpoint: honeypot + per-IP rate limit (5/hour) + per-email limit (5/day),
  charged only after validation passes. Non-existent local times (DST gap) rejected.
- Rate limiting keys on `REMOTE_ADDR`; behind Cloudflare, hook `vsps_client_ip` to return the
  verified `CF-Connecting-IP`.
- Logs are redacted (no emails); visitor-facing errors are generic.
- All input sanitized; GraphQL uses variables (no string interpolation).
- `manage_options` required for the test-connection endpoint and settings.
- Successful bookings invalidate that day's availability cache immediately.

Reviewed by security + code review passes (2026-08-26); all HIGH findings fixed. Known accepted
limitations: transient-based rate counter is not atomic under burst (soft brake only — slot
re-validation bounds the damage), and a CAPTCHA (e.g. Turnstile) is recommended before
high-traffic rollout.

## Development / tests

Local PHP harness (no WordPress needed) runs the real API E2E, including a booking at the
org's TESTING LOCATION that is deleted afterwards:

```
php tests/harness.php            # 22 checks: connectivity, types, slots, cache, dedupe, guardrails, booking, cleanup
php -S 127.0.0.1:8737 tests/demo-server.php   # visual demo at http://127.0.0.1:8737
node <scratchpad>/visual-test.js # puppeteer: renders, books, checks dataLayer events
```

Requires PHP with `openssl` (and `curl` for WP parity). `.env` must contain `VETSPIRE_API_TOKEN`.

## Vetspire API notes (learned during development)

- Endpoint `https://api.vetspire.com/graphql`; header `Authorization: <key>`; query depth ≤ 8.
- Org query field is `org`, not `organization`.
- `AvailableTimeSlot.scheduleId` can be null — treat as optional.
- `Sex` enum is only `MALE | FEMALE | UNKNOWN`.
- `date` field within `availableTimes` results returns null; use the requested date.
- `appointments(locationId:…, start:…)` for reading back; `deleteAppointment(id)` works.
