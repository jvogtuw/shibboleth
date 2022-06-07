<?php
namespace Drupal\shibboleth_path\Exception;

use Drupal\Core\Config\ConfigException;

/**
 * Exception thrown when a Shibboleth path rule's pattern property is the same
 * as another rule's.
 */
class ShibbolethPathRulePatternDuplicateException extends ConfigException {

}
