<?php

declare(strict_types = 1);

namespace Drupal\helfi_tunnistamo\Plugin\OpenIDConnectClient;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Url;
use Drupal\helfi_tunnistamo\Event\RedirectUrlEvent;
use Drupal\openid_connect\Plugin\OpenIDConnectClientBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Implements OpenID Connect Client plugin for Tunnistamo.
 *
 * @OpenIDConnectClient(
 *   id = "tunnistamo",
 *   label = @Translation("Tunnistamo")
 * )
 */
final class Tunnistamo extends OpenIDConnectClientBase {

  /**
   * Testing environment address.
   *
   * @var string
   */
  public const TESTING_ENVIRONMENT = 'https://api.hel.fi/sso-test';

  /**
   * Production environment address.
   *
   * @var string
   */
  public const PRODUCTION_ENVIRONMENT = 'https://api.hel.fi/sso';

  /**
   * Whether to send silent authentication or not.
   *
   * @var bool
   */
  private bool $silentAuthentication = FALSE;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  private EventDispatcherInterface $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->eventDispatcher = $container->get('event_dispatcher');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() : array {
    return [
      'is_production' => FALSE,
      'client_scopes' => 'openid,email',
      'auto_login' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * Whether 'auto_login' setting is enabled or not.
   *
   * @return bool
   *   TRUE if we should auto login.
   */
  public function autoLogin() : bool {
    return (bool) $this->configuration['auto_login'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRedirectUrl(
    array $route_parameters = [],
    array $options = []
  ): Url {
    $url = parent::getRedirectUrl($route_parameters, $options);
    /** @var \Drupal\helfi_tunnistamo\Event\RedirectUrlEvent $urlEvent */
    $urlEvent = $this->eventDispatcher->dispatch(new RedirectUrlEvent(
      $url,
      $this->requestStack->getCurrentRequest()
    ));
    return $urlEvent->getRedirectUrl();
  }

  /**
   * Attempt to authenticate silently without prompt.
   *
   * @return $this
   *   The self.
   */
  public function setSilentAuthentication() : self {
    $this->silentAuthentication = TRUE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  protected function getUrlOptions(
    string $scope,
    GeneratedUrl $redirect_uri
  ): array {
    $options = parent::getUrlOptions($scope, $redirect_uri);

    if ($this->silentAuthentication) {
      $options['query'] += [
        'prompt' => 'none',
      ];
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoints() : array {
    $base = $this->isProduction() ?
      self::PRODUCTION_ENVIRONMENT :
      self::TESTING_ENVIRONMENT;

    return [
      'authorization' => sprintf('%s/openid/authorize/', $base),
      'token' => sprintf('%s/openid/token/', $base),
      'userinfo' => sprintf('%s/openid/userinfo/', $base),
    ];
  }

  /**
   * Checks whether we're operating on production environment.
   *
   * @return bool
   *   TRUE if we're operating on production environment.
   */
  public function isProduction() : bool {
    return (bool) $this->configuration['is_production'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state
  )  : array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['is_production'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use production environment'),
      '#default_value' => $this->isProduction(),
    ];

    $form['auto_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto login on 403 pages'),
      '#default_value' => $this->configuration['auto_login'],
    ];

    $form['client_scopes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client scopes'),
      '#description' => $this->t('A comma separated list of client scopes.'),
      '#default_value' => $this->configuration['client_scopes'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getClientScopes() : array {
    $scopes = $this->configuration['client_scopes'];

    if (!$scopes) {
      return ['openid', 'email', 'ad_groups'];
    }
    return explode(',', $this->configuration['client_scopes']);
  }

}
