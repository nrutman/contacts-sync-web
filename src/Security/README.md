# 🔒 Security Namespace

This namespace contains three services that handle authentication integrity and data encryption.

## EncryptionService

Wraps PHP's libsodium functions (`sodium_crypto_secretbox` / `sodium_crypto_secretbox_open`) to provide authenticated encryption (XSalsa20-Poly1305) for sensitive database fields.

### Key Versioning

Every encrypted value is prefixed with a version tag (e.g. `v1:`, `v2:`) so the service knows which key to use for decryption. This enables zero-downtime key rotation:

1. Set the old key as a previous key: `APP_PREVIOUS_ENCRYPTION_KEYS="1:<old-hex-key>"`
2. Set the new key as current: `APP_ENCRYPTION_KEY=<new-hex-key>`
3. Run `bin/console app:rotate-encryption-keys --force` to re-encrypt all fields with the new key.

The service always encrypts with the current (latest) key and can decrypt with any previous key. Legacy data without a version prefix is decrypted using the current key for backwards compatibility.

### How It Connects to Doctrine

The `#[Encrypted]` attribute (in `App\Attribute`) marks entity properties for automatic encryption. The `EncryptedFieldListener` (in `App\EventListener`) intercepts Doctrine's `prePersist`, `preUpdate`, and `postLoad` events to encrypt on write and decrypt on read. Application code never sees ciphertext — it works with plaintext values on the entity, and encryption is transparent.

## UserChecker

A Symfony `UserCheckerInterface` implementation registered on the security firewall. It runs two pre-authentication checks:

1. **Verification check** — Blocks users where `isVerified === false` (invitation not yet completed).
2. **Password check** — Blocks users where `password` is `null` or empty, as a defense-in-depth guard against edge cases where `isVerified` is `true` but no password has been set.

The verification check runs first so the user sees the most helpful error message.

## UserInvitationService

Sends invitation emails to newly created users. It uses `symfonycasts/verify-email-bundle` to generate signed, time-limited URLs. The flow:

1. Admin creates a user via `/users/new` (no password is set).
2. `UserInvitationService::sendInvitation()` generates a signed URL and sends an email.
3. The user clicks the link, which hits `VerifyEmailController`.
4. The controller validates the signature, shows a "set password" form, hashes the password, sets `isVerified = true`, and the account is active.

CLI-created users (via `app:create-user` or `app:setup`) bypass this flow entirely — they are auto-verified with a password set at the command line.

## Roles & Permissions

Every user is assigned `ROLE_USER` automatically. Admins additionally receive `ROLE_ADMIN`, which can be granted via the `--admin` flag on `app:create-user`, the setup wizard (`app:setup`), or the user edit form in the web UI.

All authenticated routes require `ROLE_USER` (configured in `security.yaml`). Admin restrictions are enforced at the controller level with `#[IsGranted('ROLE_ADMIN')]` and in Twig templates with `is_granted('ROLE_ADMIN')`.

| Capability                          | User | Admin |
|-------------------------------------|------|-------|
| View dashboard                      | Yes  | Yes   |
| View sync lists and sync history    | Yes  | Yes   |
| Trigger manual syncs                | Yes  | Yes   |
| Personal notification preferences   | Yes  | Yes   |
| Create, edit, delete sync lists     | No   | Yes   |
| Enable/disable sync lists           | No   | Yes   |
| View manual contacts                | Yes  | Yes   |
| Create, edit, delete manual contacts| No   | Yes   |
| Manage provider credentials & OAuth | No   | Yes   |
| Organization settings               | No   | Yes   |
| Manage users & send invitations     | No   | Yes   |