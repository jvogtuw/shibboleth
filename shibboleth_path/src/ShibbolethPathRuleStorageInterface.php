<?php

namespace Drupal\shibboleth_path;

interface ShibbolethPathRuleStorageInterface {

  public function getMatchingRules(string $path, $include_disabled = FALSE);

  public function isExcluded(string $path);

  public function getExcludedPaths();
}
