<?php

declare(strict_types=1);

namespace Drupal\aia\Form;

use Drupal\aia\ConditionEvaluator;
use Drupal\aia\ScoreCalculator;
use Drupal\aia\SurveyRepository;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the native, multi-step AIA questionnaire.
 */
final class AiaAssessmentForm extends FormBase {

  public function __construct(
    protected SurveyRepository $surveyRepository,
    protected ConditionEvaluator $conditionEvaluator,
    protected ScoreCalculator $scoreCalculator,
    protected LanguageManagerInterface $languageManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aia.survey_repository'),
      $container->get('aia.condition_evaluator'),
      $container->get('aia.score_calculator'),
      $container->get('language_manager'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'aia_assessment_form';
  }

  /**
   * Returns session-backed assessment storage.
   *
   * The store is resolved from the factory on each use so Drupal can serialize
   * this form between steps without leaving an uninitialized typed property.
   */
  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get('aia_assessment');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $answers = $this->store()->get('answers') ?? [];
    $liveAnswers = array_replace($answers, $this->questionValuesFromBag($form_state->getUserInput()));
    $pages = $this->visiblePages($liveAnswers);
    $totalPages = count($pages);
    if (!isset($liveAnswers['projectDetailsPhase'])) {
      $totalPages = count($this->visiblePages(
        $liveAnswers + ['projectDetailsPhase' => 'item1'],
      ));
    }
    $pageNumber = (int) ($this->store()->get('page') ?? 0);
    $pageNumber = max(0, min($pageNumber, count($pages) - 1));
    $page = $pages[$pageNumber] ?? [];

    $ui = $this->surveyRepository->getUiData();
    $form['#tree'] = FALSE;
    // SurveyJS checkErrorsMode is onNextPage; HTML5 required tooltips would
    // otherwise replace "Please answer the question."
    $form['#attributes']['novalidate'] = 'novalidate';
    $form['#attached']['library'][] = 'aia/assessment';
    $form['#attached']['drupalSettings']['aia']['state'] = [
      'version' => 'v1.0.1',
      'currentPage' => max(0, $pageNumber - 1),
      'data' => $liveAnswers,
      'translationsOnResult' => $this->store()->get('translations') ?? [],
      'completed' => (bool) $this->store()->get('completed'),
      'answerLanguage' => $this->store()->get('answer_language')
      ?? $this->getRequest()->getLocale(),
    ];
    // Only auto-restore localStorage on a fresh GET with an empty Drupal
    // session. Restoring after Next/Previous POSTs sends currentPage 0 and
    // loops page 1 → 2 → 1.
    $form['#attached']['drupalSettings']['aia']['allowRestore'] = $this->getRequest()->isMethod('GET')
      && $answers === []
      && $pageNumber === 0;
    $form['#attached']['drupalSettings']['aia']['confirmRestart'] = (string) ($ui['alertConfirmRestart'] ?? '');
    $form['#cache']['max-age'] = 0;
    $form['assessment'] = [
      '#type' => 'container',
      '#tree' => FALSE,
      '#attributes' => [
        'id' => 'aia-form-wrapper',
        'class' => ['aia-assessment'],
      ],
    ];
    $form['assessment']['toolbar_links'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['aia-toolbar-links']],
    ];
    $form['assessment']['toolbar_links']['language_links'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['aia-language-links'],
        'aria-label' => $this->t('Language selection'),
      ],
    ];
    $currentLangcode = $this->languageManager->getCurrentLanguage()->getId() === 'fr' ? 'fr' : 'en';
    $otherLangcode = $currentLangcode === 'fr' ? 'en' : 'fr';
    $otherLanguage = $this->languageManager->getLanguage($otherLangcode);
    if ($otherLanguage !== NULL) {
      $form['assessment']['toolbar_links']['language_links'][$otherLangcode] = [
        '#type' => 'submit',
        '#value' => (string) ($ui['swtchLang'] ?? ($otherLangcode === 'fr' ? 'Français' : 'English')),
        '#submit' => ['::switchLanguage'],
        '#limit_validation_errors' => [],
        '#aia_language' => $otherLangcode,
        '#attributes' => [
          'class' => ['aia-btn', 'aia-btn--default'],
          'lang' => $otherLangcode,
        ],
      ];
    }
    $form['assessment']['toolbar_links']['source'] = [
      '#type' => 'link',
      '#title' => (string) ($ui['linkProjectText'] ?? $this->t('View the source project')),
      '#url' => Url::fromUri((string) ($ui['linkProjectAnchor'] ?? 'https://github.com/canada-ca/aia-eia-js')),
      '#attributes' => ['class' => ['aia-btn', 'aia-btn--default', 'aia-source-link']],
    ];
    $form['assessment']['storage_notice'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Your answers are stored temporarily in this Drupal session and mirrored to local browser storage for recovery.'),
      '#attributes' => ['class' => ['aia-storage-notice']],
    ];
    $form['assessment']['file_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['aia-file-actions']],
    ];
    $form['assessment']['file_actions']['export'] = [
      '#type' => 'submit',
      '#value' => (string) ($ui['saveButton'] ?? $this->t('Save')),
      '#submit' => ['::exportAssessment'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['aia-btn', 'aia-btn--success', 'aia-save']],
      '#access' => $answers !== [] || $pageNumber > 0,
    ];
    $form['assessment']['file_actions']['aia_import'] = [
      '#type' => 'file',
      '#title' => (string) ($ui['jsonFileUpload'] ?? $this->t('Upload JSON File')),
      '#title_display' => 'before',
      '#description' => '',
      '#accept' => '.json,application/json',
      '#attributes' => ['class' => ['aia-file-input']],
      '#label_attributes' => ['class' => ['aia-btn', 'aia-btn--default']],
    ];
    $form['assessment']['file_actions']['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load progress'),
      '#submit' => ['::importAssessment'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['visually-hidden'],
        'data-aia-import-submit' => TRUE,
      ],
    ];
    $form['assessment']['file_actions']['reset'] = [
      '#type' => 'submit',
      '#value' => (string) ($ui['startAgain'] ?? $this->t('Start Again')),
      '#submit' => ['::resetAssessment'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['aia-btn', 'aia-btn--default'],
        'data-aia-reset' => TRUE,
      ],
      '#access' => $answers !== [] || $pageNumber > 0,
    ];
    $form['assessment']['client_restore_payload'] = [
      '#type' => 'hidden',
      '#parents' => ['client_restore_payload'],
      '#attributes' => ['data-aia-restore-payload' => TRUE],
    ];
    $form['assessment']['client_restore'] = [
      '#type' => 'submit',
      '#name' => 'client_restore',
      '#value' => $this->t('Restore browser progress'),
      '#submit' => ['::restoreBrowserProgress'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['visually-hidden'],
        'data-aia-restore-submit' => TRUE,
      ],
    ];
    if ($pageNumber > 0 && isset($liveAnswers['projectDetailsPhase'])) {
      $pageOptions = [];
      foreach ($pages as $index => $visiblePage) {
        if ($index === 0) {
          continue;
        }
        $pageOptions[$index] = $this->pageLabel($index, $ui);
      }
      $form['assessment']['section_navigation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['aia-section-navigation']],
      ];
      $form['assessment']['section_navigation']['jump_page'] = [
        '#type' => 'select',
        '#title' => (string) ($ui['navigateSectionLabel'] ?? $this->t('Navigate to a Specific Page (Out of 13)')),
        '#empty_option' => (string) ($ui['selectSection'] ?? $this->t('Select Section')),
        '#options' => $pageOptions,
        '#default_value' => $pageNumber,
        '#parents' => ['jump_page'],
        '#attributes' => ['class' => ['aia-section-select']],
      ];
      $form['assessment']['section_navigation']['jump'] = [
        '#type' => 'submit',
        '#value' => $this->t('Go'),
        '#submit' => ['::jumpToPage'],
        // The public app assigns currentPageNo directly without validation.
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => ['visually-hidden'],
          'data-aia-jump-submit' => TRUE,
        ],
      ];
    }
    if ($pageNumber > 0) {
      $contentPages = max(1, $totalPages - 1);
      $percent = (int) round(($pageNumber / $contentPages) * 100);
      $progressLabel = $this->t('Page @current', ['@current' => $pageNumber])
        . (string) ($ui['pageProgressBar'] ?? (' of ' . $contentPages));
      $form['assessment']['progress'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['aia-progress'],
          'aria-live' => 'polite',
        ],
        'bar' => [
          '#markup' => Markup::create(sprintf(
            '<div class="aia-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$d" aria-label="%2$s"><div class="aia-progress__bar" style="width:%1$d%%">%2$s</div></div>',
            $percent,
            $this->escape((string) $progressLabel),
          )),
        ],
      ];
    }
    $pageTitle = $this->surveyRepository->text($page['title'] ?? NULL);
    if ($pageTitle !== '') {
      $form['assessment']['page_title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $pageTitle,
        '#attributes' => ['class' => ['aia-page-title']],
      ];
    }

    $this->buildElements(
      $page['elements'] ?? [],
      $form['assessment'],
      $liveAnswers,
    );

    $form['assessment']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['aia-nav-actions']],
    ];
    if ($pageNumber > 0) {
      $form['assessment']['actions']['previous'] = [
        '#type' => 'submit',
        '#value' => $this->chrome('Previous', 'Précédent'),
        '#submit' => ['::previousPage'],
        // SurveyJS prevPage() deliberately does not validate the current page.
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['aia-btn', 'aia-btn--default']],
      ];
    }
    if ($pageNumber === 0 || $pageNumber < count($pages) - 1) {
      $form['assessment']['actions']['next'] = [
        '#type' => 'submit',
        '#button_type' => 'primary',
        '#value' => $pageNumber === 0 ? $this->chrome('Start', 'Commencer') : $this->chrome('Next', 'Suivant'),
        '#attributes' => ['class' => ['aia-btn', 'aia-btn--primary']],
      ];
    }
    if ($pageNumber > 0) {
      $form['assessment']['actions']['complete'] = [
        '#type' => 'submit',
        '#button_type' => $pageNumber === count($pages) - 1 ? 'primary' : 'default',
        '#value' => $this->chrome('Complete', 'Terminer'),
        '#submit' => ['::completeAssessment'],
        '#attributes' => ['class' => ['aia-btn', $pageNumber === count($pages) - 1 ? 'aia-btn--primary' : 'aia-btn--success']],
      ];
    }
    $score = $this->scoreCalculator->calculate(
      $this->surveyRepository->getQuestions(),
      $liveAnswers,
    );
    $form['assessment']['score'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'aia-current-score',
          'aia-current-score--level-' . $score['level'],
        ],
        'aria-live' => 'polite',
      ],
      'level' => [
        '#markup' => $this->scoreItem(
          (string) ($ui['riskLevel'] ?? 'Impact Level'),
          (string) ($ui['IL'] ?? 'IL'),
          (string) $score['level'],
        ),
      ],
      'total' => [
        '#markup' => $this->scoreItem(
          (string) ($ui['currentScore'] ?? 'Current Score'),
          (string) ($ui['CS'] ?? 'CS'),
          (string) $score['total'],
        ),
      ],
      'raw' => [
        '#markup' => $this->scoreItem(
          (string) ($ui['rawRiskScore'] ?? 'Raw Impact Score'),
          (string) ($ui['RS'] ?? 'RS'),
          (string) $score['raw'],
        ),
      ],
      'mitigation' => [
        '#markup' => $this->scoreItem(
          (string) ($ui['mitigationScore'] ?? 'Mitigation Score'),
          (string) ($ui['MS'] ?? 'MS'),
          (string) $score['mitigation'],
        ),
      ],
    ];
    return $form;
  }

  /**
   * Builds questionnaire elements as native Drupal Form API elements.
   */
  private function buildElements(array $elements, array &$container, array $answers): void {
    foreach ($elements as $index => $element) {
      if (!$this->conditionEvaluator->evaluate($element['visibleIf'] ?? NULL, $answers)) {
        continue;
      }

      $type = $element['type'] ?? '';
      if ($type === 'panel') {
        $key = 'panel_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $element['name'] ?? (string) $index);
        $container[$key] = [
          '#type' => 'fieldset',
          '#title' => $this->surveyRepository->text($element['title'] ?? NULL),
          '#attributes' => ['class' => ['aia-panel']],
        ];
        $this->buildElements($element['elements'] ?? [], $container[$key], $answers);
        continue;
      }
      if ($type === 'html') {
        $container['html_' . $index] = [
          '#type' => 'container',
          'content' => [
            '#markup' => Markup::create(Xss::filterAdmin(
              $this->surveyRepository->text($element['html'] ?? NULL),
            )),
          ],
        ];
        continue;
      }

      $name = (string) ($element['name'] ?? '');
      if ($name === '') {
        continue;
      }
      $formElement = $this->createQuestionElement($element, $answers[$name] ?? NULL);
      $formElement['#parents'] = [$name];
      $container[$name] = $formElement;
    }
  }

  /**
   * Maps a source question to its Drupal element definition.
   */
  private function createQuestionElement(array $question, mixed $default): array {
    $type = $question['type'] ?? 'text';
    $description = $this->surveyRepository->text($question['description'] ?? NULL);
    $title = $this->surveyRepository->text($question['title'] ?? $question['name'] ?? '');
    $required = (bool) ($question['isRequired'] ?? FALSE);
    $langcode = $this->languageManager->getCurrentLanguage()->getId() === 'fr' ? 'fr' : 'en';
    $titleMarkup = $this->escape($title);
    if ($required) {
      $requiredLabel = $langcode === 'fr' ? 'obligatoire' : 'required';
      $titleMarkup .= ' <strong class="aia-required">(' . $requiredLabel . ')</strong>';
    }
    $element = [
      '#title' => Markup::create($titleMarkup),
      '#description' => Markup::create(Xss::filterAdmin($description)),
      '#required' => $required,
      '#default_value' => $default,
    ];
    if ($required) {
      $element['#required_error'] = $this->chrome(
        'Please answer the question.',
        'La réponse à cette question est obligatoire.',
      );
    }

    if (isset($question['help'])) {
      $help = $this->surveyRepository->text($question['help']);
      if ($help !== '') {
        $element['#suffix'] = Markup::create(sprintf(
          '<details class="aia-help"><summary>%s</summary><div>%s</div></details>',
          $this->escape((string) $this->t('Show help')),
          Xss::filterAdmin($help),
        ));
      }
    }

    switch ($type) {
      case 'comment':
        $element['#type'] = 'textarea';
        $element['#rows'] = 5;
        break;

      case 'dropdown':
        $element['#type'] = 'select';
        $element['#empty_option'] = $this->t('- Select -');
        $element['#options'] = $this->options($question);
        $element['#ajax'] = $this->ajaxDefinition();
        $element['#limit_validation_errors'] = [];
        break;

      case 'radiogroup':
        $element['#type'] = 'radios';
        $element['#options'] = $this->options($question);
        $element['#ajax'] = $this->ajaxDefinition();
        $element['#limit_validation_errors'] = [];
        break;

      case 'checkbox':
        $element['#type'] = 'checkboxes';
        $element['#options'] = $this->options($question);
        $element['#default_value'] = is_array($default) ? $default : [];
        $element['#ajax'] = $this->ajaxDefinition();
        $element['#limit_validation_errors'] = [];
        break;

      default:
        $element['#type'] = 'textfield';
        if (($question['inputType'] ?? '') === 'number') {
          $element['#type'] = 'number';
        }
        break;
    }

    return $element;
  }

  /**
   * Builds localized choices keyed by their source values.
   */
  private function options(array $question): array {
    $options = [];
    foreach ($question['choices'] ?? [] as $choice) {
      $options[(string) ($choice['value'] ?? '')] = $this->surveyRepository->text($choice['text'] ?? $choice['value'] ?? '');
    }
    return $options;
  }

  /**
   * Returns the shared conditional-field AJAX configuration.
   */
  private function ajaxDefinition(): array {
    return [
      'callback' => '::ajaxRebuild',
      'wrapper' => 'aia-form-wrapper',
      'event' => 'change',
    ];
  }

  /**
   * Returns the rebuilt questionnaire wrapper.
   */
  public function ajaxRebuild(array &$form, FormStateInterface $form_state): array {
    return $form['assessment'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $pageNumber = (int) ($this->store()->get('page') ?? 0);
    $this->savePageAnswers($form_state, $pageNumber);
    $answers = $this->store()->get('answers') ?? [];
    $lastPage = count($this->visiblePages($answers)) - 1;

    if ($pageNumber >= $lastPage) {
      $this->store()->set('completed', TRUE);
      $this->store()->set('answer_language', $this->getRequest()->getLocale());
      $form_state->setRedirect('aia.results');
      return;
    }

    $this->store()->set('page', $pageNumber + 1);
    $this->forgetStaleNavigationInput($form_state);
    $form_state->setRebuild();
  }

  /**
   * Validates the current page and finishes, matching SurveyJS Complete.
   */
  public function completeAssessment(array &$form, FormStateInterface $form_state): void {
    $pageNumber = (int) ($this->store()->get('page') ?? 0);
    $this->savePageAnswers($form_state, $pageNumber);
    $this->store()->set('completed', TRUE);
    $this->store()->set('answer_language', $this->getRequest()->getLocale());
    $form_state->setRedirect('aia.results');
  }

  /**
   * Saves the page and moves back one step.
   */
  public function previousPage(array &$form, FormStateInterface $form_state): void {
    $pageNumber = (int) ($this->store()->get('page') ?? 0);
    $this->savePageAnswers($form_state, $pageNumber);
    $this->store()->set('page', max(0, $pageNumber - 1));
    $this->forgetStaleNavigationInput($form_state);
    $form_state->setRebuild();
  }

  /**
   * Saves current edits before switching Drupal's interface language.
   */
  public function switchLanguage(array &$form, FormStateInterface $form_state): void {
    $pageNumber = (int) ($this->store()->get('page') ?? 0);
    $this->savePageAnswers($form_state, $pageNumber);
    $langcode = (string) ($form_state->getTriggeringElement()['#aia_language'] ?? 'en');
    $language = $this->languageManager->getLanguage($langcode)
      ?? $this->languageManager->getDefaultLanguage();
    $form_state->setRedirect('aia.assessment', [], ['language' => $language]);
  }

  /**
   * Saves current edits and downloads a portable progress file.
   */
  public function exportAssessment(array &$form, FormStateInterface $form_state): void {
    $pageNumber = (int) ($this->store()->get('page') ?? 0);
    $this->savePageAnswers($form_state, $pageNumber);
    $payload = [
      'version' => 'v1.0.1',
      'currentPage' => max(0, $pageNumber - 1),
      'data' => $this->store()->get('answers') ?? [],
      'translationsOnResult' => $this->store()->get('translations') ?? [],
      'completed' => (bool) $this->store()->get('completed'),
      'answerLanguage' => $this->store()->get('answer_language')
      ?? $this->getRequest()->getLocale(),
    ];
    $response = new Response(
      json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
    $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="AIA Results.json"');
    $response->headers->set('Cache-Control', 'private, no-store');
    $form_state->setResponse($response);
  }

  /**
   * Saves the current page and jumps to a selected visible section.
   */
  public function jumpToPage(array &$form, FormStateInterface $form_state): void {
    $pageNumber = (int) ($this->store()->get('page') ?? 0);
    $this->savePageAnswers($form_state, $pageNumber);
    $rawTarget = $form_state->getValue('jump_page')
      ?? ($form_state->getUserInput()['jump_page'] ?? '');
    if ($rawTarget === NULL || $rawTarget === '') {
      $form_state->setRebuild();
      return;
    }
    $answers = $this->store()->get('answers') ?? [];
    $lastPage = count($this->visiblePages($answers)) - 1;
    $target = max(0, min((int) $rawTarget, $lastPage));
    $this->store()->set('page', $target);
    $this->forgetStaleNavigationInput($form_state);
    $form_state->setRebuild();
  }

  /**
   * Clears all progress and starts a new assessment.
   */
  public function resetAssessment(array &$form, FormStateInterface $form_state): void {
    $this->store()->delete('answers');
    $this->store()->delete('page');
    $this->store()->delete('completed');
    $this->store()->delete('translations');
    $this->store()->delete('answer_language');
    $form_state->setRedirect('aia.assessment');
  }

  /**
   * Loads a portable progress file created by the original or Drupal app.
   */
  public function importAssessment(array &$form, FormStateInterface $form_state): void {
    $files = $this->getRequest()->files->get('files', []);
    $file = $this->findUploadedFile($files);
    if (!$file instanceof UploadedFile || !$file->isValid()) {
      $this->messenger()->addError($this->t('Select a valid JSON progress file.'));
      $form_state->setRebuild();
      return;
    }
    if (strtolower($file->getClientOriginalExtension()) !== 'json' || $file->getSize() > 2_000_000) {
      $this->messenger()->addError($this->t('The progress file must be JSON and no larger than 2 MB.'));
      $form_state->setRebuild();
      return;
    }

    try {
      $payload = json_decode((string) file_get_contents($file->getPathname()), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $this->messenger()->addError($this->t('The selected file does not contain valid JSON.'));
      $form_state->setRebuild();
      return;
    }
    if (!is_array($payload) || !is_array($payload['data'] ?? NULL)) {
      $this->messenger()->addError($this->t('The selected file is not an AIA progress file.'));
      $form_state->setRebuild();
      return;
    }

    $answers = $this->normalizeImportedAnswers($payload['data']);
    $translations = is_array($payload['translationsOnResult'] ?? NULL)
      ? $this->normalizeImportedTranslations($payload['translationsOnResult'])
      : [];
    $this->store()->set('translations', $translations);
    $this->restorePayload(
      $answers,
      (int) ($payload['currentPage'] ?? 0),
      array_key_exists('completed', $payload)
        ? (bool) $payload['completed']
        : NULL,
      is_string($payload['answerLanguage'] ?? NULL)
        ? $payload['answerLanguage']
        : NULL,
    );
    $this->messenger()->addStatus($this->t('Assessment progress loaded.'));
    $form_state->setRedirect(
      $this->store()->get('completed') ? 'aia.results' : 'aia.assessment',
    );
  }

  /**
   * Restores progress mirrored to browser local storage.
   */
  public function restoreBrowserProgress(array &$form, FormStateInterface $form_state): void {
    $rawPayload = (string) $form_state->getValue('client_restore_payload');
    if (strlen($rawPayload) > 2_000_000) {
      $this->messenger()->addError($this->t('Browser progress could not be restored because it is larger than 2 MB.'));
      $form_state->setRebuild();
      return;
    }
    try {
      $payload = json_decode(
        $rawPayload,
        TRUE,
        512,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException) {
      $this->messenger()->addError($this->t('Browser progress could not be restored because it is invalid.'));
      $form_state->setRebuild();
      return;
    }
    if (!is_array($payload) || !is_array($payload['data'] ?? NULL)) {
      $this->messenger()->addError($this->t('Browser progress could not be restored because it is invalid.'));
      $form_state->setRebuild();
      return;
    }
    $answers = $this->normalizeImportedAnswers($payload['data']);
    $this->store()->set('translations', is_array($payload['translationsOnResult'] ?? NULL)
      ? $this->normalizeImportedTranslations($payload['translationsOnResult'])
      : []);
    $this->restorePayload(
      $answers,
      (int) ($payload['currentPage'] ?? 0),
      array_key_exists('completed', $payload)
        ? (bool) $payload['completed']
        : NULL,
      is_string($payload['answerLanguage'] ?? NULL)
        ? $payload['answerLanguage']
        : NULL,
    );
    $this->messenger()->addStatus($this->t('Browser progress restored.'));
    $form_state->setRedirect(
      $this->store()->get('completed') ? 'aia.results' : 'aia.assessment',
    );
  }

  /**
   * Applies historical migrations and filters unknown answer keys.
   */
  private function normalizeImportedAnswers(array $answers): array {
    // Preserve compatibility with two historical source-file migrations.
    if (is_array($answers['aboutSystem1'] ?? NULL)) {
      $position = array_search('item6-1', $answers['aboutSystem1'], TRUE);
      if ($position !== FALSE) {
        $answers['aboutSystem1'][$position] = 'item6';
      }
    }
    if (isset($answers['impact4']) && !isset($answers['decisionSector3'])) {
      $answers['decisionSector3'] = $answers['impact4'];
      unset($answers['impact4']);
    }

    $normalized = [];
    foreach ($this->surveyRepository->getQuestionsByName() as $name => $question) {
      if (!array_key_exists($name, $answers)) {
        continue;
      }
      $value = $answers[$name];
      $type = (string) ($question['type'] ?? '');
      if (in_array($type, ['text', 'comment'], TRUE)) {
        if (is_scalar($value)) {
          $normalized[$name] = (string) $value;
        }
        continue;
      }

      $choices = array_fill_keys(array_map(
        static fn (array $choice): string => (string) ($choice['value'] ?? ''),
        $question['choices'] ?? [],
      ), TRUE);
      if ($type === 'checkbox' && is_array($value)) {
        $selected = array_values(array_unique(array_filter(
          array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            $value,
          ),
          static fn (string $item): bool => $item !== '' && isset($choices[$item]),
        )));
        if ($selected !== []) {
          $normalized[$name] = $selected;
        }
      }
      elseif (
        in_array($type, ['radiogroup', 'dropdown'], TRUE) &&
        is_scalar($value) &&
        isset($choices[(string) $value])
      ) {
        $normalized[$name] = (string) $value;
      }
    }
    return $normalized;
  }

  /**
   * Applies historical migrations to free-text translations.
   */
  private function normalizeImportedTranslations(array $translations): array {
    if (isset($translations['impact4']) && !isset($translations['decisionSector3'])) {
      $translations['decisionSector3'] = $translations['impact4'];
      unset($translations['impact4']);
    }

    $normalized = [];
    foreach ($this->surveyRepository->getQuestionsByName() as $name => $question) {
      if (
        array_key_exists($name, $translations) &&
        in_array($question['type'] ?? '', ['text', 'comment'], TRUE) &&
        is_scalar($translations[$name])
      ) {
        $normalized[$name] = (string) $translations[$name];
      }
    }
    return $normalized;
  }

  /**
   * Finds the uploaded JSON file regardless of Form API parent naming.
   */
  private function findUploadedFile(mixed $files): ?UploadedFile {
    if ($files instanceof UploadedFile) {
      return $files;
    }
    if (!is_array($files)) {
      return NULL;
    }
    if (($files['aia_import'] ?? NULL) instanceof UploadedFile) {
      return $files['aia_import'];
    }
    foreach ($files as $file) {
      $match = $this->findUploadedFile($file);
      if ($match !== NULL) {
        return $match;
      }
    }
    return NULL;
  }

  /**
   * Stores normalized answers at a valid visible page.
   */
  private function restorePayload(
    array $answers,
    int $requestedPage,
    ?bool $completed = NULL,
    ?string $answerLanguage = NULL,
  ): void {
    $this->purgeHiddenAnswers($answers);
    $lastPage = max(0, count($this->visiblePages($answers)) - 1);
    $lastPortablePage = max(0, $lastPage - 1);
    $page = $answers === []
      ? 0
      : max(1, min($requestedPage + 1, $lastPage));
    $completed ??= $answers !== [] && $requestedPage >= $lastPortablePage;
    $this->store()->set('answers', $answers);
    $this->store()->set('page', $page);
    if ($completed) {
      $this->store()->set('completed', TRUE);
    }
    else {
      $this->store()->delete('completed');
    }
    if (in_array($answerLanguage, ['en', 'fr'], TRUE)) {
      $this->store()->set('answer_language', $answerLanguage);
    }
    elseif ($answers !== []) {
      $this->store()->set('answer_language', $this->getRequest()->getLocale());
    }
  }

  /**
   * Stores values from the current page and drops newly hidden answers.
   */
  private function savePageAnswers(FormStateInterface $formState, int $pageNumber): void {
    $answers = $this->store()->get('answers') ?? [];
    $page = $this->visiblePages($answers)[$pageNumber] ?? [];
    $questions = [];
    $this->collectQuestions($page['elements'] ?? [], $questions);
    $submitted = array_replace(
      $answers,
      $this->questionValuesFromBag($formState->getValues()),
      $this->questionValuesFromBag($formState->getUserInput()),
    );

    foreach ($questions as $question) {
      $name = $question['name'] ?? '';
      if ($name === '') {
        continue;
      }
      if (!$this->conditionEvaluator->evaluate($question['visibleIf'] ?? NULL, $submitted)) {
        unset($answers[$name]);
        continue;
      }
      $value = $this->submittedQuestionValue($formState, $name);
      if (($question['type'] ?? '') === 'checkbox' && is_array($value)) {
        $value = array_values(array_filter(
          $value,
          static fn (mixed $item): bool => $item !== 0 && $item !== '0',
        ));
      }
      if ($value !== NULL && $value !== '') {
        $answers[$name] = $value;
      }
      else {
        unset($answers[$name]);
      }
    }

    $this->purgeHiddenAnswers($answers);
    $this->store()->set('answers', $answers);
    $this->store()->delete('completed');
  }

  /**
   * Drops navigation fields from rebuild input so they cannot rewind the page.
   */
  private function forgetStaleNavigationInput(FormStateInterface $formState): void {
    $input = $formState->getUserInput();
    unset($input['jump_page'], $input['op'], $input['client_restore_payload']);
    $formState->setUserInput($input);
  }

  /**
   * Reads a question value from Form API values or raw POST input.
   */
  private function submittedQuestionValue(FormStateInterface $formState, string $name): mixed {
    foreach ([$formState->getValue($name), $formState->getValue(['assessment', $name])] as $value) {
      if ($value !== NULL) {
        return $value;
      }
    }
    $input = $formState->getUserInput();
    if (is_array($input['assessment'] ?? NULL) && array_key_exists($name, $input['assessment'])) {
      return $input['assessment'][$name];
    }
    return $input[$name] ?? NULL;
  }

  /**
   * Extracts known question answers from a values or user-input bag.
   */
  private function questionValuesFromBag(array $bag): array {
    if (isset($bag['assessment']) && is_array($bag['assessment'])) {
      $bag = array_replace($bag, $bag['assessment']);
    }
    return array_intersect_key($bag, $this->surveyRepository->getQuestionsByName());
  }

  /**
   * Recursively collects questions from page panels.
   */
  private function collectQuestions(array $elements, array &$questions): void {
    foreach ($elements as $element) {
      if (($element['type'] ?? '') === 'panel') {
        $this->collectQuestions($element['elements'] ?? [], $questions);
      }
      elseif (($element['type'] ?? '') !== 'html') {
        $questions[] = $element;
      }
    }
  }

  /**
   * Returns pages whose phase conditions match the current answers.
   */
  private function visiblePages(array $answers): array {
    return array_values(array_filter(
      $this->surveyRepository->getPages(),
      fn (array $page): bool => $this->conditionEvaluator->evaluate(
        $page['visibleIf'] ?? NULL,
        $answers,
      ),
    ));
  }

  /**
   * Builds one sticky score-bar cell with desktop and mobile labels.
   */
  private function scoreItem(string $fullLabel, string $shortLabel, string $value): Markup {
    return Markup::create(sprintf(
      '<div class="aia-current-score__item"><span class="aia-score-full">%s</span><span class="aia-score-short">%s</span>: %s</div>',
      $this->escape($fullLabel),
      $this->escape($shortLabel),
      $this->escape($value),
    ));
  }

  /**
   * Returns the upstream section navigator label.
   */
  private function pageLabel(int $index, array $ui): string {
    $titles = [
      1 => $ui['resultSectionPD'] ?? 'Project Details',
      2 => $ui['sectionBusinessDriverImpact'] ?? 'Reasons for Automation',
      3 => $ui['riskProfile'] ?? 'Risk Profile',
      4 => $ui['projectAuthority'] ?? 'Project Authority',
      5 => $ui['aboutTheSystem'] ?? 'About the System',
      6 => $ui['aboutTheAlgorithm'] ?? 'About the Algorithm',
      7 => $ui['aboutDecision'] ?? 'About the Decision',
      8 => $ui['impactAssessment'] ?? 'Impact Assessment',
      9 => $ui['aboutTheData'] ?? 'About the Data',
      10 => $ui['consultations'] ?? 'Consultations',
      11 => $ui['deRiskingAndMitigationMeasuresDQ'] ?? 'Data Quality',
      12 => $ui['deRiskingAndMitigationMeasuresPF'] ?? 'Procedural Fairness',
      13 => $ui['deRiskingAndMitigationMeasuresP'] ?? 'Privacy',
    ];
    return sprintf('Section %d: %s', $index, (string) ($titles[$index] ?? $index));
  }

  /**
   * SurveyJS chrome that must work even when Locale is not enabled.
   */
  private function chrome(string $english, string $french): string {
    return $this->languageManager->getCurrentLanguage()->getId() === 'fr'
      ? $french
      : $english;
  }

  /**
   * Escapes chrome text placed into markup.
   */
  private function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Removes answers belonging to hidden pages, panels, or questions.
   */
  private function purgeHiddenAnswers(array &$answers): void {
    foreach ($this->surveyRepository->getPages() as $page) {
      $pageVisible = $this->conditionEvaluator->evaluate(
        $page['visibleIf'] ?? NULL,
        $answers,
      );
      $this->purgeHiddenElements($page['elements'] ?? [], $answers, $pageVisible);
    }
  }

  /**
   * Recursively removes answers below an invisible ancestor.
   */
  private function purgeHiddenElements(array $elements, array &$answers, bool $parentVisible): void {
    foreach ($elements as $element) {
      $visible = $parentVisible && $this->conditionEvaluator->evaluate(
        $element['visibleIf'] ?? NULL,
        $answers,
      );
      if (($element['type'] ?? '') === 'panel') {
        $this->purgeHiddenElements($element['elements'] ?? [], $answers, $visible);
        continue;
      }
      $name = $element['name'] ?? '';
      if (!$visible && $name !== '') {
        unset($answers[$name]);
      }
    }
  }

}
