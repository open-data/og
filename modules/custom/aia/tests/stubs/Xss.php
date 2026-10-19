<?php

declare(strict_types=1);

namespace Drupal\Component\Utility;

/**
 * Minimal XSS filter stand-in so unit tests can run without Drupal core.
 */
final class Xss {

  /**
   * Strips tags that are not in the allowed list.
   */
  public static function filter(mixed $string, array $html_tags = []): string {
    $allowed = $html_tags === [] ? NULL : '<' . implode('><', $html_tags) . '>';
    return strip_tags((string) $string, $allowed);
  }

}
