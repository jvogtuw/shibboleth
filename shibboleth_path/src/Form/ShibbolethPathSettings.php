<?php

namespace Drupal\shibboleth_path\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Shibboleth path rule settings.
 */
class ShibbolethPathSettings extends ConfigFormBase {

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $shibbolethCache;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CacheBackendInterface $shibboleth_cache) {
    parent::__construct($config_factory);
    $this->shibbolethCache = $shibboleth_cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // $instance = parent::create($container);

    return new static(
      $container->get('config.factory'),
      $container->get('cache.shibboleth')
    );
  }
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
    $form['enforcement'] = [
      '#type' => 'radios',
      '#title' => $this->t('Rule enforcement type'),
      '#description' => $this->t('The permissive option is less secure, but allows you to selectively open up parts of the site more broadly than the site as a whole.'),
      '#options' => [
        'strict' => t('Strict - all matching rules must pass'),
        'permissive' => t('Permissive - only the most granular rule must pass'),
      ],
      '#default_value' => $config->get('enforcement') ? $config->get('enforcement') : 'strict',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->config('shibboleth_path.settings')
      ->set('excluded_routes', $form_state->getValue('excluded_routes'))
      ->set('enforcement', $form_state->getValue('enforcement'))
      ->save();

    $this->shibbolethCache->deleteAll();
    \Drupal::messenger()->addStatus($this->t('Shibboleth paths cache cleared.'));

    parent::submitForm($form, $form_state);
  }

}
