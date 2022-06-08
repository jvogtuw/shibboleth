<?php

namespace Drupal\shibboleth_path\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
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
   * Constructs event subscriber.
   *
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shib_auth
   *   The Shibboleth authentication manager.
   */
  public function __construct(ShibbolethAuthManager $shib_auth) {
    $this->shibAuth = $shib_auth;
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
      // Perform before AuthenticationSubscriber->onExceptionAccessDenied()
      KernelEvents::EXCEPTION => ['onShibbolethSessionException', 70],
    ];
  }

}
