<?php

namespace Drupal\update\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\update\ModuleVersion;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure update settings for this site.
 *
 * @internal
 */
class UpdateManagerUpdate extends FormBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The Drupal state storage service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new UpdateManagerUpdate object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(ModuleHandlerInterface $module_handler, StateInterface $state) {
    $this->moduleHandler = $module_handler;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'update_manager_update_form';
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->moduleHandler->loadInclude('update', 'inc', 'update.manager');

    $last_markup = [
      '#theme' => 'update_last_check',
      '#last' => $this->state->get('update.last_check') ?: 0,
    ];
    $form['last_check'] = [
      '#markup' => \Drupal::service('renderer')->render($last_markup),
    ];

    if (!_update_manager_check_backends($form, 'update')) {
      return $form;
    }

    $available = update_get_available(TRUE);
    if (empty($available)) {
      $form['message'] = [
        '#markup' => $this->t('There was a problem getting update information. Try again later.'),
      ];
      return $form;
    }

    $form['#attached']['library'][] = 'update/drupal.update.admin';

    // This will be a nested array. The first key is the kind of project, which
    // can be either 'enabled', 'disabled', 'manual' (projects which require
    // manual updates, such as core). Then, each subarray is an array of
    // projects of that type, indexed by project short name, and containing an
    // array of data for cells in that project's row in the appropriate table.
    $projects = [];

    // This stores the actual download link we're going to update from for each
    // project in the form, regardless of if it's enabled or disabled.
    $form['project_downloads'] = ['#tree' => TRUE];
    $this->moduleHandler->loadInclude('update', 'inc', 'update.compare');
    $project_data = update_calculate_project_data($available);
    foreach ($project_data as $name => $project) {
      // Filter out projects which are up to date already.
      if ($project['status'] == UPDATE_CURRENT) {
        continue;
      }
      // The project name to display can vary based on the info we have.
      if (!empty($project['title'])) {
        if (!empty($project['link'])) {
          $project_name = Link::fromTextAndUrl($project['title'], Url::fromUri($project['link']))->toString();
        }
        else {
          $project_name = $project['title'];
        }
      }
      elseif (!empty($project['info']['name'])) {
        $project_name = $project['info']['name'];
      }
      else {
        $project_name = $name;
      }
      if ($project['project_type'] == 'theme' || $project['project_type'] == 'theme-disabled') {
        $project_name .= ' ' . $this->t('(Theme)');
      }

      if (empty($project['recommended'])) {
        // If we don't know what to recommend they upgrade to, we should skip
        // the project entirely.
        continue;
      }

      $recommended_release = $project['releases'][$project['recommended']];
      $recommended_version = '{{ release_version }} (<a href="{{ release_link }}" title="{{ project_title }}">{{ release_notes }}</a>)';
      $recommended_version_parser = ModuleVersion::createFromVersionString($recommended_release['version']);
      if ($recommended_version_parser->getMajorVersion() != $project['existing_major']) {
        $recommended_version .= '<div title="{{ major_update_warning_title }}" class="update-major-version-warning">{{ major_update_warning_text }}</div>';
      }

      $recommended_version = [
        '#type' => 'inline_template',
        '#template' => $recommended_version,
        '#context' => [
          'release_version' => $recommended_release['version'],
          'release_link' => $recommended_release['release_link'],
          'project_title' => $this->t('Release notes for @project_title', ['@project_title' => $project['title']]),
          'major_update_warning_title' => $this->t('Major upgrade warning'),
          'major_update_warning_text' => $this->t('This update is a major version update which means that it may not be backwards compatible with your currently running version. It is recommended that you read the release notes and proceed at your own risk.'),
          'release_notes' => $this->t('Release notes'),
        ],
      ];

      // Create an entry for this project.
      $entry = [
        'title' => $project_name,
        'installed_version' => $project['existing_version'],
        'recommended_version' => ['data' => $recommended_version],
      ];

      switch ($project['status']) {
        case UPDATE_NOT_SECURE:
        case UPDATE_REVOKED:
          $entry['title'] .= ' ' . $this->t('(Security update)');
          $entry['#weight'] = -2;
          $type = 'security';
          break;

        case UPDATE_NOT_SUPPORTED:
          $type = 'unsupported';
          $entry['title'] .= ' ' . $this->t('(Unsupported)');
          $entry['#weight'] = -1;
          break;

        case UPDATE_UNKNOWN:
        case UPDATE_NOT_FETCHED:
        case UPDATE_NOT_CHECKED:
        case UPDATE_NOT_CURRENT:
          $type = 'recommended';
          break;

        default:
          // Jump out of the switch and onto the next project in foreach.
          continue 2;
      }

      // Use the project title for the tableselect checkboxes.
      $entry['title'] = [
        'data' => [
          '#title' => $entry['title'],
          '#markup' => $entry['title'],
        ],
      ];
      $entry['#attributes'] = ['class' => ['update-' . $type]];

      // Drupal core needs to be upgraded manually.
      $needs_manual = $project['project_type'] == 'core';

      // If the recommended release for a contributed project is not compatible
      // with the currently installed version of core, list that project in a
      // separate table. To determine if the release is compatible, we inspect
      // the 'core_compatible' key from the release info array. If it's not
      // defined, it means we can't determine compatibility requirements (or
      // we're looking at core), so we assume it is compatible.
      $compatible = $recommended_release['core_compatible'] ?? TRUE;

      if ($needs_manual) {
        $this->removeCheckboxFromRow($entry);
        $projects['manual'][$name] = $entry;
      }
      elseif (!$compatible) {
        $this->removeCheckboxFromRow($entry);
        // If the release has a core_compatibility_message, inject it.
        if (!empty($recommended_release['core_compatibility_message'])) {
          // @todo In https://www.drupal.org/project/drupal/issues/3121769
          //   refactor this into something theme-friendly so we don't have a
          //   classless <div> here.
          $entry['data']['recommended_version']['data']['#template'] .= ' <div>{{ core_compatibility_message }}</div>';
          $entry['data']['recommended_version']['data']['#context']['core_compatibility_message'] = $recommended_release['core_compatibility_message'];
        }
        $projects['not-compatible'][$name] = $entry;
      }
      else {
        $form['project_downloads'][$name] = [
          '#type' => 'value',
          '#value' => $recommended_release['download_link'],
        ];

        // Based on what kind of project this is, save the entry into the
        // appropriate subarray.
        switch ($project['project_type']) {
          case 'module':
          case 'theme':
            $projects['enabled'][$name] = $entry;
            break;

          case 'module-disabled':
          case 'theme-disabled':
            $projects['disabled'][$name] = $entry;
            break;
        }
      }
    }

