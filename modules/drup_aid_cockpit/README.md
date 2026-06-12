# Drup-AID Agent Cockpit

A "team chat" admin screen for your AI agents: the master agent and every
specialist in one room. Send a request, watch the master delegate, and see each
agent's work land as its own chat bubble. Click any agent for its profile —
model, skills, MCP servers, team.

## Requirements

- `ai` + `ai_agents` (hard dependencies)
- `ai_agents_explorer` + a default chat provider (optional — unlocks live mode)

## Routes & permissions

| Route | What | Permission |
|---|---|---|
| `/admin/drup-aid/cockpit` | The cockpit | `access drup-aid cockpit` |
| `/admin/drup-aid/cockpit/profile/{agent}` | Agent profile | `access drup-aid cockpit` |

Live runs additionally require `use the agent explorer`.

## Demo vs live

The cockpit always starts in **demo mode** — a scripted, zero-cost walkthrough.
When the AI Agents Explorer and a default chat provider are available, the mode
badge becomes a toggle: going live runs your REAL agents and **uses your AI
provider credits** (it asks first, and remembers your choice per browser).

## Artwork

The module ships with neutral colored-initial badges. To skin your team, drop
PNGs into `images/agents/agent-<key>.png` (keys are mapped in
`src/Service/AgentRoster.php`). Note: droplet-style artwork derived from the
Drupal logo requires trademark licensing — ship your own original characters.

## AI usage disclosure

AI-Generated (human-guided, reviewed, and statically analysed: PHPCS
Drupal/DrupalPractice + PHPStan level 5, both clean).
