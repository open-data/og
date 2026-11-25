<?php

declare(strict_types=1);

namespace Drupal\Tests\aia\Unit;

use Drupal\aia\SurveyRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests localized bundled survey data handling.
 */
#[CoversClass(SurveyRepository::class)]
final class SurveyRepositoryTest extends TestCase {

  /**
   * Tests localization and fallback behavior.
   */
  public function testLocalizationAndFallback(): void {
    $text = [
      'default' => 'Assessment',
      'fr' => 'Évaluation',
    ];
    self::assertSame('Évaluation', SurveyRepository::localize($text, 'fr'));
    self::assertSame('Assessment', SurveyRepository::localize($text, 'en'));
    self::assertSame('Assessment', SurveyRepository::localize($text, 'es'));
    self::assertSame('Plain text', SurveyRepository::localize('Plain text', 'fr'));
    self::assertSame('', SurveyRepository::localize(NULL, 'fr'));
  }

  /**
   * Tests requirement paragraphs, lists, and links.
   */
  public function testRequirementParagraphsListsAndLinks(): void {
    $sections = SurveyRepository::formatRequirementText(
      'Introduction with <a href="https://example.com">link</a>.'
      . "\n\nFirst item\nSecond item",
    );
    self::assertCount(1, $sections);
    self::assertStringContainsString('<a href=', (string) $sections[0]['title']);
    self::assertSame(
      ['First item', 'Second item'],
      array_map('strval', $sections[0]['list']),
    );
  }

  /**
   * Tests requirement grouping and unsafe markup filtering.
   */
  public function testRequirementGroupsAndScriptStripping(): void {
    self::assertSame([], SurveyRepository::formatRequirementText(''));
    $sections = SurveyRepository::formatRequirementText(
      "Notice\n\nKeep this\n<script>alert(1)</script>",
    );
    self::assertSame('Notice', (string) $sections[0]['title']);
    self::assertSame(['Keep this', 'alert(1)'], array_map('strval', $sections[0]['list']));
  }

}
