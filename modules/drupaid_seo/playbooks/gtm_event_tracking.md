# Playbook: Google Tag Manager event tracking

Use this to set up event tracking. This is manual — it needs access to the site's Google Tag Manager (GTM) account. Guide the user through it.

## Before you start
- Confirm the user has (or can create) a GTM account + container for this site.
- Confirm GA4 is the destination (you'll need the GA4 Measurement ID, format `G-XXXXXXXXXX`).

## Steps
1. **Install the container** (once): in GTM, copy the two snippets (`<head>` + `<body>`) and place them on every page. On Drupal, the `google_tag` contrib module injects these for you — recommend it instead of hand-editing the theme.
2. **Add the GA4 Configuration tag**: Tags → New → Google Analytics: GA4 Configuration → paste the Measurement ID → trigger: All Pages.
3. **Define the events to track.** Typical set: `form_submit`, `file_download`, `outbound_click`, `cta_click`, `scroll_depth`. For each:
   - Create a **Trigger** (e.g. Trigger type "Form Submission", or "Click - Just Links" with a condition on the CTA's class/URL).
   - Create a **GA4 Event tag**: Event Name = the event (e.g. `cta_click`), add parameters (e.g. `link_url`, `link_text`), Configuration tag = the GA4 config tag, Trigger = the trigger above.
4. **Use Preview mode** (GTM "Preview") to load the site and confirm each event fires with the right parameters before publishing.
5. **Publish** the container (Submit → Publish).
6. **Verify in GA4**: Reports → Realtime, or Admin → DebugView, and confirm events arrive. Mark key events as **conversions** in GA4 (Admin → Events → toggle "Mark as key event").

## What to hand back to the user
- The list of events you defined and what each means.
- A note that conversions take ~24h to populate standard reports (Realtime/DebugView are instant).
