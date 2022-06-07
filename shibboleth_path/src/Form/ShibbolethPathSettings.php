<?php

namespace Drupal\shibboleth_path\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Shibboleth settings for this site.
 */
class ShibbolethPathSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shibboleth_path_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['shibboleth_path.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('shibboleth_path.settings');

    $form['excluded_routes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exclude the following routes from Shibboleth protection'),
      '#description' => $this->t('These settings will override any path access rules that apply to them. They will not override Drupal permissions.<br>* Excluding these routes is recommended if enabling the "Whole site" path rule.'),
      '#options' => [
        'shibboleth-drupal_logout' => t('Shibboleth logout from Drupal [/shibboleth/logout] (recommended*)'),
        'user-login' => t('Core user login [/user/login] (recommended*)'),
        'user-password' => t('Core user password reset [/user/password]'),
        'user-register' => t('Core user registration [/user/register]'),
      ],
      '#default_value' => $config->get('excluded_routes') ? $config->get('excluded_routes') : ['user-login'],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('shibboleth_path.settings')
      ->set('excluded_routes', $form_state->getValue('excluded_routes'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
