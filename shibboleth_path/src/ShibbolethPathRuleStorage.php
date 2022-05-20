<?php

namespace Drupal\shibboleth_path;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\shibboleth_path\Entity\ShibbolethPath;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ShibbolethPathRuleStorage extends ConfigEntityStorage implements ShibbolethPathRuleStorageInterface {

  /**
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  private PathMatcherInterface $pathMatcher;


  /**
   * The shibboleth cache bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $shibbolethCache;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private $messenger;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $pageCache;

  /**
   * Constructs a ConfigEntityStorage object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   *   The UUID service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Cache\MemoryCache\MemoryCacheInterface $memory_cache
   *   The memory cache backend.
   */
  public function __construct(EntityTypeInterface $entity_type, ConfigFactoryInterface $config_factory, UuidInterface $uuid_service, LanguageManagerInterface $language_manager, MemoryCacheInterface $memory_cache, PathMatcherInterface $path_matcher, CacheBackendInterface $shibboleth_cache, MessengerInterface $messenger, CacheBackendInterface $page_cache) {
    parent::__construct($entity_type, $config_factory, $uuid_service, $language_manager, $memory_cache);

    $this->pathMatcher = $path_matcher;
    $this->shibbolethCache = $shibboleth_cache;
    $this->messenger = $messenger;
    $this->pageCache = $page_cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('config.factory'),
      $container->get('uuid'),
      $container->get('language_manager'),
      $container->get('entity.memory_cache'),
      $container->get('path.matcher'),
      $container->get('cache.shibboleth'),
      $container->get('messenger'),
      $container->get('cache.page')
    );
  }

  /**
   * Checks if the given path matches the path patterns of any ShibPath and
   * returns the most granular match(es).
   *
   * The system-defined "all" ShibPath is excluded
   *
   * @param string $path
   *   The local, absolute path to check.
   * @param bool   $include_disabled
   *
   * @return \Drupal\shibboleth_path\Entity\ShibbolethPathRule[]
   *   Returns an array of the best (most granular) match(es) for the given path.
   */
  public function getBestMatchesForPath(string $path, $include_disabled = FALSE) {
    // Never check access for these routes
    // @todo Make these always accessible paths a config value.
    // $always_accessible_routes = [
    //   'user.logout',
    //   'shibboleth.drupal_logout',
    // ];
    // foreach ($always_accessible_routes as $always_accessible_route) {
    //   $always_accessible_path = Url::fromRoute($always_accessible_route)->toString();
    //   if ($this->pathMatcher->matchPath($path, $always_accessible_path)) {
    //     return [];
    //   }
    // }

    // Get all the ShibPaths to check for matches
    $shib_paths = $include_disabled ? parent::loadMultiple() : parent::loadByProperties(['status' => 1]);
    // Remove the 'all' path rule. We can deal with that elsewhere.
    // unset($shib_paths['all']);
    // Select only the most granular match(es).
    // @see Drupal\shibboleth\Access\ShibPathAccessChecker for handling multiple
    // best matches.
    $best_matches = [];
    $current_granularity = 0;
    foreach ($shib_paths as $shib_path) {
      $shib_path_path = $shib_path->get('pattern');
      // Check if the path matches this ShibPath's path.
      if ($this->pathMatcher->matchPath($path, $shib_path_path)) {
        // Compare the granularity of this ShibPath path to the current max
        // granularity. If equal, add the ShibPath path to the $best_matches array.
        $segment_count = count(explode('/', $shib_path_path));
        if ($segment_count >= $current_granularity) {
          // If we've reached a new max granularity, empty the $best_matches array.
          if ($segment_count > $current_granularity) {
            $current_granularity = $segment_count;
            // Reset $best_matches to get rid of the less granular.
            $best_matches = [];
          }
          // Add the full ShibPath object to the best_matches array.
          $best_matches[] = $shib_path;
        }
      }
    }
    return $best_matches;
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    $return = parent::save($entity);
    $this->shibbolethCache->deleteAll();
    $this->messenger->addStatus($this->t('Shibboleth paths cache cleared.'));
    // or should i invalidate all?
    $this->pageCache->deleteAll();
    $this->messenger->addStatus($this->t('Page cache cleared.'));
    return $return;
  }

}
