# Legacy Tag Coverage Report (2026-02-23)

## Purpose
Spot-check/report for rows that still resolve to fallback nodes with path `legacy/tag/...` after context-aware remigration.

## Summary
- `prices`: `168242` rows, `55134` users, `36` legacy-tag nodes
- `stock_prices`: `1270` rows, `1267` users, `28` legacy-tag nodes

## Interpretation
- Context-aware mapping fixed collisions and main branch-path reconstruction, but part of the data still falls back to legacy tag nodes.
- These rows need additional context coverage (usually missing `content_id + tag_id` pairs in `#__vigling_service_context_map`, or source payload contexts not represented in `#__contentitem_tag_map`).

## Generated Data Files
- `migration_reports/legacy_tag_summary.tsv`
- `migration_reports/legacy_tag_users_prices_top100.tsv`
- `migration_reports/legacy_tag_users_stock_top100.tsv`
- `migration_reports/legacy_tag_nodes_prices_top100.tsv`
- `migration_reports/legacy_tag_nodes_stock_top100.tsv`
- `migration_reports/legacy_tag_missing_contexts_prices_top150.tsv`
- `migration_reports/legacy_tag_missing_contexts_stock_top150.tsv`

## Safe Cleanup Check (new service tables)
Results from `migration_reports/service_tables_cleanup_check_2026-02-23.tsv`:
- `orphan_context_map = 0`
- `orphan_legacy_map = 0`
- `unused_nodes_no_children_no_users_no_stock_no_maps = 0`
- `unresolved_rows = 0`

## Cleanup Decision
No safe automatic cleanup was applied to service tables, because there are no obvious orphan/unused rows in the new normalized schema.

## Next Actions
1. Extend `#__vigling_service_context_map` coverage for contexts listed in `legacy_tag_missing_contexts_*` reports.
2. Re-run targeted remigration for affected users (or full remigration if mapping coverage changes are broad).
3. Re-check `legacy/tag` row counts and aim to reduce them toward zero (or document accepted residual fallback set).
