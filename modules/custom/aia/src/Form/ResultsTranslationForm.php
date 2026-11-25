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
 * Captures alternate-language versions of recorded free-text answers.
 */
final class ResultsTranslationForm extends FormBase {

  public function __construct(
    protected SurveyRepository $surveyRepository,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aia.survey_repository'),
      $container->get('tempstore.private'),
    );
  }

  public function getFormId(): string {
    return 'aia_results_translation_form';
  }

  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get('aia_assessment');
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $ui = $this->surveyRepository->getUiData();
    $answers = $this->store()->get('answers') ?? [];
    $translations = $this->store()->get('translations') ?? [];
    $answerLanguage = $this->store()->get('answer_language') === 'fr' ? 'fr' : 'en';
    $targetLabel = $answerLanguage === 'fr'
      ? (string) ($ui['englishContent'] ?? $this->t('English content'))
      : (string) ($ui['frenchContent'] ?? $this->t('French content'));

    $form['#theme_wrappers'] = [];
    $form['translations'] = ['#type' => 'container', '#tree' => TRUE];
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
        '#default_value' => $translations[$name] ?? '',
        '#rows' => 3,
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

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveTranslations($form_state);
    $this->messenger()->addStatus($this->t('Result translations saved.'));
    $form_state->setRedirect('aia.results');
  }

  public function saveTranslationAjax(array &$form, FormStateInterface $form_state): array {
    $this->saveTranslations($form_state);
    $form['status']['message'] = ['#markup' => '<p>' . $this->t('Translation saved.') . '</p>'];
    return $form['status'];
  }

  private function saveTranslations(FormStateInterface $formState): void {
    $translations = array_filter(
      $formState->getValue('translations', []),
      static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
    );
    $this->store()->set('translations', $translations);
  }

}
