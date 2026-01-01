<?php

namespace Drupal\shibboleth_path\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;
use Drupal\shibboleth_path\Entity\ShibbolethPathRule;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Defines an HTTP middleware service to implement Shibboleth-based path access.
 */
class ShibbolethPathMiddleware implements HttpKernelInterface {

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $pathRuleStorage;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *  The decorated kernel.
   * @param \Drupal\shibboleth\Authentication\ShibbolethAuthManager $shibbolethAuthManager
   *  The Shibboleth authentication manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *  The entity type manager interface.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *  The config factory.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected ShibbolethAuthManager $shibbolethAuthManager,
    protected LoggerChannelFactoryInterface $logger,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->pathRuleStorage = $entity_type_manager->getStorage('shibboleth_path_rule');
    $this->config = $config_factory->get('shibboleth_path.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {

    // Only act on main requests.
    if ($type !== self::MAIN_REQUEST) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $path = $request->getPathInfo();

    // Skip Shibboleth endpoints and login handlers.
    if (
      str_starts_with($path, '/Shibboleth.sso') ||
      str_starts_with($path, '/Login') ||
      str_starts_with($path, '/system/') ||
      str_starts_with($path, '/core/')
    ) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    // Check whether this path is protected.
    $best_matches_only = $this->config->get('enforcement') == 'permissive';
    if ($matching_rules = $this->pathRuleStorage->getMatchingRules($path, $best_matches_only)) {

      // Check for active Shibboleth session.
      if (empty($_SERVER['Shib-Session-ID'])) {

        // Preserve return destination.
        $destination = $request->getUri();

        $login_url = $this->shibbolethAuthManager->getLoginHandlerUrl()
          . '?target=' . rawurlencode($destination);

        // Not sure if the Cache-Control header is necessary.
        return new RedirectResponse($login_url, 302, ['Cache-Control' => 'no-store']);
        // return new RedirectResponse($login_url, 302);
      }
      // Check if additional restrictions are met.
      elseif (!$this->sessionMeetsPathCriteria($matching_rules)) {
        $this->logger->get('access denied')->warning('Shibboleth path rule(s) denied access for @shib_user to path @path.', [
          '@shib_user' => $this->shibbolethAuthManager->getTargetedId(),
          '@path' => $path,
          'hostname' => $request->getClientIp()
        ]);
        // Return a subrequest to render the standard Access denied content.
        $subrequest = Request::create(
          '/system/403',
          'GET',
          [],
          $request->cookies->all(),
          [],
          $request->server->all(),
        );
        return $this->httpKernel->handle(
          $subrequest,
          HttpKernelInterface::SUB_REQUEST,
          false
        );
      }
    }
    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * @param ShibbolethPathRule[] $rules
   *
   * @return bool
   */
  private function sessionMeetsPathCriteria(array $rules): bool {
    $shib_groups = $this->shibbolethAuthManager->getGroups();
    $shib_affiliations = $this->shibbolethAuthManager->getAffiliation();
    foreach ($rules as $rule) {
      if (!empty($rule->getCriteria())) {
        if ($rule->getCriteriaType() == 'groups') {
          if (array_intersect($shib_groups, $rule->getCriteria())) {
            return TRUE;

          }
        }
        elseif ($rule->getCriteriaType() == 'affiliation') {
          if (array_intersect($shib_affiliations, $rule->getCriteria())) {
            return TRUE;
          }
        }
      }
    }
    return TRUE;
  }
}
