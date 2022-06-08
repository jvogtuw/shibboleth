<?php

namespace Drupal\shibboleth_path;

/**
 * Storage for Shibboleth path rule config entities.
 */
interface ShibbolethPathRuleStorageInterface {

  /**
   * Gets the ShibbolethPathRules that match the given path.
   *
   * @param string $path
   *   The path to check.
   * @param bool $best_matches
   *   Whether to include all matches or just the "best" aka  most granular
   *   matches.
   * @param bool $include_disabled
   *   Whether to include all rules or just the currently active rules.
   *
   * @return \Drupal\shibboleth_path\Entity\ShibbolethPathRule[]
   *   Returns an array of rule matches for the given path.
   */
  public function getMatchingRules(string $path, bool $best_matches = TRUE, bool $include_disabled = FALSE);

  /**
   * Checks if the path is excluded from protection.
   *
   * Excluded paths will not be checked for matching path rules.
   *
   * @param string $path
   *   The path to check.
   *
   * @return bool
   *   Returns TRUE if the path is excluded, FALSE otherwise.
   */
  public function isExcluded(string $path);

  /**
   * Gets the excluded paths from the excluded routes.
   *
   * @see getExcludedRoutes()
   *
   * @return string[]
   *   Returns an array of paths. Returns an empty array if no excluded routes
   *   are set.
   */
  public function getExcludedPaths();

  /**
   * Gets the excluded routes.
   *
   * Excluded routes are set in the shibboleth_path.settings config. Path
   * protection isn't assessed for these routes.
   *
   * @return \Symfony\Component\Routing\Route[]
   *   Returns an array of Route objects.
   */
  public function getExcludedRoutes();

}
