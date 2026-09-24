# Migrations

Add versioned SQL files using sortable names such as `2026_01_01_000001_create_users.sql`.
Run them once, in filename order, with `composer migrate`. The runner records completed filenames in the `migrations` table. Keep table/column names static and use prepared statements for application data.

MySQL DDL can commit implicitly. Make each migration small and test backups before production changes.
