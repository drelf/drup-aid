# Playbook: Rewrite a page to be cited by AI engines (GEO)

GEO = Generative Engine Optimization: getting cited by ChatGPT, Perplexity, Google AI Overviews, Gemini, and Claude. The overlap between top Google links and AI-cited sources has fallen below 20%, so this is its own discipline. The agent can rewrite/draft; a human approves and publishes.

## The high-impact moves (Princeton/Georgia Tech study — up to +40% AI visibility)
1. **Statistics Addition** — add concrete, verifiable numbers ("X% of WISPs…", "$Y average…") with a source. Biggest single lever.
2. **Cite Sources** — reference authoritative sources inline ("according to <source>…"). Pages with citations get pulled more.
3. **Quotation Addition** — include a relevant expert/authoritative quote.

## Structure for extractability (AI engines lift self-contained blocks)
4. Lead each section with a **direct answer to a question**, then support it. Use question-style H2/H3s.
5. Keep answer blocks **autonomous** — a paragraph that makes sense lifted out of context.
6. Add an **FAQ section** with FAQPage schema.

## Technical requirements (the agent should verify with the audit tool)
7. **Author as a Person entity** in schema (not just a name) + datePublished/dateModified.
8. **3+ schema types** on the page (Article + BreadcrumbList + FAQPage + Organization) — ~13% higher citation likelihood.
9. **Server-render the content** — GPTBot/ClaudeBot/PerplexityBot/CCBot do NOT execute JavaScript; client-side-rendered content is invisible to them.
10. **Allow AI crawlers** in robots.txt (GPTBot, ClaudeBot, PerplexityBot, CCBot, Google-Extended) unless there's a reason to block.
11. **Freshness** — show a visible "last updated" date and refresh on a quarterly cycle.

## Workflow
- Run the audit tool on the URL first to see which of the above are missing.
- Draft the rewrite applying the missing items, keeping the brand voice.
- Hand the draft to the user for approval before publishing (never auto-publish).
