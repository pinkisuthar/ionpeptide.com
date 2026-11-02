---
module: inc-lib
owner: UNKNOWN
---

# inc-lib

## Responsibility

- Bundles vendored BSF/Astra libraries — admin notices (`BSF_Admin_Notices`), the NPS survey, and the Astra target-rule fields — plus HFE's own thin wrapper (`class-uae-nps-survey.php`) that selects the newest NPS-survey copy present across installed BSF plugins.
- HFE authors only the wrapper and the rename shim; the rest is vendored.

## Why it is this way

- The NPS loader coordinates across separately-versioned BSF plugins, so it uses shared globals and a late `init` priority to let the highest version win — a suite-wide protocol, not an HFE-local choice.
- `Astra_Notices` was renamed to `BSF_Admin_Notices` but froze its wire-level strings so upgrading one plugin doesn't break the notices JS/CSS already shipped by others.

## Related ADRs

None yet.
