# Production Hardening Checklist

## Security headers and sessions

- Security headers are applied in API and dashboard entry points via `core.php`.
- Session cookies are `HttpOnly` and use secure flag when HTTPS is detected.

## Payments and webhooks

- Online payment intents are handled by:
  - `customer_api.php?action=create-payment-intent`
  - `payments_api.php?action=create-intent`
- Webhook receiver:
  - `POST payments_api.php?action=webhook`
  - Header: `X-CF-Signature` = `HMAC_SHA256(raw_body, payment_webhook_secret)`

## Queue and background workers

- Queue worker:

```bat
php scripts\queue_worker.php
```

- Notification processor:

```bat
php scripts\process_notifications.php
```

- Reminder processor:

```bat
php scripts\dispatch_reminders.php
```

## Audit and observability

- Audit logs are stored in `audit_logs`.
- Login attempts and login events are stored in:
  - `login_attempts`
  - `login_audit`
- Mock outbound notifications are logged in:
  - `database/notification_mock.log`

## Recommended scheduled tasks

1. Daily at 02:00: `scripts\backup_db.bat`
2. Every 5 minutes: `php scripts\queue_worker.php`
3. Every 15 minutes: `php scripts\process_notifications.php`
4. Daily at 08:00: `php scripts\dispatch_reminders.php`

## Restore drill cadence

- Run restore drill monthly in staging.
- Validate these flows after restore:
  - login
  - create order
  - status update
  - payment intent + webhook
  - invoice and contract retrieval
