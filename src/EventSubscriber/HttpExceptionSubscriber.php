<?php

declare(strict_types = 1);

namespace Drupal\helfi_tunnistamo\EventSubscriber;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\helfi_tunnistamo\Plugin\OpenIDConnectClient\Tunnistamo;
use Drupal\openid_connect\OpenIDConnectSession;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Exception subscriber for handling HTML error pages.
 */
final class HttpExceptionSubscriber extends HttpExceptionSubscriberBase {

  /**
   * The OIDC storage.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface
   */
  private ConfigEntityStorageInterface $storage;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\openid_connect\OpenIDConnectSession $session
   *   The session.
   * @param \Drupal\Core\Session\AccountProxyInterface $accountProxy
   *   The account proxy.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private OpenIDConnectSession $session,
    private AccountProxyInterface $accountProxy
  ) {
    $this->storage = $entityTypeManager->getStorage('openid_connect_client');
  }

  /**
   * {@inheritdoc}
   */
  protected static function getPriority() : int {
    return 200;
  }

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats() : array {
    return ['html'];
  }

  /**
   * Gets the OIDC client.
   *
   * @return \Drupal\helfi_tunnistamo\Plugin\OpenIDConnectClient\Tunnistamo|null
   *   The tunnistamo client or null.
   */
  private function getClient() : ? Tunnistamo {
    $entities = $this->storage
      ->loadByProperties(['plugin' => 'tunnistamo']);

    /** @var \Drupal\openid_connect\Entity\OpenIDConnectClientEntity $entity */
    foreach ($entities as $entity) {
      if ($entity->getPlugin()?->autoLogin()) {
        return $entity->getPlugin();
      }
    }
    return NULL;
  }

  /**
   * Handles a 403 error for html.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to respond to.
   */
  public function on403(ExceptionEvent $event) : void {
    if (!$plugin = $this->getClient()) {
      return;
    }
    // Attempt to log in only once per request. This should prevent an
    // infinite loop in case authentication fails or user has no access
    // to current page even after logging in.
    if (
      $event->getRequest()->query->get('error') ||
      $this->accountProxy->isAuthenticated() ||
      $this->session->retrieveStateToken()
    ) {
      return;
    }
    $isFrameRequest = $event->getRequest()->headers->get('Sec-Fetch-Dest') === 'iframe';

    // Do silent authentication if site is served from an iframe.
    if ($isFrameRequest) {
      $plugin->setSilentAuthentication();
    }
    $response = $plugin->authorize(implode(' ', $plugin->getClientScopes()));
    $this->session->saveDestination();

    $event->setResponse($response);
  }

}
