<?php

namespace Drupal\shibboleth\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactory;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;

class ShibPathAccess {

  /**
   * @var \Drupal\shibboleth\ShibPath\ShibPathStorage
   */
  private $shibPathStorage;

  /**
   * @var \Drupal\Core\Session\SessionManagerInterface
   */
  private $sessionManager;

  /**
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  private $pathMatcher;

  /**
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  private $keyValueStore;

  /**
   * @var \Drupal\shibboleth\Authentication\ShibbolethAuthManager
   */
  private $shibAuth;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, SessionManagerInterface $session_manager, PathMatcherInterface $path_matcher, KeyValueFactory $key_value_store, ShibbolethAuthManager $shib_auth) {
    $this->shibPathStorage = $entity_type_manager->getStorage('shib_path');
    $this->sessionManager = $session_manager;
    $this->pathMatcher = $path_matcher;
    $this->keyValueStore = $key_value_store->get('shib_path');
    $this->shibAuth = $shib_auth;
  }

  /**
   *
   */
  public function access($path) {
    // Check to see if the best matches for this path are cached.

  }

}
