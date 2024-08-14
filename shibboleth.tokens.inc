<?php

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\shibboleth\Authentication\ShibbolethAuthManager;

/**
 * Implements hook_token_info().
 */
function shibboleth_token_info() {
  $types = [];
  $types['shibboleth-session'] = [
    'name' => t('Current Shibboleth session'),
    'description' => t('Tokens for the active Shibboleth session.'),
  ];

  $tokens = [];
  $tokens['shibboleth-session']['status'] = [
    'name' => t('Session status'),
    'description' => t('Boolean value. TRUE if there is an active Shibboleth session.'),
  ];
  $tokens['shibboleth-session']['user-id'] = [
    'name' => t('Shibboleth user ID'),
    'description' => t('The current Shibboleth session user ID.'),
  ];
  $tokens['shibboleth-session']['user-email'] = [
    'name' => t('Shibboleth user email'),
    'description' => t('The current Shibboleth session email address'),
  ];
  $tokens['shibboleth-session']['user-affiliations'] = [
    'name' => t('Shibboleth user affiliations'),
    'description' => t('The current Shibboleth session user affiliations.'),
    'type' => 'array',
  ];


  $types['shibboleth'] = [
    'name' => t('Shibboleth'),
    'description' => t('Global Shibboleth tokens.'),
  ];

  $tokens['shibboleth']['login-url'] = [
    'name' => t('Login URL'),
    'description' => t('Shibboleth login URL with redirect.'),
  ];

  return [
    'types' => $types,
    'tokens' => $tokens,
  ];
}

/**
 * Implements hook_tokens().
 */
function shibboleth_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];

  if (in_array($type, ['shibboleth', 'shibboleth-session'])) {
    /** @var ShibbolethAuthManager $shib_auth_mgr */
    $shib_auth_mgr = Drupal::service('shibboleth.auth_manager');

    switch ($type) {
      case 'shibboleth':
        try {
          foreach ($tokens as $name => $original) {
            switch ($name) {
              case 'login-url':
                $replacements[$original] = $shib_auth_mgr->getLoginUrl()->toString();
                break;
            }
          }
        }
        catch (Exception $e) {
          foreach ($tokens as $name => $original) {
            $replacements[$original] = '';
          }
          Drupal::logger('shibboleth')
            ->warning(t('Shibboleth token replacement error. [@code] @message.', [
              '@code' => $e->getCode(),
              '@message' => $e->getMessage()
            ]));
        }
        break;

      case 'shibboleth-session':
        try {
          foreach ($tokens as $name => $original) {
            switch ($name) {
              case 'status':
                $replacements[$original] = $shib_auth_mgr->sessionExists();
                break;
              case 'user-id':
                $replacements[$original] = $shib_auth_mgr->getTargetedId() ?? '';
                break;
              case 'user-email':
                $replacements[$original] = $shib_auth_mgr->getEmail() ?? '';
                break;
              case 'user-affiliations':
                $affiliations = $shib_auth_mgr->getAffiliation() ?? [];
                $affiliations_names = array_combine($affiliations, $affiliations);
                $replacements[$original] = token_render_array($affiliations_names,
                  $options);
                break;
            }
          }
          if ($affiliation_tokens = \Drupal::token()->findWithPrefix($tokens,
            'user-affiliations')) {
            $affiliations = $shib_auth_mgr->getAffiliation() ?? [];
            $affiliations_names = array_combine($affiliations, $affiliations);
            $replacements += \Drupal::token()->generate('array', $affiliation_tokens, ['array' => $affiliations_names], $options, $bubbleable_metadata);
          }
        }
        catch (Exception $e) {
          foreach ($tokens as $name => $original) {
            $replacements[$original] = '';
          }
          Drupal::logger('shibboleth-session')
            ->warning(t('Shibboleth session token replacement error. [@code] @message.', [
              '@code' => $e->getCode(),
              '@message' => $e->getMessage()
            ]));
        }
        break;
    }
  }
  return $replacements;
}
