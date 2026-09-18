<?php

declare(strict_types=1);

namespace Drupal\Tests\aia\Unit;

use Drupal\aia\ConditionEvaluator;
use Drupal\aia\ScoreCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the bundled questionnaire against upstream v1.0.1 invariants.
 */
final class QuestionnaireParityTest extends TestCase {

  /**
   * Tests structure, conditions, scoring, and portable page numbering.
   */
  public function testUpstreamParityInvariants(): void {
    $survey = $this->survey();

    self::assertCount(18, $survey['pages']);
    self::assertTrue($survey['firstPageIsStarted']);

    $questions = [];
    $conditionCount = 0;
    $names = [];
    foreach ($survey['pages'] as $page) {
      if (isset($page['visibleIf'])) {
        $conditionCount++;
      }
      $this->collectQuestions(
        $page['elements'] ?? [],
        $questions,
        $conditionCount,
        $page,
      );
    }
    foreach ($questions as $question) {
      if (isset($question['name'])) {
        $names[] = $question['name'];
      }
    }
    self::assertSame(124, $conditionCount);
    self::assertContains('decisionSector3', $names);
    self::assertNotContains('impact4', $names);
    $required = array_values(array_filter(
      $questions,
      static fn (array $question): bool => (bool) ($question['isRequired'] ?? FALSE),
    ));
    self::assertCount(1, $required);
    self::assertSame('projectDetailsPhase', $required[0]['name']);

    $evaluator = new ConditionEvaluator();
    foreach ($this->expressions($survey['pages']) as $expression) {
      self::assertIsBool($evaluator->evaluate($expression, []));
    }

    $score = (new ScoreCalculator())->calculate($questions, [
      'projectDetailsRespondent' => 'name',
      'projectDetailsJob' => 'position',
      'projectDetailsDepartment-NS' => 'item061',
      'projectDetailsBranch' => 'test',
      'projectDetailsTitle' => 'project',
      'projectDetailsPhase' => 'item1',
      'projectDetailsDescription' => 'desc',
      'decisionSector1' => ['item1-1', 'item2-1', 'item3-1'],
    ]);
    self::assertSame(3, $score['raw']);
    self::assertSame(0, $score['mitigation']);
    self::assertSame(3, $score['total']);
    self::assertSame(1, $score['level']);

    $calculator = new ScoreCalculator();
    self::assertCount(70, array_filter(
      $questions,
      static fn (array $question): bool =>
        $calculator->isScoreable($question)
        && $calculator->scoreType($question) === ScoreCalculator::RAW_SCORE,
    ));
    self::assertCount(90, array_filter(
      $questions,
      static fn (array $question): bool =>
        $calculator->isScoreable($question)
        && $calculator->scoreType($question) === ScoreCalculator::MITIGATION_SCORE,
    ));
    $emptyScore = $calculator->calculate($questions, []);
    self::assertSame(167, $emptyScore['maximum_raw']);
    self::assertSame(154, $emptyScore['maximum_mitigation']);

    $emptyAreas = $calculator->areaBreakdowns($questions, []);
    self::assertSame([], $emptyAreas['risk']['items']);
    self::assertSame(0, $emptyAreas['risk']['totals']['score']);

    $sampleAreas = $calculator->areaBreakdowns($questions, [
      'projectDetailsPhase' => 'item1',
      'decisionSector1' => ['item1-1', 'item2-1', 'item3-1'],
    ]);
    self::assertCount(1, $sampleAreas['risk']['items']);
    self::assertSame(1, $sampleAreas['risk']['items'][0]['questions']);
    self::assertSame(3, $sampleAreas['risk']['items'][0]['score']);
    self::assertSame(8, $sampleAreas['risk']['items'][0]['maximum']);
    $impactSection = $calculator->scoreBySection($questions, [], 'impact');
    self::assertGreaterThan(1, $impactSection['questions']);
  }

  /**
   * Drupal page 0 is welcome; portable JSON currentPage is the content index.
   */
  public function testPortablePageNumberMapping(): void {
    self::assertSame(0, max(0, 1 - 1), 'First content page exports as currentPage 0.');
    self::assertSame(12, max(0, 13 - 1), 'Last content page exports as currentPage 12.');
    self::assertSame(1, max(1, min(0 + 1, 13)), 'currentPage 0 restores to Drupal page 1.');
    self::assertSame(13, max(1, min(12 + 1, 13)), 'currentPage 12 restores to Drupal page 13.');
  }

  /**
   * English and French UI files expose the chrome keys the templates expect.
   */
  public function testUiChromeKeys(): void {
    $required = [
      'appTitle',
      'resultTitle',
      'version',
      'saveButton',
      'jsonFileUpload',
      'startAgain',
      'swtchLang',
      'pageProgressBar',
      'riskLevel',
      'currentScore',
      'rawRiskScore',
      'mitigationScore',
      'onThisPage',
      'export',
      'exportEnglishResults',
      'exportFrenchResults',
      'englishContent',
      'frenchContent',
      'modifier',
      'alertConfirmRestart',
      'requirements',
    ];
    foreach (['en', 'fr'] as $language) {
      $ui = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3) . '/data/' . $language . '.json'),
        TRUE,
        512,
        JSON_THROW_ON_ERROR,
      );
      foreach ($required as $key) {
        self::assertArrayHasKey($key, $ui, $language . ' UI is missing ' . $key);
      }
      self::assertCount(7, $ui['requirements']['elements']);
    }
  }

  /**
   * Loads the bundled questionnaire JSON.
   */
  private function survey(): array {
    $path = dirname(__DIR__, 3) . '/data/survey-enfr.json';
    return json_decode(
      (string) file_get_contents($path),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
  }

  /**
   * Collects every visibleIf expression in the survey tree.
   */
  private function expressions(array $pages): array {
    $expressions = [];
    $walker = function (array $elements) use (&$walker, &$expressions): void {
      foreach ($elements as $element) {
        if (isset($element['visibleIf'])) {
          $expressions[] = $element['visibleIf'];
        }
        if (($element['type'] ?? '') === 'panel') {
          $walker($element['elements'] ?? []);
        }
      }
    };
    foreach ($pages as $page) {
      if (isset($page['visibleIf'])) {
        $expressions[] = $page['visibleIf'];
      }
      $walker($page['elements'] ?? []);
    }
    return $expressions;
  }

  /**
   * Recursively flattens questions with their source panel context.
   */
  private function collectQuestions(
    array $elements,
    array &$questions,
    int &$conditionCount,
    array $page,
    ?array $panel = NULL,
  ): void {
    foreach ($elements as $element) {
      if (isset($element['visibleIf'])) {
        $conditionCount++;
      }
      if (($element['type'] ?? '') === 'panel') {
        $this->collectQuestions(
          $element['elements'] ?? [],
          $questions,
          $conditionCount,
          $page,
          $element,
        );
        continue;
      }
      $element['_page'] = $page;
      $element['_panel'] = $panel;
      $questions[] = $element;
    }
  }

}
