<?php

namespace Drupal\bruce_v1\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for BRUCE — sets the API token and shows status checks.
 */
class BruceSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['bruce_v1.settings'];
  }

  public function getFormId(): string {
    return 'bruce_v1_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('bruce_v1.settings');
    $token  = $config->get('api_token');
    $base   = \Drupal::request()->getSchemeAndHttpHost();

    // ── Token field ───────────────────────────────────────────────────────
    $form['api_token'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('API Token'),
      '#description'   => $this->t(
        'Secret token BRUCE must pass as <code>?token=VALUE</code> when calling <code>/api/bruce</code> and <code>/api/bruce/context</code>. Leave empty to block all access to those endpoints.'
      ),
      '#default_value' => $token,
      '#placeholder'   => 'e.g. my-secret-token-123',
      '#maxlength'     => 255,
    ];

    // ── Endpoint quick-links ──────────────────────────────────────────────
    if ($token) {
      $form['links'] = [
        '#type'  => 'details',
        '#title' => $this->t('API Endpoints'),
        '#open'  => TRUE,
      ];
      $form['links']['table'] = [
        '#type'   => 'table',
        '#header' => [$this->t('Endpoint'), $this->t('URL')],
        '#rows'   => [
          [
            $this->t('BRUCE README'),
            ['data' => ['#markup' => "<a href=\"{$base}/api/bruce?token={$token}\" target=\"_blank\">{$base}/api/bruce?token={$token}</a>"]],
          ],
          [
            $this->t('BRUCE Context (JSON)'),
            ['data' => ['#markup' => "<a href=\"{$base}/api/bruce/context?token={$token}\" target=\"_blank\">{$base}/api/bruce/context?token={$token}</a>"]],
          ],
          [
            $this->t('JSON:API root'),
            ['data' => ['#markup' => "<a href=\"{$base}/jsonapi\" target=\"_blank\">{$base}/jsonapi</a>"]],
          ],
        ],
      ];
    }

    // ── Status checks ─────────────────────────────────────────────────────
    $checks   = $this->runChecks();
    $all_ok   = !in_array(FALSE, array_column($checks, 'ok'));

    $form['status'] = [
      '#type'  => 'details',
      '#title' => $this->t('Status'),
      '#open'  => TRUE,
    ];

    $form['status']['banner'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => $all_ok ? ['messages', 'messages--status'] : ['messages', 'messages--warning']],
      'msg'         => ['#markup' => $all_ok
        ? $this->t('<strong>All checks passed.</strong>')
        : $this->t('<strong>Action required.</strong> Fix the issues below.'),
      ],
    ];

    $rows = [];
    foreach ($checks as $check) {
      $rows[] = [
        ($check['ok'] ? '✅ ' : '❌ ') . $check['label'],
        ['data' => ['#markup' => $check['ok']
          ? '<strong>' . $this->t('OK') . '</strong>'
          : '<strong style="color:#c00">' . $this->t('Action needed') . '</strong>',
        ]],
        ['data' => ['#markup' => $check['detail']]],
      ];
    }

    $form['status']['table'] = [
      '#type'   => 'table',
      '#header' => [$this->t('Check'), $this->t('Status'), $this->t('Detail')],
      '#rows'   => $rows,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('bruce_v1.settings')
      ->set('api_token', trim($form_state->getValue('api_token')))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Runs prerequisite checks.
   */
  protected function runChecks(): array {
    $module_handler = \Drupal::moduleHandler();
    $jsonapi_on     = $module_handler->moduleExists('jsonapi');
    $basicauth_on   = $module_handler->moduleExists('basic_auth');
    $pathalias_on   = $module_handler->moduleExists('path_alias');
    $writable       = !$this->config('jsonapi.settings')->get('read_only');
    $token_set      = !empty($this->config('bruce_v1.settings')->get('api_token'));

    $users      = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'bruce']);
    $bruce_user = $users ? reset($users) : NULL;

    return [
      [
        'label'  => $this->t('API token configured'),
        'ok'     => $token_set,
        'detail' => $token_set
          ? $this->t('Token is set. Use it as <code>?token=VALUE</code>.')
          : $this->t('Set a token above to enable access to the API endpoints.'),
      ],
      [
        'label'  => $this->t('JSON:API module enabled'),
        'ok'     => $jsonapi_on,
        'detail' => $jsonapi_on
          ? $this->t('Module is active.')
          : $this->t('Run <code>drush en jsonapi -y</code>.'),
      ],
      [
        'label'  => $this->t('JSON:API write mode (read_only: false)'),
        'ok'     => $writable,
        'detail' => $writable
          ? $this->t('POST / PATCH / DELETE are allowed.')
          : $this->t('Reinstall this module or set <code>read_only: false</code> in <code>jsonapi.settings</code>.'),
      ],
      [
        'label'  => $this->t('HTTP Basic Auth module enabled'),
        'ok'     => $basicauth_on,
        'detail' => $basicauth_on
          ? $this->t('Module is active.')
          : $this->t('Run <code>drush en basic_auth -y</code>.'),
      ],
      [
        'label'  => $this->t('Path Alias module enabled'),
        'ok'     => $pathalias_on,
        'detail' => $pathalias_on
          ? $this->t('Module is active.')
          : $this->t('Run <code>drush en path_alias -y</code>.'),
      ],
      [
        'label'  => $this->t('BRUCE Drupal user exists'),
        'ok'     => (bool) $bruce_user,
        'detail' => $bruce_user
          ? $this->t('User <em>@name</em> (uid @uid) found.', ['@name' => $bruce_user->getAccountName(), '@uid' => $bruce_user->id()])
          : $this->t('Create a user at <a href="/admin/people/create">admin/people/create</a> with username <strong>bruce</strong> and the <strong>Administrator</strong> role.'),
      ],
    ];
  }

}
