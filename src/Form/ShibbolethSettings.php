<?php

namespace Drupal\shibboleth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Shibboleth settings for this site.
 */
class ShibbolethSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'shibboleth_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['shibboleth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('shibboleth.settings');
    $form['shibboleth_handlers'] = [
      '#type' => 'details',
      '#title' => $this->t('Shibboleth handler settings'),
      '#open' => 'open',
    ];
    $form['shibboleth_handlers']['shibboleth_login_handler_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shibboleth login handler URL'),
      '#description' => $this->t('The URL can be absolute or relative to the server base url: http://www.example.com/Shibboleth.sso/DS; /Shibboleth.sso/DS. As with any config, this setting can be overridden in settings.php. This can be useful when cloning sites to different environments.'),
      '#required' => TRUE,
      '#default_value' => $config->get('shibboleth_login_handler_url'),
    ];
    $form['shibboleth_handlers']['shibboleth_logout_handler_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shibboleth logout handler URL'),
      '#description' => $this->t('The URL can be absolute or relative to the server base url: http://www.example.com/Shibboleth.sso/Logout; /Shibboleth.sso/Logout. As with any config, this setting can be overridden in settings.php. This can be useful when cloning sites to different environments.'),
      '#default_value' => $config->get('shibboleth_logout_handler_url'),
    ];

    $form['attributes'] = [
      '#type' => 'details',
      '#title' => $this->t('Attribute settings'),
      '#open' => 'open',
    ];
    $form['attributes']['server_variable_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server variable for username'),
      '#required' => TRUE,
      '#default_value' => $config->get('server_variable_username'),
    ];
    $form['attributes']['server_variable_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server variable for email'),
      '#required' => TRUE,
      '#default_value' => $config->get('server_variable_email'),
    ];
    $form['attributes']['server_variable_affiliation'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server variable for affiliations'),
      '#description' => $this->t('Use an unscoped variable to get just the affiliation names.'),
      '#default_value' => $config->get('server_variable_affiliation') ?? 'unscoped-affiliation',
    ];
    $form['attributes']['server_variable_groups'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server variable for groups'),
      '#description' => $this->t('Commonly: IsMemberOf'),
      '#default_value' => $config->get('server_variable_groups'),
    ];

    $form['session_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Session behavior settings'),
    ];
    $form['session_settings']['force_https_on_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force HTTPS on login'),
      '#description' => $this->t('The user will be redirected to HTTPS'),
      '#default_value' => $config->get('force_https_on_login'),
    ];
    $form['session_settings']['auto_register_user'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically register new users on login'),
      '#description' => $this->t('Upon attempting to log in, if no linked user is found, attempt to create a new Drupal user. This is only triggered when a user targets the Shibboleth login route, not for accessing other protected paths. It overrides the Drupal setting for who can register users.'),
      '#default_value' => $config->get('auto_register_user'),
    ];
    $form['session_settings']['destroy_session_on_logout'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Destroy Shibboleth session on Drupal logout'),
      '#description' => $this->t('The user will be redirected to HTTPS'),
      '#default_value' => $config->get('destroy_session_on_logout'),
    ];
    $form['session_settings']['login_redirect'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL to redirect to after login'),
      '#description' => $this->t('The URL can be absolute or relative to the server base url. The relative paths will be automatically extended with the site base URL. If this value is empty, then the user will be redirected to the originally requested page.'),
      '#default_value' => $config->get('login_redirect'),
    ];
    $form['session_settings']['logout_redirect'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL to redirect to after logout'),
      '#description' => $this->t('The URL can be absolute or relative to the server base url. The relative paths will be automatically extended with the site base URL. If you are using SLO, this setting is probably useless (depending on the IdP).'),
      '#default_value' => $config->get('logout_redirect'),
    ];
    // $form['session_settings']['logout_error_message'] = [
    //   '#type' => 'textarea',
    //   '#title' => $this->t('Error Page Message'),
    //   '#default_value' => $config->get('logout_error_message'),
    //   '#description' => $this->t('Error message displayed to the user (if an error occurs).'),
    // ];

    $form['display_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Display settings'),
    ];
    $form['display_settings']['login_link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shibboleth login link text'),
      '#description' => $this->t('The text of the login link.'),
      '#maxlength' => 128,
      '#size' => 64,
      '#default_value' => $config->get('login_link_text'),
    ];
    $form['display_settings']['logout_link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shibboleth logout link text'),
      '#description' => $this->t('The text of the logout link.'),
      '#maxlength' => 128,
      '#size' => 64,
      '#default_value' => $config->get('logout_link_text'),
    ];
    $form['display_settings']['shibboleth_id_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shibboleth ID term'),
      '#description' => $this->t('Your organization\'s term for the Shibboleth username. For instance, \'NetID\'. This has no technical impact; it\'s simply for displaying to users.'),
      '#default_value' => $config->get('shibboleth_id_label'),
    ];

    $form['path_rule_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Path access rule settings'),
      '#description' => $this->t('These settings will override any path access rules that apply to them.'),
    ];
    $form['path_rule_settings']['excluded_routes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exclude the following routes from Shibboleth protection'),
      '#description' => $this->t('These settings will override any path access rules that apply to them. They will not override Drupal permissions.<br>* Excluding the <strong>core user login</strong> route is recommended if enabling the "Whole site" path rule.'),
      '#options' => [
        'user-login' => t('Core user login (recommended*)'),
        'user-password' => t('Core user password reset'),
        'user-register' => t('Core user registration'),
      ],
      '#default_value' => $config->get('excluded_routes') ? $config->get('excluded_routes') : ['user-login'],
    ];

    $form['debug_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Debugging settings'),
    ];
    $form['debug_settings']['enable_debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable DEBUG mode.'),
      '#default_value' => $config->get('enable_debug_mode'),
    ];
    $form['debug_settings']['debug_path_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('DEBUG path prefix'),
      '#description' => $this->t("For example, use \"/user/\" to display DEBUG messages on paths like \"/user/*\"."),
      '#default_value' => $config->get('debug_path_prefix'),
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
    $this->config('shibboleth.settings')
      ->set('shibboleth_login_handler_url', $form_state->getValue('shibboleth_login_handler_url'))
      ->set('shibboleth_logout_handler_url', $form_state->getValue('shibboleth_logout_handler_url'))
      ->set('server_variable_username', $form_state->getValue('server_variable_username'))
      ->set('server_variable_email', $form_state->getValue('server_variable_email'))
      ->set('server_variable_affiliation', $form_state->getValue('server_variable_affiliation'))
      ->set('server_variable_groups', $form_state->getValue('server_variable_groups'))
      ->set('force_https_on_login', $form_state->getValue('force_https_on_login'))
      ->set('auto_register_user', $form_state->getValue('auto_register_user'))
      ->set('destroy_session_on_logout', $form_state->getValue('destroy_session_on_logout'))
      ->set('login_redirect', $form_state->getValue('login_redirect'))
      ->set('logout_redirect', $form_state->getValue('logout_redirect'))
      ->set('login_link_text', $form_state->getValue('login_link_text'))
      ->set('logout_link_text', $form_state->getValue('logout_link_text'))
      ->set('shibboleth_id_label', $form_state->getValue('shibboleth_id_label'))
      ->set('excluded_routes', $form_state->getValue('excluded_routes'))
      ->set('enable_debug_mode', $form_state->getValue('enable_debug_mode'))
      ->set('debug_path_prefix', $form_state->getValue('debug_path_prefix'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
