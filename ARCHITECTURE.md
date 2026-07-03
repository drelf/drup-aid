# Drup-AID — Architecture

## The shape

One **master orchestrator** inside Drupal that a site owner talks to in plain language. It doesn't do the work itself — it routes the request to the right **specialist agent**, then reports back. Specialists are Drupal `ai_agents` sub-agents; each carries deterministic **tools** (function calls) that do the real work.

```
Owner ─▶ Master (drupaid_master, local-LLM routing)
              │  picks ONE specialist
              ▼
  Concierge   SEO   Content   Security   Lead Desk
     │         │       │         │          │
     ▼         ▼       ▼         ▼          ▼
  local_status audit  write   security   list/capture
  (time/wx/news)(GEO) articles  audit      leads
```

A parallel path exists for **n8n minions over MCP** (the "shelf") — Drupal's `mcp_client` reaches an n8n workflow that exposes a tool. This is used where a deterministic pipeline belongs in n8n; the OOTB agents above are Drupal-native and need no n8n.

## Design principles

1. **Prescribe, don't act (yet).** Maturity ladder: report → **prescribe** → act-with-approval → autonomous. Read-only agents return the *exact fix*; they don't change the site. Zero blast radius, more sellable, and it builds a trust record before anyone gets write access. (Content Writer is the exception — it acts, safely, because Drupal content is revisioned/rollback-able.)
2. **Two-tier prescriptions.** Every finding = a **plain-English fix** for the owner + a **technical drill-down** (exact code / config path / command) for an admin.
3. **Assimilate, don't reinvent.** The Security agent reads Drupal's *own* surfaces — the Update Manager (security releases for core + every module), the Status Report, and Security Review — and translates them. It is not a parallel scanner.
4. **Sovereign by default.** The LLM brain can be a local model (Ollama); business data never leaves the box. Cloud providers are an optional, cheaper-to-host tier.
5. **Self-configuring from the site.** The Concierge derives its location from the site's own timezone/country; the suite reads business identity from the Brand settings — no per-agent config.

## The thin-connector pattern (how to add an agent)

Every specialist is the same skeleton:
- a **module** that registers an `ai_agent` config entity (system prompt + which tools it can call),
- one or more **tool plugins** (`#[FunctionCall]`, deterministic where possible),
- wired into the master by adding `ai_agents::ai_agent::<id>` to `drupaid_master`'s tools + a module dependency.

Deterministic tools carry the value (fast, reliable, no LLM); the LLM only decides *which* specialist and narrates the result.

## Boundaries (out of scope for the in-Drupal agents)

Host-level security — open ports / live attack monitoring, TLS/cert, HTTP headers, SPF/DKIM/DMARC — cannot be seen from inside Drupal. Those belong to an external infra scanner, recommended (not faked) by the Security agent.
