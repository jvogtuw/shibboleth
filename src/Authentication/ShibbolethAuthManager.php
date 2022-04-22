<?php

namespace Drupal\shibboleth\Authentication;


use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelInterface;
// use Psr\Log\LoggerChannelInterface;
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
   * @var \Drupal\shibboleth\Authentication\ShibbolethSession
   */
  // protected $shib_session;

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
   * Constructor for ShibbolethAuthManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface          $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface          $logger
   * @param \Drupal\shibboleth\Authentication\ShibbolethSession $shib_session
   *   The current Shibboleth session, if one exists.
   * @param \Symfony\Component\HttpFoundation\RequestStack      $request_stack
   * @param \Drupal\Core\Session\AccountInterface               $current_user
   * @param \Drupal\Core\Messenger\MessengerInterface           $messenger
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelInterface $logger/*, ShibbolethSession $shib_session*/, RequestStack $request_stack, AccountInterface $current_user, MessengerInterface $messenger, RouteMatchInterface $current_route_match) {
    $this->config = $config_factory->get('shibboleth.settings');
    $this->logger = $logger;
    $this->requestStack = $request_stack;
    $this->currentRouteMatch = $current_route_match;
    // $this->shib_session = $shib_session;

  }

  // public function authenticate() {
  //
  // }

  // public function getSession() {
  //   return $this->shib_session;
  // }

  public function sessionExists() {
    return !empty($this->getSessionId());
  }

  /**
   * @return string
   */
  public function getSessionId() {
    if (!isset($this->sessionId)) {
      $this->sessionId = self::fixModRewriteIssues('Shib-Session-ID');
    }
    return $this->sessionId;
  }

  /**
   * @return string
   */
  public function getTargetedId() {
    if (!isset($this->targetedId)) {
      $this->targetedId = self::fixModRewriteIssues($this->config->get('server_variable_username'));
    }
    return $this->targetedId;
  }

  /**
   * @return string
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
   * @return string
   */
  public function getIdp() {
    if (!isset($this->idp)) {
      $this->idp = self::fixModRewriteIssues('Shib-Identity-Provider');
    }
    return $this->idp;
  }

  /**
   * @todo Pluralize!
   * @return array
   */
  public function getAffiliation() {
    if (!isset($this->affiliation)) {
      $this->affiliation = $this->setAffiliation();
    }
    return $this->affiliation;
  }

  private function setAffiliation() {
    $affiliation = self::fixModRewriteIssues($this->config->get('server_variable_affiliation')) ?? self::fixModRewriteIssues('UNSCOPED_AFFILIATION');
    $this->affiliation = !empty($affiliation) ? explode(';', $affiliation) : [];
    return $this->affiliation;
  }

  /**
   * @return array
   */
  public function getGroups() {
    if (!isset($this->groups)) {
      $this->groups = $this->setGroups();
    }
    return $this->groups;
  }

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
    return $this->groups;
  }

  // public function createSession() {
  //
  // }

  // public function destroySession() {
  //
  // }

  /**
   * Gets the Shibboleth login handler URL.
   *
   * The URL can be relative or absolute. It won't include the login route or
   * redirect.
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
   * redirect.
   *
   * @see $this->getLogoutUrl() for the full logout URL.
   *
   * @return string
   */
  public function getLogoutHandlerUrl() {
    return $this->config->get('shibboleth_logout_handler_url');
  }

  public function getLoginUrl() {
    $force_https = $this->config->get('force_https_on_login');

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
        $destination = $this->requestStack->getCurrentRequest()->query->get('destination') ?? '<front>';
      }
      else {
        // Otherwise, use the current path as the destination.
        // Grab the base path in case the site is a subsite.
        $base_path = $this->requestStack->getCurrentRequest()->getBasePath();
        $destination = $base_path . $this->requestStack->getCurrentRequest()->getPathInfo();
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
    if ($force_https) {
      $target_options['https'] = TRUE;
      if (empty($_SERVER['HTTPS'])) {
        $target_options['absolute'] = TRUE;
      }
    }

    $login_handler = $this->getLoginHandlerUrl();
    $login_url = '';
    if (parse_url($login_handler, PHP_URL_HOST)) {
      $login_url = Url::fromUri($login_handler, $target_options);
    }
    else {
      $login_url = Url::fromUserInput($login_handler, $target_options);
    }
    return $login_url;
  }

  public function getLogoutUrl() {
    $logout_handler = $this->getLogoutHandlerUrl();
    $force_https = $this->config->get('force_https_on_login');

    // $redirect = $this->config->get('logout_redirect');
    //
    // if ($redirect) {
    //   $redirect = Url::fromUserInput($redirect)->toString();
    // }
    // else {
    //   // Not set, use current page.
    //   // $redirect = \Drupal::request()->getRequestUri();
    //   $redirect = $this->request_stack->getCurrentRequest()->getUri();
    // }
    //
    // if ($force_https) {
    //   $redirect = preg_replace('~^http://~', 'https://', $redirect);
    // }

    // $options = [
    //   'absolute' => TRUE,
    //   'query' => [
    //     'destination' => $redirect,
    //   ],
    // ];
    //
    // if ($force_https) {
    //   $options['https'] = TRUE;
    // }

    // This is the callback to process the Shib login with the destination for
    // the redirect when done.
    $drupal_logout_url = Url::fromRoute('user.logout')->toString();

    $options = [
      'query' => [
        'target' => $drupal_logout_url,
      ],
    ];

    if ($force_https) {
      $options['https'] = TRUE;
      if (empty($_SERVER['HTTPS'])) {
        $options['absolute'] = TRUE;
      }
    }

    // $login_url = '';
    // if (parse_url($login_handler, PHP_URL_HOST)) {
    //   $login_url = Url::fromUri($login_handler, $options);
    // }
    // else {
    //   $login_url = Url::fromUserInput($login_handler, $options);
    // }

    return Url::fromUri($logout_handler, $options);
    // return $login_url;
    // return Link::fromTextAndUrl($link_text, $login_url)->toString();
  }

  /**
   * @return \Drupal\Core\Url
   */
  public function getAuthenticateUrl() {
    $force_https = $this->config->get('force_https_on_login');


    // Use the current path as the redirect destination.
    // Grab the base path in case the site is a subsite.
    $base_path = $this->requestStack->getCurrentRequest()->getBasePath();
    $destination = $base_path . $this->requestStack->getCurrentRequest()->getPathInfo();

    $destination_options = [
      // Set this just in case, to make sure the destination starts with a /.
      'absolute' => TRUE,
      'query' => [
        'target' => $destination,
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
    if ($force_https) {
      $target_options['https'] = TRUE;
      if (empty($_SERVER['HTTPS'])) {
        $target_options['absolute'] = TRUE;
      }
    }

    $login_handler = $this->getLoginHandlerUrl();
    $authenticate_url = '';
    if (parse_url($login_handler, PHP_URL_HOST)) {
      $authenticate_url = Url::fromUri($login_handler, $destination_options);
    }
    else {
      $authenticate_url = Url::fromUserInput($login_handler, $destination_options);
    }
    return $authenticate_url;
  }

  public function getLoginLink() {
    $link_text = $this->config->get('login_link_text');
    $login_url = $this->getLoginUrl();
    return Link::fromTextAndUrl($link_text, $login_url)->toString();
  }

  public function getLogoutLink() {
    $link_text = $this->config->get('logout_link_text');
    $logout_url = $this->getLogoutUrl();
    // dpm($logout_url, 'logout url');
    $link = Link::fromTextAndUrl($link_text, $logout_url)->toString();
    // dpm($link, 'link');
    return $link;
  }
//
//   /**
//    * @var
//    */
//   protected $user;
//
//   /**
//    * @var \Drupal\Core\Entity\EntityStorageInterface
//    */
//   protected $user_store;
//
//

//
//   /**
//    * @var \Drupal\Core\Session\SessionManagerInterface
//    */
//   protected $session_manager;
//
//   /**
//    * @var \Drupal\Core\Session\AccountInterface
//    */
//   protected $current_user;
//
//   /**
//    * @var string
//    */
//   protected $error_message;
//
//   /**
//    * @var \Drupal\Core\Messenger\MessengerInterface
//    */
//   protected $messenger;
//
//   /**
//    * MySQL error code for duplicate entry.
//    */
//   const MYSQL_ER_DUP_KEY = 23000;
//
//
//   /**
//    * LoginHandler constructor.
//    *
//    * @param \Drupal\Core\Database\Connection $db
//    * @param \Drupal\Core\Config\ImmutableConfig $config
//    * @param \Drupal\Core\Config\ImmutableConfig $adv_config
//    * @param \Drupal\Core\Entity\EntityTypeManagerInterface $etm
//    * @param \Drupal\shib_auth\Login\ShibSessionVars $shib_session
//    * @param \Psr\Log\LoggerInterface $logger
//    * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
//    * @param \Drupal\Core\Session\SessionManagerInterface $session_manager
//    * @param \Drupal\Core\Session\AccountInterface $current_user
//    * @param \Drupal\Core\Messenger\MessengerInterface $messenger
//    *
//    * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
//    * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
//    */
//   public function __construct(/*Connection $db, */ImmutableConfig $config, ImmutableConfig $adv_config, EntityTypeManagerInterface $etm, ShibSessionVars $shib_session, LoggerInterface $logger/*, PrivateTempStoreFactory $temp_store_factory*/, SessionManagerInterface $session_manager, AccountInterface $current_user, MessengerInterface $messenger) {
//     // $this->db = $db;
//     $this->config = $config;
//     $this->adv_config = $adv_config;
//     $this->user_store = $etm->getStorage('user');
//     $this->shib_session = $shib_session;
//     $this->logger = $logger;
//     // $this->temp_store_factory = $temp_store_factory;
//     $this->session_manager = $session_manager;
//     $this->current_user = $current_user;
//     $this->messenger = $messenger;
//     // $this->custom_data_store = $this->temp_store_factory->get('shib_auth');
//
//     // Start Session if it does not exist yet.
//     if ($this->current_user->isAnonymous() && !isset($_SESSION['session_started'])) {
//       $_SESSION['session_started'] = TRUE;
//       $this->session_manager->start();
//     }
//   }
//
//   /**
//    * @return \Symfony\Component\HttpFoundation\RedirectResponse
//    */
//   public function shibLogin() {
//
//     try {
//       $user_registered = FALSE;
//       // Register new user if user does not exist.
//       if (!$this->checkUserExists()) {
//
//         // Use the Shib email, if we've got it.
//         // if (!empty($this->shib_session->getEmail())) {
//         //   // Add custom Email to the session.
//         //   $this->custom_data_store->set('custom_email', $this->shib_session->getEmail());
//         // }
//
//         // Check if custom email has been set.
//         // if (!$this->custom_data_store->get('custom_email')) {
//         //   $this->custom_data_store->set('return_url', \Drupal::request()->getRequestUri());
//         //   // Redirect to email form if custom email has not been set.
//         //   $response = new RedirectResponse(Url::fromRoute('shib_auth.custom_data_form')
//         //     ->toString());
//         //   return $response;
//         // }
//         // else {
//
//         // }
//         // return FALSE;
//         // Check if there's a likely match with an existing Drupal user.
//         // $user_match = user_load_by_name($this->shib_session->getTargetedId());
//         if ($this->checkPotentialUserMatch()) {
//           throw new \Exception('Shibboleth user is not mapped to a Drupal user, however, there is a potential matching account.', 11000);
//           // Set the redirect to the Account map request form.
//           // $redirect = Url::fromRoute('shib_auth.account_map_request');
//           // echo $redirect;
//           // return new RedirectResponse($redirect->toString());
//         }
//         else {
//           $user_registered = $this->registerNewUser();
//         }
//       }
//       else {
//         $user_registered = TRUE;
//       }
//
//       if ($user_registered) {
//         $this->authenticateUser();
//         return FALSE;
//       }
//
//     }
//     catch (\Exception $e) {
//       // Log the error to Drupal log messages.
//       $this->logger->error($e);
//
//       // Shibboleth user not mapped to a Drupal user, but a potential match
//       // exists.
//       if ($e->getCode() == 11000) {
//         // $this->setErrorMessage(t('There was an error logging you in and we were unable to create a user for you.'));
//         // $this->messenger->addError($this->getErrorMessage());
//         return $e->getCode();
//       }
//       else {
//         $user = \Drupal::currentUser();
//         if ($user->isAuthenticated()) {
//           // Kill the drupal session.
//           // @todo - Do we need to kill the session for anonymous users, too? If so, how do we set the error message?
//           user_logout();
//         }
//
//         if ($this->getErrorMessage()) {
//           $this->messenger->addError($this->getErrorMessage());
//         }
//
//         $return_url = '';
//         if ($this->adv_config->get('url_redirect_logout')) {
//           $return_url = '?return=' . $this->adv_config->get('url_redirect_logout');
//         }
//         // Redirect to shib logout url.
//         // @todo do we really want this?
//         return new TrustedRedirectResponse($this->config->get('shibboleth_logout_handler_url') . $return_url);
//       }
//     }
//     return FALSE;
//   }
//
//   /**
//    * Adds user to the shib_auth table in the database.
//    *
//    * @param bool $success
//    *
//    * @return bool
//    *
//    * @throws \Exception
//    */
//   private function registerNewUser($success = FALSE) {
//     $user_data = [
//       'name' => $this->shib_session->getTargetedId(),
//       'mail' => $this->shib_session->getEmail(),
//       'pass' => $this->genPassword(),
//       'status' => 1,
//       'shibboleth_username' => $this->shib_session->getTargetedId(),
//     ];
//
//     try {
//       // Create Drupal user.
//       $this->user = $this->user_store->create($user_data);
//       if (!$results = $this->user->save()) {
//         // Throw exception if Drupal user creation fails.
//         throw new \Exception();
//       }
//
//     }
//     catch (\Exception $e) {
//       if ($e->getCode() == self::MYSQL_ER_DUP_KEY) {
//         $this->setErrorMessage(t('There was an error creating your user. A user with your email address already exists.'));
//         throw new \Exception('Error creating new Drupal user from Shibboleth Session. Duplicate user row.');
//       }
//       else {
//         $this->setErrorMessage(t('There was an error creating your user.'));
//         throw new \Exception('Error creating new Drupal user from Shibboleth Session.');
//       }
//     }
//
//     return TRUE;
//   }
//
//   /**
//    * Finalize user login.
//    *
//    * @return bool
//    *
//    * @throws \Exception
//    */
//   private function authenticateUser() {
//     if (empty($this->user)) {
//       $this->setErrorMessage(t('There was an error logging you in.'));
//       throw new \Exception('No uid found for user when trying to initialize Drupal session.');
//     }
//     user_login_finalize($this->user);
//     return TRUE;
//   }
//
//   /**
//    * Check shib_authmap table for user, return true if user found.
//    *
//    * @return bool
//    *
//    * @throws \Exception
//    */
//   public function checkUserExists() {
//     $shib_user_lookup = $this->user_store
//       ->loadByProperties([
//         'shibboleth_username' => $this->shib_session->getTargetedId(),
//       ]);
//     $shib_user = reset($shib_user_lookup);
//     // return $users ? reset($users) : FALSE;
//     if (empty($shib_user)) {
//
//       // $this->setErrorMessage(t('There was an error logging you in.'));
//       // throw new \Exception('No Drupal user found mapped to this Shibboleth username.');
//       return FALSE;
//     }
//     $this->user = User::load($shib_user->id());
//     // $user_query = $this->db->select('shib_authmap');
//     // $user_query->fields('shib_authmap', ['id', 'uid', 'targeted_id']);
//     // $user_query->condition('targeted_id', $this->shib_session->getTargetedId());
//     // $results = $user_query->execute()->fetchAll();
//     //
//     // if (empty($results)) {
//     //   // No user found.
//     //   return FALSE;
//     // }
//     //
//     // if (count($results) > 1) {
//     //   $this->setErrorMessage(t('There was an error logging you in.'));
//     //   throw new \Exception('Multiple entries for a user exist in the shib_authmap table.');
//     // }
//
//     // $this->user = User::load($results[0]->uid);
//     //
//     // if (empty($this->user)) {
//     //   $this->setErrorMessage(t('There was an error logging you in.'));
//     //   throw new \Exception('User information exists in shib_authmap table, but Drupal user does not exist.');
//     // }
//     return TRUE;
//   }
//
//   public function checkPotentialUserMatch() {
//     $user_match = user_load_by_name($this->shib_session->getTargetedId());
//     return $user_match ?? FALSE;
//   }
//
//   /**
//    * Generate a random password for the Drupal user account.
//    *
//    * @return string
//    */
//   private function genPassword() {
//     $rand = new Random();
//     return $rand->string(30);
//   }
//
//   /**
//    * @return \Drupal\shib_auth\Login\ShibSessionVars
//    */
//   public function getShibSession() {
//     return $this->shib_session;
//   }
//
//   /**
//    *
//    */
//   private function setErrorMessage($message) {
//     $this->error_message = $message;
//   }
//
//   /**
//    *
//    */
//   private function getErrorMessage() {
//     return $this->error_message;
//   }
//
  /**
   * Get environment variables that may have been modified by mod_rewrite.
   *
   * @param $var
   *
   * @return string or null
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
