<?php

declare(strict_types=1);

namespace Drupal\aia;

/**
 * Evaluates the small condition language used by the source questionnaire.
 */
final class ConditionEvaluator {

  /**
   * Determines whether an element should be visible.
   */
  public function evaluate(?string $expression, array $answers): bool {
    if ($expression === NULL || trim($expression) === '') {
      return TRUE;
    }

    return $this->evaluateExpression(trim($expression), $answers);
  }

  /**
   * Recursively evaluates boolean operators and comparisons.
   */
  private function evaluateExpression(string $expression, array $answers): bool {
    $expression = $this->stripOuterParentheses(trim($expression));

    $parts = $this->splitTopLevel($expression, 'or');
    if (count($parts) > 1) {
      foreach ($parts as $part) {
        if ($this->evaluateExpression($part, $answers)) {
          return TRUE;
        }
      }
      return FALSE;
    }

    $parts = $this->splitTopLevel($expression, 'and');
    if (count($parts) > 1) {
      foreach ($parts as $part) {
        if (!$this->evaluateExpression($part, $answers)) {
          return FALSE;
        }
      }
      return TRUE;
    }

    return $this->evaluateComparison($expression, $answers);
  }

  /**
   * Evaluates equality and membership comparisons.
   */
  private function evaluateComparison(string $expression, array $answers): bool {
    $pattern = '/^\{([A-Za-z0-9_-]+)\}\s*(=|!=|contains)\s*(.+)$/i';
    if (!preg_match($pattern, trim($expression), $matches)) {
      return FALSE;
    }

    $actual = $answers[$matches[1]] ?? NULL;
    $expected = $this->parseLiteral(trim($matches[3]));
    $operator = strtolower($matches[2]);

    if ($operator === 'contains') {
      if (is_array($actual)) {
        return in_array($expected, $actual, TRUE);
      }
      return is_string($actual) && str_contains($actual, (string) $expected);
    }

    $equal = is_array($actual)
      ? in_array($expected, $actual, TRUE)
      : (string) $actual === (string) $expected;

    return $operator === '!=' ? !$equal : $equal;
  }

  /**
   * Converts a quoted string or one-item source array to a scalar.
   */
  private function parseLiteral(string $literal): string {
    if (str_starts_with($literal, '[') && str_ends_with($literal, ']')) {
      $decoded = json_decode($literal, TRUE);
      if (is_array($decoded) && $decoded !== []) {
        return (string) reset($decoded);
      }
    }

    if (
      (str_starts_with($literal, '"') && str_ends_with($literal, '"')) ||
      (str_starts_with($literal, "'") && str_ends_with($literal, "'"))
    ) {
      return stripcslashes(substr($literal, 1, -1));
    }

    return $literal;
  }

  /**
   * Splits on an operator only when outside quotes and parentheses.
   */
  private function splitTopLevel(string $expression, string $operator): array {
    $parts = [];
    $start = 0;
    $depth = 0;
    $quote = NULL;
    $length = strlen($expression);
    $needle = ' ' . $operator . ' ';
    $needleLength = strlen($needle);

    for ($index = 0; $index < $length; $index++) {
      $character = $expression[$index];
      if ($quote !== NULL) {
        if ($character === $quote && ($index === 0 || $expression[$index - 1] !== '\\')) {
          $quote = NULL;
        }
        continue;
      }
      if ($character === '"' || $character === "'") {
        $quote = $character;
        continue;
      }
      if ($character === '(') {
        $depth++;
        continue;
      }
      if ($character === ')') {
        $depth--;
        continue;
      }
      if ($depth === 0 && strcasecmp(substr($expression, $index, $needleLength), $needle) === 0) {
        $parts[] = trim(substr($expression, $start, $index - $start));
        $index += $needleLength - 1;
        $start = $index + 1;
      }
    }

    if ($parts === []) {
      return [$expression];
    }
    $parts[] = trim(substr($expression, $start));
    return $parts;
  }

  /**
   * Removes balanced parentheses that wrap an entire expression.
   */
  private function stripOuterParentheses(string $expression): string {
    while (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
      $depth = 0;
      $balanced = TRUE;
      $length = strlen($expression);
      for ($index = 0; $index < $length; $index++) {
        if ($expression[$index] === '(') {
          $depth++;
        }
        elseif ($expression[$index] === ')') {
          $depth--;
          if ($depth === 0 && $index < $length - 1) {
            $balanced = FALSE;
            break;
          }
        }
      }
      if (!$balanced || $depth !== 0) {
        break;
      }
      $expression = trim(substr($expression, 1, -1));
    }
    return $expression;
  }

}
