<?php

declare(strict_types=1);

namespace Drupal\aia;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;

/**
 * Loads and normalizes the upstream questionnaire data.
 */
final class SurveyRepository {

  /**
   * Legacy institutions whose official names changed materially.
   */
  private const LEGACY_DEPARTMENT_ALIASES = [
    '005' => 'dfatd-maecd',
    '007' => 'ec',
    '012' => 'ced-dec',
    '015' => 'elections',
    '019' => 'ppsc-sppc',
    '033' => 'ic',
    '042' => 'aandc-aadnc',
    '050' => 'cic',
    '055' => 'csec-cstc',
    '074' => 'cer-rec',
    '083' => 'wage',
    '088' => 'ps-sp',
    '101' => 'tsb-bst',
    '103' => 'nsira-ossnr',
    '124' => 'pc',
    '127' => 'pwgsc-tpsgc',
    '142' => 'infc',
  ];

  /**
   * Parsed questionnaire data.
   */
  private ?array $survey = NULL;

  /**
   * Parsed UI and requirements data indexed by language.
   */
  private array $uiData = [];

  /**
   * Legacy department values indexed in both directions where names match.
   */
  private array $legacyDepartments = [];

  private array $departmentToLegacy = [];

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ?InstitutionDirectory $institutionDirectory = NULL,
  ) {}

  /**
   * Returns the complete questionnaire.
   */
  public function getSurvey(): array {
    if ($this->survey === NULL) {
      $path = $this->moduleExtensionList->getPath('aia') . '/data/survey-enfr.json';
      $contents = file_get_contents($path);
      if ($contents === FALSE) {
        throw new \RuntimeException(sprintf('Unable to read AIA questionnaire at %s.', $path));
      }
      $this->survey = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
      $this->applyNativeStorageDisclosure();
      $this->applyInstitutionFile();
    }

    return $this->survey;
  }

  /**
   * Returns all survey pages.
   */
  public function getPages(): array {
    return $this->getSurvey()['pages'] ?? [];
  }

  /**
   * Returns localized source UI strings and impact-level requirements.
   */
  public function getUiData(?string $language = NULL): array {
    $language ??= $this->languageManager->getCurrentLanguage()->getId();
    $language = $language === 'fr' ? 'fr' : 'en';
    if (!isset($this->uiData[$language])) {
      $path = $this->moduleExtensionList->getPath('aia') . '/data/' . $language . '.json';
      $contents = file_get_contents($path);
      if ($contents === FALSE) {
        throw new \RuntimeException(sprintf('Unable to read AIA UI data at %s.', $path));
      }
      $this->uiData[$language] = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    return $this->uiData[$language];
  }

  /**
   * Returns requirements applicable to an impact level.
   */
  public function getRequirements(int $level, ?string $language = NULL): array {
    $language ??= $this->languageManager->getCurrentLanguage()->getId();
    $data = $this->getUiData($language);
    $requirements = [];
    foreach ($data['requirements']['elements'] ?? [] as $requirement) {
      $requirements[] = [
        'title' => (string) ($requirement['title'] ?? ''),
        'text' => (string) ($requirement['elements'][$level - 1]['text'] ?? ''),
        'sections' => self::formatRequirementText(
          (string) ($requirement['elements'][$level - 1]['text'] ?? ''),
        ),
      ];
    }
    return [
      'title' => (string) ($data['requirements']['title'] ?? ''),
      'items' => $requirements,
      'other_title' => (string) ($data['otherRequirementsTitle'] ?? ''),
      'other_text' => (string) ($data['otherRequirements'] ?? ''),
      'directive_link_text' => (string) ($data['linkDirectiveText'] ?? ''),
      'directive_url' => $language === 'fr'
        ? 'https://www.tbs-sct.canada.ca/pol/doc-fra.aspx?id=32592'
        : 'https://www.tbs-sct.canada.ca/pol/doc-eng.aspx?id=32592',
      'privacy_text' => (string) ($data['contactAtipForPia'] ?? ''),
    ];
  }

  /**
   * Localizes a questionnaire text value.
   */
  public function text(string|array|null $value, ?string $language = NULL): string {
    $language ??= $this->languageManager->getCurrentLanguage()->getId();
    return self::localize($value, $language);
  }

  /**
   * Converts a legacy department code to the current organization identifier.
   */
  public function normalizeDepartment(string $value): string {
    $this->getSurvey();
    return $this->legacyDepartments[$value]['current'] ?? $value;
  }

  /**
   * Converts a current organization identifier for legacy JSON consumers.
   */
  public function exportDepartment(string $value): string {
    $this->getSurvey();
    return $this->departmentToLegacy[$value] ?? $value;
  }

  /**
   * Gets the bundled label for an unmatched legacy department code.
   */
  public function legacyDepartmentLabel(string $value): string {
    $this->getSurvey();
    $choice = $this->legacyDepartments[$value]['choice'] ?? NULL;
    return is_array($choice) ? $this->text($choice['text'] ?? '') : '';
  }

  /**
   * Localizes a value without Drupal dependencies, useful for tests.
   */
  public static function localize(string|array|null $value, string $language): string {
    if (is_string($value)) {
      return $value;
    }
    if (!is_array($value)) {
      return '';
    }

    if ($language === 'fr' && isset($value['fr'])) {
      return (string) $value['fr'];
    }

    return (string) ($value['default'] ?? $value['en'] ?? reset($value) ?: '');
  }

  /**
   * Converts upstream newline conventions into safe paragraphs and lists.
   */
  public static function formatRequirementText(string $text): array {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
      return [];
    }

    $sections = [];
    foreach (explode("\n\n\n", $text) as $group) {
      if (str_contains($group, "\n\n")) {
        [$title, $list] = explode("\n\n", $group, 2);
        $sections[] = [
          'title' => self::safeRequirementMarkup($title),
          'list' => array_map(
            [self::class, 'safeRequirementMarkup'],
            array_values(array_filter(
              array_map('trim', explode("\n", $list)),
              static fn (string $item): bool => $item !== '',
            )),
          ),
        ];
        continue;
      }
      foreach (explode("\n", $group) as $paragraph) {
        if (trim($paragraph) !== '') {
          $sections[] = [
            'title' => self::safeRequirementMarkup($paragraph),
            'list' => [],
          ];
        }
      }
    }
    return $sections;
  }

  /**
   * Filters trusted bundled requirement markup to its supported inline tags.
   */
  private static function safeRequirementMarkup(string $text): Markup {
    return Markup::create(Xss::filter(
      trim($text),
      ['a', 'abbr', 'em', 'strong'],
    ));
  }

  /**
   * Corrects the browser-only notice for the native server-rendered port.
   */
  private function applyNativeStorageDisclosure(): void {
    $html = &$this->survey['pages'][0]['elements'][0]['html'];
    $html['default'] = str_replace(
      "<p>No. We don't store your responses and data only remains within your browser's local storage. Feel free to use this tool as many times as you need throughout the design and implementation of your automation project. </p>",
      "<p>This Drupal version temporarily stores your responses in private session storage on the Drupal server and mirrors them to your browser's local storage for recovery. Responses are not saved as permanent Drupal content. Use the <em>Download JSON file</em> action if you need a portable copy. </p>",
      (string) ($html['default'] ?? ''),
    );
    $html['fr'] = str_replace(
      "<p>Non. Nous ne stockons pas vos réponses et les données saisies résident uniquement dans votre ordinateur. N'hésitez pas à utiliser cet outil autant de fois que vous en avez besoin tout au long de la conception et de la mise en œuvre de votre projet d'automatisation.</p>",
      "<p>Cette version Drupal conserve temporairement vos réponses dans le stockage de session privé du serveur Drupal et les copie dans le stockage local de votre navigateur pour permettre leur récupération. Les réponses ne sont pas enregistrées comme contenu Drupal permanent. Utilisez l'action <em>Télécharger le fichier JSON</em> pour obtenir une copie transférable.</p>",
      (string) ($html['fr'] ?? ''),
    );
  }

  /**
   * Replaces the bundled department list with organizations.json.
   */
  private function applyInstitutionFile(): void {
    if ($this->institutionDirectory === NULL) {
      return;
    }
    $choices = $this->institutionDirectory->choices(
      $this->languageManager->getCurrentLanguage()->getId(),
    );
    if ($choices === []) {
      return;
    }
    if (isset($this->survey['pages']) && is_array($this->survey['pages'])) {
      $legacyChoices = $this->findDepartmentChoices($this->survey['pages']);
      $this->buildDepartmentAliases($legacyChoices, $choices);
      $this->replaceDepartmentChoices($this->survey['pages'], $choices);
    }
  }

  /**
   * Finds the bundled department choices before replacing them.
   */
  private function findDepartmentChoices(array $elements): array {
    foreach ($elements as $element) {
      if (($element['name'] ?? '') === 'projectDetailsDepartment-NS') {
        return is_array($element['choices'] ?? NULL) ? $element['choices'] : [];
      }
      foreach (['elements', 'pages'] as $children) {
        if (isset($element[$children]) && is_array($element[$children])) {
          $match = $this->findDepartmentChoices($element[$children]);
          if ($match !== []) {
            return $match;
          }
        }
      }
    }
    return [];
  }

  /**
   * Builds conservative bilingual aliases for unchanged department names.
   */
  private function buildDepartmentAliases(array $legacyChoices, array $currentChoices): void {
    $currentByName = [];
    $currentIds = [];
    foreach ($currentChoices as $choice) {
      $currentIds[(string) ($choice['value'] ?? '')] = TRUE;
      foreach (['default', 'fr'] as $language) {
        $name = self::departmentName((string) ($choice['text'][$language] ?? ''));
        if ($name !== '') {
          $currentByName[$name][] = (string) $choice['value'];
        }
      }
    }
    foreach ($legacyChoices as $choice) {
      $legacy = (string) ($choice['value'] ?? '');
      if ($legacy === '') {
        continue;
      }
      $legacyAliases = str_starts_with($legacy, 'item')
        ? [$legacy, substr($legacy, 4)]
        : [$legacy, 'item' . $legacy];
      foreach ($legacyAliases as $legacyAlias) {
        $this->legacyDepartments[$legacyAlias] = ['choice' => $choice];
      }
      $matches = [];
      foreach (['default', 'fr'] as $language) {
        $name = self::departmentName((string) ($choice['text'][$language] ?? ''));
        if (count($currentByName[$name] ?? []) === 1) {
          $matches[] = $currentByName[$name][0];
        }
      }
      $matches = array_values(array_unique($matches));
      if (
        $matches === [] &&
        isset(self::LEGACY_DEPARTMENT_ALIASES[$legacy], $currentIds[self::LEGACY_DEPARTMENT_ALIASES[$legacy]])
      ) {
        $matches[] = self::LEGACY_DEPARTMENT_ALIASES[$legacy];
      }
      if (count($matches) === 1) {
        $current = $matches[0];
        foreach ($legacyAliases as $legacyAlias) {
          $this->legacyDepartments[$legacyAlias]['current'] = $current;
        }
        $english = mb_strtolower((string) ($choice['text']['default'] ?? ''));
        if (
          !isset($this->departmentToLegacy[$current]) ||
          !str_contains($english, 'administered activities')
        ) {
          $this->departmentToLegacy[$current] = str_starts_with($legacy, 'item')
            ? $legacy
            : 'item' . $legacy;
        }
      }
    }
  }

  /**
   * Normalizes harmless naming differences between old and current lists.
   */
  private static function departmentName(string $name): string {
    $name = mb_strtolower($name);
    $name = preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name;
    $name = preg_replace('/\b(department|ministère)\s+(of|de|du|des|de la|de l’|de l\')?\b/u', ' ', $name) ?? $name;
    $name = preg_replace('/\b(canada|canadian|of|the|du|de|des|la|le|l|d)\b/u', ' ', $name) ?? $name;
    return trim(preg_replace('/[^\pL\pN]+/u', ' ', $name) ?? $name);
  }

  /**
   * Walks survey pages and panels to replace the department dropdown.
   */
  private function replaceDepartmentChoices(array &$elements, array $choices): void {
    foreach ($elements as &$element) {
      if (($element['name'] ?? '') === 'projectDetailsDepartment-NS') {
        $element['choices'] = $choices;
        continue;
      }
      if (isset($element['elements']) && is_array($element['elements'])) {
        $this->replaceDepartmentChoices($element['elements'], $choices);
      }
      if (isset($element['pages']) && is_array($element['pages'])) {
        $this->replaceDepartmentChoices($element['pages'], $choices);
      }
    }
  }

  /**
   * Flattens questions while retaining their page and panel context.
   */
  public function getQuestions(): array {
    $questions = [];
    foreach ($this->getPages() as $page) {
      $this->collectElements(
        $page['elements'] ?? [],
        $questions,
        $page,
      );
    }
    return $questions;
  }

  /**
   * Returns questions indexed by machine name.
   */
  public function getQuestionsByName(): array {
    $indexed = [];
    foreach ($this->getQuestions() as $question) {
      if (isset($question['name'])) {
        $indexed[$question['name']] = $question;
      }
    }
    return $indexed;
  }

  /**
   * Recursively collects non-container elements.
   */
  private function collectElements(array $elements, array &$questions, array $page, ?array $panel = NULL): void {
    foreach ($elements as $element) {
      if (($element['type'] ?? '') === 'panel') {
        $this->collectElements($element['elements'] ?? [], $questions, $page, $element);
        continue;
      }
      $element['_page'] = $page;
      $element['_panel'] = $panel;
      $questions[] = $element;
    }
  }

}
