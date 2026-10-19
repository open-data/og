<?php

declare(strict_types=1);

namespace Drupal\aia;

/**
 * Reproduces the upstream assessment scoring rules.
 */
final class ScoreCalculator {

  public const NOT_SCORED = 1;
  public const RAW_SCORE = 2;
  public const MITIGATION_SCORE = 3;

  /**
   * Calculates the complete assessment score.
   */
  public function calculate(array $questions, array $answers): array {
    $raw = 0;
    $mitigation = 0;
    $maximumRaw = 0;
    $maximumMitigation = 0;

    foreach ($questions as $question) {
      if (!$this->isScoreable($question)) {
        continue;
      }
      $type = $this->scoreType($question);
      $name = $question['name'] ?? '';
      if ($type === self::RAW_SCORE) {
        $raw += $this->value($answers[$name] ?? NULL);
        $maximumRaw += $this->maximum($question);
      }
      elseif ($type === self::MITIGATION_SCORE) {
        $mitigation += $this->value($answers[$name] ?? NULL);
        $maximumMitigation += $this->maximum($question);
      }
    }

    $total = $raw;
    // The source survey has mutually exclusive design and implementation
    // branches, so its maximum mitigation score is divided by two.
    if ($mitigation >= 0.8 * ($maximumMitigation / 2)) {
      $total = (int) round(0.85 * $raw);
    }

    $level = match (TRUE) {
      $total <= $maximumRaw * 0.25 => 1,
      $total <= $maximumRaw * 0.50 => 2,
      $total <= $maximumRaw * 0.75 => 3,
      default => 4,
    };

    return [
      'raw' => $raw,
      'mitigation' => $mitigation,
      'total' => $total,
      'level' => $level,
      'maximum_raw' => $maximumRaw,
      'maximum_mitigation' => $maximumMitigation,
    ];
  }

  /**
   * Scores every scoreable question whose name contains a section substring.
   *
   * Matches the public app getter: questionNames.filter(name => name.includes(section)).
   *
   * @return array{questions: int, score: int|float, maximum: int|float}
   */
  public function scoreBySection(array $questions, array $answers, string $section): array {
    $questionsCount = 0;
    $score = 0;
    $maximum = 0;
    foreach ($questions as $question) {
      if (!$this->isScoreable($question)) {
        continue;
      }
      $name = (string) ($question['name'] ?? '');
      if ($section === '' || !str_contains($name, $section)) {
        continue;
      }
      $questionsCount++;
      $score += $this->value($answers[$name] ?? NULL);
      $maximum += $this->maximum($question);
    }

    return [
      'questions' => $questionsCount,
      'score' => $score,
      'maximum' => $maximum,
    ];
  }

  /**
   * Builds the public results-page area tables.
   *
   * A row is emitted when the English page/panel header changes among answered
   * questions. Risk keys strip every digit (name.replace(/\d/g, "")); mitigation
   * keys strip only the first digit (name.replace(/\d/, "")).
   *
   * @return array{risk: array{items: array<int, array<string, mixed>>, totals: array<string, int|float>}, mitigation: array{items: array<int, array<string, mixed>>, totals: array<string, int|float>}}
   */
  public function areaBreakdowns(array $questions, array $answers, string $language = 'en'): array {
    $language = $language === 'fr' ? 'fr' : 'en';
    $breakdowns = [
      'risk' => ['items' => [], 'totals' => ['questions' => 0, 'score' => 0, 'maximum' => 0]],
      'mitigation' => ['items' => [], 'totals' => ['questions' => 0, 'score' => 0, 'maximum' => 0]],
    ];
    $lastHeader = [
      'risk' => '',
      'mitigation' => '',
    ];

    foreach ($questions as $question) {
      $name = (string) ($question['name'] ?? '');
      if ($name === '' || !$this->hasRecordedAnswer($answers[$name] ?? NULL)) {
        continue;
      }
      $type = $this->scoreType($question);
      if (!in_array($type, [self::RAW_SCORE, self::MITIGATION_SCORE], TRUE)) {
        continue;
      }
      $bucket = $type === self::RAW_SCORE ? 'risk' : 'mitigation';
      $headerKey = $this->areaHeader($question, 'en');
      if ($headerKey === '' || $headerKey === $lastHeader[$bucket]) {
        continue;
      }
      $lastHeader[$bucket] = $headerKey;
      $section = $this->sectionKey($name, $type === self::MITIGATION_SCORE);
      $row = $this->scoreBySection($questions, $answers, $section);
      $row['area'] = $this->areaHeader($question, $language);
      $breakdowns[$bucket]['items'][] = $row;
      $breakdowns[$bucket]['totals']['questions'] += $row['questions'];
      $breakdowns[$bucket]['totals']['score'] += $row['score'];
      $breakdowns[$bucket]['totals']['maximum'] += $row['maximum'];
    }

    return $breakdowns;
  }

