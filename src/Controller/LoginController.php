<?php

namespace Drupal\shibboleth\Controller;

use Drupal;
use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Controller\ControllerBase;
// use Drupal\Core\Logger\LoggerChannelFactory;
// use Drupal\Core\Logger\LoggerChannelFactoryInterface;
// use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\shibboleth\Form\AccountMapRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for Shibboleth routes.
 */
class LoginController extends ControllerBase {

  /**
   * Form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  // private $current_user;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  private $login_failed;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibAuth;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager
   */
  private $shibDrupalAuth;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * LoginController constructor.
   *
   * @param ShibbolethAuthManager                               $shib_auth
   * @param ShibbolethDrupalAuthManager                         $shib_drupal_auth
   * @param \Drupal\Core\Form\FormBuilderInterface              $form_builder
   */
  public function __construct(ShibbolethAuthManager $shib_auth, ShibbolethDrupalAuthManager $shib_drupal_auth, RequestStack $request_stack, FormBuilderInterface $form_builder/*, AccountInterface $current_user*/) {
    // might not need
    // $this->shibSession = $shib_session;
    $this->shibAuth = $shib_auth;
    $this->shibDrupalAuth = $shib_drupal_auth;
    $this->requestStack = $request_stack;
    $this->formBuilder = $form_builder;
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
      $container->get('request_stack'),
      $container->get('form_builder'),
      // $container->get('current_user'),
    );
  }

  /**
   * Logs into Drupal with the current Shibboleth user.
   *
   * The Shibboleth session must already exist. This will attempt to log in a
   * Drupal user that has been mapped to the Shibboleth user ID.
   *
   * @return array|RedirectResponse
   */
  public function login() {

    $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? $this->requestStack->getCurrentRequest()->getBasePath();

    // The user is logged into Shibboleth.
    if ($this->shibAuth->sessionExists()) {

      $authname = $this->shibAuth->getTargetedId();
      $id_label = $this->config('shibboleth.settings')->get('shibboleth_id_label');

      // The user is not logged into Drupal.
      if ($this->currentUser()->isAnonymous()) {

        // Attempt to log into Drupal with the Shibboleth ID.
        /** @var \Drupal\user\Entity\User|false $account */
        $account = $this->shibDrupalAuth->loginRegister($authname);

        // Login successful.
        if ($account) {
          return new RedirectResponse($destination);
        }

        // Login failed. Return access denied.
        $this->messenger()->addError($this->t('Login failed via Shibboleth. We were unable to find or create a user linked to the @id_label <strong>%authname</strong>. Please contact the site administrator to request access.', ['@id_label' => $id_label, '%authname' => $authname]));
        return $this->loginError();

      }
      else {

        $current_user_authname = $this->shibDrupalAuth->getShibbolethUsername($this->currentUser()->id());

        // Check if Shibboleth user matches Drupal user.
        if ($current_user_authname == $authname || $this->currentUser()->hasPermission('bypass shibboleth login')) {

          $this->messenger()->addStatus($this->t('You are already logged in.'));
          return new RedirectResponse($destination);

        }
        elseif (!$this->currentUser()->hasPermission('bypass shibboleth login')) {

          // The Shibboleth and Drupal user don't match and the Drupal user
          // doesn't have permission to bypass Shibboleth login.
          $this->messenger()->addError($this->t('You have been logged out of this site because the @id_label <strong>%authname</strong> did not match the Drupal user and the Drupal user did not have permission to bypass Shibboleth login. You can try to log in again. Please contact the site administrator for more information.', ['@id_label' => $id_label, '%authname' => $authname]));
          return $this->loginError();

        }
      }
    }

    // No Shibboleth session exists, so redirect to the Shibboleth login URL.
    return new RedirectResponse($this->shibAuth->getLoginUrl()->toString());

  }

  /**
   * Displays the Shibboleth login block in the page content.
   *
   * Similar to the standard Drupal login page, but without the username and
   * password fields. The block displays only to anonymous users. Include
   * Shibboleth username and link to destroy that session if one exists.
   */
  // public function loginLanding() {
  //   $block_manager = \Drupal::service('plugin.manager.block');
  //   // You can hard code configuration or you load from settings.
  //   $config = [];
  //   $plugin_block = $block_manager->createInstance('shibboleth_login_block', $config);
  //   // Some blocks might implement access check.
  //   $access_result = $plugin_block->access(\Drupal::currentUser());
  //   // Return empty render array if user doesn't have access.
  //   // $access_result can be boolean or an AccessResult class
  //   if (is_object($access_result) && $access_result->isForbidden() || is_bool($access_result) && !$access_result) {
  //     // You might need to add some cache tags/contexts.
  //     return [];
  //   }
  //   $render = $plugin_block->build();
  //   // Add the cache tags/contexts.
  //   \Drupal::service('renderer')->addCacheableDependency($render, $plugin_block);
  //   return $render;
  //     // return $build;
  // }

  /**
   * Provides content for the Login error page.
   *
   * @return array
   *   Returns a render array.
   */
  protected function loginError() {

    return [
      '#title' => t('Access denied'),
      '#markup' => t('Login failed.')
    ];

  }

}
