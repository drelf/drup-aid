# Installing Drup-AID

Drup-AID installs on any host that can run Drupal 11 — no Docker, no GPU. The AI
layer only makes outbound HTTPS calls to your LLM provider.

## Requirements

- **Drupal 11.3+** on PHP **8.3+** with Composer (most modern shared hosts + all VPS/managed hosts qualify; Hostinger included).
- An **Anthropic API key** (`sk-ant-…`). OpenAI works too — swap `ai_provider_anthropic` → `ai_provider_openai` and set that model in the setup step.
- Drush available (`vendor/bin/drush`).

> **Advisory block — you WILL hit this (proven on a live VPS, 2026-06-16).** Even on a *current* core (11.3.11), Composer refuses the AI-rails install because a core dependency (`guzzlehttp/psr7`) carries open security advisories, and Composer's `policy.advisories.block` halts the resolve. **`--no-audit` does NOT fix it** (that only skips the post-install report). Clear it before Step 1 — see Step 1. Keep core patched regardless.

## Steps

**1. Require the AI rails** (validated versions):

```bash
# Clear the advisory block first (see the note above) — otherwise this resolve fails:
composer config --no-plugins policy.advisories.block false

composer require -W \
  drupal/ai:^1.4 \
  drupal/ai_provider_anthropic \
  drupal/ai_agents \
  drupal/canvas \
  drupal/canvas_ai \
  drupal/key
```

**2. Add the Drup-AID modules + recipe** to your project: copy everything under `modules/`
(the out-of-the-box team — `drupaid_core`, `drupaid_master`, `drupaid_concierge`, `drupaid_seo`,
`drupaid_content`, `drupaid_leads`, `peak_security_agent`, plus `drup_aid_cockpit` and the
`peak_web_agent` Web Editor) into `web/modules/custom/` and `recipes/drup_aid` into your site's
`recipes/` directory (or require this repo as a path/VCS Composer repository).

**3. Apply the recipe** — ⚠️ two things bite here (both proven on the live VPS install):

```bash
# • 512M PHP memory: the default 128M dies mid-apply (~step 48/52, on Canvas) with
#   "Allowed memory size exhausted". Raise php.ini memory_limit, or pass -d as below.
# • ABSOLUTE recipe path: the recipe lives at the project root /recipes; a relative
#   path resolves wrong when drush --root points at the webroot ("not a directory").
# • drush via its PHP entrypoint (not vendor/bin/drush) on restricted hosts — see gotchas.
php -d memory_limit=512M vendor/drush/drush/drush.php recipe "$PWD/recipes/drup_aid"
```

This enables the AI rails + the full out-of-the-box team — the master orchestrator plus
Concierge, SEO, Content Writer, Security Monitor, and Lead Desk — along with the Web Editor
minion, and registers them under the orchestrator.
(Recipe validated 2026-06-16 on a clean Drupal 11.3.11 / PHP 8.4 VPS: `[OK] Drup-AID applied successfully`.)

**4. Point it at your key** (the one config step — the key is read from the environment, never committed):

```bash
ANTHROPIC_API_KEY=sk-ant-... drush php:script scripts/setup-cloud-provider.php
# optional: DRUPAID_MODEL=claude-sonnet-4-6 for higher quality (costs more)
```

**6. (Optional, recommended) Enable the Agent Cockpit** — the team-chat UI:

```bash
drush en drup_aid_cockpit -y
```

Then open `/admin/drup-aid/cockpit` (permission: *access drup-aid cockpit*). It starts
in a zero-cost demo mode; click the mode badge to run your real agents (see USING.md).

**5. Use it.** Log in as an editor and chat (via the AI assistant / Canvas AI):
> *"Change the homepage headline to 'Fiber that just works.'"*

The minion reads the page, makes the edit, and saves it as a **new, rollback-able revision**.

## Notes & gotchas

- **Recommended patch — agent runs survive tool errors:** in `ai_agents` 1.3.x a tool that throws (e.g. an agent guessing a non-existent entity type) fatals the ENTIRE agent run; the module catches two exception types but a `LogicException` from entity introspection escapes. `patches/ai_agents-tool-resilience.patch` wraps tool execution so failures return to the model as a recoverable tool result (it self-corrects). Apply via [cweagans/composer-patches](https://github.com/cweagans/composer-patches):

  ```json
  "extra": {
    "patches": {
      "drupal/ai_agents": {
        "Tool errors should not fatal the whole agent run": "patches/ai_agents-tool-resilience.patch"
      }
    }
  }
  ```

  We found this via live agent roll-call testing (see the closed issues); upstream report to drupal.org is pending.
- **Shared hosting (`public_html` docroots):** set the scaffold `web-root` to your host's docroot **before** running `composer install` — e.g. `"web-root": "public_html/"` in `extra.drupal-scaffold.locations` (with matching `installer-paths`). **Never rename the webroot after building:** composer bakes the path into the generated autoloader and everything downstream — including drush — fails *silently*. We burned a session on exactly this; a textbook "session-time risk."
- **Drush 13 on restricted shared hosts:** the `vendor/bin/drush` bash launcher can exit silently. Call the PHP entrypoint directly: `php vendor/drush/drush/drush.php --root=/path/to/webroot --uri=https://example.com <command>`.
- **Permissions:** the minion writes as the **logged-in user**, so chat under an account with edit access. For unattended/background runs, give the agent a `masquerade_roles` or run it under an authorized account, or writes return "Access denied."
- **Tool rule:** only executable (`ExecutableFunctionCallInterface`) tools belong in a minion's `tools:` list — never a `Children/` schema tool. (This is the one bug that bit us; the shipped minion is correct.)
- **Model choice:** `claude-haiku-4-5-20251001` (default) is cheap and strong at tool-use; bump to a Sonnet/Opus model for higher-quality copy.
