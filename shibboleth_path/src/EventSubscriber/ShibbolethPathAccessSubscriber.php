<?php

namespace Drupal\shibboleth_path\EventSubscriber;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;


/**
 * Shibboleth event subscriber.
 *
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
   * Kernel response event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   Response event.
   */
  public function onResponseShibbolethPathRule(ResponseEvent $event) {

    if ($event->getRequest()->attributes->get('shibboleth_auth_required')) {
      $event->getRequest()->attributes->remove('shibboleth_auth_required');
      // Redirect to the Shibboleth authentication only, not Drupal login.
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
      KernelEvents::RESPONSE => ['onResponseShibbolethPathRule', 15],
    ];
  }

}
