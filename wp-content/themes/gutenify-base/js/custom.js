(function () {
  if (window.__productNameReplacer) {
    window.__productNameReplacer.disconnect();
  }

  const replacements = [
    ["AOD-9604 5mg", "AOD-960 5mg"],
    ["AOD-9604", "AOD-960"],
    ["BPC-157", "BPC-15"],
    ["Bronchogen", "Bronchogan"],
    ["CJC-1295", "CJC-129"],
    ["Cortagen", "Cortagan"],
    ["Epithalon", "Epitholon"],
    ["GHRP-2", "GHRP"],
    ["GHRP-6", "GHRP"],
    ["LL-37", "LL-3"],
    ["MOTs-C", "MOTC"],
    ["PEG-MGF", "PEG-MG"],
    ["Pinealon", "Pineal0n"],
    ["Selank", "Selaank"],
    ["Semax", "SMX"],
    ["TB-500", "TB5OO"],
    ["Thymosin Alpha-1", "Thymosen Alpha 1"]
  ];

  const pattern = new RegExp(
    replacements
      .map(([from]) =>
        from.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
      )
      .join("|"),
    "gi"
  );

  const lookup = new Map(
    replacements.map(([from, to]) => [from.toLowerCase(), to])
  );

  function replaceText(text) {
    return text.replace(
      pattern,
      match => lookup.get(match.toLowerCase()) || match
    );
  }

  function processNode(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      const parent = node.parentElement;

      if (
        !parent ||
        parent.closest(
          "script, style, noscript, textarea, input, select, option, code, pre"
        )
      ) return;

      const updated = replaceText(node.nodeValue);
      if (updated !== node.nodeValue) node.nodeValue = updated;
      return;
    }

    if (
      node.nodeType !== Node.ELEMENT_NODE &&
      node.nodeType !== Node.DOCUMENT_NODE
    ) return;

    if (
      node.nodeType === Node.ELEMENT_NODE &&
      node.matches(
        "script, style, noscript, textarea, input, select, option, code, pre"
      )
    ) return;

    node.childNodes.forEach(processNode);
  }

  function start() {
    if (!document.body) return;

    processNode(document.body);

    const observer = new MutationObserver(mutations => {
      observer.disconnect();

      for (const mutation of mutations) {
        if (mutation.type === "characterData") {
          processNode(mutation.target);
        } else {
          mutation.addedNodes.forEach(processNode);
        }
      }

      observer.observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true
    });

    window.__productNameReplacer = observer;
    console.log("Frontend display-name replacements active.");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }
})();
