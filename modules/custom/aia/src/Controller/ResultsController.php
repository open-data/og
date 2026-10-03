<?php

declare(strict_types=1);

namespace Drupal\aia\Controller;

use Drupal\aia\Form\ResetAssessmentForm;
use Drupal\aia\ScoreCalculator;
use Drupal\aia\SurveyRepository;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Displays a completed AIA assessment.
 */
final class ResultsController extends ControllerBase {

  public function __construct(
    private readonly SurveyRepository $surveyRepository,
    private readonly ScoreCalculator $scoreCalculator,
    private readonly FormBuilderInterface $resultsFormBuilder,
    private readonly LanguageManagerInterface $resultsLanguageManager,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aia.survey_repository'),
      $container->get('aia.score_calculator'),
      $container->get('form_builder'),
      $container->get('language_manager'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * Returns session-backed assessment storage.
   */
  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get('aia_assessment');
  }

  /**
   * Builds the score and grouped answer report.
   */
  public function view(): array|RedirectResponse {
    if (!$this->store()->get('completed')) {
      return new RedirectResponse(Url::fromRoute('aia.assessment')->toString());
    }

    $answers = $this->store()->get('answers') ?? [];
    $questions = $this->surveyRepository->getQuestions();
    $score = $this->scoreCalculator->calculate($questions, $answers);
    $ui = $this->surveyRepository->getUiData();
    $answerLanguage = $this->store()->get('answer_language') === 'fr' ? 'fr' : 'en';
    $currentLangcode = $this->resultsLanguageManager->getCurrentLanguage()->getId() === 'fr' ? 'fr' : 'en';
    $otherLangcode = $currentLangcode === 'fr' ? 'en' : 'fr';
    $languageLinks = [];
    $otherLanguage = $this->resultsLanguageManager->getLanguage($otherLangcode);
    if ($otherLanguage !== NULL) {
      $languageLinks[] = [
        'title' => (string) ($ui['swtchLang'] ?? ($otherLangcode === 'fr' ? 'Français' : 'English')),
        'url' => Url::fromRoute($otherLangcode === 'fr' ? 'aia.results_fr' : 'aia.results')->toString(),
        'langcode' => $otherLangcode,
      ];
    }
    $sections = [
      'project' => [
        'title' => 'Section 3.1: ' . (string) ($ui['resultSectionPD'] ?? $this->t('Project Details')),
        'items' => [],
      ],
      'risk' => [
        'title' => 'Section 3.2: ' . (string) ($ui['resultSectionRQA'] ?? $this->t('Impact Questions and Answers')),
        'items' => [],
      ],
      'mitigation' => [
        'title' => 'Section 3.3: ' . (string) ($ui['resultSectionMQA'] ?? $this->t('Mitigation Questions and Answers')),
        'items' => [],
      ],
    ];
    $areaBreakdowns = $this->scoreCalculator->areaBreakdowns($questions, $answers, $currentLangcode);
    $breakdowns = [
      'risk' => [
        'title' => '',
        'total_label' => (string) ($ui['rawRiskScore'] ?? $this->t('Raw Impact Score')),
        'headers' => [
          'area' => (string) ($ui['riskArea'] ?? $this->t('Risk Area')),
          'questions' => (string) ($ui['noOfQuestions'] ?? $this->t('No. of Questions')),
          'score' => (string) ($ui['projectScore'] ?? $this->t('Project Score')),
          'maximum' => (string) ($ui['maximumScore'] ?? $this->t('Maximum Score')),
        ],
        'items' => $areaBreakdowns['risk']['items'],
        'totals' => $areaBreakdowns['risk']['totals'],
      ],
      'mitigation' => [
        'title' => '',
        'total_label' => (string) ($ui['mitigationScore'] ?? $this->t('Mitigation Score')),
        'headers' => [
          'area' => (string) ($ui['mitigationArea'] ?? $this->t('Mitigation Area')),
          'questions' => (string) ($ui['noOfQuestions'] ?? $this->t('No. of Questions')),
          'score' => (string) ($ui['projectScore'] ?? $this->t('Project Score')),
          'maximum' => (string) ($ui['maximumScore'] ?? $this->t('Maximum Score')),
        ],
        'items' => $areaBreakdowns['mitigation']['items'],
        'totals' => $areaBreakdowns['mitigation']['totals'],
      ],
    ];

    foreach ($questions as $question) {
      $name = $question['name'] ?? '';
      if ($name === '' || !array_key_exists($name, $answers)) {
        continue;
      }
      $type = $this->scoreCalculator->scoreType($question);
      $isScoreable = $this->scoreCalculator->isScoreable($question);
      $panelName = (string) ($question['_panel']['name'] ?? '');
      if (
        $type === ScoreCalculator::NOT_SCORED &&
        !in_array($panelName, ['projectDetailsPanel-NS', 'aboutSystemPanel-NS'], TRUE)
      ) {
        continue;
      }
      $section = match ($type) {
        ScoreCalculator::RAW_SCORE => 'risk',
        ScoreCalculator::MITIGATION_SCORE => 'mitigation',
        default => 'project',
      };
      $item = [
        'name' => $name,
        'is_text' => in_array($question['type'] ?? '', ['text', 'comment'], TRUE),
        'group' => $this->groupTitle($question),
        'number' => count($sections[$section]['items']) + 1,
        'question' => $this->surveyRepository->text($question['title'] ?? $name),
        'answer_items' => $this->answerItems(
          $question,
          $answers[$name],
          $isScoreable,
        ),
      ];
      $sections[$section]['items'][] = $item;
    }

    return [
      '#theme' => 'aia_results',
      '#title' => (string) ($ui['resultTitle'] ?? $this->t('Algorithmic Impact Assessment Results')),
      '#score' => $score,
      '#ui' => $ui,
      '#answer_language' => $answerLanguage,
      '#sections' => $sections,
      '#breakdowns' => $breakdowns,
      '#requirements' => $this->surveyRepository->getRequirements($score['level']),
      '#language_links' => $languageLinks,
      '#reset_form' => $this->resultsFormBuilder->getForm(ResetAssessmentForm::class),
      '#export_url' => Url::fromRoute('aia.export')->toString(),
      '#pdf_url' => Url::fromRoute(
        $currentLangcode === 'fr' ? 'aia.pdf_fr' : 'aia.pdf',
        ['language' => $currentLangcode],
      )->toString(),
      '#restart_url' => Url::fromRoute('aia.assessment')->toString(),
      '#attached' => [
        'library' => ['aia/assessment', 'core/drupal.ajax'],
        'drupalSettings' => [
          'aia' => [
            'confirmRestart' => (string) ($ui['alertConfirmRestart'] ?? ''),
            'completed' => TRUE,
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Returns the localized panel or page heading for a question.
   */
  private function groupTitle(array $question): string {
    $page = $this->surveyRepository->text($question['_page']['title'] ?? NULL);
    $panel = $this->surveyRepository->text($question['_panel']['title'] ?? NULL);
    if ($page !== '' && $panel !== '' && $page !== $panel) {
      return $page . ' - ' . $panel;
    }
    return $panel !== '' ? $panel : $page;
  }

  /**
   * Returns localized answer rows with per-choice score modifiers.
   */
  private function answerItems(array $question, mixed $answer, bool $scoreable): array {
    $labels = [];
    foreach ($question['choices'] ?? [] as $choice) {
      $labels[(string) ($choice['value'] ?? '')] = $this->surveyRepository->text(
        $choice['text'] ?? $choice['value'] ?? '',
      );
    }
    $values = is_array($answer) ? $answer : [$answer];
    return array_map(
      fn (mixed $value): array => [
        'label' => $labels[(string) $value] ?? (string) $value,
        'points' => $scoreable ? $this->scoreCalculator->value($value) : NULL,
      ],
      $values,
    );
  }

}
