# Playbook: Build a reporting dashboard in Looker Studio

Looker Studio (formerly Data Studio) dashboards are built in Google's UI — they can't be created from inside Drupal. Guide the user; the agent supplies the data definitions and the narrative.

## Steps
1. Go to lookerstudio.google.com → Create → Report.
2. **Add data sources** (Add data): connect **Search Console** (pick the site; both "Site impression" and "URL impression" tables), **Google Analytics (GA4)**, and optionally **PageSpeed Insights**.
3. **Build the standard SEO page** (one page, from the template pattern):
   - Scorecards: Clicks, Impressions, Avg CTR, Avg Position (GSC); Sessions, Engaged sessions, Conversions (GA4).
   - Time series: Clicks + Impressions over the date range.
   - Table: top Queries by Clicks (with CTR + Position).
   - Table: top Landing Pages by Sessions + Conversions (GA4).
   - Geo + Device breakdown (GA4).
4. **Add a date-range control** + a **filter control** (by query/page) so the client can self-serve.
5. **Validate the data** (critical QA step): spot-check 2–3 numbers against the GSC and GA4 UIs for the same date range. Watch for date-range mismatches and GSC's 2–3 day data lag.
6. **Share**: Share → set the client's email to Viewer, or publish a link. Schedule email delivery (File → Schedule email) for quarterly reporting.

## What to hand back to the user
- The dashboard link + who has access.
- A one-paragraph plain-language summary of what the current numbers say (trend up/down, top opportunity).
