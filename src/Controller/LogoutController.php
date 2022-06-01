<?php

namespace Drupal\shibboleth\Controller;

use Drupal;
use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Controller\ControllerBase;
// use Drupal\Core\Logger\LoggerChannelFactory;
// use Drupal\Core\Logger\LoggerChannelFactoryInterface;
// use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\shibboleth\Form\AccountMapRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for Shibboleth routes.
 */
class LogoutController extends ControllerBase {

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibAuth;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager
   */
  // private $shib_drupal_auth;

  /**
   * Form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  // protected $form_builder;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  // private $current_user;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  private Drupal\Core\Config\Config $config;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private RequestStack $requestStack;

  /**
   * LoginController constructor.
   *
   * @param ShibbolethAuthManager                  $shib_auth
   * @param ShibbolethDrupalAuthManager            $shib_drupal_auth
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   */
  public function __construct(ShibbolethAuthManager $shib_auth, RequestStack $request_stack/*, ShibbolethDrupalAuthManager $shib_drupal_auth, FormBuilderInterface $form_builder*//*, AccountInterface $current_user*/) {
    $this->shibAuth = $shib_auth;
    // $this->shib_drupal_auth = $shib_drupal_auth;
    // $this->form_builder = $form_builder;
    // $this->current_user = $current_user;
    $this->logger = $this->getLogger('shibboleth');
    // $this->messenger();
    $this->config = $this->config('shibboleth.settings');
    $this->requestStack = $request_stack;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('shibboleth.auth_manager'),
      $container->get('request_stack'),
      // $container->get('shibboleth.drupal_auth_manager'),
      // $container->get('form_builder'),
      // $container->get('current_user'),
    );
  }

  /**
   * Logs a Shibboleth user out of Drupal, optionally destroying the Shibboleth
   * session as well.
   */
  public function logout() {

    // A Shibboleth session may still exist if a user went straight to the
    // logout route instead of using the logout link which includes the
    // Shibboleth logout handler.
    // if ($this->shibAuth->sessionExists()) {
    //   // $this->logger->notice('shib session exists.');
    //   // $this->messenger()->addStatus($this->t('shib session exists.'));
    //   $shibboleth_logout_url = $this->shibAuth->getLogoutUrl()->toString();
    //   // $shibboleth_logout_url = 'https://facweb13.s.uw.edu/Shibboleth.sso/Logout?return=/migrations-d9/2013-new-york-yankees';
    //   // $shibboleth_logout_url = 'https://facweb13.s.uw.edu/Shibboleth.sso/Logout';
    //
    //   return new TrustedRedirectResponse($shibboleth_logout_url);
    // }
    // else {
    //
    //   $this->logger->notice('no shib session');
    //   $this->messenger()->addStatus($this->t('no shib session'));
    // }

    $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? $this->requestStack->getCurrentRequest()->getBasePath();
    if ($this->currentUser()->isAuthenticated()) {

      // var_dump('hi1');
      $this->logger->notice('logging out from Drupal');
      $this->messenger()->addStatus($this->t('User logout.'));
      user_logout();

      if ($this->shibAuth->sessionExists()) {
        $logout_url = $this->shibAuth->getLogoutHandlerUrl();
        $id_label = $this->config->get('shibboleth_id_label');
        $authname = $this->shibAuth->getTargetedId();
        return [
          '#markup' => t('<p>Success! You were logged out of this site.</p><p>If you wish, you can also end your @id_label session entirely. Ending the session will result in a sign in prompt if you try to log in again. This is useful if you want to log in with a different @id_label.</p><p><a href="@logout_url">End the @id_label session for %authname</a></p>',
            [
              '@id_label' => $id_label,
              '@logout_url' => $logout_url,
              '%authname' => $authname,
            ]),
        ];
      //   $this->logger->notice('shib session exists.');
      //   $this->messenger()->addStatus($this->t('shib session exists.'));
      //   $shibboleth_logout_url = $this->shibAuth->getLogoutUrl()->toString();
      //   $shibboleth_logout_url = 'https://facweb13.s.uw.edu/Shibboleth.sso/Logout?return=https://facweb13.s.uw.edu/migrations-d9/2013-new-york-yankees';
      //   // var_dump($shibboleth_logout_url);
      //   // $shibboleth_logout_url = 'https://facweb13.s.uw.edu/Shibboleth.sso/Logout';
      //   return new TrustedRedirectResponse($shibboleth_logout_url);
      //   return new RedirectResponse($this->requestStack->getCurrentRequest()->getRequestUri());
      }


    }


    $this->messenger()->addStatus($this->t('log out successful'), TRUE);
    return new RedirectResponse($destination);
    // if ($this->shibAuth->sessionExists()) {
    //   $this->logger->notice('logging out from shib');
    //   // $temp_url = 'https://facweb13.s.uw.edu/Shibboleth.sso/Logout?return=https://facweb13.s.uw.edu/migrations-d9/shibboleth/logout?destination=/migrations-d9/2013-new-york-yankees';
    //   // $temp_url = $this->shibAuth->getLogoutUrl()->toString();
    //   // return new TrustedRedirectResponse($temp_url);
    //   // // dpm($this->shibAuth->getLogoutUrl(), 'logout url');
    //   // return [];
    //   // $this->messenger()->addStatus($this->t('Yes Shib session.'));
    //   // return [
    //   //   '#markup' => 'wtf'
    //   // ];
    //   // var_dump($this->shibAuth->getLogoutUrl()->toString());
    //   // A Shibboleth session exists, so redirect to the Shibboleth logout URL.
    //   $shibboleth_logout_url = $this->shibAuth->getLogoutUrl()->toString();
    //   return new TrustedRedirectResponse($shibboleth_logout_url);
    // }
    // else {
    //   $this->messenger()->addStatus($this->t('No Shib session.'));
    // }


    // return new RedirectResponse($destination);
    // The user is logged out of Shibboleth. Continue redirecting to the
    // destination.
    // if (!$this->shibAuth->sessionExists()) {
    //   $this->messenger()->addStatus($this->t('No Shib session.'));
    //   // $authname = $this->shibAuth->getTargetedId();
    //   // $id_label = $this->config('shibboleth.settings')->get('shibboleth_id_label');
    //
    //   // The user is logged into Drupal.
    //   // if ($this->currentUser()->isAnonymous()) {
    //   //
    //   //   // // Attempt to log into Drupal with the Shibboleth ID.
    //   //   // /** @var \Drupal\user\Entity\User|false $account */
    //   //   // $account = $this->shibDrupalAuth->loginRegister();
    //   //
    //   //   user_logout();
    //   //
    //   //   // Login successful.
    //   //   // if ($account) {
    //   //   //   return new RedirectResponse($destination);
    //   //   // }
    //   //
    //   //   // Login failed. Return access denied.
    //   //   // $this->messenger()->addError($this->t('Login failed via Shibboleth. We were unable to find or create a user linked to the @id_label <strong>%authname</strong>. Please contact the site administrator to request access.', ['@id_label' => $id_label, '%authname' => $authname]));
    //   //   // return $this->loginError();
    //   //
    //   // }
    //   // else {
    //   //
    //   //   $current_user_authname = $this->shibDrupalAuth->getShibbolethUsername($this->currentUser()->id());
    //   //
    //   //   // Check if Shibboleth user matches Drupal user.
    //   //   if ($current_user_authname == $authname || $this->currentUser()->hasPermission('bypass shibboleth login')) {
    //   //
    //   //     $this->messenger()->addStatus($this->t('You are already logged in.'));
    //   //     return new RedirectResponse($destination);
    //   //
    //   //   }
    //   //   elseif (!$this->currentUser()->hasPermission('bypass shibboleth login')) {
    //   //
    //   //     // The Shibboleth and Drupal user don't match and the Drupal user
    //   //     // doesn't have permission to bypass Shibboleth login.
    //   //     $this->messenger()->addError($this->t('You have been logged out of this site because the @id_label <strong>%authname</strong> did not match the Drupal user and the Drupal user did not have permission to bypass Shibboleth login. You can try to log in again. Please contact the site administrator for more information.', ['@id_label' => $id_label, '%authname' => $authname]));
    //   //     return $this->loginError();
    //   //
    //   //   }
    //   // }
    //
    //   return new RedirectResponse($destination);
    // }

    // $this->messenger()->addStatus($this->t('Yes Shib session.'));
    // // A Shibboleth session exists, so redirect to the Shibboleth logout URL.
    // return new TrustedRedirectResponse($this->shibAuth->getLogoutUrl()->toString());

    // // return [
    // //   '#markup' => $this->shibAuth->getLogoutUrl()->toString(),
    // // ];
    // user_logout();
    // if ($this->config->get('destroy_session_on_logout')) {
    // //   return [
    // //   '#markup' => $this->shibAuth->getLogoutUrl()->toString(),
    // // ];
    //   $logout_url = $this->shibAuth->getLogoutUrl();
    //   $return = $logout_url->
    //   // $return_url = $this->requestStack->getCurrentRequest()->query->get('return');
    //   // $this->requestStack->getCurrentRequest()->query->remove('return');
    //   return new TrustedRedirectResponse($this->shibAuth->getLogoutUrl()->toString());
    // }
    // return new RedirectResponse($this->shibAuth->getLogoutUrl(FALSE)->toString());
  }


  /**
   * Destroy the Shibboleth session and route to current destination.
   *
   * This should only be accessible by anonymous users. Otherwise, it could
   * result in a conflict between the Shibboleth and Drupal user session.
   */
  public function shibDestroy() {

  }

}
