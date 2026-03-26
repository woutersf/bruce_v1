<?php

namespace Drupal\bruce_v1\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes Drupal as an API shell for BRUCE, the external AI agent.
 */
class BruceController extends ControllerBase {

  // Base fields that are internal plumbing — skip them in the context output.
  protected const SKIP_FIELDS = [
    'nid', 'vid', 'uuid', 'langcode', 'revision_uid', 'revision_timestamp',
    'revision_log', 'revision_translation_affected', 'default_langcode',
    'content_translation_source', 'content_translation_outdated',
    'menu_link', 'publish_on', 'unpublish_on',
  ];

  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
    );
  }

  /**
   * Validates the ?token= parameter against the stored config token.
   */
  protected function tokenValid(Request $request): bool {
    $stored = $this->config('bruce_v1.settings')->get('api_token');
    return !empty($stored) && $request->query->get('token') === $stored;
  }

  /**
   * GET /api/bruce — plain-text README for BRUCE to read and understand the site.
   */
  public function readme(Request $request): Response {
    if (!$this->tokenValid($request)) {
      return new Response('Access denied. Provide a valid ?token= parameter.', 403, ['Content-Type' => 'text/plain']);
    }

    $ctx = $this->buildContext($request);
    $base = $ctx['site']['url'];
    $name = $ctx['site']['name'];

    // ── Content types section ─────────────────────────────────────────────
    $types_doc = '';
    foreach ($ctx['content_types'] as $bundle => $info) {
      $types_doc .= "### {$info['label']}  (resource type: `{$info['resource_type']}`)\n";
      $types_doc .= "Endpoint: `{$info['endpoint']}`\n\n";
      $types_doc .= "| Field | Type | Notes |\n";
      $types_doc .= "|-------|------|-------|\n";
      foreach ($info['fields'] as $fname => $fdesc) {
        $types_doc .= "| `{$fname}` | {$fdesc['type']} | {$fdesc['notes']} |\n";
      }
      $types_doc .= "\n";
    }

    // ── Vocabularies section ──────────────────────────────────────────────
    $vocab_doc = '';
    foreach ($ctx['vocabularies'] as $vocab => $info) {
      $vocab_doc .= "- **{$info['label']}** — `{$info['resource_type']}` — `{$info['endpoint']}`\n";
    }
    if (!$vocab_doc) {
      $vocab_doc = "_No vocabularies found._\n";
    }

    $readme = <<<GUIDE
# BRUCE API Guide — {$name}

You are **BRUCE**, an external AI agent. This Drupal site is your shell.
You control it entirely through its **JSON:API**. Read this document before acting.

---

## 1. Authentication

Every request requires **HTTP Basic Authentication**.

```
Authorization: Basic <base64("USERNAME:PASSWORD")>
```

Credentials are provided to you separately. Always include this header.

---

## 2. Base URL

```
{$base}/jsonapi
```

---

## 3. Required Headers for Write Operations

```
Content-Type: application/vnd.api+json
Accept:       application/vnd.api+json
Authorization: Basic <credentials>
```

GET requests only need the Authorization header.

---

## 4. Site Discovery

**This document (auto-generated from live config):**
```
GET {$base}/api/bruce
```

**Machine-readable JSON (content types, fields, vocabularies):**
```
GET {$base}/api/bruce/context
```

**Full list of every JSON:API resource type on this site:**
```
GET {$base}/jsonapi
```

---

## 5. Content Types on This Site

{$types_doc}
---

## 6. Taxonomy Vocabularies

{$vocab_doc}
---

## 7. Create Content

```
POST {$base}/jsonapi/node/article
Authorization: Basic ...
Content-Type: application/vnd.api+json

{
  "data": {
    "type": "node--article",
    "attributes": {
      "title": "My Article Title",
      "status": true,
      "body": {
        "value": "<p>Content here.</p>",
        "format": "basic_html"
      }
    }
  }
}
```

The response contains `data.id` (UUID) and `data.attributes.drupal_internal__nid` (numeric ID).

---

## 8. Update Content

Send only the fields you want to change. The `id` field is required.

```
PATCH {$base}/jsonapi/node/article/{uuid}
Authorization: Basic ...
Content-Type: application/vnd.api+json

{
  "data": {
    "type": "node--article",
    "id": "{uuid}",
    "attributes": {
      "title": "Updated Title"
    }
  }
}
```

---

## 9. Delete Content

```
DELETE {$base}/jsonapi/node/article/{uuid}
Authorization: Basic ...
```

---

## 10. Find / Query Content

### Get a single node by UUID

```
GET {$base}/jsonapi/node/article/{uuid}
Authorization: Basic ...
```

### List all nodes of a type

```
GET {$base}/jsonapi/node/article
Authorization: Basic ...
```

### Filter by published status

```
# Published only
GET {$base}/jsonapi/node/article?filter[status]=1

# Unpublished only
GET {$base}/jsonapi/node/article?filter[status]=0
```

### Filter by exact field value

```
GET {$base}/jsonapi/node/article?filter[title]=My+Exact+Title
```

### Filter with operators

Supported operators: `=`, `<>`, `<`, `<=`, `>`, `>=`, `STARTS_WITH`, `CONTAINS`, `ENDS_WITH`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `IS NULL`, `IS NOT NULL`

```
# Title contains a word
GET {$base}/jsonapi/node/article?filter[title][value]=hello&filter[title][operator]=CONTAINS

# Created after a timestamp
GET {$base}/jsonapi/node/article?filter[created][value]=1700000000&filter[created][operator]=>
```

### Multiple filters (AND)

```
GET {$base}/jsonapi/node/article?filter[status]=1&filter[title][value]=bruce&filter[title][operator]=CONTAINS
```

### Multiple filters (OR group)

```
GET {$base}/jsonapi/node/article
  ?filter[or-group][group][conjunction]=OR
  &filter[cond-a][condition][path]=title
  &filter[cond-a][condition][value]=Bruce
  &filter[cond-a][condition][memberOf]=or-group
  &filter[cond-b][condition][path]=title
  &filter[cond-b][condition][value]=BRUCE
  &filter[cond-b][condition][memberOf]=or-group
```

### Filter by taxonomy term (entity reference)

First get the term UUID:
```
GET {$base}/jsonapi/taxonomy_term/tags?filter[name]=MyTag
```

Then filter nodes by that term UUID:
```
GET {$base}/jsonapi/node/article?filter[field_tags.id]={term-uuid}
```

### Sort results

```
# Newest first
GET {$base}/jsonapi/node/article?sort=-created

# Oldest first
GET {$base}/jsonapi/node/article?sort=created

# Alphabetical by title
GET {$base}/jsonapi/node/article?sort=title

# Multiple sorts: status desc, then title asc
GET {$base}/jsonapi/node/article?sort=-status,title
```

### Pagination

```
# First 10 results
GET {$base}/jsonapi/node/article?page[limit]=10

# Next page (offset)
GET {$base}/jsonapi/node/article?page[limit]=10&page[offset]=10
```

The response includes `links.next` with a ready-made URL for the next page when more results exist.

### Include related entities in one request

Avoids extra round-trips. Use `include` to embed relationships:

```
# Include the author (uid) and tags with each article
GET {$base}/jsonapi/node/article?include=uid,field_tags
```

Related entities appear in `included[]` in the response.

### Sparse fieldsets (limit which fields are returned)

Reduces response size — useful when you only need a few fields:

```
# Only return title and created date
GET {$base}/jsonapi/node/article?fields[node--article]=title,created,status
```

### Find a node by its URL alias

```
GET {$base}/jsonapi/path_alias/path_alias?filter[alias]=/my-friendly-url
```

The response contains `relationships.path_alias--path_alias` — cross-reference `drupal_internal__nid` to load the node.

### Full combined example

Find the 5 most recent published articles matching "event", including their tags:

```
GET {$base}/jsonapi/node/article
  ?filter[status]=1
  &filter[title][value]=event
  &filter[title][operator]=CONTAINS
  &sort=-created
  &page[limit]=5
  &include=field_tags
  &fields[node--article]=title,created,path,field_tags
Authorization: Basic ...
```

---

## 11. Create a Taxonomy Term

```
POST {$base}/jsonapi/taxonomy_term/tags
Authorization: Basic ...
Content-Type: application/vnd.api+json

{
  "data": {
    "type": "taxonomy_term--tags",
    "attributes": {
      "name": "My Tag"
    }
  }
}
```

---

## 12. Attach a Taxonomy Term to Content

Add a `relationships` key alongside `attributes`:

```json
{
  "data": {
    "type": "node--article",
    "attributes": { "title": "Tagged Article", "status": true },
    "relationships": {
      "field_tags": {
        "data": [
          { "type": "taxonomy_term--tags", "id": "{term-uuid}" }
        ]
      }
    }
  }
}
```

For single-value reference fields (not arrays), use `"data": { "type": "...", "id": "..." }`.

---

## 13. Set a URL Alias

After creating content, give it a friendly URL:

```
POST {$base}/jsonapi/path_alias/path_alias
Authorization: Basic ...
Content-Type: application/vnd.api+json

{
  "data": {
    "type": "path_alias--path_alias",
    "attributes": {
      "path": "/node/{nid}",
      "alias": "/my-friendly-url",
      "langcode": "en"
    }
  }
}
```

Use `drupal_internal__nid` from the create response as `{nid}`.

---

## 14. Publishing

- `"status": true` → published (visible on site)
- `"status": false` → unpublished (draft)

Always set `"status": true` unless the user explicitly asks for a draft.

---

## 15. UUIDs vs Numeric IDs

JSON:API uses **UUIDs** for all operations.
The create/read response gives you both:
- `data.id` — UUID (use for PATCH, DELETE, relationships)
- `data.attributes.drupal_internal__nid` — numeric node ID (use for path aliases)

---

## 16. Error Reference

| HTTP | Meaning | Fix |
|------|---------|-----|
| 401  | Missing or wrong credentials | Check Authorization header |
| 403  | No permission for this action | Check the bruce user's role/permissions |
| 404  | Wrong UUID or resource doesn't exist | Verify the UUID |
| 422  | Validation failed | Read the `errors` array in the response body for the exact field |

---

## 17. Recommended Workflow for Content Creation

1. Call `GET {$base}/api/bruce/context` to confirm field names for the content type
2. Create the entity: `POST {$base}/jsonapi/node/{type}`
3. Note `data.id` (UUID) and `data.attributes.drupal_internal__nid` from the response
4. Optionally create a URL alias: `POST {$base}/jsonapi/path_alias/path_alias`
5. The public URL is: `{$base}/node/{nid}` or the alias you set

---

*Auto-generated from live site configuration. Fetch this document again after content model changes.*
GUIDE;

    return new Response($readme, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
  }

  /**
   * GET /api/bruce/context — machine-readable site context as JSON.
   */
  public function context(Request $request): Response {
    if (!$this->tokenValid($request)) {
      return new JsonResponse(['error' => 'Access denied. Provide a valid ?token= parameter.'], 403);
    }
    return new JsonResponse($this->buildContext($request));
  }

  /**
   * Build the context array shared by both endpoints.
   */
  protected function buildContext(Request $request): array {
    $site_config = $this->config('system.site');
    $base = $request->getSchemeAndHttpHost();

    $token = $request->query->get('token', '');
    $t     = $token ? "?token={$token}" : '?token=YOUR_TOKEN';

    return [
      'site' => [
        'name' => $site_config->get('name'),
        'url'  => $base,
      ],
      'api' => [
        'jsonapi_base'  => "{$base}/jsonapi",
        'readme_url'    => "{$base}/api/bruce{$t}",
        'context_url'   => "{$base}/api/bruce/context{$t}",
        'authentication' => 'HTTP Basic Auth for JSON:API writes — credentials provided separately',
      ],
      'content_types' => $this->getContentTypes($base),
      'vocabularies'  => $this->getVocabularies($base),
    ];
  }

  /**
   * Returns a structured map of node bundles → fields.
   */
  protected function getContentTypes(string $base): array {
    $types = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $bundle => $info) {
      $fields = [];
      foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $def) {
        if (in_array($name, self::SKIP_FIELDS)) {
          continue;
        }
        $type = $def->getType();
        $required = $def->isRequired();
        $notes = $required ? 'required' : 'optional';

        if (in_array($type, ['entity_reference', 'entity_reference_revisions'])) {
          $settings = $def->getSettings();
          $target = $settings['target_type'] ?? 'unknown';
          $bundles = array_keys($settings['handler_settings']['target_bundles'] ?? []);
          if ($bundles) {
            $target .= '--' . implode('|', $bundles);
          }
          $notes .= ", references: {$target}";
        }

        $fields[$name] = [
          'type'  => $type,
          'notes' => $notes,
        ];
      }

      $types[$bundle] = [
        'label'         => $info['label'],
        'resource_type' => "node--{$bundle}",
        'endpoint'      => "{$base}/jsonapi/node/{$bundle}",
        'fields'        => $fields,
      ];
    }
    return $types;
  }

  /**
   * Returns a structured map of taxonomy vocabularies.
   */
  protected function getVocabularies(string $base): array {
    $vocabs = [];
    foreach ($this->bundleInfo->getBundleInfo('taxonomy_term') as $vocab => $info) {
      $vocabs[$vocab] = [
        'label'         => $info['label'],
        'resource_type' => "taxonomy_term--{$vocab}",
        'endpoint'      => "{$base}/jsonapi/taxonomy_term/{$vocab}",
      ];
    }
    return $vocabs;
  }

}
