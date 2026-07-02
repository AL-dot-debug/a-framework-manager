# Framework Manager — Public API

Base URL: `https://<your-domain>/api.php`

## Authentication

All public endpoints require a Bearer token in the `Authorization` header:

```
Authorization: Bearer <token>
```

The token is generated in **Settings > Public API** within the Framework Manager admin panel.

### Error responses

| Status | Body | Meaning |
|--------|------|---------|
| 401 | `{"error": "Invalid or missing API token"}` | Token is missing or incorrect |
| 503 | `{"error": "Public API not configured"}` | No token has been configured yet |

---

## Endpoints

### Submit a change proposal

```
POST /api.php?action=public_submit_proposal
```

Submit a public change proposal for TSC review.

**Request body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | yes | Submitter's full name |
| `email` | string | yes | Contact email (must be valid) |
| `organisation` | string | no | Submitter's organisation |
| `type` | string | yes | Change type (see values below) |
| `title` | string | yes | Short description of the proposed change |
| `description` | string | yes | Full rationale and details |
| `references` | string | no | Related framework IDs, e.g. `T0049.001, TA06` |

**Allowed `type` values:**

| Value | Meaning |
|-------|---------|
| `new-technique` | New technique or sub-technique |
| `modify` | Modification to an existing entry |
| `deprecate` | Deprecate or retire an entry |
| `structural` | Structural or schema change |
| `correction` | Correction or clarification |
| `other` | Other |

**Example request:**

```bash
curl -X POST "https://example.com/api.php?action=public_submit_proposal" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Smith",
    "email": "jane@example.com",
    "organisation": "ACME Research",
    "type": "new-technique",
    "title": "Add technique for AI-generated audio deepfakes",
    "description": "Propose adding a technique covering the use of AI-generated voice cloning in disinformation campaigns. Several documented cases in 2025 elections.",
    "references": "T0087, TA06"
  }'
```

**Success response** (`200`):

```json
{
  "success": true,
  "submission_id": "a1b2c3d4-..."
}
```

**Validation errors** (`400`):

```json
{
  "success": false,
  "error": "Fields name, email, type, title, and description are required"
}
```

---

### Get TSC members

```
GET /api.php?action=public_get_members
```

Returns the current Technical Steering Committee members. Only names and roles are exposed — no email addresses.

**Example request:**

```bash
curl "https://example.com/api.php?action=public_get_members" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response** (`200`):

```json
{
  "members": [
    { "name": "Alice Johnson", "role": "President" },
    { "name": "Bob Williams", "role": "Vice-President" },
    { "name": "Carol Davis", "role": "Member" }
  ]
}
```

Members are sorted by role hierarchy: President, Vice-President, then Members.

---

## CORS

Both public endpoints return permissive CORS headers and support `OPTIONS` preflight requests, so they can be called directly from browser JavaScript on any domain.
