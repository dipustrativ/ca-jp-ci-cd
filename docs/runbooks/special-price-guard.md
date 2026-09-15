# Turn on the special price guard

**Use when** enabling, re-tuning, or switching off the JP minimum-special-price
rule — the cron that raises `special_price` to at least
`cost × multiplier + shipping incl. tax`.

It only ever moves prices **up**. If the floor later drops, the product keeps its
raised price until the ERP sends a new one.

Ships **disabled**. Nothing changes until step 4.

Design, config reference, CLI reference and why there is no database view:
[`app/code/CarpetAvenue/Japan/README.md`](../../app/code/CarpetAvenue/Japan/README.md#special-price-guard).

## 1. Check the formula before anything else

Admin → Stores → Configuration → **CarpetAvenue → Japan → Special Price Guard**
shows the effective values. On the CLI you can only see *overrides* —
`config:show` does not report `config.xml` defaults, and errors on a group path:

```bash
bin/mysql -e "SELECT path, value FROM core_config_data
              WHERE path LIKE 'japan/special_price_guard/%';"   # empty = all defaults
```

Defaults are `cost_multiplier 1.5`, `shipping_base 100`, `tax_percent 5` —
`cost × 1.5 + 105`, in **EUR**, the store's base currency. Cost and
`special_price` are both stored in EUR; the JPY the customer sees is a display
conversion. Do not enter JPY figures here.

These are **not** the `carpet_reports/jp/*` values. That group's `cost_base` is
`60` and the JP royalty report reads it. Changing one must not move the other.

## 2. Dry run

Works while the guard is still disabled — `--force` overrides the master switch,
`--dry-run` writes nothing to the catalog.

```bash
bin/magento carpetavenue:japan:special-price-guard --dry-run --force
```

Prints the first 50 changes and a summary:

```
[spg_…] DRY RUN scanned 676, raised 253, skipped 423, errors 0
```

Every decision is recorded in `carpetavenue_japan_price_action_log` with
`dry_run = 1`, viewable at **CarpetAvenue → Price Action Log**.

## 3. Sanity-check the count against the old rule

Run the base store's view logic as a **plain query** — never `CREATE VIEW`:

```bash
bin/mysql -e "
SELECT COUNT(*) AS legacy FROM catalog_product_entity cpe
  JOIN catalog_product_entity_decimal c  ON c.attribute_id=81 AND c.store_id=0 AND c.entity_id=cpe.entity_id
  JOIN catalog_product_entity_decimal p  ON p.attribute_id=77 AND p.store_id=0 AND p.entity_id=cpe.entity_id
  JOIN catalog_product_entity_decimal sp ON sp.attribute_id=78 AND sp.store_id=0 AND sp.entity_id=cpe.entity_id
 WHERE sp.value < ROUND(c.value*1.5 + 100*1.05, 2);

SELECT COUNT(*) AS guard FROM carpetavenue_japan_price_action_log
 WHERE rule_code='special_price_guard' AND action='raise';"
```

The two numbers must match. They did on 2026-09-15: **253 and 253**, with zero
products in one set and not the other.

Attribute ids `81/77/78` are `cost`/`price`/`special_price` **on this install**
— confirm with `SELECT attribute_id, attribute_code FROM eav_attribute WHERE
entity_type_id=4 AND attribute_code IN ('cost','price','special_price');` before
pasting this anywhere else. The guard itself never hardcodes them.

## 4. Turn it on

```bash
bin/magento config:set japan/special_price_guard/enabled 1
bin/magento cache:flush
```

## 5. First real run

The blast-radius cap refuses any run that would reprice more than
`max_affected_percent` of the catalog (default 20 %). A first run clears a
backlog and will usually trip it:

```
Aborted: 253 of 1114 products (22.7%) would change, over the 20.0% cap
```

That is the cap working. Confirm the dry-run output was what you expected, then:

```bash
bin/magento carpetavenue:japan:special-price-guard --force
```

Prefer a single SKU first — `--sku=` also bypasses the cap:

```bash
bin/magento carpetavenue:japan:special-price-guard --sku=VAN212125
```

## 6. Verify

```bash
# the run changed prices
bin/mysql -e "SELECT rule_code, attribute_code, action, reason, COUNT(*)
              FROM carpetavenue_japan_price_action_log
              WHERE dry_run=0 GROUP BY rule_code, attribute_code, action, reason;"

# nothing sits below its own floor
bin/mysql -e "SELECT COUNT(*) AS below_floor FROM carpetavenue_japan_price_action_log g
              JOIN catalog_product_entity_decimal c
                ON c.attribute_id=81 AND c.store_id=0 AND c.entity_id=g.product_id
             WHERE g.action='raise' AND g.new_value < ROUND(c.value*1.5+100*1.05,2);"
```

`below_floor` must be `0`.

Then run it a second time — it must be a no-op:

```bash
bin/magento carpetavenue:japan:special-price-guard
# → Nothing to change.  scanned N, raised 0
```

A second run that still reports changes means something else is writing
`special_price` between runs — normally the ERP sync.

## 7. Hand over to cron

Nothing more to do — `carpetavenue_japan_fix_special_price` runs on
`japan/special_price_guard/cron_expr`, default `40 * * * *`.

Keep it before `:00` and `:10`. Productify's on-sale flag job and
`Evalent_CatalogPriceHistory` run then and read `catalog_product_index_price`,
which needs the price indexer's mview consumer to have caught up first.

```bash
bin/mysql -e "SELECT status, COUNT(*) FROM cron_schedule
              WHERE job_code='carpetavenue_japan_fix_special_price' GROUP BY status;"
```

Empty until Magento's cron has scheduled the job for the first time. No rows
after an hour means cron itself is not running, not that the guard is broken.

## 8. Change the formula later

```bash
bin/magento config:set japan/special_price_guard/cost_multiplier 1.6
bin/magento cache:flush
bin/magento carpetavenue:japan:special-price-guard --dry-run --force   # look first
```

Raising the multiplier raises more prices on the next run. **Lowering it does
nothing** — prices already raised stay where they are, because the rule keeps no
record of what they were. The cap exists for exactly this edit: a mistyped
multiplier would otherwise reprice the catalog in one unattended cron, with no
way back.

## 9. Exclude an owner

```bash
bin/magento config:set japan/special_price_guard/excluded_owners "Bo Tepp,Vani"
bin/magento cache:flush
```

Values of the `owner` product attribute, comma-separated, case-sensitive. Ships
empty — the rule applies to everyone. Excluded owners are filtered out in SQL, so
their products are never scanned. Prices already raised before the exclusion stay
raised.

```bash
bin/mysql -e "SELECT DISTINCT value FROM catalog_product_entity_varchar
              WHERE attribute_id=(SELECT attribute_id FROM eav_attribute
                                  WHERE entity_type_id=4 AND attribute_code='owner');"
```

## 10. Undo

**There is no automatic undo.** The rule keeps no record of what a price was
before it was raised — the raise overwrites the only copy. Stop it first:

```bash
bin/magento config:set japan/special_price_guard/enabled 0
bin/magento cache:flush
```

Then recover the old prices from whichever source still has them:

- **The log**, for anything the guard itself changed. `old_value` is the price
  as it stood immediately before the raise, and it is kept for
  `log_retention_days` (default 90):

  ```bash
  bin/mysql -e "SELECT product_id, sku, old_value, new_value, created_at
                FROM carpetavenue_japan_price_action_log
                WHERE rule_code='special_price_guard' AND action='raise' AND dry_run=0
                ORDER BY created_at DESC;"
  ```

  Restoring means writing those `old_value`s back yourself. Check first that the
  ERP has not since set a newer price you would be overwriting.

- **A re-sync from the ERP**, which is the authoritative source for
  `special_price` and the normal way a price comes back down.

Re-enabling the guard will raise anything below the floor again, so fix the
config (multiplier, shipping, tax) before turning it back on.

## Troubleshooting

| Symptom                                  | Cause                                                                    | Fix                                                                                               |
|------------------------------------------|--------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| `Aborted: guard disabled in config`      | `enabled = 0`                                                            | Step 4, or pass `--force` for a one-off                                                           |
| `Aborted: … over the … cap`              | Run would reprice too much of the catalog                                | Check the dry run, then `--force`, or raise `max_affected_percent`                                |
| `Aborted: missing product attributes: …` | `cost`, `price` or `special_price` absent                                | Wrong install — do not force it                                                                   |
| Command not listed by `bin/magento list` | Stale DI cache                                                           | `rm -rf var/cache/* generated/metadata/*`                                                         |
| A price is stuck too high                | The rule only moves prices up — it never lowers one when the floor drops | Have the ERP re-send the price, or edit it in admin. See §10                                      |
| Floor is a cent off a hand calculation   | The floor rounds half-cent ties **up**, by design                        | `Calculator::floorFor()` uses bcmath; a float would round a minimum *down*. See the module README |
| Grid empty after a run                   | Only real actions are logged, never skips                                | A run that changes nothing writes nothing                                                         |
