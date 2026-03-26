# Bruce - Make Digital Experiences Flow

Exposes a Drupal site as a JSON:API shell for **BRUCE**, an external AI agent. The module gives BRUCE everything it needs to discover the site's content model and manage content via JSON:API.

## How it works

1. BRUCE calls `GET /api/bruce?token=SECRET` to receive a Markdown guide with all content types, fields, and JSON:API usage examples.
2. BRUCE calls `GET /api/bruce/context?token=SECRET` to get the same information as machine-readable JSON.
3. BRUCE uses standard JSON:API endpoints (`/jsonapi/node/{type}`, etc.) with HTTP Basic Auth to create, update, delete, and query content.

## Setup

1. Enable the module (automatically sets JSON:API to write mode).
2. Create a Drupal user named `bruce` with the **Administrator** role.
3. Go to **Configuration > AI > Bruce** and set an API token.
4. Verify all status checks pass on the same page.

## Endpoints

| Endpoint | Description |
|---|---|
| `GET /api/bruce?token=TOKEN` | Human-readable API guide (Markdown) |
| `GET /api/bruce/context?token=TOKEN` | Machine-readable site metadata (JSON) |
| `GET/POST/PATCH/DELETE /jsonapi/...` | Standard JSON:API content operations |

## Authentication

- **API endpoints** (`/api/bruce*`): token via `?token=` query parameter.
- **JSON:API write operations**: HTTP Basic Auth with the `bruce` user credentials.

## Dependencies

- `drupal:jsonapi`
- `drupal:basic_auth`
- `drupal:path_alias`

---

[dropsolid.ai](https://dropsolid.ai)
