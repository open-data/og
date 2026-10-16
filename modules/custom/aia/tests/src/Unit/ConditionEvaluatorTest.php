<?php

declare(strict_types=1);

namespace Drupal\Tests\aia\Unit;

use Drupal\aia\ConditionEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the questionnaire condition evaluator.
 */
#[CoversClass(ConditionEvaluator::class)]
final class ConditionEvaluatorTest extends TestCase {

  /**
   * Evaluator under test.
   */
  private ConditionEvaluator $evaluator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->evaluator = new ConditionEvaluator();
  }

  /**
   * Tests empty conditions.
   */
  public function testEmptyConditionIsVisible(): void {
    self::assertTrue($this->evaluator->evaluate(NULL, []));
    self::assertTrue($this->evaluator->evaluate('   ', []));
  }

  /**
   * Tests equality and Boolean operators.
   */
  public function testEqualityAndBooleanOperators(): void {
    $answers = ['phase' => 'item1', 'followUp' => 'yes'];
    self::assertTrue($this->evaluator->evaluate(
      '({phase} = "item1" or {phase} = "item2") and {followUp} = "yes"',
      $answers,
    ));
    self::assertFalse($this->evaluator->evaluate(
      '{phase} = "item2" and {followUp} = "yes"',
      $answers,
    ));
    self::assertTrue($this->evaluator->evaluate('{phase} != "item2"', $answers));
    self::assertFalse($this->evaluator->evaluate('{phase} != "item1"', $answers));
  }

  /**
   * Tests scalar and array membership expressions.
   */
  public function testContainsScalarAndArraySyntax(): void {
    $answers = ['drivers' => ['item2', 'item6']];
    self::assertTrue($this->evaluator->evaluate('{drivers} contains "item6"', $answers));
    self::assertTrue($this->evaluator->evaluate('{drivers} contains ["item2"]', $answers));
    self::assertFalse($this->evaluator->evaluate('{drivers} contains "item9"', $answers));
    self::assertTrue($this->evaluator->evaluate('{title} contains "AIA"', ['title' => 'The AIA tool']));
  }

  /**
   * Tests missing answer behavior.
   */
  public function testMissingAnswersAreHidden(): void {
    self::assertFalse($this->evaluator->evaluate('{phase} = "item1"', []));
    self::assertTrue($this->evaluator->evaluate('{phase} != "item1"', []));
  }

}
