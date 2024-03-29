<?php

namespace Drupal\shibboleth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for the route to log in Drupal users via Shibboleth
 * credentials.
 */
class LoginController extends ControllerBase {

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibbolethAuthManager;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager
   */
  private $shibbolethDrupalAuthManager;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * LoginController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shib_auth
   *   The Shibboleth authentication manager.
   * @param \Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager $shib_drupal_auth
   *   The Shibboleth Drupal authentication manager.
   */
  public function __construct(RequestStack $request_stack, ShibbolethAuthManager $shib_auth, ShibbolethDrupalAuthManager $shib_drupal_auth) {

    $this->requestStack = $request_stack;
    $this->shibbolethAuthManager = $shib_auth;
    $this->shibbolethDrupalAuthManager = $shib_drupal_auth;
    $this->logger = $this->getLogger('shibboleth');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('shibboleth.auth_manager'),
      $container->get('shibboleth.drupal_auth_manager'),
    );
  }

  /**
   * Logs into Drupal with the current Shibboleth user.
   *
   * The Shibboleth session must already exist. This will attempt to log in a
   * Drupal user that has been mapped to the Shibboleth authname.
   *
   * @return array|RedirectResponse
   *   Returns a RedirectResponse upon successful login or a renderable array
   *   upon failure.
   */
  public function login() {

    // Get the redirect destination if there is one. Otherwise, set the
    // destination to the homepage.
    $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? \Drupal::urlGenerator()->generateFromRoute('<front>', []);

    // The user is logged into Shibboleth.
    if ($this->shibbolethAuthManager->sessionExists()) {
      $authname = $this->shibbolethAuthManager->getTargetedId();
      $id_label = $this->config('shibboleth.settings')->get('shibboleth_id_label');

      // The user is not logged into Drupal.
      if ($this->currentUser()->isAnonymous()) {
        // Attempt to log into Drupal with the Shibboleth ID.
        /** @var \Drupal\user\Entity\User|false $account */
        $account = $this->shibbolethDrupalAuthManager->loginRegister();

        // Login successful.
        if ($account) {
          return new RedirectResponse($destination);
        }

        // Login failed. Return access denied.
        $this->messenger()->addError($this->t('Login failed via Shibboleth. We were unable to find or create a user linked to the @id_label <strong>%authname</strong>. Please contact the site administrator to request access.',
          ['@id_label' => $id_label, '%authname' => $authname]));
        return $this->loginError();
      }
      else {
        $current_user_authname = $this->shibbolethDrupalAuthManager->getShibbolethAuthname($this->currentUser()->id());

        // Check if Shibboleth user matches Drupal user.
        if ($current_user_authname == $authname || $this->currentUser()->hasPermission('bypass shibboleth login')) {
          $this->messenger()->addStatus($this->t('You are already logged in.'));
          return new RedirectResponse($destination);
        }
        elseif (!$this->currentUser()->hasPermission('bypass shibboleth login')) {
          // The Shibboleth and Drupal user authnames don't match and the Drupal
          // user doesn't have permission to bypass Shibboleth login.
          $this->messenger()->addError($this->t('You have been logged out of this site because the @id_label <strong>%authname</strong> did not match the Drupal user and the Drupal user did not have permission to bypass Shibboleth login. You can try to log in again. Please contact the site administrator for more information.',
            ['@id_label' => $id_label, '%authname' => $authname]));
          return $this->loginError();
        }
      }
    }

    // No Shibboleth session exists, so redirect to the Shibboleth login URL.
    $response = new TrustedRedirectResponse($this->shibbolethAuthManager->getLoginUrl()->toString(TRUE)->getGeneratedUrl());
    return $response->send();
  }

  /**
   * Provides content for the Login error page.
   *
   * @return array
   *   Returns a render array.
   */
  protected function loginError() {
    return [
      '#title' => t('Access denied'),
      '#markup' => t('Login failed.'),
    ];
  }

}
