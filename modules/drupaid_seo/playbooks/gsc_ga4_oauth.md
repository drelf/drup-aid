# Playbook: Connect Google Search Console + GA4 for data pulls

Use this so the agent can later pull search + analytics data automatically. Manual — it needs the user's Google Cloud + property access.

## What you're setting up
A **service account** (preferred for server automation) granted read access to the GSC property and the GA4 property, so the site can call the Search Console API and the GA4 Data API without a human logging in.

## Steps
1. **Google Cloud project**: go to console.cloud.google.com → create (or pick) a project.
2. **Enable APIs**: APIs & Services → Enable APIs → enable **Google Search Console API**, **Google Analytics Data API**, and (for Core Web Vitals) **PageSpeed Insights API**.
3. **Create a service account**: IAM & Admin → Service Accounts → Create. Give it a name like `seo-agent`. Create a **JSON key** and download it — this is the credential the site stores (keep it secret; never commit it).
4. **Grant Search Console access**: in Search Console → Settings → Users and permissions → Add user → paste the service account email (ends in `...iam.gserviceaccount.com`) → role **Full** or **Restricted (read)**.
5. **Grant GA4 access**: GA4 Admin → Property Access Management → Add → paste the same service account email → role **Viewer**.
6. **Get a PageSpeed/CrUX API key** (separate, simple): Google Cloud → Credentials → Create credentials → API key. Used for Core Web Vitals field data.
7. **Store the credentials in Drupal**: save the service-account JSON and the API key via the **Key** module (already installed) — never in code or config that gets exported.

## What to confirm back to the user
- The service-account email that now has access (so they can audit it later).
- Which property IDs are connected (GSC site URL + GA4 property ID, format `properties/123456789`).
- Reminder: rotate/revoke the key if it's ever exposed.
