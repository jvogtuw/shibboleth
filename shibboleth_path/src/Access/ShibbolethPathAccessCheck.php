<?php

namespace Drupal\shibboleth_path\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
   * @var \Drupal\shibboleth_path\ShibbolethPathRuleStorageInterface
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
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private $messenger;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;


  /**
   * @param \Symfony\Component\HttpFoundation\RequestStack          $request_stack
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shibboleth_auth_manager
   * @param \Drupal\Core\Cache\CacheBackendInterface                $shibboleth_cache
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface          $entity_type_manager
   * @param \Drupal\path_alias\AliasManagerInterface                $alias_manager
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch        $kill_switch
   * @param \Drupal\Core\Config\ConfigFactoryInterface              $config_factory
   * @param \Drupal\Core\Messenger\MessengerInterface               $messenger
   * @param \Psr\Log\LoggerInterface                                $logger
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(RequestStack $request_stack, ShibbolethAuthManager $shibboleth_auth_manager, CacheBackendInterface $shibboleth_cache, EntityTypeManagerInterface $entity_type_manager, AliasManagerInterface $alias_manager, KillSwitch $kill_switch, ConfigFactoryInterface $config_factory, MessengerInterface $messenger, LoggerInterface $logger) {

    $this->requestStack = $request_stack;
    $this->shibbolethAuthManager = $shibboleth_auth_manager;
    $this->shibbolethCache = $shibboleth_cache;
    $this->pathRuleStorage = $entity_type_manager->getStorage('shibboleth_path_rule');
    $this->aliasManager = $alias_manager;
    $this->killSwitch = $kill_switch;
    $this->config = $config_factory->get('shibboleth.settings');
    $this->messenger = $messenger;
    $this->logger = $logger;
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

    // If the user doesn't have access according to Drupal, don't bother
    // checking the Shibboleth path access.
    $current_access_result = $this->requestStack->getCurrentRequest()->attributes->get(AccessAwareRouterInterface::ACCESS_RESULT);
    if (!empty($current_access_result) && !$current_access_result instanceof AccessResultAllowed) {
      return $current_access_result;
    }

    if ($this->checkAccess($account)) {
      return AccessResult::allowed();
    }
    else {
      $id_label = $this->config->get('shibboleth_id_label');
      $authname = $this->shibbolethAuthManager->getTargetedId();
      $this->messenger->addError(t('The @id_label <strong>%authname</strong> does not have access to this page. Please contact the site administrator to request access.', ['@id_label' => $id_label, '%authname' => $authname]));
      $this->logger->warning('A Shibboleth path rule prevented the @id_label %authname from accessing this path.', ['@id_label' => $id_label, '%authname' => $authname]);
      // Throw this exception because AccessResult::forbidden() is cacheable
      // and messes things up.
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Checks if the current Shibboleth user meets the criteria to access the path.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The Drupal user to check.
   *
   * @return bool
   *   Returns TRUE if the Shibboleth user meets the criteria or if the Drupal
   *   user has permission to bypass path rules. FALSE otherwise.
   */
  protected function checkAccess(AccountInterface $account) {

    if ($account->hasPermission('bypass shibboleth_path rules')) {
      return TRUE;
    }

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
      $path_rules = $this->pathRuleStorage->getMatchingRules($path);

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
   * Caches the Shibboleth path rules that protect the path.
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
  }

}
