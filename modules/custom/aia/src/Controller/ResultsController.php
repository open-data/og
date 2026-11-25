<?php

declare(strict_types=1);

namespace Drupal\aia\Controller;

use Drupal\aia\AssessmentReportBuilder;
use Drupal\aia\Form\ResetAssessmentForm;
use Drupal\aia\Form\ResultsTranslationForm;
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
    private readonly AssessmentReportBuilder $reportBuilder,
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
      $container->get('aia.assessment_report_builder'),
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
    $sections = $this->reportBuilder->build(
      $answers,
      $this->store()->get('translations') ?? [],
      $answerLanguage,
      $currentLangcode,
      FALSE,
    );
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
      '#translation_form' => $this->resultsFormBuilder->getForm(ResultsTranslationForm::class),
      '#export_url' => Url::fromRoute('aia.export')->toString(),
      '#pdf_urls' => [
        'en' => Url::fromRoute('aia.pdf', ['language' => 'en'])->toString(),
        'fr' => Url::fromRoute('aia.pdf_fr', ['language' => 'fr'])->toString(),
      ],
      '#report_problem' => [
        'title' => $currentLangcode === 'fr'
          ? 'Signaler un problème sur cette page'
          : 'Report a problem on this page',
        'url' => $currentLangcode === 'fr'
          ? 'https://www.canada.ca/fr/signaler-probleme.html'
          : 'https://www.canada.ca/en/report-problem.html',
      ],
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

}
