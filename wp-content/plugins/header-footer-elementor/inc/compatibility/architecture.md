---
module: compatibility
owner: UNKNOWN
---

# compatibility

## Responsibility

- Owns multilingual compatibility for HFE templates: translates the header/footer/before-footer template post IDs via WPML (`wpml_object_id`) or Polylang so the correct language variant renders.
- Stops at ID translation — it hooks the core render filters but does not own rendering.

## Why it is this way

- WPML and Polylang resolve missing translations differently, so the null-handling is deliberately asymmetric (render-nothing vs render-original). This is a bending-to-a-constraint choice the code cannot explain on its own.

## Related ADRs

None yet.
