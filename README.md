# Hybrid Promotional Campaign Laboratory

Rebuilt on 27 October 2026 after the original school-computer source and database became unavailable. Historical screenshots demonstrate that `CAMP-001` reached 50 SENT on 26 October. Those historical outcomes are not inserted into this rebuilt database.

## Requirements and setup

PHP 8.2+ with pdo_mysql, mbstring and OpenSSL; Composer; MySQL 8.0.16+ or an instructor-approved compatible server; AWS CLI v2; approved AWS access; and Mailtrap Email Sandbox. The recovery computer uses MariaDB 10.4.32 bound to localhost on port 3307.

Run `composer install`. Configure the environment in each terminal. Supply your own values; never put credentials in Git:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306' # recovery computer uses 3307
$env:DB_NAME = 'marketing_lab'
$env:DB_USER = 'YOUR_LOCAL_USER'
$env:DB_PASSWORD = 'YOUR_LOCAL_PASSWORD'
$env:AWS_REGION = 'ap-southeast-2'
$env:AWS_PROFILE = 'marketing-lab'
$env:SQS_QUEUE_URL = 'https://sqs.ap-southeast-2.amazonaws.com/408852558600/MarketingEmailQueue-Escoto'
$env:MAILTRAP_HOST = 'sandbox.smtp.mailtrap.io'
$env:MAILTRAP_PORT = '2525'
$env:MAILTRAP_USERNAME = 'YOUR_SANDBOX_USERNAME'
$env:MAILTRAP_PASSWORD = 'YOUR_SANDBOX_PASSWORD'
$env:MAIL_FROM_ADDRESS = 'marketing@example.com'
$env:MAILTRAP_DELAY_SECONDS = '11'
```

Create the local `marketing_lab` database with utf8mb4. `schema.sql` contains definitions only. Then:

```powershell
php init-db.php
php test-db.php
php seed-customers.php
php prepare-campaign.php CAMP-REBUILD-20261027 PROMO15
php prepare-campaign.php CAMP-REBUILD-20261027 PROMO15
php inspect.php CAMP-REBUILD-20261027
```

The generator preserves existing values. Preparation selects 50 distinct opted-in customers once in a transaction. It preserves the same request IDs and promo code on repeats and rejects a partial campaign or a changed promo code. Preparation and generation use only local MySQL after Composer has installed dependencies.

## AWS authentication and setup

Use the classroom-approved credential method. With browser-based console login and an approved CLI identity:

```powershell
aws login --profile lab-signin --region ap-southeast-2
aws configure set credential_process 'aws configure export-credentials --profile lab-signin --format process' --profile marketing-lab
aws configure set region ap-southeast-2 --profile marketing-lab
aws sts get-caller-identity --profile marketing-lab
php test-sqs.php
```

Use separate profile names to avoid a recursive credential process. The SDK reads the selected profile; temporary credentials must not be printed into logs or committed. The CLI login needs the classroom identity's approved local-development access. `iam-policy.json` is the queue-scoped policy template, not evidence that it is attached. Account setup and monitoring use the approved setup identity; application access is limited to the assigned queues. Do not grant broad administrator permissions as a workaround.

Main queue: Standard, 180-second visibility, four-day retention, 20-second long polling, redrive to `MarketingEmailDLQ-Escoto` after three receives. DLQ retention: 14 days. Keep encryption and restricted access enabled.

Dashboard `MarketingLab-Escoto`: main-queue sent/received/deleted counts (Sum), visible/in-flight backlog and oldest age (Maximum), and DLQ visible backlog (Maximum), all with a one-minute period. DLQ alarm: Maximum visible backlog >=1 for one minute. Backlog alarm: Maximum oldest age >=300 seconds for one minute. Treat missing data as not breaching. Confirm an approved SNS subscription if account permissions allow.

## Publish and process

Verify at least 50 remaining Sandbox submissions and an empty main queue first. Capture the dashboard baseline and pending counts. Run one publisher and one worker against the same database:

```powershell
php test-smtp.php # authenticates only; sends no email
php enqueue.php CAMP-REBUILD-20261027
php enqueue.php CAMP-REBUILD-20261027 # must publish zero
php worker.php
```

`worker.php [MAX_MESSAGES] [MAX_SECONDS]` optionally bounds a run. It long-polls one message at a time. Keep MySQL running. The worker records outcomes before deleting with the current receipt handle. Failures remain undeleted. SMTP TLS certificate checks remain enabled. Only fictional example.com addresses and the Mailtrap Sandbox endpoint are permitted.

SQS messages contain only requestId, campaignId, customerId, promoCode, subject and body. The processor resolves the recipient locally and rechecks current consent. SENT means SMTP acceptance, not delivery to a real inbox. History stores the latest outcome per campaign/customer; SENT and SKIPPED are terminal. FAILED may be retried.

## Required diagnostics

After the fresh baseline reaches 50 SENT:

```powershell
php diagnostic.php duplicate CAMP-REBUILD-20261027
php diagnostic.php broken
# Keep the worker running through three visibility intervals and capture retries/DLQ/alarm.
php diagnostic.php recovery
php inspect.php CAMP-RECOVERY-20261027
```

Duplicate: duplicate_skipped followed by deleted, without SMTP. Failure: malformed payload must enter the main queue, fail validation, remain undeleted and move automatically to the DLQ. Recovery: CUST-08001 must be SKIPPED for marketing_opt_out and acknowledged without SMTP. Resolve only the identified malformed incident messages after recovery and verify DLQ zero/alarm OK. Never redrive the invalid payload unchanged or purge a queue.

If no Sandbox quota is available, `$env:LAB_SMTP_DISABLED='1'` enables a diagnostic safety mode that throws on every attempted SMTP send. It can verify opted-out recovery and malformed-message handling; it cannot establish a SENT baseline or a real-email duplicate result. Remove that variable before an authorized full campaign.

## Tests, evidence and limitations

Create a separate `marketing_lab_test` database and set `LAB_TEST_DB_NAME` if needed (name must end in `_test`). Run `composer test`. The tests reset only that dedicated database and use mocked SQS and SMTP. They verify constraints, repeated generation/preparation/publication, failed publication, consent, missing customers, failed SMTP retry, acknowledgment order, invalid payloads and duplicate suppression after an acknowledgment failure. Mocked results are code verification, not evidence of live delivery.

Operational logs are UTC JSON lines in `logs/events.jsonl`. They contain approved IDs and phases, excluding names, addresses, credentials and message content. Distinguish SQS MessageId from the MIME Message-ID. CloudWatch metrics are approximate and delayed; they are not unique-email totals.

SQS Standard is at-least-once. A crash after SMTP acceptance but before history storage can cause another send. A lost acknowledgment between SQS acceptance and the outbox update can cause republishing. Terminal history suppresses sequential duplicates; it does not guarantee exactly-once delivery or solve concurrent-worker races. Use one worker for this laboratory.

Only source, composer manifests/lockfile, schema definitions, policy template and documentation belong in the repository. Keep the database, generated customers, logs, runtime credentials and vendor directory local. The Google Doc distinguishes dated historical screenshots, verified recovery results, and any tests still pending.
