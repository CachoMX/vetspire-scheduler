# SOW — Vetspire Scheduler: Pilot Launch
**Source:** Team demo meeting (Carlos, Drew, Jessica, Nicole, Abi) — Aug 28, 2026
**Status:** DRAFT v2 (QA-reviewed) — for approval, no work started
**Legend:** items marked **[PROPOSAL]** were not decided in the meeting — they are the
author's suggested implementation and need sign-off. Everything else was said/decided.

**Timeline constraint:** Carlos is out Aug 31–Sep 4. Analytics meeting early week of
Sep 8. Paul call ≈ Sep 10–11. Phase 0 dev lands Sep 8–12 → launch **Tuesday/Wednesday
Sep 15–16** (Nicole: never Thu/Fri; Jess: not Monday). The meeting's "~two weeks" matches.

---

## Objective
Take the scheduler from demo to a live 30-day pilot at easyvet Iowa Colony (+ Katy),
measuring appointments booked and full-funnel analytics as first-party data. Heritage
(2 doctors) was named by Drew as a good candidate to test multi-doctor afterwards.

---

## Phase 0 — Code changes before the pilot (owner: Carlos, ≈5–6 dev days)

### 0.1 Remove competitor names from the product ⚡
Drew: never label these tools with corporate group names (legal exposure).
- Settings UI already uses neutral labels (Full / Bar / Calendar / Floating card);
  remaining occurrences are code comments (~10), demo-page copy, and repo docs.
