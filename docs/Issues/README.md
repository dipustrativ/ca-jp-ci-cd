← [Knowledge base index](../README.md)

# Known Issues

Upstream (Adobe/Magento, third-party vendor, or PHP-version) defects that affect this
install and are **not fixable in `app/code`**. Each file records what breaks, the verified
root cause in vendor source, the workaround in force, and a concrete re-check procedure to
run after every Magento or PHP upgrade.

Bugs in CarpetAvenue/IXPF/Evalent code do **not** belong here — fix those and let git
history record them.

## Open

| ID | Title | Affects | Status | Last re-checked |
|---|---|---|---|---|
| [ISSUE-001](ISSUE-001-scopecoderesolver-null-array-offset-php85.md) | Admin Design Configuration → Global row fatals: `null` array offset in `ScopeCodeResolver` | Magento 2.4.9 + PHP 8.5 | 🔴 OPEN — CLI workaround | 2026-08-08 |
| [ISSUE-002](ISSUE-002-uniqueattributevalidator-null-array-offset-php85.md) | Product import: `null` array offset in `UniqueAttributeValidator` from a NULL row on a unique attribute (`ean_barcode`) | Magento 2.4.9 + PHP 8.5 | 🟡 WORKAROUND — data cleanup | 2026-08-12 |
| [ISSUE-003](ISSUE-003-catalog-image-url-store-param.md) | Core appends `?___store=<code>` to every product image URL, fragmenting the CDN cache once per store view | Magento 2.4.9 (`web/url/use_store=1`) | 🟢 WORKAROUND — after-plugin | 2026-08-21 |
| [ISSUE-004](ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md) | All store views share one full-page-cache entry, so `/` serves whichever language rendered first | Magento 2.4.9 (`web/url/use_store=1`) | 🟡 WORKAROUND — frontend plugin | 2026-08-22 |

## Resolved

_none yet_

---

## Conventions

- **Filename** — `ISSUE-<NNN>-<kebab-slug>.md`, `NNN` sequential, never reused.
- **Status** — 🔴 OPEN · 🟡 WORKAROUND IN PLACE (risk accepted) · 🟢 FIXED UPSTREAM.
- **Every issue file carries:**
  1. Header table — status, affected package **versions** (from `composer.lock`, not
     marketing version), PHP requirement, origin, workaround in force.
  2. Symptom — exact error text and repro URL/command, verbatim.
  3. Root cause — real `file:line` with the actual vendor code quoted. Line numbers drift
     between releases; quoting the code makes the finding survive that.
  4. Proof local code is not at fault (or the local code that is).
  5. Fix in force, plus alternatives rejected **and why** — the rejection reasoning is the
     part future-you will not remember.
  6. **Re-check procedure** — copy-pasteable commands whose output decides FIXED vs
     STILL BROKEN. This is the point of the file.
  7. Verification log table — one row per check, appended never rewritten.
- **On upgrade** — run every open issue's re-check procedure, append a verification-log
  row, update `Last re-checked` here. Move to _Resolved_ only after step-by-step
  confirmation, recording the version that fixed it.
- Prefer read-only verification. State plainly in the log when a documented workaround has
  been written down but not yet exercised.
