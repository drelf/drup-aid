# Drup-AID Core

The shared foundation for the Drup-AID support suite. Two responsibilities, one
place each:

## 1. Brand identity (`BrandManager`)
`drupaid_core.settings` holds the tenant's brand name, legal company name,
support email/phone, public site URL, and conversational agent display names.
`BrandManager` exposes them to every minion. Set them once — in the admin form
at `/admin/config/drupaid`, via the recipe's `setup-branding.php`, or from
`tenant.yml` — and **no module hardcodes a company name, email, phone, or URL.**

## 2. AI brain (`AiBrain`)
Cloud-first. Every completion is answered by the Drupal AI module's default chat
provider, so the box works the moment a cloud LLM key is configured — no n8n, no
GPU, no local model.

- `complete($system, $user)` — one-shot generation (used by the KB writer).
- `supportTurn($payload)` — a conversational turn that drives a support/lead
  diagnosis and parses a `[STATE]{…}[/STATE]` block back into session state.

A tenant who wants the visual-workflow path can set
`drupaid_core.settings:n8n_enabled = true` and supply a webhook; turns then route
through n8n, with the cloud provider as the automatic fallback if n8n is down.

## 3. CORS
Every controller echoes the request Origin only when it is on the
tenant-configured allow-list from `BrandManager::corsAllowedOrigins()` —
replacing the hardcoded `peakaisupport.com` allow-lists the original modules
each carried.

## Why this module exists
The original Peak AI Support modules each carried their own copy of: the brand
strings, the OpenAI/n8n call, and the CORS list. Drup-AID Core collapses those
three duplicated concerns into one dependency every support minion shares. That
is what turns a single-tenant site into a product that installs for anyone.
