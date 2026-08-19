# Legacy Tag Residuals After Context Backfill + In-Place Remap (2026-02-23)

## What was done
1. Added `52_backfill_context_map_from_residual_legacy_nodes.php`
2. Backfilled context map from residual `legacy/tag` rows in normalized tables
3. Performed in-place remap of user rows using `context_map + source_payload.cat_id`

## Backfill stats
- `pairs_scanned = 57`
- `ctx_map_upserted = 57`
- `nodes_top_new = 1`
- `nodes_content_new = 33`
- `nodes_leaf_new = 56`

## In-place remap stats
- `prices_rows_updated = 168242`
- `stock_rows_updated = 1266`

## Residual after remap
- `prices`: `0` rows (`0` users)
- `stock`: `4` rows (`3` users)

## Why 4 stock rows remain
All remaining rows have `source_payload.cat_id = NULL`, so there is no context key (`content_id`) for context-aware resolution. They must stay on fallback `legacy/tag/...` nodes unless a manual rule is introduced.

See data dump:
- `migration_reports/legacy_tag_post_backfill_residuals_2026-02-23.tsv`
