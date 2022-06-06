<?php

namespace Drupal\shibboleth\Controller;

use Drupal\Core\Controller\ControllerBase;
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
  private $shibbolethAuthManager;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethDrupalAuthManager
   */
  private $shibbolethDrupalAuthManager;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * LoginController constructor.
   *
   * @param ShibbolethAuthManager        $shib_auth
   * @param RequestStack                 $request_stack
   * @param ShibbolethDrupalAuthManager  $shib_drupal_auth
   */
  public function __construct(ShibbolethAuthManager $shib_auth, RequestStack $request_stack, ShibbolethDrupalAuthManager $shib_drupal_auth) {
    $this->shibbolethAuthManager = $shib_auth;
    $this->shibbolethDrupalAuthManager = $shib_drupal_auth;
    $this->logger = $this->getLogger('shibboleth');
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
      $container->get('shibboleth.drupal_auth_manager'),
    );
  }

  /**
   * Logs a Shibboleth user out of Drupal, optionally destroying the Shibboleth
   * session as well.
   *
   * Note: Depending on your Shibboleth Service Provider configuration, this may
   * only perform a local logout. A local logout removes the session from the
   * current site, but doesn't actually destroy the Shibboleth session entirely.
   * In this situation, if a user tries to log in again, a new Shibboleth
   * session will be created with the same account instead of prompting for
   * credentials.
   *
   * @return array|RedirectResponse
   *
   * @todo Find a way to destroy the session completely AND redirect back to the
   * site afterwards.
   */
  public function logout() {

    $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? $this->requestStack->getCurrentRequest()->getBasePath();

    // Log out the current user.
    if ($this->currentUser()->isAuthenticated()) {

      $authname = $this->shibbolethDrupalAuthManager->getShibbolethAuthname($this->currentUser->id());
      user_logout();
      $this->logger->notice($this->t('Shibboleth user %authname logged out.', [ '%authname' => $authname]));

      // If the Shibboleth session still exists, provide the opportunity to kill
      // it. This may happen if the user went directly to the Shibboleth logout
      // path instead of using the logout link that includes the handler.
      if ($this->shibbolethAuthManager->sessionExists()) {

        $logout_handler = $this->shibbolethAuthManager->getLogoutHandlerUrl();
        $id_label = $this->config->get('shibboleth_id_label');
        $authname = $this->shibbolethAuthManager->getTargetedId();
        return [
          '#markup' => t('<p>Success! You were logged out of this site.</p><p>If you wish, you can also end your @id_label session entirely. Ending the session will result in a sign in prompt if you try to log in again. This is useful if you want to log in with a different @id_label.</p><p><a href="@logout_handler">End the @id_label session for %authname</a></p>',
            [
              '@id_label' => $id_label,
              '@logout_handler' => $logout_handler,
              '%authname' => $authname,
            ]),
        ];

      }
      else {
        // The Shibboleth session ended.
        // @see logout() docblock for more info about ending Shibboleth sessions.
        $this->logger->notice($this->t('Shibboleth session ended for %authname.', [ '%authname' => $authname ]));

      }

    }

    return new RedirectResponse($destination);

  }

}
