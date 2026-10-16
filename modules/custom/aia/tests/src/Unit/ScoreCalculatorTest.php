<?php

declare(strict_types=1);

namespace Drupal\Tests\aia\Unit;

use Drupal\aia\ScoreCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests upstream-compatible score calculations.
 */
#[CoversClass(ScoreCalculator::class)]
final class ScoreCalculatorTest extends TestCase {

  /**
   * Calculator under test.
   */
  private ScoreCalculator $calculator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->calculator = new ScoreCalculator();
  }

  /**
   * Tests embedded and checkbox values.
   */
  public function testEmbeddedAndCheckboxValues(): void {
    self::assertSame(3, $this->calculator->value('item1-3'));
    self::assertSame(0, $this->calculator->value('item1'));
    self::assertSame(5, $this->calculator->value(['item1-2', 'item2-3']));
    self::assertSame(0, $this->calculator->value(NULL));
    self::assertSame(4, $this->calculator->value(4));
  }

  /**
   * Tests maximum score calculation.
   */
  public function testMaximumScores(): void {
    self::assertSame(3, $this->calculator->maximum([
      'type' => 'radiogroup',
      'choices' => [
        ['value' => 'item1-1'],
        ['value' => 'item2-3'],
      ],
    ]));
    self::assertSame(4, $this->calculator->maximum([
      'type' => 'checkbox',
      'choices' => [
        ['value' => 'item1-1'],
        ['value' => 'item2-3'],
      ],
    ]));
  }

  /**
   * Tests score classification from source names.
   */
  public function testScoreTypeFromQuestionAndPanelNames(): void {
    self::assertSame(ScoreCalculator::RAW_SCORE, $this->calculator->scoreType([
      'name' => 'impact1-RS',
      'type' => 'radiogroup',
    ]));
    self::assertSame(ScoreCalculator::MITIGATION_SCORE, $this->calculator->scoreType([
      'name' => 'fairnessDesign1-MS',
      'type' => 'checkbox',
    ]));
    self::assertSame(ScoreCalculator::NOT_SCORED, $this->calculator->scoreType([
      'name' => 'projectDetailsTitle',
      'type' => 'text',
      '_panel' => ['name' => 'projectDetailsPanel-NS'],
    ]));
    self::assertFalse($this->calculator->isScoreable([
      'name' => 'risk-RS',
      'type' => 'text',
    ]));
    self::assertTrue($this->calculator->isScoreable([
      'name' => 'risk-RS',
      'type' => 'radiogroup',
    ]));
  }

  /**
   * Tests mitigation deduction and impact level calculation.
   */
  public function testMitigationDeductionAndImpactLevel(): void {
    $questions = [
      [
        'type' => 'radiogroup',
        'name' => 'risk-RS',
        'choices' => [['value' => 'item1-100']],
      ],
      [
        'type' => 'checkbox',
        'name' => 'mitigation-MS',
        'choices' => [
          ['value' => 'item1-10'],
          ['value' => 'item2-10'],
        ],
      ],
      [
        'type' => 'text',
        'name' => 'unscored-free-text-RS',
      ],
    ];
    $score = $this->calculator->calculate($questions, [
      'risk-RS' => 'item1-100',
      'mitigation-MS' => ['item1-10'],
      'unscored-free-text-RS' => 'not-a-choice-999',
    ]);

    self::assertSame(100, $score['raw']);
    self::assertSame(10, $score['mitigation']);
    self::assertSame(85, $score['total']);
    self::assertSame(4, $score['level']);
  }

  /**
   * Mitigation below 80% of half the branch maximum does not reduce raw risk.
   */
  public function testNoDeductionBelowMitigationThreshold(): void {
    $questions = [
      [
        'type' => 'radiogroup',
        'name' => 'risk-RS',
        'choices' => [['value' => 'item1-100']],
      ],
      [
        'type' => 'checkbox',
        'name' => 'mitigation-MS',
        'choices' => [
          ['value' => 'item1-10'],
          ['value' => 'item2-10'],
        ],
      ],
    ];
    $score = $this->calculator->calculate($questions, [
      'risk-RS' => 'item1-100',
      'mitigation-MS' => ['item1-10'],
    ]);
    // Half-max mitigation is 10; 80% of that is 8. Score 10 deducts.
    self::assertSame(85, $score['total']);

    $below = $this->calculator->calculate($questions, [
      'risk-RS' => 'item1-100',
      'mitigation-MS' => [],
    ]);
    self::assertSame(100, $below['raw']);
    self::assertSame(0, $below['mitigation']);
    self::assertSame(100, $below['total']);
  }

  /**
   * Tests each impact-level boundary.
   */
  #[DataProvider('impactLevelProvider')]
  public function testImpactLevelBoundaries(int $raw, int $expectedLevel): void {
    $questions = [
      [
        'type' => 'radiogroup',
        'name' => 'risk-RS',
        'choices' => [['value' => 'item1-100']],
      ],
      [
        'type' => 'radiogroup',
        'name' => 'mitigation-MS',
        'choices' => [['value' => 'item1-100']],
      ],
    ];
    $score = $this->calculator->calculate($questions, [
      'risk-RS' => 'item1-' . $raw,
    ]);
    self::assertSame($raw, $score['total']);
    self::assertSame($expectedLevel, $score['level']);
  }

  /**
   * Section keys and substring totals match the public Results.vue algorithm.
   */
  public function testPublicAppSectionScoring(): void {
    $questions = [
      [
        'type' => 'checkbox',
        'name' => 'decisionSector1',
        'choices' => [
          ['value' => 'item1-1'],
          ['value' => 'item2-1'],
          ['value' => 'item3-1'],
        ],
        '_page' => ['title' => ['default' => 'About the Decision', 'fr' => 'Au sujet de la décision']],
        '_panel' => ['name' => 'decisionSectorPanel-RS', 'title' => ['default' => 'Sectors', 'fr' => 'Secteurs']],
      ],
      [
        'type' => 'radiogroup',
        'name' => 'impact3',
        'choices' => [['value' => 'item1-4']],
        '_page' => ['title' => ['default' => 'Impact Assessment', 'fr' => 'Évaluation des incidences']],
        '_panel' => ['name' => 'impactPanel-RS', 'title' => ['default' => 'Impact', 'fr' => 'Incidence']],
      ],
      [
        'type' => 'radiogroup',
        'name' => 'impact4A',
        'choices' => [['value' => 'item1-2']],
        '_page' => ['title' => ['default' => 'Impact Assessment', 'fr' => 'Évaluation des incidences']],
        '_panel' => ['name' => 'impactPanel-RS', 'title' => ['default' => 'Impact', 'fr' => 'Incidence']],
      ],
      [
        'type' => 'checkbox',
        'name' => 'fairnessDesign10',
        'choices' => [['value' => 'item1-2']],
        '_page' => ['title' => ['default' => 'Procedural Fairness', 'fr' => 'Équité procédurale']],
        '_panel' => ['name' => 'fairnessDesignPanel-MS', 'title' => ['default' => 'Design', 'fr' => 'Conception']],
      ],
    ];

    self::assertSame('decisionSector', $this->calculator->sectionKey('decisionSector1'));
    self::assertSame('impact', $this->calculator->sectionKey('impact3'));
    self::assertSame('impactA', $this->calculator->sectionKey('impact4A'));
    self::assertSame('fairnessDesign0', $this->calculator->sectionKey('fairnessDesign10', TRUE));

    $answers = [
      'decisionSector1' => ['item1-1', 'item2-1', 'item3-1'],
      'impact3' => 'item1-4',
    ];
    $decision = $this->calculator->scoreBySection($questions, $answers, 'decisionSector');
    self::assertSame(1, $decision['questions']);
    self::assertSame(3, $decision['score']);
    self::assertSame(3, $decision['maximum']);

    $impact = $this->calculator->scoreBySection($questions, $answers, 'impact');
    self::assertSame(2, $impact['questions']);
    self::assertSame(4, $impact['score']);
    self::assertSame(6, $impact['maximum']);

    $breakdowns = $this->calculator->areaBreakdowns($questions, $answers, 'en');
    self::assertCount(2, $breakdowns['risk']['items']);
    self::assertSame('About the Decision - Sectors', $breakdowns['risk']['items'][0]['area']);
    self::assertSame(3, $breakdowns['risk']['items'][0]['score']);
    self::assertSame('Impact Assessment - Impact', $breakdowns['risk']['items'][1]['area']);
    self::assertSame(4, $breakdowns['risk']['items'][1]['score']);
    self::assertSame(3, $breakdowns['risk']['totals']['questions']);
    self::assertSame([], $breakdowns['mitigation']['items']);
  }

  /**
   * Upstream 25/50/75% cut-points against the raw maximum.
   */
  public static function impactLevelProvider(): array {
    return [
      'level 1 at 25%' => [25, 1],
      'level 2 just above 25%' => [26, 2],
      'level 2 at 50%' => [50, 2],
      'level 3 just above 50%' => [51, 3],
      'level 3 at 75%' => [75, 3],
      'level 4 just above 75%' => [76, 4],
    ];
  }

}
