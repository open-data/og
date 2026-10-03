<?php

declare(strict_types=1);

namespace Drupal\aia\Plugin\LanguageNegotiation;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\language\Attribute\LanguageNegotiation;
use Drupal\language\LanguageNegotiationMethodBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sets English from /aia and French from /eia.
 */
#[LanguageNegotiation(
  id: LanguageNegotiationAiaPath::METHOD_ID,
  name: new TranslatableMarkup('AIA / EIA path'),
  types: [
    LanguageInterface::TYPE_INTERFACE,
    LanguageInterface::TYPE_URL,
  ],
  weight: -20,
  description: new TranslatableMarkup('Determines the language from /aia (English) and /eia (French).'),
)]
final class LanguageNegotiationAiaPath extends LanguageNegotiationMethodBase {

  /**
   * The language negotiation method id.
   */
  public const METHOD_ID = 'aia-path';

  /**
   * {@inheritdoc}
   */
  public function getLangcode(?Request $request = NULL) {
    if ($request === NULL) {
      return NULL;
    }

    $parts = explode('/', trim($request->getPathInfo(), '/'));
    if (isset($parts[0]) && in_array($parts[0], ['en', 'fr'], TRUE)) {
      array_shift($parts);
    }

    return match ($parts[0] ?? '') {
      'aia' => 'en',
      'eia' => 'fr',
      default => NULL,
    };
  }

}
