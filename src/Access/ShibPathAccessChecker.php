<?php

namespace Drupal\shibboleth\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\shibboleth\Authentication\ShibbolethSession;
use Drupal\shibboleth\ShibPath\ShibPath;
use Drupal\shibboleth\ShibPath\ShibPathStorageInterface;
use Symfony\Component\Routing\Route;

/**
 * Checks if passed parameter matches the route configuration.
 *
 * @DCG
 * To make use of this access checker add '_shib_path_access_check: Some value' entry to route
 * definition under requirements section.
 */
class ShibPathAccessChecker implements AccessInterface {

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethSession
   */
  private $shibSession;

  /**
   * @var \Drupal\shibboleth\ShibPath\ShibPathStorage
   */
  private $shibPathStorage;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private $logger;

  /**
   * Constructs an ShibPathAccessChecker object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface      $etm
   * @param \Drupal\shibboleth\Authentication\ShibbolethSession $shib_session
   * @param \Drupal\Core\Logger\LoggerChannelInterface          $logger
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $etm, ShibbolethSession $shib_session, LoggerChannelInterface $logger) {
    $this->shibPathStorage = $etm->getStorage('shib_path');
    $this->shibSession = $shib_session;
    $this->logger = $logger;
  }

  /**
   * Access callback.
   *
   * @param \Symfony\Component\Routing\Route         $route
   *   The route to check against.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   * @param \Drupal\Core\Session\AccountInterface    $account
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account) {
    $this->logger->notice('Access check reached for ' . $route->getPath());
    
    // $current_path = $route->getPath();
    // $best_matches = $this->shibPathStorage->getBestMatchesForPath($current_path);
    // if (!empty($best_matches)) {
    //   if (!$this->shibSession->sessionExists() || $account->hasPermission('bypass shibboleth login')) {
    //     return AccessResult::forbidden('No Shibboleth session detected.');
    //   }
    //   foreach ($best_matches as $shib_path) {
    //     // If a criteria type is set, check the associated shibSession value.
    //     $criteria_type = $shib_path->get('criteria_type');
    //     if ($criteria_type == 'affiliation') {
    //       $affiliation_intersect = array_intersect($this->shibSession->getAffiliation(), $shib_path->get('criteria'));
    //       if (count($affiliation_intersect) == 0) {
    //         return AccessResult::forbidden('Affiliation match not found.');
    //       }
    //     }
    //     elseif ($criteria_type == 'group') {
    //       $group_intersect = array_intersect($this->shibSession->getGroups(), $shib_path->get('criteria'));
    //       if (count($group_intersect) == 0) {
    //         return AccessResult::forbidden('Group membership match not found.');
    //       }
    //     }
    //   }
    // }
    return AccessResult::allowed('Shibboleth path access OK.');
    // return AccessResult::allowedIf($parameter->getSomeValue() == $route->getRequirement('_shib_path_access_check'));
  }

}
