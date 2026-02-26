# Entity Relationship Diagram

```mermaid
erDiagram
    Organization ||--o{ SyncList : "has many"
    Organization ||--o{ ProviderCredential : "has many"
    Organization ||--o{ InMemoryContact : "has many"
    SyncList }o--o| ProviderCredential : "sourceCredential"
    SyncList }o--o| ProviderCredential : "destinationCredential"
    SyncList ||--o{ SyncRun : "has many"
    SyncList }o--o{ InMemoryContact : "many-to-many"
    SyncRun }o--o| User : "triggeredByUser"

    Organization {
        uuid id PK
        string name
        datetime createdAt
        datetime updatedAt
    }

    User {
        uuid id PK
        string email UK
        string password "nullable"
        bool isVerified
        json roles
        string firstName
        string lastName
        bool notifyOnSuccess
        bool notifyOnFailure
        bool notifyOnNoChanges
        datetime createdAt
        datetime updatedAt
    }

    SyncList {
        uuid id PK
        uuid organization_id FK
        string name
        uuid sourceCredential_id FK "nullable"
        string sourceListIdentifier "nullable"
        uuid destinationCredential_id FK "nullable"
        string destinationListIdentifier "nullable"
        bool isEnabled
        string cronExpression "nullable"
        datetime createdAt
        datetime updatedAt
    }

    ProviderCredential {
        uuid id PK
        uuid organization_id FK
        string providerName
        string label "nullable"
        text credentials "encrypted"
        json metadata "nullable"
        datetime createdAt
        datetime updatedAt
    }

    SyncRun {
        uuid id PK
        uuid syncList_id FK
        string triggeredBy
        uuid triggeredByUser_id FK "nullable"
        string status
        int sourceCount "nullable"
        int destinationCount "nullable"
        int addedCount "nullable"
        int removedCount "nullable"
        text log "nullable"
        text errorMessage "nullable"
        datetime startedAt "nullable"
        datetime completedAt "nullable"
        datetime createdAt
    }

    InMemoryContact {
        uuid id PK
        uuid organization_id FK
        string name
        string email
        datetime createdAt
    }
```

## Relationships

- **Organization** is the top-level tenant. It owns SyncLists, ProviderCredentials, and InMemoryContacts (all cascade-removed with orphan removal).
- **SyncList** defines a sync job between a source and destination provider. Each endpoint is configured via a nullable reference to a **ProviderCredential** plus a list identifier string.
- **SyncRun** records the outcome of a single sync execution for a SyncList. It optionally tracks which **User** triggered it.
- **ProviderCredential** stores encrypted API credentials (OAuth tokens, API keys) for a given provider (e.g., Google, Planning Center).
- **InMemoryContact** represents a contact that exists only in the database (not from an external provider). It belongs to an Organization and is associated with SyncLists via a many-to-many join table (`in_memory_contact_sync_list`).
- **User** is independent of Organization and represents an authenticated application user with notification preferences.
