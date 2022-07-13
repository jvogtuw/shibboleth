<?php

namespace Drupal\shibboleth_path\EventSubscriber;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Drupal\shibboleth_path\Access\ShibbolethPathAccessCheck;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles Shibboleth authentication redirects.
 */
class ShibbolethPathAccessSubscriber implements EventSubscriberInterface {

  /**
   * The Shibboleth authentication manager.
   *
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibAuth;

  /**
   * @var \Drupal\shibboleth_path\Access\ShibbolethPathAccessCheck
   */
  private $shibPathAccess;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  private $currentUser;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shib_auth
   *   The Shibboleth authentication manager.
   */
  public function __construct(ShibbolethAuthManager $shib_auth, ShibbolethPathAccessCheck $shib_path_access, AccountInterface $current_user, SessionManagerInterface $session_manager) {
    $this->shibAuth = $shib_auth;
    $this->shibPathAccess = $shib_path_access;
    $this->currentUser = $current_user;

    // Leaving this here for the moment in case I haven't really fixed the
    // Shib session getting caught in a loop thing.
    // if ($this->currentUser->isAnonymous() && !isset($_SESSION['session_started'])) {
    //   $_SESSION['session_started'] = TRUE;
    //   $session_manager->start();
    // }
  }

  /**
   * Denies access if authentication provider is not allowed on this route.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequestCheckAccess(RequestEvent $event) {

    $request = $event->getRequest();
    if (!$event->isMasterRequest() || !$request->isMethod('GET')) {
      return;
    }

    $access_result = $request->attributes->get(AccessAwareRouterInterface::ACCESS_RESULT);
    if ($access_result && !$access_result->isAllowed()) {
      return;
    }

    $request_path = $request->getPathInfo();
    $shib_path_check = $this->shibPathAccess->checkAccess($this->currentUser, $request_path);
    $shib_access_result = $shib_path_check ? AccessResult::allowed() : AccessResult::forbidden();

    if (!$shib_access_result->isAllowed()) {
      \Drupal::messenger()->addWarning(t('The NetID <strong>%netid</strong> cannot access this page. Contact the site owner to request access or close all browser windows to log out and try again with a different NetID.',
        ['%netid' => $this->shibAuth->getTargetedId()]
      ));
      throw new AccessDeniedHttpException('Blocked by Shibboleth path rule.');
    }

  }

  /**
   * Redirects users when access is denied.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   */
  public function onShibbolethSessionException(ExceptionEvent $event) {
    $exception = $event->getThrowable();
    if ($exception instanceof ShibbolethSessionException) {
      $auth_redirect = $this->shibAuth->getAuthenticateUrl();
      $response = new TrustedRedirectResponse($auth_redirect->toString());
      $event->setResponse($response);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onRequestCheckAccess', 35],
      // Perform before AuthenticationSubscriber->onExceptionAccessDenied()
      KernelEvents::EXCEPTION => ['onShibbolethSessionException', 70],
    ];
  }

}
