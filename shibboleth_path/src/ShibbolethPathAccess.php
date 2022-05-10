<?php

namespace Drupal\shibboleth_path;

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
use Drupal\shibboleth_path\Entity\ShibbolethPathRule;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ShibbolethPathAccess implements ShibbolethPathAccessInterface {

  /**
   * @var \Drupal\shibboleth_path\ShibbolethPathRuleStorage
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
   * @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule
   */
  private $systemRuleAll;

  /**
   * @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule
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
   * Checks the current Shibboleth user's access based on the given rule.
   *
   * @param \Drupal\shibboleth_path\Entity\ShibbolethPathRule $rule
   *
   * @return \Drupal\Core\Access\AccessResultAllowed|\Drupal\Core\Access\AccessResultForbidden|mixed
   */
  public function checkAccessRule(ShibbolethPathRule $rule) {

    if (!$this->shibAuth->sessionExists()) {
      throw new ShibbolethSessionException();
    }

    // Check the Drupal user session in case we've already evaluated the access.
    if ($session_rule = $this->getSessionRule($rule->id())) {
      return $session_rule;
    }

    $access_result = AccessResult::neutral();
    if ($criteria_type = $rule->get('criteria_type')) {
      $method = 'get' . ucfirst($criteria_type);
      $shib_session_values = $this->shibAuth->$method();
      $criteria = $rule->getCriteria();
      $criteria_met = array_intersect($shib_session_values, $criteria);
      if (empty($criteria_met)) {
        $access_result = AccessResult::forbidden('Shibboleth access rule criteria were not met.');
      }
    }

    // Save the access result to the user's session.
    $this->setSessionRule($rule->id(), $access_result);

    return $access_result;
  }

  /**
   * Checks Shibboleth rule access for a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   */
  public function checkAccess(Request $request, string $rule_id = '') {

    $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);

    $access_result = AccessResult::neutral();

    if (!empty($rule_id)) {
      $access_result = $this->checkAccessRuleById($rule_id);
    }
    elseif (!$this->ignoreRoute($route_name)) {
      // Ensure this route can be protected by a Shibboleth rule.
      $path = $request->getPathInfo();
      $access_result = $this->checkAccessByPath($path, TRUE);
    }

    // Set the access result value on the request.
    $request->attributes->set(ShibRuleAccessInterface::ACCESS_RESULT, $access_result);
  }

  /**
   * @param string $path
   * @param bool   $return_as_object
   *
   * @return \Drupal\Core\Access\AccessResult
   */
  protected function checkAccessByPath(string $path, $return_as_object = FALSE) {

    $access_result = AccessResult::neutral();
    $path_or_alias = $this->aliasManager->getAliasByPath($path);

    // Check to see if the best matches for this path are cached.
    /** @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule[] $rule_matches */
    if ($rule_matches = $this->shibPathStorage->getBestMatchesForPath($path)) {
      foreach ($rule_matches as $rule) {
        $rule_result = $this->checkAccessRule($rule);
        // All checks must pass. Fail at the first access denied.
        if ($rule_result->isForbidden()) {
          $access_result = $rule_result;
          break;
        }
      }
    }

    // Either the path isn't protected or all criteria were met.
    return $return_as_object ? $access_result : $access_result->isAllowed();
  }



  public function checkAccessRuleById($id) {
    /** @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule $rule */
    $rule = $this->shibPathStorage->load($id);
    return $this->checkAccessRule($rule);
  }

  public function checkAccessWholeSite(Request $request) {
    $access_result = $this->checkAccessRuleById('all');
    $request->attributes->set(ShibbolethPathAccessInterface::ACCESS_RESULT, $access_result);
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
   * @return \Drupal\shibboleth_path\Entity\ShibbolethPathRule|null
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
   * @return \Drupal\shibboleth_path\Entity\ShibbolethPathRule|null
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
