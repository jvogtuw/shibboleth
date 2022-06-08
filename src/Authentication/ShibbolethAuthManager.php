<?php

namespace Drupal\shibboleth\Authentication;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Psr\Log\LoggerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the creation and destruction of Shibboleth sessions.
 */
class ShibbolethAuthManager {

  /**
   * Shibboleth module config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * The current route.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  private $currentRouteMatch;

  /**
   * The Shibboleth session ID.
   *
   * @var string
   */
  private $sessionId;

  /**
   * The Shibboleth user ID (aka authname).
   *
   * @var string
   */
  private $targetedId;

  /**
   * The Shibboleth user email.
   *
   * @var string
   */
  private $email;

  /**
   * The Shibboleth IdP.
   *
   * @var string
   */
  private $idp;

  /**
   * The Shibboleth user's organizational affiliations.
   *
   * @var array
   */
  private $affiliation;

  /**
   * The Shibboleth user's organizational group memberships.
   *
   * @var array
   */
  private $groups;

  /**
   * Constructor for ShibbolethAuthManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger interface.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $current_route_match
   *   The current route.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger, RequestStack $request_stack, RouteMatchInterface $current_route_match) {

    $this->config = $config_factory->get('shibboleth.settings');
    $this->logger = $logger;
    $this->requestStack = $request_stack;
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * Reports if there is or is not a Shibboleth session for the current user.
   *
   * @return bool
   *   Returns TRUE if there is a Shibboleth session for the current user, FALSE
   *   otherwise.
   */
  public function sessionExists() {
    return !empty($this->getSessionId());
  }

  /**
   * Gets the Shibboleth session ID.
   *
   * @return string
   *   Returns the Shibboleth session ID. If no session ID is found, it returns
   *   NULL.
   */
  public function getSessionId() {
    if (!isset($this->sessionId)) {
      $this->sessionId = self::fixModRewriteIssues('Shib-Session-ID');
    }
    return $this->sessionId;
  }

  /**
   * Gets the authname from the Shibboleth session.
   *
   * @return string
   *   Returns the authname from the Shibboleth session. If no authname is
   *   found, it returns NULL.
   */
  public function getTargetedId() {
    if (!isset($this->targetedId)) {
      $this->targetedId = self::fixModRewriteIssues($this->config->get('server_variable_authname'));
    }
    return $this->targetedId;
  }

  /**
   * Gets the email from the Shibboleth session.
   *
   * @return string
   *   Returns the email from the Shibboleth session. If no email is found, it
   *   returns NULL.
   */
  public function getEmail() {

    if (!isset($this->email)) {
      $email = self::fixModRewriteIssues($this->config->get('server_variable_email'));
      // Replace the outdated 'u.washington.edu' email domain with 'uw.edu'.
      if (str_replace('@u.washington.edu', '', $email) == $this->getTargetedId()) {
        $email = $this->getTargetedId() . '@uw.edu';
      }
      $this->email = $email;
    }
    return $this->email;
  }

  /**
   * Gets the Identity Provider from the Shibboleth session.
   *
   * @return string
   *   Returns the Identity Provider from the Shibboleth session. If no Identity
   *   Provider is found, it returns NULL.
   */
  public function getIdp() {
    if (!isset($this->idp)) {
      $this->idp = self::fixModRewriteIssues('Shib-Identity-Provider');
    }
    return $this->idp;
  }

  /**
   * Gets a list of affiliations from the Shibboleth session.
   *
   * @return array
   *   Returns an array of affiliations. Returns an empty array if no
   *   affiliations are found.
   *
   * @todo Pluralize!
   */
  public function getAffiliation() {
    if (!isset($this->affiliation)) {
      $this->setAffiliation();
    }
    return $this->affiliation;
  }

  /**
   * Sets the affiliations from the Shibboleth session.
   *
   * @todo Pluralize!
   */
  private function setAffiliation() {
    $affiliation = self::fixModRewriteIssues($this->config->get('server_variable_affiliation')) ?? self::fixModRewriteIssues('UNSCOPED_AFFILIATION');
    $this->affiliation = !empty($affiliation) ? explode(';', $affiliation) : [];
  }

  /**
   * Gets a list of groups for the user from the Shibboleth session.
   *
   * @return array
   *   Returns an array of groups. Returns an empty array if no groups are
   *   found.
   */
  public function getGroups() {
    if (!isset($this->groups)) {
      $this->setGroups();
    }
    return $this->groups;
  }

