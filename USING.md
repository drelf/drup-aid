# Talking to your agents

You've installed Drup-AID — here is every way to actually have the conversation.

## 1. Canvas AI — build pages by chat (the main surface)

Edit any page with **Canvas** (Drupal's visual page builder) and open the **AI panel**. Type what you want — *"build a pricing page with three tiers and a FAQ"* — and the orchestrator runs the right agents to make it happen. This is the surface the "build your site by chat" promise lives on, and it ships configured by the recipe.

## 2. AI Agent Explorer — talk to ANY agent directly

Go to **`/admin/config/ai/agents/explore`** (permission: *use the agent explorer*).
Pick an agent — including the master — type a prompt, and run it. You'll see the **full decision log**: every step, every tool call, every sub-agent delegation, as it happens. The UI is plain, but it's the most honest window into what your agents actually do. Great for testing a minion you just configured.

## 3. The site-wide chat block (optional, a few minutes of config)

The `ai` module ecosystem includes an **AI Assistant** API with a chat-block submodule:
1. Create an assistant at **`/admin/config/ai/ai-assistant`** and give it your agent as a tool.
2. Place the **chatbot block** in a region (e.g. the footer).
Now any admin page (or the whole site, your call) has a chat widget wired to your team.

## 4. Drush — headless / scripting

```bash
drush php:script your-script.php   # run an agent from code
```
Inside the script, load and run an agent via the `plugin.manager.ai_agents` service. Useful for cron-driven agent work and CI. (Tip from our own use: on restricted shared hosts, call drush as `php vendor/drush/drush/drush.php --root=... --uri=...` — see [INSTALL.md](INSTALL.md).)

## 5. The Agent Cockpit — the whole team in one room

Enable `drup_aid_cockpit` and go to **`/admin/drup-aid/cockpit`**: your master agent and every specialist as a chat roster. Send a request and watch the delegation happen — each agent's work appears as its own bubble. Starts in a zero-cost demo mode; click the mode badge to go live (uses your AI provider credits, and says so first).

## Which one should I use?

| You want to... | Use |
|---|---|
| Build or change pages conversationally | **Canvas AI** |
| Watch the whole team work in one room | **Agent Cockpit** |
| Test an agent / watch it think | **Agent Explorer** |
| Give site editors an always-there chat | **Assistant chat block** |
| Automate agents on a schedule or from outside | **Drush** (and the upcoming flow/webhook layer) |

*Coming next on the roadmap: every agent addressable as a webhook, so visual flows (n8n) and external systems can run your team directly.*
