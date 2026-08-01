# Paid pilot acceptance checklist

Use a new email address and an otherwise empty workspace. Record the time, workspace ID, Stripe checkout/session ID, discovery job ID, and any provider run IDs.

## Account and workspace

- [ ] Sign up through the public signup page.
- [ ] Confirm that an unverified email cannot enter the product.
- [ ] Verify the email and create a workspace.
- [ ] Sign out and sign in again; confirm the existing workspace opens rather than workspace creation.
- [ ] Invite a second email and confirm that the invitation is delivered and accepted into the correct workspace.

## Billing

- [ ] Record the wallet balances before checkout.
- [ ] Complete a Stripe test-mode subscription checkout using the intended production plan amount.
- [ ] Confirm that the selected plan activates.
- [ ] Confirm that the expected scrape and AI credits are granted once.
- [ ] Refresh and sign in again; confirm that credits are not granted a second time.
- [ ] Open the owner-only billing QA endpoint and confirm every check is green.
- [ ] Confirm the Stripe event is `processed` and has no `last_error`.
- [ ] If top-ups are offered, purchase one and confirm its credits are granted once.
- [ ] Test cancellation and confirm access follows the intended period-end behavior.

## Discovery and enrichment

- [ ] Start a normal Instagram discovery and complete deep enrichment.
- [ ] Confirm creator counts, provider run identifiers, credit usage, and review results.
- [ ] Start a second run and cancel during discovery.
- [ ] Start another run and cancel during enrichment.
- [ ] Confirm each cancelled run reaches a final state and another run can start afterward.
- [ ] Confirm credits consumed equal verified completed work and unused reserved credits return.
- [ ] Trigger insufficient credits and confirm no provider job starts.
- [ ] Trigger or observe a provider failure and confirm the customer sees a useful nontechnical message while internal logs retain the provider detail.
- [ ] Repeat the supported flow for TikTok while the feature is enabled.

## Workflow

- [ ] Review discovered creators and add selected creators to CRM.
- [ ] Open a creator profile and confirm the suggested next action is correct.
- [ ] Log initial outreach and a creator reply.
- [ ] Complete and snooze follow-up tasks.
- [ ] Confirm messages appear in message history and technical lifecycle events remain collapsed/minimal.
- [ ] Confirm the relationship timeline is collapsed by default.

## Recovery and data lifecycle

- [ ] Export the workspace and inspect the downloaded archive.
- [ ] Delete a test workspace and restore it during the 30-day recovery period.
- [ ] Schedule a test account for deletion and restore it.
- [ ] Confirm another workspace cannot access any of the test workspace's data.

## Operations

- [ ] Confirm `/api/health/operational` returns `ok` and HTTP 200.
- [ ] Run `php artisan ops:test-alert` and receive the email.
- [ ] Confirm the worker heartbeat updates at least every minute.
- [ ] Confirm the scheduler heartbeat updates at least every minute.
- [ ] Complete the backup restoration rehearsal in the operations runbook.

Do not launch the paid pilot until every failed item is either fixed or explicitly removed from the customer offer.

