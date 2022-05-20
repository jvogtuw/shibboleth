<?php

namespace Drupal\shibboleth_path\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
// use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Checks a Shibboleth user's access to the current path.
 */
class ShibbolethPathAccessCheck implements AccessInterface {

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibbolethAuthManager;

  /**
   * The shibboleth cache bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $shibbolethCache;

  /**
   * @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule
   */
  private $pathRuleStorage;

  /**
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  private $aliasManager;

  /**
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  private $killSwitch;


  /**
   * @param \Symfony\Component\HttpFoundation\RequestStack          $request_stack
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shibboleth_auth_manager
   * @param \Drupal\Core\Cache\CacheBackendInterface                $shibboleth_cache
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface          $entity_type_manager
   * @param \Drupal\path_alias\AliasManagerInterface                $alias_manager
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch        $kill_switch
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(RequestStack $request_stack, ShibbolethAuthManager $shibboleth_auth_manager, CacheBackendInterface $shibboleth_cache, EntityTypeManagerInterface $entity_type_manager, AliasManagerInterface $alias_manager, KillSwitch $kill_switch) {

    $this->requestStack = $request_stack;
    $this->shibbolethAuthManager = $shibboleth_auth_manager;
    $this->shibbolethCache = $shibboleth_cache;
    $this->pathRuleStorage = $entity_type_manager->getStorage('shibboleth_path_rule');
    $this->aliasManager = $alias_manager;
    $this->killSwitch = $kill_switch;
  }

  /**
   * Determines a user's access to the request path.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check against.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The parametrized route
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {

    // If the user doesn't have access according to Drupal, don't bother checking
    // the Shibboleth path access.
    $current_access_result = $this->requestStack->getCurrentRequest()->attributes->get(AccessAwareRouterInterface::ACCESS_RESULT);
    if (!empty($current_access_result) && !$current_access_result instanceof AccessResultAllowed) {
      return AccessResult::neutral();
    }

    try {

      if ($this->checkAccess()) {
        return AccessResult::allowed();
      } else {
        return AccessResult::forbidden();
      }

    }
    catch (ShibbolethSessionException $exception) {

      // @todo move this check to checkAccess().
      if (!$account->hasPermission('bypass shibboleth login')) {
        $this->requestStack->getCurrentRequest()->attributes->set('shibboleth_auth_required', TRUE);
        return AccessResult::forbidden();
      }
    }
    return AccessResult::allowed();
  }

  /**
   * Checks if the current Shibboleth user meets the criteria to access the path.
   *
   * @return bool
   *   Returns TRUE if the Shibboleth user meets the criteria. FALSE otherwise.
   *
   * @throws ShibbolethSessionException
   *   Exception thrown when no active Shibboleth session is found.
   */
  protected function checkAccess() {
    $path = $this->requestStack->getCurrentRequest()->getPathInfo();

    // Swap the path out for the alias if available.
    $path = $this->aliasManager->getAliasByPath($path);

    $cached_path = $this->getPathCache($path);
    $path_rules = [];
    // If the path has been cached, we already know the rules that apply.
    if ($cached_path) {
      $path_rules = $cached_path['rules'];
    }
    else {
      /** @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule $path_rules[] */
      $path_rules = $this->pathRuleStorage->getBestMatchesForPath($path);

      // Build the data for the cache item.
      $data = ['rules' => $path_rules];
      $this->setPathCache($path, $data);
    }

    // There are no Shibboleth path rules that match the path so no further
    // checks are needed.
    if (empty($path_rules)) {
      return TRUE;
    }

    // Prevent protected pages from caching.
    $this->killSwitch->trigger();

    // Can't continue if there's no Shibboleth session.
    if (!$this->shibbolethAuthManager->sessionExists()) {
      // Delete any existing rule checks from the session and throw an exception.
      throw new ShibbolethSessionException('No Shibboleth session found.');
    }

    // At this point, the path is protected and there's a Shibboleth session.
    // We'll assume access until we check the other criteria.
    $criteria_met = TRUE;
    // /** @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule $path_rule */
    foreach ($path_rules as $path_rule) {
      $criteria_type = $path_rule->get('criteria_type');
      $criteria = $path_rule->getCriteria();
      if ($criteria_type == 'affiliation') {
        $shib_affiliation = $this->shibbolethAuthManager->getAffiliation();
        if (empty($shib_affiliation) || empty(array_intersect($shib_affiliation, $criteria))) {
          $criteria_met = FALSE;
        }
      }
      elseif ($criteria_type == 'groups') {
        $shib_groups = $this->shibbolethAuthManager->getGroups();
        if (empty($shib_groups) || empty(array_intersect($shib_groups, $criteria))) {
          $criteria_met = FALSE;
        }
      }
    }
    return $criteria_met;
  }

  /**
   * Gets the cached Shibboleth path rules for the given path.
   *
   * @param string $path
   *   The path or alias if available.
   *
   * @return array|FALSE
   *   Returns the cached data for the path. The returned array contains a
   *   'rules' array of ShibbolethPathRule objects. The rules array is empty if
   *   no rules protect the path. Returns FALSE if the path has not been cached.
   */
  protected function getPathCache(string $path) {
    $cid = 'shib_path:' . $path;
    if ($cache = $this->shibbolethCache->get($cid)) {
      return $cache->data;
    }
    return FALSE;
  }

  /**
   * Sets the Shibboleth path rules that protect the path.
   *
   * @param string $path
   *   The path or alias if available.
   * @param array  $data
   *   The data to cache. Should contain an array of ShibbolethPathRules with
   *   the key 'rules'.
   */
  protected function setPathCache(string $path, array $data) {
    $cid = 'shib_path:' . $path;
    $this->shibbolethCache->set($cid, $data);
    // $this->setShibCacheItem('shib_path:' . $path, $data);
  }

  // protected function getShibCacheItem($cid) {
  //   if ($cache = $this->shibbolethCache->get($cid)) {
  //     return $cache->data;
  //   }
  //   return FALSE;
  // }
  // protected function setShibCacheItem($cid, $data) {
  //   $this->shibbolethCache->set($cid, $data);
  // }

  // protected function clearSessionAccessChecks() {
  //   // $this->requestStack->getCurrentRequest()->getSession()->getBag('shibboleth_path')->clear();
  // }
}
