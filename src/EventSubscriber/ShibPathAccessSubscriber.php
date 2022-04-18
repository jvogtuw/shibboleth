<?php

namespace Drupal\shibboleth\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\shibboleth\Access\ShibPathAccess;
// use Drupal\shibboleth\ShibPath\ShibPathAccessChecker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
// use Symfony\Component\Routing\Route;

/**
 * Shibboleth event subscriber.
 *
 */
class ShibPathAccessSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * @var \Drupal\shibboleth\Access\ShibPathAccess
   */
  private $shibPathAccess;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger, ShibPathAccess $shib_path_access) {
    $this->messenger = $messenger;
    $this->shibPathAccess = $shib_path_access;
  }

  /**
   * Kernel request event handler.
   *
   * @todo on every kernel.REQUEST do a series of checks:
   * 1) Check if shibboleth module is installed + not in debug mode
   *    - No: [end]
   * 2) Check for a user session
   *    - No: initiate session [continue]
   *    - Yes: [continue]
   * 3) Check if 'all' shib path is enabled
   *    - Yes: check for shib session
   *      - No: redirect to shib login with current page as destination [end]
   *      - Yes: check session for shibPathAllAccess = TRUE
   *        - No: ShibPathController->accessAll()
   *        - Yes: [continue]
   *    - No: [continue]
   * 4)
   *
   *
   *
   * 1) Is the path protected by a rule?
   *    - No: load the page
   * 2) Yes: is there a Shibboleth session?
   *    - No: Does the page require Drupal login?
   *      - Yes + user is anon: If user is anon, redirect to shibboleth.drupal_login route.
   *         Hopefully this lets Drupal's access control take over?
   *      - Yes + user is logged in: continue to page load. Drupal's access takes precedence.
   *      - No + any user state: redirect to the shibboleth.shib_auth_only route.
   * 3) Yes: is the user's access to the path cached in the session?
   *    - Yes: load the page.
   * 3) No: is access further restricted by groups or affiliation?
   *    - No: cache access and load the page
   * 4) Yes: does the Shibboleth user meet the additional criteria?
   *    - No: Access denied. Option to log out of Shibboleth.
   * 5) Yes, cache access and load the page.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   Response event.
   */
  public function onKernelRequest(RequestEvent $event) {
    // if ($event->isMasterRequest()) {
    //   $path = $event->getRequest()->getPathInfo();
    /** @var \Drupal\Core\TypedData\Plugin\DataType\Uri $uri */
    // $uri = $event->getRequest()->getUri();
    // $event->isMasterRequest();
    // dpm($event->getRequest(), '$event->getRequest()');
    // dpm();
    // $event->getRequest()->getPathInfo()->
    // }

    $this->messenger->addStatus(__FUNCTION__);

  }

  /**
   * Kernel response event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   Response event.
   */
  public function onKernelResponse(ResponseEvent $event) {

    // dpm($event->getResponse(), '$event->getResponse()');
    $this->messenger->addStatus(__FUNCTION__);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest'],
      KernelEvents::RESPONSE => ['onKernelResponse'],
    ];
  }

}
