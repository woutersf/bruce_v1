<?php

namespace Drupal\bruce_v1\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin status page for the BRUCE API integration.
 */
class BruceStatusController extends ControllerBase {

  /**
   * Renders the BRUCE status page.
   */
  public function page(Request $request): array {
    $base = $request->getSchemeAndHttpHost();
    $checks = $this->runChecks($base);
    $all_ok = !in_array(FALSE, array_column($checks, 'ok'));

    $build = [];

    // ── Overall status banner ─────────────────────────────────────────────
    $build['status_banner'] = [
      '#type'       => 'container',
      '#attributes' => [
        'class' => $all_ok
          ? ['messages', 'messages--status']
          : ['messages', 'messages--warning'],
      ],
      'message' => [
        '#markup' => $all_ok
          ? $this->t('<strong>BRUCE is ready.</strong> All checks passed. Point your AI at <code>GET @url/api/bruce</code> to get started.', ['@url' => $base])
          : $this->t('<strong>Action required.</strong> Some checks failed. Fix the issues below before using BRUCE.'),
      ],
    ];

    // ── Checks table ──────────────────────────────────────────────────────
    $rows = [];
    foreach ($checks as $check) {
      $icon   = $check['ok'] ? '✅' : '❌';
      $status = $check['ok'] ? $this->t('OK') : $this->t('Action needed');
      $rows[] = [
        ['data' => $icon . ' ' . $check['label']],
        ['data' => ['#markup' => $check['ok']
          ? '<strong>' . $status . '</strong>'
          : '<strong style="color:#c00">' . $status . '</strong>',
        ]],
        ['data' => ['#markup' => $check['detail']]],
      ];
    }

    $build['checks'] = [
      '#type'    => 'table',
      '#caption' => $this->t('Configuration Checks'),
      '#header'  => [$this->t('Check'), $this->t('Status'), $this->t('Detail')],
      '#rows'    => $rows,
    ];

    // ── API Endpoints ─────────────────────────────────────────────────────
    $endpoint_rows = [
      [
        ['data' => $this->t('BRUCE README (point your AI here first)')],
        ['data' => ['#markup' => "<code>GET <a href=\"{$base}/api/bruce\" target=\"_blank\">{$base}/api/bruce</a></code>"]],
        ['data' => $this->t('Plain-text guide: auth, content types, fields, example calls. Auto-generated from live config.')],
      ],
      [
        ['data' => $this->t('BRUCE Context (machine JSON)')],
        ['data' => ['#markup' => "<code>GET <a href=\"{$base}/api/bruce/context\" target=\"_blank\">{$base}/api/bruce/context</a></code>"]],
        ['data' => $this->t('Structured JSON with all content types, fields, and vocabularies.')],
      ],
      [
        ['data' => $this->t('JSON:API root (all resource types)')],
        ['data' => ['#markup' => "<code>GET <a href=\"{$base}/jsonapi\" target=\"_blank\">{$base}/jsonapi</a></code>"]],
        ['data' => $this->t('Lists every JSON:API resource type. BRUCE uses child endpoints for CRUD.')],
      ],
    ];

    $build['endpoints'] = [
      '#type'    => 'table',
      '#caption' => $this->t('API Endpoints'),
      '#header'  => [$this->t('Endpoint'), $this->t('URL'), $this->t('Purpose')],
      '#rows'    => $endpoint_rows,
    ];

    // ── Quick-start ───────────────────────────────────────────────────────
    $build['quickstart'] = [
      '#type'  => 'details',
      '#title' => $this->t('Quick-start instructions for BRUCE'),
      '#open'  => !$all_ok,
    ];

    $bruce_user = $this->getBruceUser();
    $user_note  = $bruce_user
      ? $this->t('User <em>@name</em> (uid @uid) found.', [
        '@name' => $bruce_user->getAccountName(),
        '@uid'  => $bruce_user->id(),
      ])
      : $this->t('No <em>bruce</em> user found — create one at <a href="/admin/people/create">admin/people/create</a> with the Administrator role.');

    $build['quickstart']['steps'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Run <code>drush en bruce_v1 -y && drush cr</code> if not already enabled.'),
        $this->t('Create a Drupal user named <strong>bruce</strong> with the <strong>Administrator</strong> role. @note', ['@note' => $user_note]),
        $this->t('Give BRUCE its credentials and point it at: <code>GET @url/api/bruce</code>', ['@url' => $base]),
        $this->t('BRUCE reads the README, then uses <code>@url/jsonapi/node/{type}</code> for all content operations.', ['@url' => $base]),
      ],
    ];

