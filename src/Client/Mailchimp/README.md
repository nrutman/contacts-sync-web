# Mailchimp Client

The `App\Client\Mailchimp` namespace implements both source and destination integration with the [Mailchimp Marketing API v3](https://mailchimp.com/developer/marketing/api/).

## Authentication

Mailchimp uses **API key** authentication. Keys follow the format `{key}-{dc}` (e.g. `abc123def456-us21`), where the suffix identifies the data center. The client extracts the data center from the key and constructs the base URI `https://{dc}.api.mailchimp.com/3.0/`. Requests use HTTP Basic Auth with `anystring` as the username and the API key as the password.

## Endpoints

| Operation | Method | Endpoint | Notes |
|-----------|--------|----------|-------|
| Get contacts | `GET` | `/lists/{list_id}/members?status=subscribed` | Offset pagination with `count=1000` |
| Add/update contact | `PUT` | `/lists/{list_id}/members/{subscriber_hash}` | Idempotent upsert with `status: subscribed` |
| Remove contact | `PATCH` | `/lists/{list_id}/members/{subscriber_hash}` | Sets `status: unsubscribed` (does not archive/delete) |
| List audiences | `GET` | `/lists` | Returns `[id => name]` map for list discovery |

## Subscriber Hash

Mailchimp identifies subscribers by the MD5 hash of their lowercased email address. The `subscriberHash()` static method computes this.

## Pagination

`getContacts()` uses offset-based pagination. It requests up to 1000 members per page and continues fetching until all `total_items` have been retrieved.

## Contact Mapping

- `email_address` → `Contact::email`
- `merge_fields.FNAME` → `Contact::firstName` (empty string treated as `null`)
- `merge_fields.LNAME` → `Contact::lastName` (empty string treated as `null`)

## List Discovery

`MailchimpProvider` implements `ListDiscoverableInterface`. The `getAvailableLists()` method calls `GET /lists` and returns an `[id => name]` map of audiences, allowing the UI to present a dropdown of available audiences when configuring sync lists.

## Provider Capabilities

Mailchimp supports both **Source** and **Destination** capabilities, making it the first provider that can serve either role in the sync pipeline.
