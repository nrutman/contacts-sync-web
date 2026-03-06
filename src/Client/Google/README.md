# Google Client (`App\Client\Google`)

This namespace contains the Google Workspace integration layer, communicating with the [Google Admin Directory API](https://developers.google.com/admin-sdk/directory) to manage Google Group memberships.

## OAuth Lifecycle

`GoogleClient::initialize()` handles the full OAuth lifecycle and must be called before any read/write operations:

```mermaid
flowchart TD
    A[initialize] --> B["Configure Google Client<br/>scopes, credentials, offline access"]
    B --> C["Load token from<br/>ProviderCredential"]
    C --> D{Token valid?}
    D -- Yes --> E[Ready]
    D -- No --> F{"Refresh token<br/>available?"}
    F -- Yes --> G[Refresh access token]
    G --> H[GoogleGroupsProvider persists updated credentials]
    H --> E
    F -- No --> I["Throw InvalidGoogleTokenException"]
```

The two required scopes are `ADMIN_DIRECTORY_GROUP` and `ADMIN_DIRECTORY_GROUP_MEMBER`. Access type is set to `offline` so that a refresh token is issued on initial authorization. The `select_account` consent prompt ensures the refresh token is always included.

## GoogleGroupsProvider

`GoogleGroupsProvider` implements `ProviderInterface` and `OAuthProviderInterface`. It builds a `GoogleClient` from a `ProviderCredential` entity. The credential's encrypted JSON blob contains `oauth_credentials`, `domain`, and optionally `token`. During `createClient()`, the provider:

1. Extracts OAuth credentials and token from the credential blob.
2. Configures and initializes the `GoogleClient`.
3. If the token was refreshed (access token expired), updates the credential blob with the new token and flushes the entity manager.

## Token Storage

The OAuth token is stored as part of the encrypted JSON credentials in the `ProviderCredential` entity (under the `token` key). There are two ways to provision it:

- **Web OAuth redirect flow** — `ProviderCredentialController::oauthConnect` redirects the user to Google's consent screen; `oauthCallback` exchanges the authorization code for a token and stores it in the credential blob.
- **CLI paste flow** — `sync:configure` finds OAuth-requiring credentials, prompts the user to visit a URL, paste the authorization code, and stores the token. This is primarily useful during initial setup before the web UI is available.

## Installed vs. Web Credentials

The Google Cloud Console offers "Desktop app" (`installed`) and "Web application" (`web`) credential types. The `GoogleGroupsProvider` handles both transparently — if it detects `installed`-type credentials, it converts them to `web`-style with the correct redirect URI before initiating the OAuth flow.

For production use with the web UI, "Web application" credentials with the correct redirect URI (`https://your-domain.com/credentials/{id}/oauth/callback`) are recommended.