- Acceptance: no Thrive/Chewy/VCA references in the **working tree, UI, or docs**
  (git history excluded — rewriting it would break the updater's release lineage).
- Est: 0.5 h

### 0.2 Repo: private, under the company account
Decided in the meeting: repo will be **private** under the webscelerator account.
Pending detail: Drew to send the account/Heroku consolidation info (see 1.9).
- Transfer `CachoMX/vetspire-scheduler` → company GitHub, set private.
- Impact (verified in code): the auto-updater has no auth today; a private repo requires
  a read-only token on each client site (`setAuthentication` + fine-grained PAT).
- Acceptance: repo private under company account; one-click update re-verified E2E.
- Est: 2–3 h

### 0.3 New vs. Existing client flow 🔴 the big UX change (Drew)
After picking a time, ask **"Have you visited us before?"**
- **Existing:** enter email → look up client in Vetspire → pick which pet → confirm.
  No pet-detail questions. (Plumbing already exists: email dedupe + patients list.)
- **New:** current short form, unchanged.
- Analytics: add `vsps_client_type` (new|existing) to events.
- **[PROPOSAL — needs Drew/Nicole sign-off]** privacy design for the lookup: server
  returns **pet names only** (never owner name/phone), hard rate-limited. Note Drew's
  caution that customer data is "a gray area"; pet names by bare email is a conscious
  tradeoff to keep the flow frictionless. Alternative: email a one-time code first
  (adds friction).
- Acceptance: existing client books with email + pet pick + confirm; events carry
  client_type; lookup rate-limited.
- **Implementation note (QA, Aug 28):** adding a NEW pet to an existing account with
  only an email was deliberately excluded (record-write abuse surface) — returning
  clients with a new pet go through the full form (dedupe reuses their account).
  A lighter path needs the same Drew/Nicole sign-off as the lookup design.
- Est: 1.5–2 days

### 0.4 Per-field checkboxes for the optional questions **[PROPOSAL]**
Carlos floated backend checkboxes to pick which optional fields the form asks
(breed, sex, age, spayed/neutered — only API-backed fields, per the field document).
**Open decision:** Nicole wants field choice controlled by *us* (best practice), not by
clinics. Since clinics get **no wp-admin access** (Jess), the checkboxes are effectively
OUR control tool — this likely resolves Nicole's concern, but the team should confirm.
- Prerequisite: team reviews `docs/api-fields.md` and picks the allowed field set (1.10).
- Default: all optional fields OFF (minimal form).
- Est: 0.5 day

### 0.5 A/B testing support (Jess: bare-minimum vs more-questions, side by side)
- **[PROPOSAL]** mechanism: shortcode attribute `variant="a|b"` overriding the optional
  field set + `vsps_variant` on every event → two pages run side by side a couple of weeks.
  (No auto-split in v1 — sticky assignment adds complexity for little gain.)
- Est: 0.5 day

### 0.6 Analytics additions for the funnel story
- `vsps_after_hours` (true/false) + booked-at timestamp on `vsps_booking_completed`,
  computed against the clinic's real `locationHours` (already fetched by the plugin).
  Drew: "~40% of appointments are booked after hours" is the value proof.
- `vsps_form_started` event when the booking form opens (Jess wants "form started →
  form completed" on the dashboard; today the closest event is `slot_selected`).
- Acceptance: parameters verified in dataLayer for in-hours and after-hours bookings;
  the after-hours flag is computed server-side (echoed in the `/book` response).
- Est: 0.5 day

### 0.7 Admin "Appointments" view — go-live hardening
Decisions from the meeting: the view **stays** (Jess) and is internal-only (clinics
never get wp-admin). Nicole: the **Cancel** action must be removed from general view
before go-live (accidental clicks on real appointments); Carlos: last change before launch.
- **[PROPOSAL]** implement the removal as a setting ("enable appointment actions")
  defaulting **OFF at go-live** — same effect as removal, but reversible for testing.
  When off, actions are blocked **server-side**, not just hidden. Covers Confirm/
  Reschedule/Cancel as a group (Nicole named Cancel; grouping is the author's call).
- **Column controls** (Carlos said configurable in the demo; the current code is
  hardcoded — this is net-new): show/hide columns; **[PROPOSAL]** hide the client
  name/phone column by default (Drew: customer name is the gray area; pet name is fine).
- Est: 0.5–1 day

### 0.8 Configurable booking source label
Carlos offered renaming the "online" source (e.g. "Vetcelerator") to show how many
appointments the platform sends the clinic.
- Acceptance: setting exists; the label is carried in the appointment **reason field**
  and our admin badge. (Whether Vetspire's own UI surfaces it in its source column is
  unverified — do not promise that.)
- Est: 2 h

### 0.9 Mobile behavior for Bar AND Calendar
Carlos in the meeting: Full and Floating are fine on mobile; **Bar is not** ("hide it
and use another one") — and **Calendar also** needs mobile work. Current CSS has no
real responsive handling for either.
- Auto-fallback below a breakpoint: Bar → Floating card; Calendar → verified/fixed
  responsive rendering.
- Acceptance: browser check at 375px for all four designs.
- Est: 0.5–1 day

---

## Phase 1 — Launch operations (not code)

| # | Item | Owner |
|---|---|---|
| 1.1 | Analytics working session: event naming, dashboard shows **"Appointments booked"** (not "key events"), form started → completed funnel, after-hours split, first-party data → real-time ads automation plan (today it's manual monthly batches) | **Jess** + Carlos, Nicole, Brandon (early week of Sep 8) |
| 1.2 | Legal review: ToS/marketing-use language on sites collecting the form; overall legal agreements | **Drew** ("I'll take the ball") |
| 1.3 | Per-clinic Vetspire API keys (Iowa Colony/Katy exists; one key per account, easy to generate) | **Drew** |
| 1.4 | Baseline: Paul's current online-appointment volume for the 30-day comparison | **Jess/Drew** |
| 1.5 | Check Vetspire's confirmation-email configuration (no reschedule link today — "sounds like a Vetspire issue"); team has full Vetspire admin access to test | **Drew/team** |
| 1.6 | Call with Paul + his CS team: going live, feedback loop, "if it breaks, tell us" (≈ Sep 10–11) | **Drew/Jess** |
| 1.7 | Go-live **Tue/Wed Sep 15–16**; team available to troubleshoot; run 30 days, collect feedback | All |
| 1.8 | Full live end-to-end test runs before flipping on (using our own Vetspire access) | **[PROPOSAL]** Carlos + Jess |
| 1.9 | Send Carlos the company GitHub/Heroku consolidation details (feeds 0.2) | **Drew** |
| 1.10 | Review `docs/api-fields.md` and approve the allowed optional-field set (feeds 0.4) | **Nicole + Jess + Carlos** |
| 1.11 | Verify the Vetspire API terms mention no usage cost at fleet scale (Drew's ask) | **Carlos** |

---

## Phase 2 — Post-pilot backlog (build only after data / explicit go)

| # | Item | Trigger / note |
|---|---|---|
| 2.1 | Self-service reschedule/cancel ("Reschedule?" link → email → manage booking), behind an admin toggle default OFF. ⚠️ **This defers what Carlos committed to build in the meeting** — deferred here because of Nicole's compliance concern (serial reschedulers) and to keep the pilot scope tight; if the team wants it in the pilot, add ~1.5 days to Phase 0. **[PROPOSAL]** add email verification (one-time code) rather than bare email entry. | Team decision |
| 2.2 | Provider/doctor picker (Drew asked "can you build that?"; Carlos needs an account with multiple doctors to design it — Drew: "we will get that at some point"; Heritage has 2 doctors) | Heritage (or similar) API key |
| 2.3 | Real-time first-party data push to ads platforms (Jess: "that'd be sick") | Output of 1.1 |
| 2.4 | Multi-pet booking (`numberOfPets` in the API) | Clinic demand |
| 2.5 | GPO portal integration (see/manage appointments in the portal) | Rodrigo's side — "much more down the road" (Jess) |
| 2.6 | CSV export of the admin view | Likely unnecessary — Jess: GA4 events cover segmentation |

## Explicitly OUT of scope now
- **Deposits/payments** — Drew: people will ask; deliberately not building yet.
- Per-clinic question customization beyond our approved field set (Nicole: we prescribe
  best practice; extra info is collected by phone / at the door / in the exam room).
- Fixing Vetspire's own confirmation email (their product).

---

## Risks & open questions
1. **Private repo vs. auto-updates** (0.2): requires a read token per install — small
   per-site operational step. Verified against the current updater code.
2. **Existing-client lookup privacy** (0.3): pet-name disclosure by bare email is a
   **proposal needing Drew/Nicole sign-off**, not a settled decision.
3. **Who controls the field checkboxes** (0.4): needs an explicit call — mitigated by
   clinics having no wp-admin access, but confirm with Nicole.
4. **Data retention**: the plugin stores nothing locally (admin view reads live from
   Vetspire); the real retention surface is the **analytics events** (GA4/ads). Covered
   by 1.2 (ToS) — keep client PII out of event parameters (current events carry none).
5. **Timeline**: Phase 0 (≈5–6 days) runs Sep 8–12, overlapping the analytics meeting
   and Paul call — the call is a heads-up/demo, not the launch, so overlap is fine;
   launch lands Sep 15–16. Any Phase-0 scope growth pushes launch a week.
