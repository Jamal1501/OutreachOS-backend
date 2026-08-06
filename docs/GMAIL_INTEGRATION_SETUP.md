# Gmail integration setup

SocialCore uses Google's server-side OAuth flow and requests only:

- `openid`
- `email`
- `https://www.googleapis.com/auth/gmail.send`

The integration can send a user-reviewed email. It cannot read inboxes, replies, contacts, or existing messages.

## 1. Create the Google Cloud project

1. Open Google Cloud Console and create or select a dedicated production project, for example `SocialCore Gmail`.
2. Open **APIs & Services → Library**.
3. Find **Gmail API** and enable it.

## 2. Configure Google Auth Platform

Use an **External** audience because pilot customers are not all in one Google Workspace organization.

Branding values:

- App name: `SocialCore`
- Homepage: `https://www.socialcore.app`
- Privacy policy: `https://www.socialcore.app/privacy`
- Authorized domain: `socialcore.app`
- Add the real support and developer contact emails.

Under **Data access**, add the identity scopes and the Gmail send-only scope listed above. Do not add Gmail read, modify, metadata, IMAP, or full-mailbox scopes.

For a short private test, keep the audience in Testing and add the test Gmail addresses. Google expires authorizations that include Gmail scopes after seven days while an external app remains in Testing. Before pilots, move the app to In production and submit the send-only scope for verification.

## 3. Create the OAuth client

1. Open **Clients → Create client**.
2. Choose **Web application**.
3. Name it `SocialCore production web`.
4. Add this exact authorized redirect URI:

   `https://loveframes-outreach-api-1.onrender.com/api/integrations/gmail/callback`

5. Create the client and copy the client ID and client secret.

This is a server-side flow, so an authorized JavaScript origin is not required.

## 4. Configure the Render web service

Add these environment variables only to the backend web service:

```text
GOOGLE_GMAIL_ENABLED=true
GOOGLE_GMAIL_CLIENT_ID=<Google OAuth client ID>
GOOGLE_GMAIL_CLIENT_SECRET=<Google OAuth client secret>
GOOGLE_GMAIL_REDIRECT_URI=https://loveframes-outreach-api-1.onrender.com/api/integrations/gmail/callback
FRONTEND_URL=https://www.socialcore.app
```

Do not add the client secret to Vercel or any `VITE_` environment variable. The worker and cron job do not need these values while Gmail sends are synchronous.

Deploy the backend so the Gmail database migration runs, then deploy the frontend.

## 5. Test the complete flow

1. Open **Admin Settings → Email**.
2. Select **Connect Gmail** and approve the send-only permission.
3. Confirm the account appears as Connected and Default sender.
4. Open an outreach task with a creator email address.
5. Generate or write an email, verify its subject and body, and select **Send with Gmail**.
6. Confirm the message appears once in Gmail Sent.
7. Confirm SocialCore marks the task complete and records the outreach event.
8. Pressing the send button twice or retrying the same request must not create two deliveries.
9. Disconnect the mailbox and confirm it can no longer send.

## 6. Verification preparation

Google's send-only scope is sensitive but is narrower than restricted inbox-reading scopes. Verification typically requires:

- Verified ownership of `socialcore.app` in Google Search Console.
- A finished public homepage and privacy policy with no placeholder operator details.
- An accurate OAuth consent screen.
- A short video showing sign-in, consent, sending one reviewed email, and disconnecting.
- A written explanation that SocialCore sends only user-reviewed messages and does not read or train on Gmail data.

Keep the OAuth client secret and all connected-user tokens server-side. Rotate the client secret immediately if it is ever exposed.
