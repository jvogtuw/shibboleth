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
use Drupal\shibboleth\Authentication\ShibbolethSession;
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

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethSession
   */
  // private $shibSession;

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
   * @param \Drupal\shibboleth\Authentication\ShibbolethSession $shib_session
   * @param ShibbolethAuthManager                               $shib_auth
   * @param ShibbolethDrupalAuthManager                         $shib_drupal_auth
   * @param \Drupal\Core\Form\FormBuilderInterface              $form_builder
   */
  public function __construct(/*ShibbolethSession $shib_session, */ShibbolethAuthManager $shib_auth, ShibbolethDrupalAuthManager $shib_drupal_auth, RequestStack $request_stack, FormBuilderInterface $form_builder/*, AccountInterface $current_user*/) {
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
      // $container->get('shibboleth.session'),
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
   * @return array|AccessResultForbidden|RedirectResponse
   */
  public function login() {
    // $destination = $this->requestStack->getCurrentRequest()->getQueryString();
    $destination = $this->requestStack->getCurrentRequest()->query->get('destination');
    $destination_url = Url::fromUserInput($destination);

    // The user is not logged into Drupal.
    if ($this->currentUser()->isAnonymous()) {

      // The user is logged into Shibboleth but not Drupal
      if ($this->shibAuth->sessionExists()) {

        $authname = $this->shibAuth->getTargetedId();
        // var_dump($authname);
        // There is no Drupal user synced to the Shibboleth user.
        // if (!$this->shibDrupalAuth->checkProvisionedUser($shib_username)) {
        //   return AccessResult::forbidden();
        // }

        // Attempt to log in a shibboleth + drupal user
        /** @var \Drupal\user\Entity\User|false $account */
        $account = $this->shibDrupalAuth->loginRegister($authname);

        // Login successful
        if ($account) {
          return new RedirectResponse($destination);
        }

        // Login failed. Return access denied.
        $id_label = $this->config('shibboleth.settings')->get('shibboleth_id_label');
        $this->messenger()->addError($this->t('Login failed via Shibboleth. We were unable to find or create a user linked to the @id_label %authname.', ['@id_label' => $id_label, '%authname' => $authname]));
        return $this->loginError();

        // The Shibboleth user isn't tied to any Drupal user currently, but
        // there's a potential match based on the username.
        // @todo Is this too much of a security risk?
        // if ($this->shibDrupalAuth->checkPotentialUserMatch()) {
        //
        // }
      }

    }




    // if ($this->shib_auth->sessionExists()) {
    //
    // }

    // If login was attempted and failed, stop here to prevent an infinite loop.
    // if ($this->login_failed) {
    //   return [
    //     '#markup' => 'Access denied'
    //   ];
    // }
    // if (!empty($this->shib_drupal_auth->getShibSession()->getSessionId())) {
    // $session = \Drupal::request()->getSession();
    // if ($this->shib_session->sessionExists()) {
    //   $session->remove('shibboleth.auth_attempted');
    //   // Check if there is an active Drupal login.
    //   if ($this->currentUser()->isAnonymous()) {
    //     // Call the shib login function in the login handler class.
    //     $response = $this->shib_drupal_auth->login();
    //     // if ($response = $this->loginHandler->shibLogin()) {
    //     if ($response) {
    //       if ($this->shib_drupal_auth->getPotentialUserMatch()) {
    //         return $this->form_builder->getForm(AccountMapRequest::class);
    //       }
    //       else {
    //         // We need to remove the destination or it will redirect to that
    //         // rather than where we actually want to go. We want it to go through
    //         // the Drupal login...then it can redirect to the destination.
    //         \Drupal::request()->query->remove('destination');
    //         return new RedirectResponse($response);
    //       }
    //     }
    //   }
    //   else {
        // A Shibboleth session exists and the user is logged into Drupal
        // If Shib user matches Drupal user, continue and show the 'already
        // logged in' status message.
        // If the users don't match, show a message with a link to log out of
        // Drupal and try again. Log out of Drupal only, then redirect to the
        // loginLanding page.

    //   }
    // }
    // else {
    //   // Check if we've already tried sending the user to Shibboleth login.
    //   // Avoids infinite loop.
    //   if (!$session->get('shibboleth.auth_attempted')) {
    //     $session->set('shibboleth.auth_attempted', 1);
    //     // Redirect to the full Shib login URL.
    //     $shib_login_url = $this->shib_drupal_auth->getLoginUrl();
    //     return RedirectResponse($shib_login_url);
    //   }
    //   else {
    //     // Redirect them to the loginLanding page...or front? Need error message.
    //   }
    // }

    // Will redirect to ?destination by default.
    // return $this->redirect('<front>');

  }

  // protected function loginError() {
  //   return
  // }
  public function loginAccess() {

  }
  /**
   * Displays the Shibboleth login block in the page content.
   *
   * Similar to the standard Drupal login page, but without the username and
   * password fields. The block displays only to anonymous users. Include
   * Shibboleth username and link to destroy that session if one exists.
   */
  public function loginLanding() {
    $block_manager = \Drupal::service('plugin.manager.block');
    // You can hard code configuration or you load from settings.
    $config = [];
    $plugin_block = $block_manager->createInstance('shibboleth_login_block', $config);
    // Some blocks might implement access check.
    $access_result = $plugin_block->access(\Drupal::currentUser());
    // Return empty render array if user doesn't have access.
    // $access_result can be boolean or an AccessResult class
    if (is_object($access_result) && $access_result->isForbidden() || is_bool($access_result) && !$access_result) {
      // You might need to add some cache tags/contexts.
      return [];
    }
    $render = $plugin_block->build();
    // Add the cache tags/contexts.
    \Drupal::service('renderer')->addCacheableDependency($render, $plugin_block);
    return $render;
      // return $build;
  }

  protected function loginError() {
    $page_title = t('Access denied');
    return [
      '#markup' => t('Login failed.')
    ];
    // if ($this->shib_session->sessionExists() && $this->shib_drupal_auth->getPotentialUserMatch()) {
    //   return $this->form_builder->getForm(AccountMapRequest::class);
    // }
    // else {
    //   return [
    //     '#type' => 'item',
    //     '#markup' => 'The site was unable to log you in using Shibboleth.',
    //   ];
    // }

  }

  /**
   * Make sure there's an active Shibboleth session and redirect to the
   * destination. Called from the route: shibboleth.shib_auth_only. That route
   * should be called from the PathAccessSubscriber. If this returns a successful
   * Shibboleth session, the PathAccessHandler (or something) can figure out if
   * the user has access.
   */
  public function shibAuthenticate() {

  }

}
