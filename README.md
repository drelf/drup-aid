<p align="center">
  <img src="assets/drup-aid-mascot.png" alt="Drup-AID" width="300">
</p>

<h1 align="center">Drup-AID</h1>
<p align="center"><em>the self-building, AI-Designed flavor of Drupal</em></p>

> **Drup-AID** = **Drup**al + **AID** (**AI D**esign). It reads like Kool-Aid on purpose — drink the Drup-AID. 🧃
> Install it on an ordinary hosting account, point it at one LLM API key, and it comes up running: you **build and edit your site by chatting with it**. No Docker, no GPU, no local model to babysit.

---

## What it is

Drup-AID is a thin, opinionated **assembly of Drupal's official AI rails** + a small set of **guardrailed minions** (focused AI agents), packaged so a non-technical owner can run an AI-driven site without touching code. It rides — it does not replace — the [Drupal AI initiative](https://www.drupal.org/about/starshot/initiatives/ai): `ai`, `ai_agents`, `canvas` + `canvas_ai`, and a provider.

The differentiator isn't "autonomous Drupal" (the initiative already ships that). It's the **turnkey packaging** — *one command, point at a key, running* — which the official project deliberately leaves to assemblers.

## How it works

The one component that ever needed special infrastructure was a *local* LLM. Swap that for a **cloud provider** and the whole thing becomes pure PHP making outbound HTTPS calls — so it installs like any normal Drupal site:

```
Recipe (drup_aid)  ─►  ai + ai_agents + canvas_ai + ai_provider_anthropic + key + minion
                        │
   your LLM API key ───►│  (one setup step)
                        ▼
   chat: "change the homepage headline to X"  ─►  the minion edits the node (rollback-able revision)
```

## Quick start

Requirements: a Drupal 11.3+ site (PHP 8.3+, Composer), and an Anthropic API key.

```bash
composer require drupal/ai:^1.4 drupal/ai_provider_anthropic drupal/ai_agents drupal/canvas drupal/canvas_ai drupal/key
# add this repo's modules/ + recipes/ to your project, then:
drush recipe recipes/drup_aid
ANTHROPIC_API_KEY=sk-ant-... drush php:script scripts/setup-cloud-provider.php
```

Full steps: see [INSTALL.md](INSTALL.md). The minions are described in [AGENTS.md](AGENTS.md).

## The build ladder

| Rung | What | Brain | Infra |
|---|---|---|---|
| **1 — this repo** | turnkey cloud install | cloud LLM (Anthropic/OpenAI) | any Drupal host |
| 2 | data-sovereign tier | local Ollama (14B) | Docker + GPU/box |
| 3 | full agency-in-a-box | either | + orchestration & business minions |

## Status

**v0.1 — early but real.** The Web Editor minion is **validated end-to-end** (it edits a live node by chat on a cloud model). More minions (page builder, security monitor) land as each is validated. Treat as a working preview, not a finished product.

## Built with AI

Drup-AID is developed with heavy AI assistance, disclosed honestly per Drupal's AI-contribution policy. Every change is human-reviewed and must be explainable in review — see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later (Drupal-compatible). See [LICENSE](LICENSE).