  /**
   * Public-app section key derived from a question name.
   */
  public function sectionKey(string $name, bool $stripFirstDigitOnly = FALSE): string {
    $pattern = '/\d/';
    return $stripFirstDigitOnly
      ? (string) preg_replace($pattern, '', $name, 1)
      : (string) preg_replace($pattern, '', $name);
  }

  /**
   * Whether the upstream application includes this question in score totals.
   */
  public function isScoreable(array $question): bool {
    return in_array(
      $question['type'] ?? '',
      ['radiogroup', 'checkbox', 'dropdown'],
      TRUE,
    ) && $this->scoreType($question) > self::NOT_SCORED;
  }

  /**
   * Returns the score classification inherited from a question or panel.
   */
  public function scoreType(array $question): int {
    $type = $this->scoreTypeFromName((string) ($question['name'] ?? ''));
    if ($type !== 0) {
      return $type;
    }

    return $this->scoreTypeFromName((string) ($question['_panel']['name'] ?? '')) ?: self::NOT_SCORED;
  }

  /**
   * Extracts an answer's numeric suffix.
   */
  public function value(mixed $value): int|float {
    if (is_array($value)) {
      return array_sum(array_map(fn (mixed $item): int|float => $this->value($item), $value));
    }
    if (is_int($value) || is_float($value)) {
      return $value;
    }
    if (!is_string($value)) {
      return 0;
    }

    $position = strrpos($value, '-');
    if ($position === FALSE) {
      return 0;
    }
    $candidate = substr($value, $position + 1);
    return is_numeric($candidate) ? $candidate + 0 : 0;
  }

  /**
   * Finds the maximum possible score for a question.
   */
  public function maximum(array $question): int|float {
    $values = array_map(
      fn (array $choice): int|float => $this->value($choice['value'] ?? NULL),
      $question['choices'] ?? [],
    );
    if (($question['type'] ?? '') === 'checkbox') {
      return array_sum($values);
    }
    return $values === [] ? 0 : max($values);
  }

  /**
   * Infers a score type from the source naming convention.
   */
  private function scoreTypeFromName(string $name): int {
    return match (TRUE) {
      str_ends_with($name, '-RS') => self::RAW_SCORE,
      str_ends_with($name, '-MS') => self::MITIGATION_SCORE,
      str_ends_with($name, '-NS') => self::NOT_SCORED,
      default => 0,
    };
  }

  /**
   * Localized page and panel title used as a results-table area label.
   */
  private function areaHeader(array $question, string $language): string {
    $page = SurveyRepository::localize($question['_page']['title'] ?? NULL, $language);
    $panel = SurveyRepository::localize($question['_panel']['title'] ?? NULL, $language);
    if ($page !== '' && $panel !== '') {
      return $page . ' - ' . $panel;
    }
    return $panel !== '' ? $panel : $page;
  }

  /**
   * Whether the public app would include this value in getPlainData().
   */
  private function hasRecordedAnswer(mixed $value): bool {
    if ($value === NULL || $value === '') {
      return FALSE;
    }
    if (is_array($value)) {
      return $value !== [];
    }
    return TRUE;
  }

}