    if (empty($projects)) {
      $form['message'] = [
        '#markup' => $this->t('All of your projects are up to date.'),
      ];
      return $form;
    }

    $headers = [
      'title' => [
        'data' => $this->t('Name'),
        'class' => ['update-project-name'],
      ],
      'installed_version' => $this->t('Installed version'),
      'recommended_version' => $this->t('Recommended version'),
    ];

    if (!empty($projects['enabled'])) {
      $form['projects'] = [
        '#type' => 'tableselect',
        '#header' => $headers,
        '#options' => $projects['enabled'],
      ];
      if (!empty($projects['disabled'])) {
        $form['projects']['#prefix'] = '<h2>' . $this->t('Enabled') . '</h2>';
      }
    }

    if (!empty($projects['disabled'])) {
      $form['disabled_projects'] = [
        '#type' => 'tableselect',
        '#header' => $headers,
        '#options' => $projects['disabled'],
        '#weight' => 1,
        '#prefix' => '<h2>' . $this->t('Disabled') . '</h2>',
      ];
    }

    // If either table has been printed yet, we need a submit button and to
    // validate the checkboxes.
    if (!empty($projects['enabled']) || !empty($projects['disabled'])) {
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Download these updates'),
      ];
    }

    if (!empty($projects['manual'])) {
      $prefix = '<h2>' . $this->t('Manual updates required') . '</h2>';
      $prefix .= '<p>' . $this->t('Automatic updates of Drupal core are not supported at this time.') . '</p>';
      $form['manual_updates'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $projects['manual'],
        '#prefix' => $prefix,
        '#weight' => 120,
      ];
    }

    if (!empty($projects['not-compatible'])) {
      $form['not_compatible'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $projects['not-compatible'],
        '#prefix' => '<h2>' . $this->t('Not compatible') . '</h2>',
        '#weight' => 150,
      ];
    }

    return $form;
  }

  /**
   * Prepares a row entry for use in a regular table, not a 'tableselect'.
   *
   * There are no checkboxes in the 'Manual updates' or 'Not compatible' tables,
   * so they will be rendered by '#theme' => 'table', not 'tableselect'. Since
   * the data formats are incompatible, this method converts to the format
   * expected by '#theme' => 'table'. Generally, rows end up in the main tables
   * that have a checkbox to allow the site admin to select which missing
   * updates to install. This method is only used for the special case tables
   * that have no such checkbox.
   *
   * @todo In https://www.drupal.org/project/drupal/issues/3121775 refactor
   *   self::buildForm() so that we don't need this method at all.
   *
   * @param array[] $row
   *   The render array for a table row.
   */
  protected function removeCheckboxFromRow(array &$row) {
    unset($row['#weight']);
    $attributes = $row['#attributes'];
    unset($row['#attributes']);
    $row = [
      'data' => $row,
    ] + $attributes;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->isValueEmpty('projects')) {
      $enabled = array_filter($form_state->getValue('projects'));
    }
    if (!$form_state->isValueEmpty('disabled_projects')) {
      $disabled = array_filter($form_state->getValue('disabled_projects'));
    }
    if (empty($enabled) && empty($disabled)) {
      $form_state->setErrorByName('projects', $this->t('You must select at least one project to update.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->moduleHandler->loadInclude('update', 'inc', 'update.manager');
    $projects = [];
    foreach (['projects', 'disabled_projects'] as $type) {
      if (!$form_state->isValueEmpty($type)) {
        $projects = array_merge($projects, array_keys(array_filter($form_state->getValue($type))));
      }
    }
    $operations = [];
    foreach ($projects as $project) {
      $operations[] = [
        'update_manager_batch_project_get',
        [
          $project,
          $form_state->getValue(['project_downloads', $project]),
        ],
      ];
    }
    $batch = [
      'title' => $this->t('Downloading updates'),
      'init_message' => $this->t('Preparing to download selected updates'),
      'operations' => $operations,
      'finished' => 'update_manager_download_batch_finished',
      'file' => drupal_get_path('module', 'update') . '/update.manager.inc',
    ];
    batch_set($batch);
  }

}
