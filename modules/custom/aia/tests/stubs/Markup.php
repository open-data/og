<?php

declare(strict_types=1);

namespace Drupal\Core\Render;

/**
 * Minimal Markup stand-in so unit tests can run without Drupal core.
 */
final class Markup implements \Stringable {

  public function __construct(private readonly string $string) {}

  /**
   * Creates markup from a string.
   */
  public static function create(mixed $string): self {
    return new self((string) $string);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return $this->string;
  }

}
