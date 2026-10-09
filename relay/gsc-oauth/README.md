# Sign in with Google relay

A Cloudflare Worker at `https://gsc-oauth.wpallstars.com` that lets SEO Pro
Stats connect Google Search Console with one sign-in. It holds the Google
OAuth client's secret, which must never ship in the plugin, and keeps
nothing: no storage, no request logs. Design and flow:
`docs/architecture.md` → Search Console → Sign in with Google. Not part of
the plugin zip (`.distignore`).

| Route | What it does |
|---|---|
| `GET /` | What the service is, with the privacy policy. |
| `GET /start?site=…&nonce=…` | Names the site asking, then Continue goes to Google's consent screen (read-only Search Console, offline access). `site` is the plugin's return address: https, or http only for local hosts (`localhost`, `.local`, `.test`). |
| `GET /callback` | Google's redirect. Checks the signed state (HMAC, 15 minutes), swaps the code for tokens and posts `nonce`, `refresh_token`, `access_token` and `expires_in` (or `nonce` and `error`) to the return address in a self-submitting form. |
| `POST /refresh` | JSON `{"refresh_token": "…"}` → `{"access_token", "expires_in", "scope"}`, or `{"error"}` with Google's status. |

Errors posted back: `access_denied` (the person cancelled), `scope_denied`
(Search Console unticked), `no_refresh_token`, `exchange_failed`,
`google_error`.

## Setup (owner)

1. Google Cloud project with the Search Console API on; Google Auth
   Platform: branding (WPALLSTARS), audience External, scope
   `https://www.googleapis.com/auth/webmasters.readonly`, a Web application
   client whose only redirect URI is `https://gsc-oauth.wpallstars.com/callback`.
2. The `wpallstars.com` zone on Cloudflare (the route makes the DNS record
   and certificate).
3. Secrets, from the owner's secret store, never in the repository:

   ```sh
   npx wrangler secret put GOOGLE_CLIENT_ID
   npx wrangler secret put GOOGLE_CLIENT_SECRET
   npx wrangler secret put STATE_KEY   # any long random value
   ```

4. Deploy from this folder: `npx wrangler deploy` (with
   `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` set).

Changing `STATE_KEY` only cancels sign-ins in progress. Changing the client
secret needs no change on sites; a new client (new ID) means every site
signs in again.

## Limits

Each connected site asks `/refresh` about once an hour while imports run
(access tokens last an hour and the plugin keeps one until it expires), so
the Workers free plan's daily requests cover a few thousand sites.
