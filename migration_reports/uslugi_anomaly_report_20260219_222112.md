# Отчёт аномалий legacy-услуг

- Дата: 2026-02-19 22:21:12
- База: `viglinbd_vigl2`
- Ограничение --limit: нет
- Фильтр --user-id: нет

## Сводка

- rows_total: 99662
- rows_parsed: 99659
- entries_total: 5660979
- parse_error: 3
- malformed_tuple: 0
- service_id_zero: 102800
- service_id_negative: 0
- service_id_not_numeric: 66616
- service_id_missing_lookup: 0

## Топ отсутствующих service_id

| service_id | count |
|---:|---:|
| - | 0 |

## Примеры аномалий

| user_id | field | cat_id | service_raw | price | duration | reason |
|---:|---|---:|---|---:|---:|---|
| 100000 | prices | 287 |  | 800.00 | 45 | service_id_not_numeric |
| 100000 | prices | 148 |  | 3600.00 | 120 | service_id_not_numeric |
| 100001 | prices | 287 |  | 800.00 | 45 | service_id_not_numeric |
| 100001 | prices | 148 |  | 3600.00 | 120 | service_id_not_numeric |
| 100004 | prices | 93 |  | 2200.00 | 150 | service_id_not_numeric |
| 100006 | prices | 93 |  | 2200.00 | 150 | service_id_not_numeric |
| 100007 | prices | 287 |  | 1000.00 | 60 | service_id_not_numeric |
| 100010 | prices | 89 | 0 | 3100.00 | 120 | service_id_zero |
| 100010 | prices | 93 |  | 2300.00 | 105 | service_id_not_numeric |
| 100013 | prices | 129 | 0 | 1200.00 | 45 | service_id_zero |
| 100013 | prices | 131 | 0 | 1500.00 | 60 | service_id_zero |
| 100013 | prices | 133 | 0 | 300.00 | 45 | service_id_zero |
| 100013 | prices | 134 | 0 | 1500.00 | 60 | service_id_zero |
| 100015 | prices | 93 |  | 2200.00 | 150 | service_id_not_numeric |
| 100016 | prices | 93 |  | 2200.00 | 150 | service_id_not_numeric |
| 100019 | prices | 93 |  | 2200.00 | 150 | service_id_not_numeric |
| 100021 | prices | 287 |  | 1000.00 | 60 | service_id_not_numeric |
| 100022 | prices | 287 |  | 800.00 | 45 | service_id_not_numeric |
| 100022 | prices | 148 |  | 3600.00 | 120 | service_id_not_numeric |
| 100023 | prices | 148 |  | 3600.00 | 105 | service_id_not_numeric |
| 100024 | prices | 287 |  | 1000.00 | 45 | service_id_not_numeric |
| 100025 | prices | 129 | 0 | 1600.00 | 45 | service_id_zero |
| 100025 | prices | 131 | 0 | 1900.00 | 60 | service_id_zero |
| 100025 | prices | 133 | 0 | 700.00 | 60 | service_id_zero |
| 100025 | prices | 134 | 0 | 1900.00 | 60 | service_id_zero |
| 100027 | prices | 287 |  | 800.00 | 45 | service_id_not_numeric |
| 100027 | prices | 148 |  | 3600.00 | 120 | service_id_not_numeric |
| 100028 | prices | 129 | 0 | 1100.00 | 75 | service_id_zero |
| 100028 | prices | 131 | 0 | 1400.00 | 45 | service_id_zero |
| 100028 | prices | 133 | 0 | 200.00 | 45 | service_id_zero |
| 100028 | prices | 134 | 0 | 1400.00 | 45 | service_id_zero |
| 100028 | stock_prices | 134 | 0 | 1325.00 | 90 | service_id_zero |
| 100032 | prices | 287 |  | 800.00 | 45 | service_id_not_numeric |
| 100032 | prices | 148 |  | 3600.00 | 120 | service_id_not_numeric |
| 100033 | prices | 287 |  | 1000.00 | 60 | service_id_not_numeric |
| 100036 | prices | 287 |  | 1000.00 | 60 | service_id_not_numeric |
| 100037 | prices | 115 | 0 | 1900.00 | 60 | service_id_zero |
| 100037 | prices | 116 | 0 | 900.00 | 45 | service_id_zero |
| 100037 | prices | 117 | 0 | 1900.00 | 45 | service_id_zero |
| 100037 | prices | 118 | 0 | 1300.00 | 60 | service_id_zero |

