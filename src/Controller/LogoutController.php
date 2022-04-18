<?php

namespace Drupal\shibboleth\Controller;

use Drupal;
use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Controller\ControllerBase;
// use Drupal\Core\Logger\LoggerChannelFactory;
// use Drupal\Core\Logger\LoggerChannelFactoryInterface;
// use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\shibboleth\Form\AccountMapRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Returns responses for Shibboleth routes.
 */
class LogoutController extends ControllerBase {

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shib_auth;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager
   */
  private $shib_drupal_auth;

  /**
   * Form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $form_builder;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  // private $current_user;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * LoginController constructor.
   *
   * @param ShibbolethAuthManager                  $shib_auth
   * @param ShibbolethDrupalAuthManager            $shib_drupal_auth
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   */
  public function __construct(ShibbolethAuthManager $shib_auth, ShibbolethDrupalAuthManager $shib_drupal_auth, FormBuilderInterface $form_builder/*, AccountInterface $current_user*/) {
    $this->shib_auth = $shib_auth;
    $this->shib_drupal_auth = $shib_drupal_auth;
    $this->form_builder = $form_builder;
    // $this->current_user = $current_user;
    $this->logger = $this->getLogger('shibboleth');
    // $this->messenger();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('shibboleth.auth_manager'),
      $container->get('shibboleth.drupal_auth_manager'),
      $container->get('form_builder'),
      // $container->get('current_user'),
    );
  }

  /**
   * Logs a Shibboleth user out of Drupal, optionally destroying the Shibboleth
   * session as well
   */
  public function logout() {

  }


  /**
   * Destroy the Shibboleth session and route to current destination.
   *
   * This should only be accessible by anonymous users. Otherwise it could
   * result in a conflict between the Shibboleth and Drupal user session.
   */
  public function shibDestroy() {

  }

}