  /**
   * Sets the list of groups for the user from the Shibboleth session.
   */
  private function setGroups() {

    $groups = self::fixModRewriteIssues($this->config->get('server_variable_groups')) ?? self::fixModRewriteIssues('isMemberOf');
    $groups_arr = [];
    if (!empty($groups)) {
      $groups_arr = explode(';', $groups);
      // Remove prefixes (separated by ':') from the groups to keep just the
      // group name.
      for ($i = 0; $i < count($groups_arr); $i++) {
        $groups_arr[$i] = trim(substr($groups_arr[$i], strrpos($groups_arr[$i], ':') + 1));
      }
    }
    $this->groups = $groups_arr;
  }

  /**
   * Gets the Shibboleth login handler URL.
   *
   * The URL can be relative or absolute. It won't include the login route or
   * redirect. This configuration may be overridden in settings.php files.
   *
   * @return string
   *   Returns the Shibboleth login handler as an absolute URL. The handler URL
   *   does not contain the redirect target.
   *
   * @see $this->getLoginUrl()
   *   For the full login URL.
   */
  public function getLoginHandlerUrl() {
    return $this->config->get('shibboleth_login_handler_url');
  }

  /**
   * Gets the Shibboleth logout handler URL.
   *
   * The URL can be relative or absolute. It won't include the logout route or
   * redirect. This configuration may be overridden in settings.php files.
   *
   * @return string
   *   Returns the Shibboleth logout handler as an absolute URL. The handler URL
   *   does not contain the redirect target.
   *
   * @see $this->getLogoutUrl()
   *   For the full logout URL.
   */
  public function getLogoutHandlerUrl() {
    return $this->config->get('shibboleth_logout_handler_url');
  }

  /**
   * Gets the full login URL.
   *
   * This URL attempts to log a Shibboleth user into Drupal.
   *
   * @return \Drupal\Core\Url
   *   Returns the full login URL including the handler, target and destination
   *   paths. Format: [login-handler]?target=[target?destination=[destination]].
   *   The target is the absolute URL to the shibboleth.drupal_login route.
   */
  public function getLoginUrl() {

    // Set the destination to redirect to after successful login.
    $destination = '';
    // Use the configured redirect destination for all logins.
    if (!empty($this->config->get('login_redirect'))) {
      $destination = Url::fromUserInput($this->config->get('login_redirect'))->toString();
    }
    else {
      // The login redirect isn't set in the configuration, so use the current
      // path. The destination is local and absolute, always starting with a /.
      //
      // First, check if the current route is the Shibboleth login page. This
      // happens upon failure to log in to Drupal with a Shibboleth user.
      // If so, set the destination to the original destination or the front
      // page.
      if ($this->currentRouteMatch->getRouteName() == 'shibboleth.drupal_login') {
        $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? $this->requestStack->getCurrentRequest()->getBasePath();
      }
      else {
        // Otherwise, use the current path as the destination.
        $destination = $this->requestStack->getCurrentRequest()->getRequestUri();
      }
    }

    $destination_options = [
      // Set this just in case, to make sure the destination starts with a /.
      'absolute' => TRUE,
      'query' => [
        'destination' => $destination,
      ],
    ];

    // Shibboleth will redirect to this 'target' route after successfully
    // creating a new Shibboleth session.
    $shib_login_url = Url::fromRoute('shibboleth.drupal_login', [], $destination_options)->toString();
    $target_options = [
      'query' => [
        'target' => $shib_login_url,
      ],
    ];

    $force_https = $this->config->get('force_https_on_login');
    if ($force_https) {
      $target_options['https'] = TRUE;
      if (empty($_SERVER['HTTPS'])) {
        $target_options['absolute'] = TRUE;
      }
    }

    $login_handler = $this->getLoginHandlerUrl();
    $login_url = '';
    // The login handler is an absolute URL.
    if (parse_url($login_handler, PHP_URL_HOST)) {
      $login_url = Url::fromUri($login_handler, $target_options);
    }
    else {
      // Otherwise, the login handler is local.
      $login_url = Url::fromUserInput($login_handler, $target_options);
    }
    return $login_url;
  }

  /**
   * Gets the URL for logging into Shibboleth, but not the Drupal site.
   *
   * @return \Drupal\Core\Url
   *   Returns an absolute URL including the handler and redirect target.
   *   Format: [login-handler]?target=[target].
   */
  public function getAuthenticateUrl() {

    // The target URL to redirect to after logging into Shibboleth.
    $target = $this->requestStack->getCurrentRequest()->getUri();
    $target_options = [
      'absolute' => TRUE,
      'query' => [
        'target' => $target,
      ],
    ];

    $login_handler = $this->getLoginHandlerUrl();
    $authenticate_url = '';
    // The login handler is an absolute URL.
    if (parse_url($login_handler, PHP_URL_HOST)) {
      $authenticate_url = Url::fromUri($login_handler, $target_options);
    }
    else {
      // Otherwise, the login handler is local.
      $authenticate_url = Url::fromUserInput($login_handler, $target_options);
    }

    return $authenticate_url;
  }

