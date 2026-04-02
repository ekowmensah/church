# 2026 Upgrade Migration Pack

Source schema baseline: `fmckgmib_portal.sql`

## Run Order

1. `2026_03_21_0001_create_schema_migrations.sql`
2. `2026_03_21_0002_create_release_versions.sql`
3. `2026_03_21_0100_update_membership_status_model.sql`
4. `2026_03_21_0101_add_member_spouse_marriage_fields.sql`
5. `2026_03_21_0102_standardize_emergency_contact_relationships.sql`
6. `2026_03_21_0103_bible_class_capacity_rules.sql`
7. `2026_03_21_0104_attendance_recurrence_and_scope.sql`
8. `2026_03_21_0105_create_assets_register.sql`
9. `2026_03_21_0106_payment_reporting_and_cheque_controls.sql`
10. `2026_03_21_0107_event_registration_integrity.sql`
11. `2026_03_21_0108_notifications_and_chat.sql`
12. `2026_03_21_0900_indexes_fk_hardening.sql`

## Rollbacks

Matching rollback scripts are in `rollbacks/` using the same migration key plus `.rollback.sql`.

## Runner

Use the CLI runner in this folder:

```bash
php migrations/2026_upgrade/run_migrations.php status --db=fmckgmib_portal
php migrations/2026_upgrade/run_migrations.php run --dry-run --db=fmckgmib_portal
php migrations/2026_upgrade/run_migrations.php run --db=fmckgmib_portal
php migrations/2026_upgrade/run_migrations.php rollback-last --db=fmckgmib_portal
php migrations/2026_upgrade/run_migrations.php rollback 2026_03_21_0108_notifications_and_chat --db=fmckgmib_portal
```

## Notes

- Back up DB before running.
- Run each migration in a maintenance window.
- Validate module-by-module after each migration: members, payments, attendance, events, chat/notifications.
- `0100` rollback is lossy for `Distant Member` vs `Invalid` because legacy schema had a single combined value.
- `0900` rollback keeps newly-added primary keys for safety and drops only secondary indexes.
