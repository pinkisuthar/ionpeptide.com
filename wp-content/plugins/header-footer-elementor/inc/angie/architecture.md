---
module: angie
owner: UNKNOWN
---

# angie

## Responsibility

- Bridges the `inc/abilities` registry to Elementor's in-editor "Angie" AI assistant: a wildcard REST execute route plus a compiled JS MCP server that registers HFE abilities as Angie tools.
- Consumes the abilities registry; does not own it.

## Why it is this way

- A single wildcard route exists because REST route registration (`rest_api_init`) can run before ability discovery (`wp_abilities_api_init`), so per-ability routes would register before their abilities are known.
- Angie gets a deliberately reduced tool surface (hidden complex-param tools, stripped `pro_alternative`) because Angie's AI mis-uses inputs that the general MCP Adapter channel handles fine — the two channels diverge on purpose.

## Related ADRs

None yet.
