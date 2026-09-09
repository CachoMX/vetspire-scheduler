# Vetspire Scheduler — Analytics Events Reference

The widget pushes every event to `window.dataLayer` (the standard Google Tag Manager
hand-off) and also dispatches it as a DOM `CustomEvent` (`vetspire:<name>`). The plugin
never talks to GA4 directly — GTM triggers/tags forward the events into GA4.

**Pilot wiring (Iowa Colony):** GTM container **GTM-T2GFBPP8** → GA4 property
**G-4RWBKVZSHW**. Import the attached `gtm-container-vetspire-scheduler.json` into that container
(see "Setup steps" below).

> All parameter keys are prefixed `vsps_` in the dataLayer (e.g. `vsps_date`).
> The GTM container strips the prefix when sending to GA4 (`location_id`, `date`, …).

---

## Funnel

```
vsps_widget_view → vsps_slot_selected → vsps_form_started → vsps_booking_submitted → vsps_booking_completed
                                                                                   ↘ vsps_booking_failed
vsps_location_details_open (side action: clinic info drawer)
```

**Key event / conversion:** `vsps_booking_completed`.

---

## Events and parameters

| Event | When it fires | Parameters (dataLayer keys) |
|---|---|---|
| `vsps_widget_view` | Widget rendered on the page (one per widget) | `vsps_location_id`, `vsps_layout` |
| `vsps_slot_selected` | Visitor clicks a time | `vsps_location_id`, `vsps_appointment_type_id`, `vsps_date`, `vsps_time`, `vsps_layout` |
| `vsps_form_started` | Booking modal opens (new-vs-existing choice) | `vsps_location_id`, `vsps_appointment_type_id`, `vsps_date`, `vsps_time`, `vsps_layout` |
| `vsps_booking_submitted` | Visitor presses Confirm Booking | `vsps_location_id`, `vsps_appointment_type_id`, `vsps_date`, `vsps_time`, `vsps_client_type` |
| `vsps_booking_completed` | Vetspire confirmed the appointment | `vsps_location_id`, `vsps_appointment_type_id`, `vsps_appointment_id`, `vsps_date`, `vsps_time`, `vsps_client_type`, `vsps_after_hours`, `vsps_booked_at` |
| `vsps_booking_failed` | Booking rejected (slot taken, validation, verification…) | `vsps_location_id`, `vsps_status`, `vsps_client_type` |
| `vsps_location_details_open` | Visitor opens the clinic info drawer | `vsps_location_id` |

`vsps_variant` (`a` / `b`) is added to **every** event when the shortcode uses `variant="a|b"` (A/B testing). Absent otherwise.

### Parameter dictionary

| Key | Type | Values / notes |
|---|---|---|
| `vsps_location_id` | number | Vetspire location id (Iowa Colony = 23539) |
| `vsps_appointment_type_id` | string | Vetspire appointment type id (e.g. `5541` = Wellness) |
| `vsps_appointment_id` | string | Vetspire appointment id of the created booking |
| `vsps_date` | string | `YYYY-MM-DD`, clinic-local |
| `vsps_time` | string | `HH:MM` 24h, clinic-local |
| `vsps_layout` | string | `full` / `bar` / `calendar` / `float` — which design the visitor used |
| `vsps_client_type` | string | `new` / `existing` — returning-client fast path vs new form |
| `vsps_after_hours` | boolean | `true` when booked outside the clinic's business hours (computed server-side in the clinic's timezone from Vetspire hours); `null` if hours unknown |
| `vsps_booked_at` | string | ISO-8601 timestamp of the booking moment, clinic timezone (e.g. `2026-08-28T15:50:54-05:00`) |
| `vsps_status` | number | HTTP status of the failed booking (409 = slot taken / not verified, 429 = rate limited, 400 = validation) |
| `vsps_variant` | string | `a` (minimal form) / `b` (with optional questions) |

---

## GA4 custom definitions to create (event-scoped)

So the parameters are usable in reports/explorations (Admin → Custom definitions → Create custom dimension, scope **Event**):

| Dimension name | Event parameter |
|---|---|
| Widget design | `layout` |
| Client type | `client_type` |
| A/B variant | `variant` |
| After hours | `after_hours` |
| Appointment type | `appointment_type_id` |
| Location | `location_id` |
| Failure status | `status` |

(`date`, `time`, `booked_at`, `appointment_id` are high-cardinality — keep them as parameters for exports/BigQuery, don't register as dimensions.)

---

## Setup steps (Jess)

1. **Import the container:** GTM → container **GTM-T2GFBPP8** → Admin → Import Container → choose the attached `gtm-container-vetspire-scheduler.json` → *Existing* workspace → **Merge** (rename conflicting). It adds 7 triggers, 7 GA4 event tags and 11 data-layer variables, all prefixed `VSPS`.
2. **Measurement ID:** the event tags send to `G-4RWBKVZSHW` via `measurementIdOverride`. The page already loads that Google tag — **don't add a second one** or you'll double page_views.
3. **Preview:** GTM → Preview → open `https://iowacolony.easyvet.com/home-2/` → in Tag Assistant watch `vsps_widget_view` fire on load; click a time → `vsps_slot_selected` + `vsps_form_started`; complete a test booking (a couple days out, delete in our Appointments view after) → `vsps_booking_submitted` + `vsps_booking_completed`.
4. **DebugView:** GA4 → Admin → DebugView shows the same events landing in real time with their parameters.
5. **Publish** the workspace.
6. **Key event:** GA4 → Admin → Events → mark `vsps_booking_completed` as a key event. (Optional: also `vsps_form_started` as a micro-conversion.)
7. **Dashboard label:** report `vsps_booking_completed` as **"Appointments booked"** (per Jess); `vsps_after_hours = true` share = the "booked outside office hours" metric (Drew).
8. **Ads:** once the key event exists, import it into Google Ads as a conversion for Iowa Colony's Ads account (`AW-10949229671`) — **not** Overland Park's stray tag.

### Manual sanity check (any browser, no GTM needed)

On a page with the widget, open DevTools → Console:

```js
dataLayer.filter(e => e.event && e.event.startsWith('vsps_'))
```

Interact with the widget and re-run — every step appears with its parameters.

### Custom listeners (no GTM)

```js
document.addEventListener('vetspire:booking_completed', e => console.log(e.detail));
```
