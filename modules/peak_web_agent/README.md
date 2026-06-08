# Peak Web Agent

A guardrailed **"Web Design Editor"** AI sub-agent (a *minion*) for the Peak Stack **Master Agent**. It lets a non-technical user **edit website copy and images on existing pages by chatting in plain language** — *"change the homepage headline to X", "update the pricing copy", "fix the typo on the about page"* — without ever touching code or breaking the site.

This is the local, Drupal-native answer to the "AI website CMS" pattern: instead of a separate MongoDB/Vercel CMS with a cloud LLM, the editing runs inside Drupal, on the AI provider you choose, with Drupal's own revisions as the safety net.

## What it does
- Routes under the **Master Agent** (`master_agent`): any request to change what an *existing* page **says or shows** is handed to this `peak_web_editor` agent.
- Edits **content field values only** — text, headings, body copy, summaries, link text, image alt-text.
- Reads the current values first, then makes the **smallest** change that satisfies the request.

## The guardrails (why clients can't break the kingdom)
This agent **cannot**:
- delete content;
- create or change content types, fields, displays, layouts, blocks, or any site structure (that is a separate site-builder agent's job);
- touch users, roles, permissions, billing/project (`pm_*`) entities, taxonomy structure, or configuration.

Every edit **saves a new Drupal revision**, so any change is **rollback-able** from the node's *Revisions* tab — no custom snapshot system needed; Drupal already versions content. Publishing/unpublishing only happens if explicitly requested.

## Architecture (Peak Stack)
```
Master Agent (orchestrator / router)
   ├─ master-agent-search   (search minion — shipped)
   ├─ article generation    (content minion)
   ├─ email agent           (inbox minion)
   └─ peak_web_editor       (THIS — web-design / copy-editing minion)   ← new
```
The agent is shipped as **config** (`config/install/ai_agents.ai_agent.peak_web_editor.yml`) so it deploys with the module to any Peak Stack tenant. Tune the `description`/`system_prompt`/`tools` per tenant if needed.

## AI provider (local vs cloud)
- **On Peak AI Design (dogfood):** uses the site default provider (currently OpenAI `gpt-4.1`). This is the owner's own public marketing copy, so cloud is acceptable to get it working.
- **For regulated Peak Stack tenants (ISPs, in-house-data mandate):** point the `ai.settings` chat providers at a **local Ollama** provider so no content leaves the tenant's server. This is the differentiator — the same feature, fully local. See the `no-cloud-business-data` rule.

## Install / test
```bash
# Local Docker (peakaidesign :8081) first — do NOT debut on prod:
drush en peak_web_agent -y
drush cr
```
Then open the AI Agents Explorer (`/admin/config/ai/agents` → *Explorer*) or the site chatbot, select / route to **Web Design Editor**, and try:
> "On the About page, change the headline to 'Built by engineers, run by AI'."

Confirm: the node updates, a **new revision** appears under the node's *Revisions* tab, and structure is untouched. Then deploy to prod when satisfied.

## Status
v0.1 — content-editing minion shipped as config. Next: wire it explicitly into the `master_agent` router's routable-agents list, add per-tenant scoping (limit to specific content types), and the local-Ollama provider switch for the productized version.
