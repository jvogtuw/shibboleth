<?php

namespace Drupal\shibboleth_path\Access;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;

/**
 * Checks a Shibboleth user's access to the current path.
 */
class ShibbolethPathAccessCheck implements AccessInterface {

  /**
   * The Shibboleth authentication manager.
   *
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibbolethAuthManager;

  /**
   * The Shibboleth path rules cache bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $shibbolethCache;

  /**
   * The Shibboleth path rule config entity storage.
   *
   * @var \Drupal\shibboleth_path\ShibbolethPathRuleStorageInterface
   */
  private $pathRuleStorage;

  /**
   * The KillSwitch policy.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  private $killSwitch;

  /**
   * Shibboleth module configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  // private $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor for ShibbolethPathAccessCheck.
   *
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shibboleth_auth_manager
   *   The Shibboleth authentication manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $shibboleth_cache
   *   The Shibboleth path rules cache bin.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $kill_switch
   *   The KillSwitch policy.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(ShibbolethAuthManager $shibboleth_auth_manager, CacheBackendInterface $shibboleth_cache, EntityTypeManagerInterface $entity_type_manager, KillSwitch $kill_switch, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {

    $this->shibbolethAuthManager = $shibboleth_auth_manager;
    $this->shibbolethCache = $shibboleth_cache;
    $this->pathRuleStorage = $entity_type_manager->getStorage('shibboleth_path_rule');
    $this->killSwitch = $kill_switch;
    $this->config = $config_factory->get('shibboleth_path.settings');
    $this->logger = $logger;
  }

  /**
   * Checks if the current Shibboleth user may access the path.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The Drupal user to check.
   * @param string $path
   *   The path to check.
   *
   * @return bool
   *   Returns TRUE if the Shibboleth user meets the criteria or if the Drupal
   *   user has permission to bypass path rules. FALSE otherwise.
   */
  public function checkAccess(AccountInterface $account, string $path) {

    if ($account->hasPermission('bypass shibboleth_path rules')) {
      return TRUE;
    }

    $cached_path = $this->getPathCache($path);
    $path_rules = [];
    // If the path has been cached, we already know the rules that apply.
    if ($cached_path) {
      $path_rules = $cached_path['rules'];
    }
    else {
      $permissive_enforcement = $this->config->get('enforcement') == 'permissive';
      /** @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule $path_rules[] */
      $path_rules = $this->pathRuleStorage->getMatchingRules($path, $permissive_enforcement);

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
      // Delete any existing rule checks from the session and throw an
      // exception.
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
   * @return array|false
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
   * @param array $data
   *   The data to cache. Should contain an array of ShibbolethPathRules with
   *   the key 'rules'.
   */
  protected function setPathCache(string $path, array $data) {
    $cid = 'shib_path:' . $path;
    $this->shibbolethCache->set($cid, $data);
  }

}
