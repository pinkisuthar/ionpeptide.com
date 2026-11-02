---
module: abilities
owner: UNKNOWN
---

# abilities

## Responsibility

- Owns HFE's WordPress Abilities API + MCP integration: a handler registry, category registration, three-way gating (master `enable_abilities` switch, `allow_modifications`, per-ability disable list), and fan-out that exposes the same handlers to the WP Abilities API, the MCP Adapter server (`uae/mcp`), and Angie. Also owns Elementor `_elementor_data` tree manipulation via `HFE_Element_Helpers`.
- Stops at the Angie JS bridge (`inc/angie`) and the settings REST endpoint (`inc/settings`), which only *consume* the registry.

## Why it is this way

- The registry duck-types (`is_object` + `method_exists`) instead of type-hinting the handler interface because, when HFE and UAE Pro both load, a strict typehint hit an interface load-order mismatch that threw `TypeError` and registered zero abilities. The duck-typed contract is the fix, not an oversight.
- Gating is opt-in and keyed on `readonly` so that write/mutating tools stay off by default — an AI/MCP client only gets them when a human turns on `allow_modifications`.

## Related ADRs

None yet.
