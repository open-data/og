<?php

declare(strict_types=1);

namespace Drupal\aia\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\aia\ScoreCalculator;
use Drupal\aia\SurveyRepository;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates localized AIA result PDF files.
 */
final class PdfController extends ControllerBase {

  public function __construct(
    private readonly SurveyRepository $surveyRepository,
    private readonly ScoreCalculator $scoreCalculator,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aia.survey_repository'),
      $container->get('aia.score_calculator'),
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
   * Downloads results in the requested language.
   */
  public function download(string $language): Response {
    if (!$this->store()->get('completed')) {
      return new RedirectResponse(Url::fromRoute('aia.assessment')->toString());
    }
    $language = $language === 'fr' ? 'fr' : 'en';
    $answers = $this->store()->get('answers') ?? [];
    $questions = $this->surveyRepository->getQuestions();
    $score = $this->scoreCalculator->calculate($questions, $answers);
    $requirements = $this->surveyRepository->getRequirements($score['level'], $language);
    $ui = $this->surveyRepository->getUiData($language);
    $translations = $this->store()->get('translations') ?? [];
    $answerLanguage = $this->store()->get('answer_language') === 'fr' ? 'fr' : 'en';
    $sections = $this->buildAnswerSections(
      $questions,
      $answers,
      $translations,
      $answerLanguage,
      $language,
    );

    $html = '<!doctype html><html lang="' . $language . '"><head><meta charset="UTF-8"><style>'
      . 'body{font-family:DejaVu Sans,sans-serif;font-size:10pt;color:#222}'
      . 'h1{font-size:20pt}h2{font-size:15pt;border-bottom:1px solid #777}'
      . 'h3{font-size:12pt}h4{font-size:10pt;margin-bottom:4px}'
      . 'dt{font-weight:bold;margin-top:8px}dd{margin:2px 0 8px}'
      . 'ul{margin-top:3px}.modifier{font-weight:bold;white-space:nowrap}'
      . '.score{padding:12px;background:#eee}.page-break{page-break-before:always}'
      . '</style></head><body>';
    $html .= '<h1>' . $this->escape((string) $ui['resultTitle']) . '</h1><p>'
      . $this->escape((string) $ui['version']) . '</p>';
    $html .= '<h2>Section 1: ' . $this->escape((string) $ui['riskLevel']) . ': '
      . $score['level'] . '</h2><div class="score">'
      . $this->escape((string) $ui['currentScore']) . ': '
      . $score['total'] . '<br>' . $this->escape((string) $ui['rawRiskScore']) . ': '
      . $score['raw'] . '<br>' . $this->escape((string) $ui['mitigationScore']) . ': '
      . $score['mitigation'] . '</div>';
    $html .= '<h2>' . $this->escape($requirements['title']) . ' '
      . $score['level'] . '</h2>';
    foreach ($requirements['items'] as $requirement) {
      $html .= '<h3>' . $this->escape($requirement['title']) . '</h3>';
      foreach ($requirement['sections'] as $requirementSection) {
        $html .= '<p>' . (string) $requirementSection['title'] . '</p>';
        if ($requirementSection['list'] !== []) {
          $html .= '<ul>';
          foreach ($requirementSection['list'] as $listItem) {
            $html .= '<li>' . (string) $listItem . '</li>';
          }
          $html .= '</ul>';
        }
      }
    }
    $html .= '<h3>' . $this->escape($requirements['other_title']) . '</h3><p>'
      . $this->escape($requirements['other_text']) . '</p><p><a href="'
      . $this->escape($requirements['directive_url']) . '">'
      . $this->escape($requirements['directive_link_text']) . '</a></p><p>'
      . $this->escape($requirements['privacy_text']) . '</p>';
    $html .= '<h2 class="page-break">Section 3: '
      . $this->escape((string) $ui['resultSectionQA']) . '</h2>';
    $sectionNumber = 0;
    foreach ($sections as $section) {
      $sectionNumber++;
      $html .= '<h3>Section 3.' . $sectionNumber . ': '
        . $this->escape($section['title']) . '</h3>';
      $lastGroup = '';
      foreach ($section['items'] as $index => $item) {
        if ($item['group'] !== '' && $item['group'] !== $lastGroup) {
          $html .= '<h4>' . $this->escape($item['group']) . '</h4>';
          $lastGroup = $item['group'];
        }
        $html .= '<dl><dt>' . ($index + 1) . '. '
          . $this->escape($item['question']) . '</dt><dd>';
        if (count($item['answers']) > 1) {
          $html .= '<ul>';
          foreach ($item['answers'] as $answer) {
            $html .= '<li>' . nl2br($this->escape($answer['label']))
              . $this->renderModifier($answer['points'], (string) $ui['modifier'])
              . '</li>';
          }
          $html .= '</ul>';
        }
        else {
          $answer = reset($item['answers']);
          $html .= nl2br($this->escape((string) ($answer['label'] ?? '')))
            . $this->renderModifier($answer['points'] ?? NULL, (string) $ui['modifier']);
        }
        $html .= '</dd></dl>';
      }
    }
    $html .= '</body></html>';

    $options = new Options();
    $options->setDefaultFont('DejaVu Sans');
    $options->setIsRemoteEnabled(FALSE);
    $pdf = new Dompdf($options);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('letter');
    $pdf->render();

    $response = new Response($pdf->output());
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', sprintf('attachment; filename="aia-%s.pdf"', $language));
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  /**
   * Builds the three upstream result sections in the requested language.
   */
  private function buildAnswerSections(
    array $questions,
    array $answers,
    array $translations,
    string $answerLanguage,
    string $language,
  ): array {
    $ui = $this->surveyRepository->getUiData($language);
    $sections = [
      'project' => ['title' => (string) $ui['resultSectionPD'], 'items' => []],
      'risk' => ['title' => (string) $ui['resultSectionRQA'], 'items' => []],
      'mitigation' => ['title' => (string) $ui['resultSectionMQA'], 'items' => []],
    ];
    foreach ($questions as $question) {
      $name = (string) ($question['name'] ?? '');
      if ($name === '' || !array_key_exists($name, $answers)) {
        continue;
      }
      $type = $this->scoreCalculator->scoreType($question);
      $panelName = (string) ($question['_panel']['name'] ?? '');
      if (
        $type === ScoreCalculator::NOT_SCORED &&
        !in_array($panelName, ['projectDetailsPanel-NS', 'aboutSystemPanel-NS'], TRUE)
      ) {
        continue;
      }
      $sectionKey = match ($type) {
        ScoreCalculator::RAW_SCORE => 'risk',
        ScoreCalculator::MITIGATION_SCORE => 'mitigation',
        default => 'project',
      };
      $answer = $answers[$name];
      if (
        $language !== $answerLanguage &&
        in_array($question['type'] ?? '', ['text', 'comment'], TRUE) &&
        isset($translations[$name])
      ) {
        $answer = $translations[$name];
      }
      $labels = [];
      foreach ($question['choices'] ?? [] as $choice) {
        $labels[(string) ($choice['value'] ?? '')] = SurveyRepository::localize(
          $choice['text'] ?? $choice['value'] ?? '',
          $language,
        );
      }
      $scoreable = $this->scoreCalculator->isScoreable($question);
      $answerItems = array_map(
        fn (mixed $value): array => [
          'label' => $labels[(string) $value] ?? (string) $value,
          'points' => $scoreable ? $this->scoreCalculator->value($value) : NULL,
        ],
        is_array($answer) ? $answer : [$answer],
      );
      $sections[$sectionKey]['items'][] = [
        'group' => $this->localizedGroupTitle($question, $language),
        'question' => SurveyRepository::localize(
          $question['title'] ?? $name,
          $language,
        ),
        'answers' => $answerItems,
      ];
    }
    return $sections;
  }

  /**
   * Returns the localized page and panel heading for a question.
   */
  private function localizedGroupTitle(array $question, string $language): string {
    $page = SurveyRepository::localize($question['_page']['title'] ?? NULL, $language);
    $panel = SurveyRepository::localize($question['_panel']['title'] ?? NULL, $language);
    if ($page !== '' && $panel !== '' && $page !== $panel) {
      return $page . ' – ' . $panel;
    }
    return $panel !== '' ? $panel : $page;
  }

  /**
   * Renders an upstream-style per-answer score modifier.
   */
  private function renderModifier(int|float|null $points, string $label): string {
    if ($points === NULL) {
      return '';
    }
    $value = $points >= 0 ? '+' . $points : (string) $points;
    return ' <span class="modifier">[' . $this->escape($label) . ': '
      . $this->escape($value) . ']</span>';
  }

  /**
   * Escapes text for PDF HTML.
   */
  private function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
