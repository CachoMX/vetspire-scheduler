# Online Scheduler: WCAG 2.2 Level A / AA checklist

Plugin version 1.28.0, checked October 16, 2026. This covers the booking widget itself (date strip, time buttons,
popups, booking forms, clinic info panel). The rest of each clinic's website (theme, menus, cookie banner) is outside
the plugin and is not covered here.

**How it was checked:** automated browser tests run on every release (`tests/wcag-forms-e2e.js`, 76 checks, and the
keyboard and focus suite, 33 checks), axe-core accessibility scans of every popup and form state (including the error
states), and a manual review of the criteria automated tools can't judge.

## The items from the request

| Request | Status | What a visitor gets |
|---|---|---|
| Visible "action item selection" for keyboard users | Done (1.19.0, kept) | A thick focus outline on whatever control has keyboard focus, on every theme |
| ARIA / HTML tags for screen readers | Done | Popups are announced as dialogs with a name (e.g. "Wellness Appointment"); days announce "selected"; loading, success and errors are announced |
| Every form field has a visible label and an accessible label | Done | Each field has a visible label above it, linked to the field in the code (`<label for>` + `id`) |
| "* Indicates a required field" at the top of each popup page with form fields | Done | Shown at the top of every booking step that has a required field |
| Red "*" in the visible label of required fields, plus the code marker | Done | Red asterisk (hidden from screen readers) and `required` + `aria-required="true"` on the field |
| Error identification (3.3.1) | Done | The error is written in text under the field, starting with "Error:" |
| Error suggestion (3.3.3) | Done | Every error says how to fix it, e.g. "Please enter a valid email address, like name@example.com." |
| No color-only signaling (1.4.1) | Done | Red border **and** a warning icon **and** the word "Error:" |
| Error markup uses aria-describedby | Done | The field gets `aria-invalid="true"` and `aria-describedby` pointing at its error text, so screen readers read the error with the field; focus moves to the first field with an error |

Errors found by the clinic's practice system after sending (for example the "last 4 digits of the phone" check)
are also shown under the field they belong to. Errors that are not about a field (the time was just taken, the system
is down) show at the bottom of the form with the same icon and "Error:" text, and receive focus.

## Every applicable WCAG 2.2 A / AA criterion

| Criterion | Level | Status | Notes |
|---|---|---|---|
| 1.1.1 Non-text content | A | Pass | Icons are hidden from screen readers next to text; the map has a title |
| 1.3.1 Info and relationships | A | Pass | Labels linked to fields, required state in code, dialogs and headings marked up |
| 1.3.2 Meaningful sequence | A | Pass | Reading order matches the visual order |
| 1.3.5 Identify input purpose | AA | Pass | The client's own fields declare their purpose (name, email, phone, address, birthday...) so browsers can autofill |
| 1.4.1 Use of color | A | Pass | Errors use text and an icon; the selected day also changes fill and is announced as selected |
| 1.4.3 Contrast (minimum) | AA | Pass, with one clinic setting | Widget text passes. The clinic's own brand color is used on some text; Iowa Colony's `#d3455a` is below 4.5:1 on white. That is the clinic's color choice, not the plugin's |
| 1.4.4 Resize text | AA | Pass | Sizes are relative; text scales to 200% |
| 1.4.10 Reflow | AA | Pass | At 320 px wide the form fits with no sideways scrolling (tested) |
| 1.4.11 Non-text contrast | AA | Pass (fixed in 1.28.0) | Field borders were light gray (1.6:1); now 4.5:1 |
| 1.4.12 Text spacing | AA | Pass | No fixed-height text boxes that would clip larger spacing |
| 1.4.13 Content on hover or focus | AA | Not applicable | No hover pop-ups |
| 2.1.1 Keyboard | A | Pass | Everything works with the keyboard (tested along the whole date strip and every step) |
| 2.1.2 No keyboard trap | A | Pass | Popups keep focus inside while open; Escape or the close button leaves |
| 2.4.3 Focus order | A | Pass | Each popup starts on its first control (never the ×) and returns focus to the button that opened it |
| 2.4.6 Headings and labels | AA | Pass | Labels describe each field |
| 2.4.7 Focus visible | AA | Pass | Thick outline on the focused control |
| 2.4.11 Focus not obscured (minimum) | AA | Pass | The focused control scrolls into view inside the popup |
| 2.5.3 Label in name | A | Pass | Spoken names include the visible text (e.g. "10:30 AM, Thursday...") |
| 2.5.8 Target size (minimum) | AA | Pass | Every button and link is at least 24 x 24 px (tested) |
| 3.2.1 On focus / 3.2.2 On input | A | Pass | Focusing or filling a field never changes the page by itself |
| 3.3.1 Error identification | A | Pass (1.28.0) | See above |
| 3.3.2 Labels or instructions | A | Pass | Visible labels plus the required-field note |
| 3.3.3 Error suggestion | AA | Pass (1.28.0) | See above |
| 3.3.4 Error prevention (legal, financial, data) | AA | Not applicable | Booking a visit is not a legal or financial transaction |
| 3.3.7 Redundant entry | A | Pass | An email typed on one step is carried to the next, never asked twice |
| 3.3.8 Accessible authentication (minimum) | AA | Pass | The "last 4 digits of the phone" check has an alternative: "Can't verify? Book with the full form instead" |
| 4.1.2 Name, role, value | A | Pass (dialog names added in 1.28.0) | Dialogs, buttons, selected days and fields expose their name and state |
| 4.1.3 Status messages | AA | Pass | Loading, success and error messages are announced without moving the page |

## Also fixed in 1.28.0

On phones, swiping inside an open popup no longer scrolls the page behind it. The page stays where it was and
comes back to the same spot when the popup closes.
