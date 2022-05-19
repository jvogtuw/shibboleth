<?php

namespace Drupal\shibboleth_path;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth\Exception\ShibbolethSessionException;
use Symfony\Component\Routing\Route;

class ShibbolethPathAccessHandler implements ShibbolethPathAccessHandlerInterface {

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibbolethAuthManager;

  /**
   * The
   * shibboleth
   * cache
   * bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $shibbolethCache;

  /**
   * @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule
   */
  private $pathRuleStorage;

  public function __construct(ShibbolethAuthManager $shibboleth_auth_manager, CacheBackendInterface $shibboleth_cache, EntityTypeManagerInterface $entity_type_manager) {
    // If no active shibboleth session, remove any cached session variables.
    $this->shibbolethAuthManager = $shibboleth_auth_manager;
    $this->shibbolethCache = $shibboleth_cache;
    $this->pathRuleStorage = $entity_type_manager->getStorage('shibboleth_path_rule');
  }

  /**
   * @param \Symfony\Component\Routing\Route $route
   *
   * @return bool
   */
  public function checkAccess(Route $route) {
    // if (!$this->shibbolethAuthManager->sessionExists()) {
    //   throw new ShibbolethSessionException('No Shibboleth session found.');
    // }

    $path = $route->getPath();
    $cached_path = $this->getPathCache($path);
    $path_rules = [];
    // The path has been cached, so we already know the rules that apply.
    if ($cached_path) {
      $path_rules = $cached_path->rules;
    }
    else {
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

    // Can't continue if there's no Shibboleth session.
    if (!$this->shibbolethAuthManager->sessionExists()) {
      throw new ShibbolethSessionException('No Shibboleth session found.');
    }

    // At this point, the path is protected and there's a Shibboleth session.
    // We'll assume access until we check the other criteria.
    $criteria_met = TRUE;
    /** @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule $path_rule */
    foreach ($path_rules as $path_rule) {
      // Check the user session to see if they've already been assessed for the rule
      $this->tempStore->get('shibboleth_path');
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
  // public function userRuleCriteriaCheck(string $rule_id) {
  //   // If no Shibboleth session, throw ShibbolethSessionException.
  //   // Check if a session variable has already been set for this rule. Return if so.
  //   // Check if the Shibboleth session meets the rule criteria. Set session variable
  //   // and return.
  // }

  // public function userPathAccessCheck(string $path) {
  //
  // }

  /**
   * Checks
   * the
   * validity
   * of
   * Shibboleth
   * variables
   * @return void
   */
  public function validateSession() {

  }

  protected function getPathCache($path) {
    return $this->getShibCacheItem('shib_path:' . $path);
  }

  protected function setPathCache($path, $data) {
    $this->setShibCacheItem('shib_path:' . $path, $data);
  }

  protected function getRuleCache($rule_id) {
    return $this->getShibCacheItem('shib_rule:' . $rule_id);
  }

  protected function getShibCacheItem($cid) {
    if ($cache = $this->shibbolethCache->get($cid)) {
      return unserialize($cache->data);
    }
    return FALSE;
  }
  protected function setShibCacheItem($cid, $data) {
    $this->shibbolethCache->set($cid, $data);
  }
}
