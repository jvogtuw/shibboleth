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
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * {@inheritdoc}
 */
class ShibbolethPathRuleStorage extends ConfigEntityStorage implements ShibbolethPathRuleStorageInterface {

  /**
   * The path matcher.
   *
   * @var \Drupal\Core\Path\PathMatcherInterface
   */
  private PathMatcherInterface $pathMatcher;

  /**
   * The Shibboleth path rules cache bin.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $shibbolethCache;

  /**
   * The page cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $pageCache;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $renderCache;

  /**
   * The list of routes excluded from Shibboleth path rule protection.
   *
   * @var \Symfony\Component\Routing\Route[]
   */
  private $excludedRoutes;

  /**
   * The list of paths excluded from Shibboleth path rule protection.
   *
   * @var string[]
   */
  private $excludedPaths;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  private $routeProvider;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private $messenger;

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
   * @param \Drupal\Core\Path\PathMatcherInterface $path_matcher
   *   The path matcher.
   * @param \Drupal\Core\Cache\CacheBackendInterface $shibboleth_cache
   *   The Shibboleth path cache.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Cache\CacheBackendInterface $page_cache
   *   The page cache.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   */
  public function __construct(EntityTypeInterface $entity_type, ConfigFactoryInterface $config_factory, UuidInterface
  $uuid_service, LanguageManagerInterface $language_manager, MemoryCacheInterface $memory_cache, PathMatcherInterface
  $path_matcher, CacheBackendInterface $shibboleth_cache, MessengerInterface $messenger, CacheBackendInterface
  $page_cache, CacheBackendInterface $render_cache, RouteProviderInterface $route_provider) {
    parent::__construct($entity_type, $config_factory, $uuid_service, $language_manager, $memory_cache);

    $this->pathMatcher = $path_matcher;
    $this->shibbolethCache = $shibboleth_cache;
    $this->messenger = $messenger;
    $this->pageCache = $page_cache;
    $this->renderCache = $render_cache;
    $this->routeProvider = $route_provider;
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
      $container->get('cache.page'),
      $container->get('cache.render'),
      $container->get('router.route_provider')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getMatchingRules(string $path, $best_matches = TRUE, $include_disabled = FALSE) {

    // Don't continue if the path is excluded from path protection.
    if ($this->isExcluded($path)) {
      return [];
    }

    // Get all the ShibbolethPathRules to check for matches.
    $shibboleth_path_rules = $include_disabled ? parent::loadMultiple() : parent::loadByProperties(['status' => 1]);

    // If the 'all' rule is active, add it to $matches initially and remove it
    // from the rest of the rules to test. The 'all' rule will be removed if
    // there are additional matches when $best_matches = TRUE.
    $matches = isset($shibboleth_path_rules['all']) ? ['all' => $shibboleth_path_rules['all']] : [];
    unset($shibboleth_path_rules['all']);

    $current_granularity = 0;
    /** @var \Drupal\shibboleth_path\Entity\ShibbolethPathRule $shibboleth_path_rule */
    foreach ($shibboleth_path_rules as $shibboleth_path_rule) {
      $rule_pattern = $shibboleth_path_rule->get('pattern');

      // Check if the path matches this ShibbolethPathRule's pattern.
      if ($this->pathMatcher->matchPath($path, $rule_pattern)) {

        // If $best_matches is TRUE, only get the most granular matches.
        if ($best_matches) {
          // Compare the granularity of this ShibbolethPathRule pattern to the
          // $current_granularity. If equal, add the ShibbolethPathRule to the
          // $matches array.
          $segment_count = count(explode('/', $rule_pattern));
          if ($segment_count >= $current_granularity) {

            // If we've reached a new max granularity, empty the $matches array.
            if ($segment_count > $current_granularity) {
              $current_granularity = $segment_count;
              // Reset $matches to get rid of the less granular.
              $matches = [];
            }
            $matches[$shibboleth_path_rule->id()] = $shibboleth_path_rule;
          }
        }
        else {
          // $best_matches is FALSE so add all matches regardless of
          // granularity.
          $matches[$shibboleth_path_rule->id()] = $shibboleth_path_rule;
        }
      }
    }
    return $matches;
  }

  /**
   * {@inheritdoc}
   */
  public function isExcluded(string $path) {
    $excluded_paths = $this->getExcludedPaths();
    foreach ($excluded_paths as $excluded_path) {
      if ($this->pathMatcher->matchPath($path, $excluded_path)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getExcludedPaths() {
    if (empty($this->excludedPaths)) {
      $this->setExcludedRoutes();
    }
    return $this->excludedPaths;
  }

  /**
   * {@inheritdoc}
   */
  public function getExcludedRoutes() {
    if (empty($this->excludedRoutes)) {
      $this->setExcludedRoutes();
    }
    return $this->excludedRoutes;
  }

  /**
   * Sets the values of $this->excludedRoutes and $this->excludedPaths.
   */
  private function setExcludedRoutes() {
    $config = $this->configFactory->get('shibboleth_path.settings');
    $excluded_route_names = $config->get('excluded_routes');
    $excluded_routes = [];
    $excluded_paths = [];
    foreach ($excluded_route_names as $route_name) {
      $formatted_route_name = str_replace('-', '.', $route_name);
      // Act on anything that isn't 0.
      if ($formatted_route_name) {
        $route = $this->routeProvider->getRouteByName($formatted_route_name);
        $excluded_routes[] = $route;
        $excluded_paths[] = $route->getPath();
      }
    }
    $this->excludedRoutes = $excluded_routes;
    $this->excludedPaths = $excluded_paths;
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    $return = parent::save($entity);
    $this->shibbolethCache->deleteAll();
    $this->messenger->addStatus($this->t('Shibboleth paths cache cleared.'));
    $this->pageCache->deleteAll();
    $this->messenger->addStatus($this->t('Page cache cleared.'));
    $this->renderCache->deleteAll();
    $this->messenger->addStatus($this->t('Render cache cleared.'));
    return $return;
  }

}
