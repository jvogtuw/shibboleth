<?php

namespace Drupal\shibboleth\Access;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\KeyValueStore\KeyValueFactory;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\PathMatcherInterface;
// use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Entity\ShibPath;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ShibPathAccess implements ShibRuleAccessInterface {

  /**
   * @var \Drupal\shibboleth\ShibPath\ShibPathStorage
   */
  private $shibPathStorage;

  /**
   * @var \Drupal\Core\Session\SessionInterface
   */
  private $session;

  /**
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  // private $pathMatcher;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibAuth;

  /**
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  // private $routeProvider;

  /**
   * @var \Drupal\shibboleth\Entity\ShibPath
   */
  private $systemRuleAll;

  /**
   * @var \Drupal\shibboleth\Entity\ShibPath
   */
  private $systemRuleLogin;

  /**
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  private $aliasManager;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private $messenger;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  public function __construct(MessengerInterface $messenger, EntityTypeManagerInterface $entity_type_manager, SessionInterface $session, ConfigFactoryInterface $config_factory/*, PathMatcherInterface $path_matcher, RouteProviderInterface $route_provider, AccessManagerInterface $access_manager*/, AliasManagerInterface $alias_manager, ShibbolethAuthManager $shib_auth) {
    $this->messenger = $messenger;
    $this->config = $config_factory->get('shibboleth.settings');
    $this->shibPathStorage = $entity_type_manager->getStorage('shib_path');
    $this->session = $session;
    // $this->pathMatcher = $path_matcher;
    // $this->keyValueStore = $key_value_store->get('shib_path');
    $this->shibAuth = $shib_auth;
    // $this->routeProvider = $route_provider;
    $this->aliasManager = $alias_manager;
    // $this->accessManager
  }


  /**
   * @param string $path
   *
   * @return \Drupal\Core\Access\AccessResult
   */
  protected function access(string $path, $return_as_object = FALSE) {
    // Maybe default to neutral?
    $access_result = AccessResult::allowed();
    $path_or_alias = $this->aliasManager->getAliasByPath($path);
    // Check to see if the best matches for this path are cached.
    /** @var \Drupal\shibboleth\Entity\ShibPath[] $rule_matches */
    if ($rule_matches = $this->shibPathStorage->getBestMatchesForPath($path)) {
      foreach ($rule_matches as $rule) {
        if (!$this->checkAccessRule($rule)) {
          $access_result = AccessResult::forbidden('Shibboleth access rule criteria not met.');
        }
      }
    }
    // Either the path isn't protected or all criteria were met.
    return $return_as_object ? $access_result : $access_result->isAllowed();
  }

  public function checkAccess(Request $request) {
    $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if ($this->ignoreRoute($route_name)) {
      // This route cannot be protected by a Shibboleth rule.
      return TRUE;
    }
    $path = $request->getPathInfo();
    $access_result = $this->access($path, TRUE);

    // Allow a master request to set the access result for a subrequest: if an
    // access result attribute is already set, don't overwrite it.
    if (!$request->attributes->has(ShibRuleAccessInterface::ACCESS_RESULT)) {
      $request->attributes->set(ShibRuleAccessInterface::ACCESS_RESULT, $access_result);
    }
    // if (!$access_result->isAllowed()) {
    //   if ($access_result instanceof CacheableDependencyInterface && $request->isMethodCacheable()) {
    //     throw new CacheableAccessDeniedHttpException($access_result, $access_result instanceof AccessResultReasonInterface ? $access_result->getReason() : '');
    //   }
    //   else {
    //     throw new AccessDeniedHttpException($access_result instanceof AccessResultReasonInterface ? $access_result->getReason() : '');
    //   }
    // }
    // return $this->access($path) ?? AccessResult::forbidden('i do not think so');
    // return TRUE;
  }

  public function checkAccessRuleById($id) {
    /** @var \Drupal\shibboleth\Entity\ShibPath $rule */
    $rule = $this->shibPathStorage->load($id);
    return $this->checkAccessRule($rule);
  }

  public function checkAccessRule(ShibPath $rule) {

    if (!$this->shibAuth->sessionExists()) {
      // Maybe throw ShibbolethSessionException and catch it in the subscriber?
      return FALSE;
    }

    if ($session_rule = $this->getSessionRule($rule->id())) {
      return $session_rule;
    }

    $has_access = TRUE;
    // $shib_rule = $this->shibPathStorage->load($id);
    if ($criteria_type = $rule->get('criteria_type')) {
      $method = 'get' . ucfirst($criteria_type);
      $shib_session_values = $this->shibAuth->$method();
      $criteria = $rule->getCriteria();
      $criteria_met = array_intersect($shib_session_values, $criteria);
      if (empty($criteria_met)) {
        $has_access = FALSE;
      }
    }
    $this->setSessionRule($rule->id(), $has_access);

  }

  public function checkAccessWholeSite() {
    if ($this->isWholeSiteProtected()) {
      // @todo Check session to see if access is cached.

      // If not, perform check
      return $this->checkAccessRuleById('all');
    }
    // return TRUE if user passes criteria for the full site shib path rule.
  }

  public function isWholeSiteProtected() {
    return $this->getSystemRule('all')->status();
    // $this->getSystemRule('all');
    // return TRUE;
  }

  protected function getSessionRule($id) {
    return $this->session->get('shib_rule.' . $id);
  }

  protected function setSessionRule($id, $value) {
    return $this->session->set('shib_rule.' . $id, $value);
  }

  /**
   * @param string $id
   *
   * @return \Drupal\shibboleth\Entity\ShibPath|null
   */
  protected function getSystemRule(string $id) {
    $property = 'systemRule' . ucfirst($id);
    $this->messenger->addStatus('property name: ' . $property);
    if (!isset($this->$property)) {
      $this->setSystemRule($id);
    }
    return $this->$property;
  }

  /**
   * @param string $id
   *
   * @return \Drupal\shibboleth\Entity\ShibPath|null
   */
  protected function setSystemRule(string $id) {
    $rule = $this->shibPathStorage->load($id);
    if (!empty($rule)) {
      $property = 'systemRule' . ucfirst($id);
      $this->$property = $rule;
    }
    return $rule;
  }
  // protected function isProtected($path) {
  //
  // }

  /**
   * Determines if redirect may be performed.
   *
   * @param Request $request
   *   The current request object.
   * @param string $route_name
   *   The current route name.
   *
   * @return bool
   *   TRUE if redirect may be performed.
   */
  protected function ignoreRoute(string $route_name) {
    $ignore = FALSE;
    // if (!isset($route_name)) {
    //   $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    // }
    // $route = $this->routeProvider->getRouteByName($route_name);

    // Never protect these routes
    $excluded_routes = $this->getExcludedRoutes();
    if (in_array($route_name, $excluded_routes)) {
      $ignore = TRUE;
    }
    // elseif (!preg_match('/index\.php$/', $request->getScriptName())) {
    //   // Do not redirect if the root script is not /index.php.
    //   $can_protect = FALSE;
    // }
    // elseif (!($request->isMethod('GET') || $request->isMethod('HEAD'))) {
    //   // Do not redirect if this is other than GET request.
    //   $can_protect = FALSE;
    // }
    // elseif (!$this->account->hasPermission('access site in maintenance mode') && ($this->state->get('system.maintenance_mode') || defined('MAINTENANCE_MODE'))) {
    //   // Do not redirect in offline or maintenance mode.
    //   $can_protect = FALSE;
    // }
    // elseif ($request->query->has('destination')) {
    //   $can_protect = FALSE;
    // }
    // elseif ($this->config->get('ignore_admin_path') && isset($route)) {
    //   // Do not redirect on admin paths.
    //   $can_protect &= !(bool) $route->getOption('_admin_route');
    // }

    return $ignore;
  }


  /**
   * Gets a list of routes that should never be protected by ShibPath rules.
   *
   * Because these are saved as config keys, they can't have dots (.) in them so
   * dashes are used instead.
   *
   * @return string[]
   */
  public function getExcludedRoutes() {
    $excluded_routes_settings = $this->config->get('excluded_routes');
    $excluded_routes = [];
    foreach ($excluded_routes_settings as $excluded_route_setting) {
      if ($excluded_route_setting) {
        $excluded_routes[] = str_replace('-', '.', $excluded_route_setting);
      }
    }
    // for ($i = 0; $i < count($excluded_routes); $i++) {
    //   $excluded_routes[$i] = str_replace('-', '.', $excluded_routes[$i]);
    // }
    return $excluded_routes;
  }
}
