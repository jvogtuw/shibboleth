<?php
namespace Drupal\shibboleth\Exception;

use Drupal\Core\Config\ConfigException;

/**
 * Exception thrown when a ShibPath's path property causes a conflict.
 */
class ShibPathPathDuplicateException extends ConfigException {

}
