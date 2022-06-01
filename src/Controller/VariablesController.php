<?php

namespace Drupal\shibboleth\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns a list of server variables and values.
 */
class VariablesController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build() {

    $build['intro'] = [
      '#markup' => $this->t('<p>Below are the variables from $_SERVER including the active Shibboleth session if one exists.</p>'),
    ];

    $build['server_table'] = [
      '#type' => 'table',
      '#header' => [ $this->t('Variable'), $this->t('Value') ],
    ];

    foreach ($_SERVER as $id => $var) {
      $build['server_table'][$id]['variable'] = [
        '#plain_text' => $id,
      ];
      $build['server_table'][$id]['value'] = [
        '#plain_text' => is_array($var) ? implode('; ', $var) : $var,
      ];
    }

    return $build;
  }

}
