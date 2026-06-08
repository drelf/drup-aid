# Contributing to Drup-AID

Thanks for your interest. Drup-AID aims to meet or exceed Drupal's standards for
both code quality and **honest AI-usage disclosure**.

## AI usage disclosure (required)

This project is **developed with heavy AI assistance** (AI-Generated and AI-Assisted),
under full human review. We hold ourselves to Drupal's AI-contribution policy:

- **Disclose AI usage honestly** on every PR, by Drupal's taxonomy — *No AI used* /
  *AI-Assisted* / *AI-Generated*. When in doubt, mark the higher-AI tier. Never under-state it.
- **Understand before contributing** — read the issue, the discussion, and the surrounding
  code before pointing AI at it. The human owns comprehension, not the model.
- **Explain it in review** — never submit code (AI- or human-authored) you cannot explain
  line-by-line and defend. This is stricter than "the tests pass."
- **No slop** — no AI-generated issue summaries, no bulk auto-PRs, no regenerate-and-redump
  in response to review feedback. Engage with real reasoning.

## Code standards

- Match Drupal coding standards; run `phpcs` with `drupal/coder` and the test suite
  **locally, green, before submitting**. AI origin earns zero leniency.
- GPL-2.0-or-later compatible; never commit credentials or secrets.
- Keep minions' `tools:` lists to **executable tools only** (see [AGENTS.md](AGENTS.md)).

## Workflow

Open an issue first for anything non-trivial. Be a good collaborator — follow the
maintainers' process, listen first, and don't argue AI-origin as an excuse.
