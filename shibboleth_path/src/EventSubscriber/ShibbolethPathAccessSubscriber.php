<?php

namespace Drupal\shibboleth_path\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The Shibboleth path access event subscriber handles Shibboleth authentication
 * redirects.
 */
class ShibbolethPathAccessSubscriber implements EventSubscriberInterface {

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibAuth;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shib_auth
   */
  public function __construct(ShibbolethAuthManager $shib_auth) {
    $this->shibAuth = $shib_auth;
  }

  /**
   * Redirects to Shibboleth authentication (without Drupal login) if required.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   Response event.
   */
  // public function onResponseShibbolethPathRule(ResponseEvent $event) {
  //
  //   if ($event->getRequest()->attributes->get('shibboleth_auth_required')) {
  //     $event->getRequest()->attributes->remove('shibboleth_auth_required');
  //     // Redirect to the Shibboleth authentication only, not Drupal login.
  //     $auth_redirect = $this->shibAuth->getAuthenticateUrl();
  //     $response = new TrustedRedirectResponse($auth_redirect->toString());
  //     $event->setResponse($response);
  //   }
  //
  // }
  /**
   * Redirects users when access is denied.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   */
  public function onShibbolethSessionException(ExceptionEvent $event) {
    $exception = $event->getThrowable();
    // \Drupal::messenger()->addStatus(get_class($exception));
    if ($exception instanceof ShibbolethSessionException) {
      // \Drupal::messenger()->addStatus('in exception sub');
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
      // Perform after Authentication and RouterNormalizer
      // KernelEvents::RESPONSE => ['onResponseShibbolethPathRule', 15],
      // Perform before AuthenticationSubscriber->onExceptionAccessDenied()
      KernelEvents::EXCEPTION => ['onShibbolethSessionException', 70],
    ];
  }

}
