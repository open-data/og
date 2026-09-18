<?php

declare(strict_types=1);

namespace Drupal\aia\Form;

use Drupal\aia\SurveyRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Captures alternate-language versions of free-text answers.
 */
final class ResultsTranslationForm extends FormBase {

  public function __construct(
    protected SurveyRepository $surveyRepository,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aia.survey_repository'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aia_results_translation_form';
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
    $answers = $this->store()->get('answers') ?? [];
    $translations = $this->store()->get('translations') ?? [];
    $answerLanguage = $this->store()->get('answer_language') === 'fr' ? 'fr' : 'en';
    $targetLanguage = $answerLanguage === 'fr' ? 'en' : 'fr';
    $targetLabel = $targetLanguage === 'fr'
      ? (string) ($ui['frenchContent'] ?? $this->t('French Content'))
      : (string) ($ui['englishContent'] ?? $this->t('English Content'));

    $form['#theme_wrappers'] = [];
    $form['translations'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    $form['status'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'aia-translation-status',
        'class' => ['visually-hidden'],
        'aria-live' => 'polite',
      ],
    ];
    foreach ($this->surveyRepository->getQuestions() as $question) {
      $name = (string) ($question['name'] ?? '');
      if (
        $name === '' ||
        !array_key_exists($name, $answers) ||
        !in_array($question['type'] ?? '', ['text', 'comment'], TRUE)
      ) {
        continue;
      }
      $form['translations'][$name] = [
        '#type' => 'textarea',
        '#title' => $targetLabel . ' :',
        '#title_display' => 'before',
        '#default_value' => $translations[$name] ?? '',
        '#rows' => 3,
        '#label_attributes' => ['class' => ['aia-lang-label']],
        '#wrapper_attributes' => ['class' => ['aia-translation-field']],
        '#attributes' => ['class' => ['aia-translation-input']],
        '#ajax' => [
          'callback' => '::saveTranslationAjax',
          'event' => 'change',
          'wrapper' => 'aia-translation-status',
          'progress' => ['type' => 'none'],
        ],
      ];
    }
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save translations'),
      '#attributes' => ['class' => ['visually-hidden']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveTranslations($form_state);
    $this->messenger()->addStatus($this->t('Result translations saved.'));
    $form_state->setRedirect('aia.results');
  }

  /**
   * Saves translation edits when a field loses focus.
   */
  public function saveTranslationAjax(array &$form, FormStateInterface $form_state): array {
    $this->saveTranslations($form_state);
    $form['status']['message'] = [
      '#markup' => '<p>' . $this->t('Translation saved.') . '</p>',
    ];
    return $form['status'];
  }

  /**
   * Persists all non-empty translated answers.
   */
  private function saveTranslations(FormStateInterface $formState): void {
    $translations = array_filter(
      $formState->getValue('translations', []),
      static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
    );
    $this->store()->set('translations', $translations);
  }

}
