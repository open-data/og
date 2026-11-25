<?php

declare(strict_types=1);

namespace Drupal\aia;

/**
 * Builds the one portable payload shared by all JSON download paths.
 */
final class PortableAssessmentBuilder {

  public function __construct(
    private readonly SurveyRepository $surveyRepository,
  ) {}

  public function build(
    array $answers,
    int $page,
    array $translations,
    bool $completed,
    string $answerLanguage,
    int $updatedAt,
  ): array {
    if (isset($answers['projectDetailsDepartment-NS'])) {
      $answers['projectDetailsDepartment-NS'] = $this->surveyRepository->exportDepartment(
        (string) $answers['projectDetailsDepartment-NS'],
      );
    }
    return [
      'version' => 'v1.0.1',
      'currentPage' => max(0, $page - 1),
      'data' => $answers,
      'translationsOnResult' => $translations,
      'completed' => $completed,
      'answerLanguage' => $answerLanguage === 'fr' ? 'fr' : 'en',
      'updatedAt' => $updatedAt,
    ];
  }

}
