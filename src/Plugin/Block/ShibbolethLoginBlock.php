<?php

namespace Drupal\shibboleth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'ShibbolethLoginBlock' block.
 *
 * @Block(
 *   id = "shibboleth_login_block",
 *   admin_label = @Translation("Shibboleth login block"),
 *   category = @Translation("Shibboleth")
 * )
 */
class ShibbolethLoginBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  protected $shibbolethAuthManager;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  private $currentUser;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $shibbolethConfig;

  /**
   * Constructor for the ShibbolethLoginBlock.
   *
   * @param array $configuration
   *   The block plugin config.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shibboleth_auth_manager
   *   The Shibboleth authentication manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current Drupal user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ShibbolethAuthManager $shibboleth_auth_manager, AccountInterface $current_user, ConfigFactoryInterface $config_factory) {

    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->shibbolethAuthManager = $shibboleth_auth_manager;
    $this->currentUser = $current_user;
    $this->shibbolethConfig = $config_factory->get('shibboleth.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('shibboleth.auth_manager'),
      $container->get('current_user'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {

    $config = $this->configuration;
    $form = parent::blockForm($form, $form_state);

    $form['styles'] = [
      '#type' => 'details',
      '#title' => $this->t('Link styles'),
      '#open' => FALSE,
    ];
    $form['styles']['login_link_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CSS classes to add to the login link.'),
      '#default_value' => $config['login_link_classes'] ?? '',
      '#description' => $this->t('Classes are added to the login link\'s &lt;a&gt; tag. Separate multiple classes with a space.'),
    ];
    $form['styles']['logout_link_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CSS classes to add to the logout link.'),
      '#default_value' => $config['logout_link_classes'] ?? '',
      '#description' => $this->t('Classes are added to the logout link\'s &lt;a&gt; tag. Separate multiple classes with a space.'),
    ];
    return $form;
  }

  public function blockValidate($form, FormStateInterface $form_state) {

    $values = $form_state->getValues();
    if (!empty($values['styles']['login_link_classes'])) {
      $validated_classes = $this->validateClasses($values['styles']['login_link_classes']);
      if (!$validated_classes) {
        $form_state->setErrorByName('styles][login_link_classes', $this->t('Login link classes must be valid CSS classes.'));
      }
      else {
        $form_state->setValue(['styles', 'login_link_classes'], $validated_classes);
      }
    }
    if (!empty($values['styles']['logout_link_classes'])) {
      $validated_classes = $this->validateClasses($values['styles']['logout_link_classes']);
      if (!$validated_classes) {
        $form_state->setErrorByName('styles][logout_link_classes', $this->t('Logout link classes must be valid CSS classes.'));
      }
      else {
        $form_state->setValue(['styles', 'logout_link_classes'], $validated_classes);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['login_link_classes'] = $form_state->getValue(['styles', 'login_link_classes']);
    $this->configuration['logout_link_classes'] = $form_state->getValue([
      'styles', 'logout_link_classes']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $link = $this->currentUser->isAnonymous() ?
      $this->shibbolethAuthManager->getLoginUrl()->toString() :
      $this->shibbolethAuthManager->getLogoutUrl()->toString();
    $link_text = $this->currentUser->isAnonymous() ?
      $this->shibbolethConfig->get('login_link_text') :
      $this->shibbolethConfig->get('logout_link_text');
    $link_classes = $this->currentUser->isAnonymous() ?
      $this->configuration['login_link_classes'] :
      $this->configuration['logout_link_classes'];
    // if ($this->currentUser->isAnonymous()) {
    //   $link = $this->shibbolethAuthManager->getLoginUrl()->toString();
    //   $link_text = $this->shibbolethConfig->get('login_link_text');
    //   $link_classes = $this->configuration['login_link_classes'];
    // }
    // else {
    //   $link = $this->shibbolethAuthManager->getLogoutUrl()->toString();
    //   $link_text = $this->shibbolethConfig->get('logout_link_text');
    //   $link_classes = $this->configuration['logout_link_classes'];
    // }

    $markup = $this->t('<div class="shibboleth-block"><div class="shibboleth-link"><a href="@link" class="@classes">@link_text</a></div></div>', [
      '@link' => $link,
      '@link_text' => $link_text,
      '@classes' => $link_classes,
    ]);

    $build['shibboleth_login_block'] = [
      '#markup' => $markup,
      '#cache' => [
        'contexts' => [
          'user.roles:anonymous',
        ],
      ],
    ];

    if (!$this->shibbolethConfig->get('url_redirect_login')) {
      // Redirect is not set, so it will use the current path. That means it
      // will differ per page.
      $build['shibboleth_login_block']['#cache']['contexts'][] = 'url.path';
      $build['shibboleth_login_block']['#cache']['contexts'][] = 'url.query_args';
    }

    return $build;

  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), ['shibboleth_login_block']);
  }

  /**
   * Checks if a string contains all valid CSS classes.
   *
   * @param string $classes
   *   A string of CSS classes.
   *
   * @return false|string
   *   The original string, trimmed and with extra whitespace removed. FALSE if
   *   any classes are not properly formatted.
   */
  private function validateClasses(string $classes) {
    $class_array = explode(' ', trim($classes));
    $valid_classes = [];
    // Allows alphanumeric characters, hyphens and underscores. Cannot start
    // with a digit or a hyphen followed by a digit.
    $css_class_pattern = '/^(?:-?(?!\d)[a-zA-Z_]|[a-zA-Z_])[a-zA-Z0-9_-]*$/';
    foreach ($class_array as $class) {
      // Skip empty classes to remove accidental extra whitespace in the string.
      if (empty($class)) {
        continue;
      }
      // Return FALSE if any non-empty class doesn't match the format.
      if (!preg_match($css_class_pattern, $class)) {
        return FALSE;
      }
      $valid_classes[] = $class;
    }
    return implode(' ', $valid_classes);
  }
}