    // ── Auth example ──────────────────────────────────────────────────────
    $build['auth'] = [
      '#type'  => 'details',
      '#title' => $this->t('Authentication example (Basic Auth)'),
      '#open'  => FALSE,
    ];

    $build['auth']['example'] = [
      '#markup' => '<pre>' . htmlspecialchars(
        "# Encode credentials\necho -n 'bruce:PASSWORD' | base64\n\n" .
        "# Fetch the README\ncurl -H 'Authorization: Basic <encoded>' \\\n" .
        "     {$base}/api/bruce"
      ) . '</pre>',
    ];

    return $build;
  }

  /**
   * Runs all prerequisite checks.
   */
  protected function runChecks(string $base): array {
    $jsonapi_on   = $this->moduleHandler()->moduleExists('jsonapi');
    $basicauth_on = $this->moduleHandler()->moduleExists('basic_auth');
    $pathalias_on = $this->moduleHandler()->moduleExists('path_alias');
    $writable     = !$this->config('jsonapi.settings')->get('read_only');
    $bruce_user   = $this->getBruceUser();

    return [
      [
        'label'  => $this->t('JSON:API module enabled'),
        'ok'     => $jsonapi_on,
        'detail' => $jsonapi_on
          ? $this->t('Module is active.')
          : $this->t('Run <code>drush en jsonapi -y</code> or enable via <a href="/admin/modules">admin/modules</a>.'),
      ],
      [
        'label'  => $this->t('JSON:API write mode (read_only: false)'),
        'ok'     => $writable,
        'detail' => $writable
          ? $this->t('POST / PATCH / DELETE are allowed.')
          : $this->t('Set <code>read_only: false</code> in <code>jsonapi.settings</code>, or reinstall this module.'),
      ],
      [
        'label'  => $this->t('HTTP Basic Auth module enabled'),
        'ok'     => $basicauth_on,
        'detail' => $basicauth_on
          ? $this->t('Module is active. BRUCE can authenticate with username + password.')
          : $this->t('Run <code>drush en basic_auth -y</code>.'),
      ],
      [
        'label'  => $this->t('Path Alias module enabled'),
        'ok'     => $pathalias_on,
        'detail' => $pathalias_on
          ? $this->t('Module is active. BRUCE can set friendly URLs via <code>/jsonapi/path_alias/path_alias</code>.')
          : $this->t('Run <code>drush en path_alias -y</code>.'),
      ],
      [
        'label'  => $this->t('BRUCE Drupal user exists'),
        'ok'     => (bool) $bruce_user,
        'detail' => $bruce_user
          ? $this->t('User <em>@name</em> (uid @uid) found — ensure it has the Administrator role.', [
            '@name' => $bruce_user->getAccountName(),
            '@uid'  => $bruce_user->id(),
          ])
          : $this->t('Create a user at <a href="/admin/people/create">admin/people/create</a> with username <strong>bruce</strong> and the <strong>Administrator</strong> role.'),
      ],
    ];
  }

  /**
   * Looks up a user named "bruce".
   */
  protected function getBruceUser(): ?object {
    $users = $this->entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => 'bruce']);
    return $users ? reset($users) : NULL;
  }

}
