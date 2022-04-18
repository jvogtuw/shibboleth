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
class ShibbolethAuthController extends ControllerBase {

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
   * ShibbolethAuthController constructor.
   *
   * @param ShibbolethAuthManager                         $shib_auth
   * @param ShibbolethDrupalAuthManager                   $shib_drupal_auth
   * @param \Drupal\Core\Session\AccountInterface         $current_user
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
   * Logs into Drupal with the current Shibboleth user.
   *
   * The Shibboleth session must already exist. This will attempt to log in a
   * Drupal user that has been mapped to the Shibboleth user ID.
   *
   * @return RedirectResponse|array
   */
  public function login() {
    // if ($this->shib_auth->sessionExists()) {
    //
    // }

    // if (!empty($this->shib_drupal_auth->getShibSession()->getSessionId())) {
    if ($this->shib_auth->sessionExists()) {
      // Check if there is an active Drupal login.
      if (empty($this->currentUser) || $this->currentUser->isAnonymous()) {

        // if (\Drupal::currentUser()->isAnonymous()) {
        // Call the shib login function in the login handler class.
        $response = $this->shib_drupal_auth->login();
        // if ($response = $this->loginHandler->shibLogin()) {
        if ($response) {
          if (/*gettype($response) == 'int' &&*/ (int) $response == 11000) {
            $warning_msg = t('Login unsuccessful. See below for options to fix the issue.');
            $this->messenger()->addWarning($warning_msg);
            return $this->form_builder->getForm(AccountMapRequest::class);
          }
          else {
            // We need to remove the destination or it will redirect to that
            // rather than where we actually want to go. We want it to go through
            // the Drupal login...then it can redirect to the destination.
            Drupal::request()->query->remove('destination');
            return $response;
          }
        }
      }
      // A Shibboleth session exists and the user is logged into Drupal
      else {
        // If Shib user matches Drupal user, continue and show the 'already
        // logged in' status message.
        // If the users don't match, show a message with a link to log out of
        // Drupal and try again. Log out of Drupal only, then redirect to the
        // loginLanding page.

      }
    }
    else {
      // Redirect to the full Shib login URL.
    }

    // Will redirect to ?destination by default.
    return $this->redirect('<front>');

  }

  /**
   * Displays the Shibboleth login block in the page content.
   *
   * Similar to the standard Drupal login page, but without the username and
   * password fields. The block displays only to anonymous users. Include
   * Shibboleth username and link to destroy that session if one exists.
   */
  public function loginLanding() {
    // @todo Embed the Shibboleth login block.
    // $shibboleth_block = BlockContent::load('shibboleth_login_block');
    // $render = Drupal::entityTypeManager()->
    // getViewBuilder('block_content')->view($shibboleth_block);
    // // return [$render];
    // // return [['#title'] => 'hi'];
    //   $build['shibboleth_login_block'] = [
    //     '#type' => 'item',
    //     '#markup' => $render,
    //   ];
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

  /**
   * Logs a Shibboleth user out of Drupal, optionally destroying the Shibboleth
   * session as well
   */
  public function logout() {

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

  /**
   * Destroy the Shibboleth session and route to current destination.
   *
   * This should only be accessible by anonymous users. Otherwise it could
   * result in a conflict between the Shibboleth and Drupal user session.
   */
  public function shibDestroy() {

  }

}
