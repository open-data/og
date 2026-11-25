<?php

declare(strict_types=1);

namespace Drupal\aia\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\aia\AssessmentReportBuilder;
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
    private readonly AssessmentReportBuilder $reportBuilder,
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
    $sections = $this->reportBuilder->build(
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
    foreach ($sections as $section) {
      $html .= '<h3>' . $this->escape($section['title']) . '</h3>';
      $lastGroup = '';
      foreach ($section['items'] as $index => $item) {
        if ($item['group'] !== '' && $item['group'] !== $lastGroup) {
          $html .= '<h4>' . $this->escape($item['group']) . '</h4>';
          $lastGroup = $item['group'];
        }
        $html .= '<dl><dt>' . ($index + 1) . '. '
          . $this->escape($item['question']) . '</dt><dd>';
        if (count($item['answer_items']) > 1) {
          $html .= '<ul>';
          foreach ($item['answer_items'] as $answer) {
            $html .= '<li>' . nl2br($this->escape($answer['label']))
              . $this->renderModifier($answer['points'], (string) $ui['modifier'])
              . '</li>';
          }
          $html .= '</ul>';
        }
        else {
          $answer = reset($item['answer_items']);
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
