<?php

namespace Drupal\shibboleth\Authentication;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\shibboleth\Authentication\ShibbolethSession;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the creation and destruction of Shibboleth sessions.
 */
class ShibbolethAuthManager {

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Psr\Log\LoggerChannelInterface
   */
  protected $logger;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  private $currentRouteMatch;

  /**
   * @var string
   */
  private $sessionId;

  /**
   * @var string
   */
  private $targetedId;

  /**
   * @var string
   */
  private $email;

  /**
   * @var string
   */
  private $idp;

  /**
   * @var array
   */
  private $affiliation;

  /**
   * @var array
   */
  private $groups;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private $messenger;


  /**
   * Constructor for ShibbolethAuthManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface        $config_factory
   *   The configuration factory.
   * @param \Psr\Log\LoggerInterface                          $logger
   * @param \Symfony\Component\HttpFoundation\RequestStack    $request_stack
   * @param \Drupal\Core\Session\AccountInterface             $current_user
   * @param \Drupal\Core\Messenger\MessengerInterface         $messenger
   * @param \Drupal\Core\Routing\RouteMatchInterface          $current_route_match
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger, RequestStack $request_stack, AccountInterface $current_user, MessengerInterface $messenger, RouteMatchInterface $current_route_match) {

    $this->config = $config_factory->get('shibboleth.settings');
    $this->logger = $logger;
    $this->requestStack = $request_stack;
    $this->currentRouteMatch = $current_route_match;
    $this->messenger = $messenger;

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
      // Replace the outdated 'u.washington.edu' email domain with 'uw.edu'
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
    // return $this->affiliation;
  }

  /**
   * Gets a list of groups that the user is a member of from the Shibboleth
   * session.
   *
   * @return array
   *   Returns an array of groups. Returns an empty array if no groups are found.
   */
  public function getGroups() {
    if (!isset($this->groups)) {
      $this->setGroups();
    }
    return $this->groups;
  }

  /**
   * Sets the list of groups that the user is a member of from the Shibboleth
   * session.
   */
  private function setGroups() {
    $groups = self::fixModRewriteIssues($this->config->get('server_variable_groups')) ?? self::fixModRewriteIssues('isMemberOf');

    $groups_arr = [];
    if (!empty($groups)) {
      $groups_arr = explode(';', $groups);
      // Remove prefixes (separated by ':') from the groups to keep just the
      // group name.
      for($i = 0; $i < count($groups_arr); $i++) {
        $groups_arr[$i] = trim(substr($groups_arr[$i], strrpos($groups_arr[$i], ':') + 1));
      }
    }
    $this->groups = $groups_arr;
    // return $this->groups;
  }

  // public function destroySession() {
  //
  // }

  /**
   * Gets the Shibboleth login handler URL.
   *
   * The URL can be relative or absolute. It won't include the login route or
   * redirect. This configuration may be overridden in settings.php files.
   *
   * @see $this->getLoginUrl() for the full login URL.
   *
   * @return string
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
   *   Returns the Shibboleth logout handler as either string.
   *
   * @see $this->getLogoutUrl() for the full logout URL.
   */
  public function getLogoutHandlerUrl() {
    return $this->config->get('shibboleth_logout_handler_url');
  }

  /**
   * Gets the full login URL including the handler, target and destination paths.
   *
   * Use of this path will attempt to log a Shibboleth user into Drupal.
   *
   * @return \Drupal\Core\Url
   *   Returns the full login URL
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

      // First, check if the current route is the Shibboleth login page. This
      // happens upon failure to login to Drupal with a Shibboleth user.
      // If so, set the destination to the original destination or the front page.
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
    // $auth_route = $authenticate_only ? 'shibboleth.authenticate' : 'shibboleth.drupal_login';
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
   * Gets the URL for logging into Shibboleth (but not the Drupal site) and then
   * redirecting to a path within the site.
   *
   * @return \Drupal\Core\Url
   */
  public function getAuthenticateUrl() {

    // First, check if the current route is the Shibboleth login page. This
    // happens upon failure to log in to Drupal with a Shibboleth user.
    // If so, set the destination to the original destination or the front page.
    // if ($this->currentRouteMatch->getRouteName() == 'shibboleth.drupal_login') {
    //   return $this->getLoginUrl()->toString();
    // }
    // else {
      // Set the target destination to redirect to after successful login.
      // $target = '';
      // Otherwise, use the current path as the destination.
      // Grab the base path in case the site is a subsite.
      // $base_path = $this->requestStack->getCurrentRequest()->getBasePath();
      // $target = $base_path . $this->requestStack->getCurrentRequest()->getPathInfo();
    $target = $this->requestStack->getCurrentRequest()->getUri();
    // }

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
   * The URL includes the Shibboleth logout handler, which will destroy the
   * Shibboleth session, and a 'return' key which is the full URL to return to
   * after logging out of Shibboleth.
   *
   * @return \Drupal\Core\Url
   */
  public function getLogoutUrl($destroy_session = TRUE) {

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
      // If so, set the destination to the original destination or the front page.
      if ($this->currentRouteMatch->getRouteName() == 'shibboleth.drupal_logout') {

        $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? $this->requestStack->getCurrentRequest()->getBasePath();

      }
      else {

        // Otherwise, use the current path as the destination.
        // Grab the base path in case the site is a subsite.
        // $base_path = $this->requestStack->getCurrentRequest()->getBasePath();
        // $destination = $base_path . $this->requestStack->getCurrentRequest()->getPathInfo();
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
   * Gets the renderable login link
   *
   * @return \Drupal\Core\GeneratedLink
   */
  public function getLoginLink() {

    $link_text = $this->config->get('login_link_text');
    $login_url = $this->getLoginUrl();
    return Link::fromTextAndUrl($link_text, $login_url)->toString();

  }

  /**
   * Gets the renderable logout link
   *
   * @return \Drupal\Core\GeneratedLink
   */
  public function getLogoutLink() {

    $link_text = $this->config->get('logout_link_text');
    $logout_url = $this->getLogoutUrl();
    $link = Link::fromTextAndUrl($link_text, $logout_url)->toString();
    return $link;

  }

  /**
   * Get environment variables that may have been modified by mod_rewrite.
   *
   * @param $var
   *
   * @return string|NULL
   */
  protected static function fixModRewriteIssues($var) {

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
