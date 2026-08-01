# Pilot operations runbook

This is the minimum response guide for paid pilot operations. Never paste tokens, database passwords, complete provider payloads, or customer personal data into support messages.

## Daily check

1. Open `/api/health/operational` and confirm HTTP 200 with `status: ok`.
2. Review the Render web, worker, and cron services for failed deploys or restart loops.
3. Review recent failed jobs and Stripe webhook failures shown by the health response.
4. Confirm provider balances are funded before scheduled customer work.

## Discovery is stuck

1. Record the workspace ID and discovery job ID.
2. Check whether the queue-worker heartbeat is current.
3. Check the worker log for the matching job and provider run ID.
4. Ask the customer to use Stop discovery once. Do not repeatedly submit cancellation.
5. Confirm the run becomes cancelled or failed and unused reserved credits are refunded.
6. If the worker is stale, restart only the queue-worker service and recheck the operational endpoint.
7. Do not manually edit wallet balances until the usage reservation and provider completion state have been reconciled.

## Queue worker or cron is unhealthy

1. Confirm the service has the same database variables as the web service, including `DB_SSLMODE=require`.
2. Confirm the worker command is `/worker.sh` and the cron command runs `/scheduler.sh` on the configured schedule.
3. Restart only the failing service.
4. Confirm its heartbeat returns within three minutes.
5. Confirm pending jobs decrease and no discovery provider call was duplicated.

## Failed queue job

1. Capture the job name, workspace/job identifier, provider run identifier, and exception class from the internal alert.
2. Determine whether the external provider completed any billable work.
3. Reconcile the credit reservation against verified completed units.
4. Retry only after confirming the job's idempotency guard prevents another provider call.
5. Tell the affected customer whether the run will resume, be retried, or be refunded.

## Stripe webhook failure

1. Find the event in Stripe and in `stripe_webhook_events` using the Stripe event ID.
2. Correct the configuration or application failure first.
3. Replay the event from Stripe.
4. Confirm the stored event becomes processed.
5. Confirm the subscription or credit purchase changed exactly once.
6. Use the owner-only billing QA endpoint to check the subscription, wallet, purchases, usage events, audit trail, and recent webhooks.

## Billing correction

1. Record the workspace, billing account, Stripe event, original wallet balance, and incorrect usage event.
2. Never delete the original usage or purchase record.
3. Apply a traceable compensating credit/refund through application billing logic or a reviewed database transaction.
4. Add an audit reason and operator identity.
5. Confirm the corrected balance with the customer.

## Apify or OpenAI outage

1. Stop starting new affected work.
2. Preserve provider run IDs and current reservations.
3. Let completed units settle and refund uncompleted units.
4. Confirm customers receive a nontechnical failure message.
5. Retry only after provider health and account balance are confirmed.

## Workspace/account deletion failure

1. Confirm the record remains inside its 30-day recovery window or is still scheduled for purge.
2. Review the lifecycle purge error without exposing the service-role key.
3. Correct Supabase access or the failing dependent deletion.
4. Run the lifecycle purge again.
5. Confirm one failed deletion did not stop other due deletions.

## Release rollback

1. Stop new risky operations if credits or provider calls could duplicate.
2. Prefer a forward fix for a database migration that has already changed production data.
3. If application rollback is safe, redeploy the last known-good Render commit.
4. Restart the worker so it loads the same application version as the web service.
5. Verify web, worker, cron, Stripe webhooks, and one read-only customer workflow.

## Backup restoration rehearsal

1. Confirm the Supabase backup time and retention available on the current plan.
2. Create a temporary isolated PostgreSQL database.
3. Restore the backup into the temporary database, never over production.
4. Run read-only counts and spot checks for users, workspaces, memberships, wallets, usage events, Stripe webhook events, discovery jobs, and deletion requests.
5. Confirm completed jobs remain completed and Stripe event IDs remain unique.
6. Delete the temporary restored database after documenting the result and retention requirements.

