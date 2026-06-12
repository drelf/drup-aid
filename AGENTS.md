# Drup-AID minions (AI agents)

> **See them work:** enable `drup_aid_cockpit` and open `/admin/drup-aid/cockpit` — the whole roster in one chat room (USING.md §5).

Drup-AID ships its capabilities as **minions** — focused, guardrailed `ai_agents`
agents shipped as config, routed by the Canvas AI orchestrator. Each minion is
provider-agnostic: it runs on whatever LLM the site's `ai.settings` defaults point at.

This file documents the minions for site builders and for coding agents working on
the repo. (It also satisfies Drupal's emerging `AGENTS.md` convention.)

## Design rule (important)

An agent's `tools:` list may contain **only executable tools** — those whose plugin
class implements `Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface`.
**Never** list a `Children/` tool (e.g. `ai_agent:content_entity_field_value`): those
are array-schema children of an executable parent (`ai_agent:content_entity_seeder`)
and will throw a `TypeError` in the agent runner if called directly.

To get the authoritative executable-tool list on a live site:

```php
$mgr = \Drupal::service('plugin.manager.ai.function_calls');
// check each definition's class against ExecutableFunctionCallInterface
```

## Shipped minion

### Web Editor — `peak_web_editor` (module: `peak_web_agent`)

**What:** edits the copy, headings, summaries, and image alt-text on **existing**
pages/articles from plain-language chat ("fix the typo on the about page", "update the
pricing copy to say X").

**Guardrails:** content **values only** — it cannot create/delete content types, fields,
structure, users, or config. Reads current values first; makes the smallest change;
saves a rollback-able revision; only publishes/unpublishes on explicit request.

**Tools (all executable):** `list_content_entities`, `get_content_type_info`,
`get_current_content_entity_values`, `get_field_values_and_context`,
`content_entity_seeder` (the read/edit/save writer), `agent_ckeditor_output`,
and the node save/publish/unpublish action plugins.

**Status:** validated end-to-end on a cloud model (edited a live node title by chat).

## Roadmap minions (in the platform, landing here as each is validated)

- **Page Builder** — creates new pages to per-page-type design+SEO playbooks.
- **Security Monitor** — read-only posture checks over content/config.
