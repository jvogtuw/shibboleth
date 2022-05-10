<?php

namespace Drupal\shibboleth\EventSubscriber;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Http\RequestStack;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\redirect\RedirectChecker;
use Drupal\shibboleth\Access\ShibPathAccess;
// use Drupal\shibboleth\ShibPath\ShibPathAccessChecker;
use Drupal\shibboleth\Access\ShibRuleAccessInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpCache\SubRequestHandler;
use Symfony\Component\HttpKernel\KernelEvents;
use TYPO3\PharStreamWrapper\Exception;

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
   * @var \Drupal\Core\Http\RequestStack
   */
  private $requestStack;

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  private $currentUser;

  /**
   * @var \Drupal\shibboleth\Access\ShibPathAccess
   */
  private $shibPathAccess;

  /**
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  private $pathMatcher;

  /**
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  private $currentPath;

  /**
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  private $aliasManager;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibAuth;

  /**
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  // private $routeMatch;

  /**
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  private $accessManager;

  /**
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  private $routeProvider;

  /**
   * @var \Psr\Log\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger, RequestStack $request_stack, AccountInterface $current_user, PathMatcherInterface $path_matcher, CurrentPathStack $current_path, AliasManagerInterface $alias_manager/*, RouteMatchInterface $route_match*/, AccessManagerInterface $access_manager/*, RouteProviderInterface $route_provider*/, ShibPathAccess $shib_path_access, ShibbolethAuthManager $shib_auth, LoggerChannelInterface $logger) {
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
    $this->currentUser = $current_user;
    $this->pathMatcher = $path_matcher;
    $this->currentPath = $current_path;
    $this->aliasManager = $alias_manager;
    $this->shibPathAccess = $shib_path_access;
    $this->shibAuth = $shib_auth;
    // $this->routeMatch = $route_match;
    $this->accessManager = $access_manager;
    $this->logger = $logger;
    // $this->routeProvider = $route_provider;
    // $access_manager->check($this->routeMatch, $this->currentUser);
    // $access_manager->checkRequest($request_stack->getMainRequest(), $current_user);
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
  public function onRequestShibRule(RequestEvent $event) {

    // Don't act on SubRequests.
    // if (!$event->isMasterRequest()) {
    //   return;
    // }

    // Do not capture redirects or modify XML HTTP Requests.
    if ($event->getRequest()->isXmlHttpRequest()) {
      return;
    }
    //
    // dpm($this->shibPathAccess->getExcludedRoutes(), 'excluded routes');
    // The user can bypass all protected path rules
    if ($this->currentUser->hasPermission('bypass shibboleth rules')) {
      $this->messenger->addStatus('This user can bypass Shibboleth access rules');
      return;
    }

    $request = $event->getRequest();

    // The whole site requires Shibboleth authentication. Enforce a check
    // regardless of the target destination.
    if ($this->shibPathAccess->isWholeSiteProtected()) {
      // return;
      try {
        $this->shibPathAccess->checkAccess($request, 'all');
      }
      catch (ShibbolethSessionException $e) {
        $response = new TrustedRedirectResponse($this->shibAuth->getAuthenticateUrl()->toString());
        $event->setResponse($response);
        return;
      }
      // $this->messenger->addStatus($this->shibAuth->getEmail());
      // if (!$this->shibAuth->sessionExists()) {
      //   $this->messenger->addStatus('No Shibboleth session');
      //   // redirect to Shibboleth login handler.
      //   $response = new TrustedRedirectResponse($this->shibAuth->getAuthenticateUrl()->toString());
      //   $event->setResponse($response);
      // }
      // else {
      //   // @see $this->onResponse() for how the results of this check are used.
      //   $this->shibPathAccess->checkAccessWholeSite($request);
      // }
    }

    // The request isn't valid for Shibboleth access checks.
    if (!$this->isRequestActionable($request)) {
      return;
    }

    // Finally, do the actual access check for the path.
    try {
      $this->messenger->addStatus('Checking specific access');
      $this->shibPathAccess->checkAccess($request);
    }
    catch (ShibbolethSessionException $e) {
      $response = new TrustedRedirectResponse($this->shibAuth->getAuthenticateUrl()->toString());
      $event->setResponse($response);
      return;
    }

  }

  /**
   * Determines if this is a valid request to pass through Shibboleth access
   * checks.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return bool
   */
  protected function isRequestActionable(Request $request) {
    $actionable = TRUE;
    if (!preg_match('/index\.php$/', $request->getScriptName())) {
      // Do not check Shibboleth rules if the root script is not /index.php.
      $actionable = FALSE;
    }
    elseif (!($request->isMethod('GET') || $request->isMethod('HEAD'))) {
      // Do not check Shibboleth rules if this is other than GET request.
      $actionable = FALSE;
    }
    elseif (!$this->accessManager->checkRequest($request)) {
      // Do not check Shibboleth rules if access is denied by Drupal.
      $actionable = FALSE;
    }
    return $actionable;
  }

  /**
   * Kernel response event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   Response event.
   */
  public function onResponseShibRule(ResponseEvent $event) {

    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof CacheableResponseInterface) {
      $this->messenger->addStatus('hello');
      return;
    }

    $request = $event->getRequest();
    /** @var \Drupal\Core\Access\AccessResultReasonInterface $access_result */
    $access_result = $request->attributes->get(ShibRuleAccessInterface::ACCESS_RESULT);
    $response->addCacheableDependency($access_result);

    if (isset($access_result) && $access_result->isForbidden()) {
      if ($access_result instanceof CacheableDependencyInterface && $request->isMethodCacheable()) {
        throw new CacheableAccessDeniedHttpException($access_result, $access_result instanceof AccessResultReasonInterface ? $access_result->getReason() : '');
      }
      else {
        throw new AccessDeniedHttpException($access_result instanceof AccessResultReasonInterface ? $access_result->getReason() : '');
      }
    }

  }


  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onRequestShibRule'],
      KernelEvents::RESPONSE => ['onResponseShibRule', 15],
    ];
  }

}
