<?php

namespace Drupal\shibboleth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
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
  public function build() {

    $markup = '<div class="shibboleth-block">';
    if ($this->currentUser->isAnonymous()) {
      $markup .= '<div class="shibboleth-link">' . $this->shibbolethAuthManager->getLoginLink() . '</div>';
    }
    else {
      $markup .= '<div class="shibboleth-link">' . $this->shibbolethAuthManager->getLogoutLink() . '</div>';
    }
    $markup .= '</div>';

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

}
