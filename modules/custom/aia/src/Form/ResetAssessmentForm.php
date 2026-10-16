<?php

declare(strict_types=1);

namespace Drupal\aia\Form;

use Drupal\aia\SurveyRepository;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides result actions to load progress or restart the assessment.
 */
final class ResetAssessmentForm extends FormBase {

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected SurveyRepository $surveyRepository,
    protected ClassResolverInterface $classResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('aia.survey_repository'),
      $container->get('class_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aia_reset_assessment_form';
  }

  /**
   * Returns session-backed assessment storage.
   */
  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get('aia_assessment');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $ui = $this->surveyRepository->getUiData();
    $form['#attributes']['class'][] = 'aia-toolbar-form';
    $form['aia_import'] = [
      '#type' => 'file',
      '#title' => (string) ($ui['jsonFileUpload'] ?? $this->t('Upload JSON File')),
      '#title_display' => 'before',
      '#description' => '',
      '#accept' => '.json,application/json',
      '#attributes' => ['class' => ['aia-file-input']],
      '#label_attributes' => ['class' => ['btn', 'btn-default']],
    ];
    $form['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load progress'),
      '#submit' => ['::importAssessment'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['visually-hidden'],
        'data-aia-import-submit' => TRUE,
      ],
    ];
    $form['reset'] = [
      '#type' => 'submit',
      '#value' => (string) ($ui['startAgain'] ?? $this->t('Start Again')),
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-default'],
        'data-aia-reset' => TRUE,
      ],
    ];
    return $form;
  }

  /**
   * Loads a portable progress file using the questionnaire import path.
   */
  public function importAssessment(array &$form, FormStateInterface $form_state): void {
    $this->classResolver
      ->getInstanceFromDefinition(AiaAssessmentForm::class)
      ->importAssessment($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    foreach (['answers', 'page', 'completed', 'translations', 'answer_language'] as $key) {
      $this->store()->delete($key);
    }
    $form_state->setRedirect('aia.assessment');
  }

}
