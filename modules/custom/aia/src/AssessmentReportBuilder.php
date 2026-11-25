<?php

declare(strict_types=1);

namespace Drupal\aia;

/**
 * Builds one complete bilingual answer model for web and PDF reports.
 */
final class AssessmentReportBuilder {

  public function __construct(
    private readonly SurveyRepository $surveyRepository,
    private readonly ScoreCalculator $scoreCalculator,
  ) {}

  /**
   * Builds all recorded answers in the three public report sections.
   */
  public function build(
    array $answers,
    array $translations,
    string $answerLanguage,
    string $language,
    bool $useTranslations = TRUE,
  ): array {
    $language = $language === 'fr' ? 'fr' : 'en';
    $ui = $this->surveyRepository->getUiData($language);
    $sections = [
      'project' => ['title' => 'Section 3.1: ' . (string) $ui['resultSectionPD'], 'items' => []],
      'risk' => ['title' => 'Section 3.2: ' . (string) $ui['resultSectionRQA'], 'items' => []],
      'mitigation' => ['title' => 'Section 3.3: ' . (string) $ui['resultSectionMQA'], 'items' => []],
    ];

    foreach ($this->surveyRepository->getQuestions() as $question) {
      $name = (string) ($question['name'] ?? '');
      if ($name === '' || !array_key_exists($name, $answers)) {
        continue;
      }
      $answer = $answers[$name];
      if ($answer === NULL || $answer === '' || $answer === []) {
        continue;
      }
      $isText = in_array($question['type'] ?? '', ['text', 'comment'], TRUE);
      if (
        $isText &&
        $useTranslations &&
        $language !== $answerLanguage &&
        is_scalar($translations[$name] ?? NULL)
      ) {
        $answer = (string) $translations[$name];
      }
      $scoreType = $this->scoreCalculator->scoreType($question);
      $section = match ($scoreType) {
        ScoreCalculator::RAW_SCORE => 'risk',
        ScoreCalculator::MITIGATION_SCORE => 'mitigation',
        default => 'project',
      };
      $scoreable = $this->scoreCalculator->isScoreable($question);
      $sections[$section]['items'][] = [
        'name' => $name,
        'is_text' => $isText,
        'group' => $this->groupTitle($question, $language),
        'number' => count($sections[$section]['items']) + 1,
        'question' => SurveyRepository::localize($question['title'] ?? $name, $language),
        'answer_items' => $this->answerItems($question, $answer, $scoreable, $language),
      ];
    }

    return $sections;
  }

  /**
   * Localizes selected choices while preserving free-text values.
   */
  private function answerItems(
    array $question,
    mixed $answer,
    bool $scoreable,
    string $language,
  ): array {
    $labels = [];
    foreach ($question['choices'] ?? [] as $choice) {
      $labels[(string) ($choice['value'] ?? '')] = SurveyRepository::localize(
        $choice['text'] ?? $choice['value'] ?? '',
        $language,
      );
    }
    return array_map(
      fn (mixed $value): array => [
        'label' => $labels[(string) $value] ?? (string) $value,
        'points' => $scoreable ? $this->scoreCalculator->value($value) : NULL,
      ],
      is_array($answer) ? $answer : [$answer],
    );
  }

  /**
   * Returns the localized page/panel heading.
   */
  private function groupTitle(array $question, string $language): string {
    $page = SurveyRepository::localize($question['_page']['title'] ?? NULL, $language);
    $panel = SurveyRepository::localize($question['_panel']['title'] ?? NULL, $language);
    if ($page !== '' && $panel !== '' && $page !== $panel) {
      return $page . ' – ' . $panel;
    }
    return $panel !== '' ? $panel : $page;
  }

}
