<?php
namespace Drupal\shibboleth\Exception;

use Drupal\Core\Config\ConfigException;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * Exception thrown when no Shibboleth session exists.
 */
class ShibbolethSessionException extends Exception {

}
