<?php
namespace Drupal\shibboleth_path\Exception;

use Drupal\Core\Config\ConfigException;

/**
 * Exception thrown when a ShibPath's path property causes a conflict.
 */
class ShibPathRulePatternDuplicateException extends ConfigException {

}
