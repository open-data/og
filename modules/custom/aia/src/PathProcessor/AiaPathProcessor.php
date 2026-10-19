<?php

declare(strict_types=1);

namespace Drupal\aia\PathProcessor;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps /eia to the /aia routes and emits /eia for French URLs.
 */
final class AiaPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request): string {
    return (string) $path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], ?Request $request = NULL, ?BubbleableMetadata $bubbleable_metadata = NULL): string {
    $path = (string) $path;
    if (!$this->isLocalizedPath($path, 'aia') && !$this->isLocalizedPath($path, 'eia')) {
      return $path;
    }

    $langcode = $this->outboundLangcode($options);
    if ($langcode === 'fr') {
      $path = $this->swapPrefix($path, 'aia', 'eia');
    }
    else {
      $path = $this->swapPrefix($path, 'eia', 'aia');
    }

    // Public URLs are /aia and /eia, not /en/aia or /fr/eia.
    $options['prefix'] = '';
    return $path;
  }

  /**
   * Returns the language the outbound URL should use.
   */
  private function outboundLangcode(array $options): string {
    if (isset($options['language']) && $options['language'] instanceof LanguageInterface) {
      return $options['language']->getId() === 'fr' ? 'fr' : 'en';
    }
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL)->getId() === 'fr'
      ? 'fr'
      : 'en';
  }

  /**
   * Replaces the first path segment when it matches.
   */
  private function swapPrefix(string $path, string $from, string $to): string {
    if ($path === '/' . $from) {
      return '/' . $to;
    }
    if (str_starts_with($path, '/' . $from . '/')) {
      return '/' . $to . substr($path, strlen($from) + 1);
    }
    return $path;
  }

  /**
   * Whether the path is an AIA or EIA public path.
   */
  private function isLocalizedPath(string $path, string $prefix): bool {
    return $path === '/' . $prefix || str_starts_with($path, '/' . $prefix . '/');
  }

}
