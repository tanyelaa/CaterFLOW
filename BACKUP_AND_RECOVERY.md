# CaterFlow Backup and Recovery

## Automated backup (Windows Task Scheduler)

1. Set environment variables in the task:
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS` (optional if no password)
   - `BACKUP_DIR` (optional)
2. Schedule `scripts\\backup_db.bat` to run daily.
3. Keep at least 7 daily backups and 4 weekly backups.

## Manual backup

Run:

```bat
scripts\\backup_db.bat
```

## Restore checklist

1. Put app in maintenance mode (`app_settings.maintenance_mode = 1`).
2. Verify target database credentials.
3. Restore backup:

```bat
mysql -h 127.0.0.1 -P 3306 -u root -p caterflow < path\\to\\backup.sql
```

4. Confirm table counts for `users`, `orders`, `packages`, `payment_receipts`.
5. Run a smoke test: login, create order, update status, payment receipt.
6. Set maintenance mode back to `0`.

## Reminder scheduler

Run reminder dispatch daily:

```bat
php scripts\\dispatch_reminders.php
```

This sends 7-day and 1-day reminders and prevents duplicates through `reminder_dispatch_log`.
