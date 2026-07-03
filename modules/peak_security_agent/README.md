# Peak Security Agent

A guardrailed **Security Monitor** AI sub-agent (minion) for the Master Agent, part of **Peak Stack**. Built on the contrib `ai_agents` framework (assimilate-first), mirroring `peak_web_agent`.

## What it does
Audits a Drupal site for security weaknesses and reports them with severity + a concrete fix:
- **Permissions** — dangerous grants to non-admin roles (administer users/permissions/modules/config, unfiltered HTML/PHP).
- **Text formats** — formats available to untrusted roles that allow `<script>`/full-HTML/PHP (stored-XSS risk).
- **Content/PII** — exposed personal data, internal notes, or credential-looking strings in content.
- **Registration/accounts** — open self-registration, email verification, password policy, brute-force protection.
- **Config hygiene** — error display on prod, missing `trusted_host_patterns`, dangerous modules (devel/php) on a public site.

## What it does NOT do
- **Read-only.** It never edits content, config, users, permissions, or structure. It inspects and recommends only.
- **Never echoes secrets.** Exposed credentials are reported by location/risk, never by value.
- **Stays in Drupal.** Host-level checks — open ports, TLS cert expiry, HTTP security headers, SPF/DKIM/DMARC email auth, and Drupal core CVE/advisory status — are handled by the external `security-scan` tool (Atlas plugin), which the agent recommends running.

## The agent
`config/install/ai_agents.ai_agent.peak_security_monitor.yml` — ships as config, so it deploys with the module. Leaf worker minion (`orchestration_agent: false`, `triage_agent: false`), read-only tool set (list/get only, no save/modify), `max_loops: 8`.

## Provider note
For regulated tenants, point `ai.settings` chat providers at a **local Ollama** (e.g. `Foundation-Sec-8B`) so audit reasoning never leaves the tenant server.

## Install
`drush en peak_security_agent` → the `peak_security_monitor` agent imports as a config entity. Then wire it into the Master Agent's routable agents.
