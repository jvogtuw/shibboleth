<?php

namespace Drupal\shibboleth\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
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
  protected $shib_auth_manager;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  private $current_user;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ShibbolethAuthManager $shib_auth_manager, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->shib_auth_manager = $shib_auth_manager;
    $this->current_user = $current_user;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('shibboleth.auth_manager'),
      $container->get('current_user'),
      // $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = \Drupal::config('shibboleth.settings');

    $markup = '<div class="shibboleth-block">';
    if ($this->current_user->isAnonymous()) {
      $markup .= '<div class="shibboleth-login">' . $this->shib_auth_manager->getLoginLink() . '</div>';
    }
    else {
      $markup .= '<div class="shibboleth-logout">' . $this->shib_auth_manager->getLogoutLink() . '</div>';
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

    if (!$config->get('url_redirect_login')) {
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