  /**
   * Gets the Shibboleth logout URL.
   *
   * @return \Drupal\Core\Url
   *   Returns an absolute URL including the Shibboleth logout handler, which
   *   will destroy* the Shibboleth session, and a 'return' key which is the
   *   absolute URL to return to after logging out of Shibboleth.
   *
   * @see \Drupal\shibboleth\Controller\LogoutController->logout()
   *   For details about destroying Shibboleth sessions.
   */
  public function getLogoutUrl() {

    // Set the destination to redirect to after successful logout.
    $destination = '';

    // If set, use the configured redirect destination for all logouts.
    if (!empty($this->config->get('logout_redirect'))) {
      $destination = Url::fromUserInput($this->config->get('logout_redirect'))->toString();
    }
    else {
      // The logout redirect isn't set in the configuration, so use the current
      // path. The destination is local and absolute, always starting with a /.
      //
      // First, check if the current route is the Shibboleth logout page.
      // If so, set the destination to the original destination or the front
      // page.
      if ($this->currentRouteMatch->getRouteName() == 'shibboleth.drupal_logout') {
        $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? $this->requestStack->getCurrentRequest()->getBasePath();
      }
      else {
        // Otherwise, use the current path as the destination.
        // First, remove 'check_logged_in' key from the query string if found.
        // That key will cause an error about cookies after logout.
        $current_request = $this->requestStack->getCurrentRequest();
        $query = $current_request->query->all();
        unset($query['check_logged_in']);
        $destination = Url::fromUserInput($current_request->getPathInfo(), ['query' => $query])->toString();
      }
    }

    $destination_options = [
      // Set this just in case, to make sure the destination starts with a /.
      'absolute' => TRUE,
      'query' => [
        'destination' => $destination,
      ],
    ];

    // Shibboleth will redirect to this 'return' route after ending the
    // Shibboleth session.
    $shib_logout_url = Url::fromRoute('shibboleth.drupal_logout', [], $destination_options)->toString();
    $return_options = [
      'query' => [
        'return' => $shib_logout_url,
      ],
    ];

    $force_https = $this->config->get('force_https_on_login');
    if ($force_https) {
      $return_options['https'] = TRUE;
      if (empty($_SERVER['HTTPS'])) {
        $return_options['absolute'] = TRUE;
      }
    }

    $logout_handler = $this->getLogoutHandlerUrl();
    $logout_url = '';
    // The logout handler is an absolute URL.
    if (parse_url($logout_handler, PHP_URL_HOST)) {
      $logout_url = Url::fromUri($logout_handler, $return_options);
    }
    else {
      // Otherwise, the logout handler is local.
      $logout_url = Url::fromUserInput($logout_handler, $return_options);
    }
    return $logout_url;
  }

  /**
   * Gets the renderable login link.
   *
   * @return \Drupal\Core\GeneratedLink
   *   Returns a renderable link.
   */
  public function getLoginLink() {

    $link_text = $this->config->get('login_link_text');
    $login_url = $this->getLoginUrl();
    return Link::fromTextAndUrl($link_text, $login_url)->toString();
  }

  /**
   * Gets the renderable logout link.
   *
   * @return \Drupal\Core\GeneratedLink
   *   Returns a renderable link.
   */
  public function getLogoutLink() {

    $link_text = $this->config->get('logout_link_text');
    $logout_url = $this->getLogoutUrl();
    $link = Link::fromTextAndUrl($link_text, $logout_url)->toString();
    return $link;
  }

  /**
   * Gets the value of the Shibboleth attribute.
   *
   * Checks various formats of the attribute name to account for modifications
   * by mod_rewrite.
   *
   * @param string $var
   *   The Shibboleth session attribute name.
   *
   * @return string|null
   *   Returns the value of the Shibboleth attribute. Returns NULL if the
   *   attribute was not found
   *
   * @todo Legacy function. Rename to getAttribute() or something.
   */
  protected static function fixModRewriteIssues(string $var) {

    if (!$var) {
      return NULL;
    }
    // foo-bar.
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    // FOO-BAR.
    $var = strtoupper($var);
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    // REDIRECT_foo_bar.
    $var = "REDIRECT_" . str_replace('-', '_', $var);
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    // HTTP_FOO_BAR.
    $var = strtoupper($var);
    $var = preg_replace('/^REDIRECT/', 'HTTP', $var);
    if (array_key_exists($var, $_SERVER)) {
      return $_SERVER[$var];
    }

    return NULL;
  }

}
