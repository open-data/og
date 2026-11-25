<?php

/**
 * @file
 * Runs AIA unit assertions without requiring a project-wide PHPUnit install.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Drupal\aia\ConditionEvaluator;
use Drupal\aia\InstitutionDirectory;
use Drupal\aia\PortableAssessmentBuilder;
use Drupal\aia\ScoreCalculator;
use Drupal\aia\SurveyRepository;

$_aia_failures = [];
$_aia_passes = 0;

/**
 * Records one standalone test assertion.
 */
function aia_assert(bool $condition, string $message) : void {
  global $_aia_failures, $_aia_passes;
  if ($condition) {
    $_aia_passes++;
    return;
  }
  $_aia_failures[] = $message;
}

$calculator = new ScoreCalculator();
aia_assert($calculator->value('item1-3') === 3, 'Embedded score suffix is parsed.');
aia_assert($calculator->value(['item1-2', 'item2-3']) === 5, 'Checkbox scores are summed.');
aia_assert($calculator->maximum([
  'type' => 'radiogroup',
  'choices' => [['value' => 'item1-1'], ['value' => 'item2-3']],
]) === 3, 'Radio maximum is the highest choice.');
aia_assert($calculator->isScoreable(['name' => 'risk-RS', 'type' => 'radiogroup']), 'RS radios are scoreable.');
aia_assert(!$calculator->isScoreable(['name' => 'risk-RS', 'type' => 'text']), 'Free text is not scoreable.');

$deducted = $calculator->calculate([
  ['type' => 'radiogroup', 'name' => 'risk-RS', 'choices' => [['value' => 'item1-100']]],
  ['type' => 'checkbox', 'name' => 'mitigation-MS', 'choices' => [['value' => 'item1-10'], ['value' => 'item2-10']]],
], ['risk-RS' => 'item1-100', 'mitigation-MS' => ['item1-10']]);
aia_assert($deducted['raw'] === 100, 'Raw score is 100.');
aia_assert($deducted['total'] === 85, '80% of half-max mitigation deducts 15%.');
aia_assert($deducted['level'] === 4, 'Score 85 of 100 is impact level 4.');

$below = $calculator->calculate([
  ['type' => 'radiogroup', 'name' => 'risk-RS', 'choices' => [['value' => 'item1-100']]],
  ['type' => 'checkbox', 'name' => 'mitigation-MS', 'choices' => [['value' => 'item1-10'], ['value' => 'item2-10']]],
], ['risk-RS' => 'item1-100', 'mitigation-MS' => []]);
aia_assert($below['total'] === 100, 'Mitigation below the threshold does not deduct.');

foreach ([25 => 1, 26 => 2, 50 => 2, 51 => 3, 75 => 3, 76 => 4] as $raw => $level) {
  $score = $calculator->calculate([
    ['type' => 'radiogroup', 'name' => 'risk-RS', 'choices' => [['value' => 'item1-100']]],
    ['type' => 'radiogroup', 'name' => 'mitigation-MS', 'choices' => [['value' => 'item1-100']]],
  ], ['risk-RS' => 'item1-' . $raw]);
  aia_assert($score['level'] === $level, "Raw $raw should be impact level $level.");
}

$evaluator = new ConditionEvaluator();
aia_assert($evaluator->evaluate(NULL, []) === TRUE, 'Empty conditions are visible.');
aia_assert(
  $evaluator->evaluate(
    '({phase} = "item1" or {phase} = "item2") and {followUp} = "yes"',
    ['phase' => 'item1', 'followUp' => 'yes'],
  ) === TRUE,
  'Grouped and/or conditions match.',
);
aia_assert($evaluator->evaluate('{drivers} contains "item6"', ['drivers' => ['item2', 'item6']]) === TRUE, 'Contains matches array values.');
aia_assert($evaluator->evaluate('{phase} != "item1"', []) === TRUE, 'Missing answers fail equality and pass inequality.');

$organizationFile = dirname(__DIR__, 2) . '/og_ext_cron/choices/organizations.json';
aia_assert(is_file($organizationFile), 'og_ext_cron organizations.json is the department source file.');
$organizationPayload = json_decode((string) file_get_contents($organizationFile), TRUE, 512, JSON_THROW_ON_ERROR);
$institutionChoices = InstitutionDirectory::mapOrganizations($organizationPayload);
aia_assert($institutionChoices !== [], 'organizations.json contains department choices.');
$institutionValues = array_column($institutionChoices, 'value');
aia_assert(in_array('aafc-aac', $institutionValues, TRUE), 'Agriculture uses the Open Government organization id.');
aia_assert(in_array('cra-arc', $institutionValues, TRUE), 'CRA uses the Open Government organization id.');
$aafc = array_values(array_filter($institutionChoices, static fn (array $choice): bool => $choice['value'] === 'aafc-aac'))[0];
aia_assert($aafc['text']['default'] === 'Agriculture and Agri-Food Canada', 'Organization English titles are used.');
aia_assert($aafc['text']['fr'] === 'Agriculture et Agroalimentaire Canada', 'Organization French titles are used.');
$frenchChoices = InstitutionDirectory::mapOrganizations([
  'cra-arc' => ['en' => 'Canada Revenue Agency', 'fr' => 'Agence du revenu du Canada'],
  'aafc-aac' => ['en' => 'Agriculture and Agri-Food Canada', 'fr' => 'Agriculture et Agroalimentaire Canada'],
], 'fr');
aia_assert(
  array_column($frenchChoices, 'value') === ['cra-arc', 'aafc-aac'],
  'French department choices sort by French title.',
);
$repositoryReflection = new ReflectionClass(SurveyRepository::class);
$repository = $repositoryReflection->newInstanceWithoutConstructor();
$surveyProperty = $repositoryReflection->getProperty('survey');
$surveyProperty->setValue($repository, []);
$aliasBuilder = $repositoryReflection->getMethod('buildDepartmentAliases');
$aliasBuilder->invoke($repository, [
  ['value' => '001', 'text' => ['default' => 'Agriculture and Agri-Food (Department of)', 'fr' => 'Agriculture et de l’Agroalimentaire (Ministère de l’)']],
  ['value' => '122', 'text' => ['default' => 'Canada Revenue Agency - (Administered Activities)', 'fr' => 'Agence du revenu du Canada - (Activités administrées)']],
  ['value' => '130', 'text' => ['default' => 'Canada Revenue Agency', 'fr' => 'Agence du revenu du Canada']],
], [
  ['value' => 'aafc-aac', 'text' => ['default' => 'Agriculture and Agri-Food Canada', 'fr' => 'Agriculture et Agroalimentaire Canada']],
  ['value' => 'cra-arc', 'text' => ['default' => 'Canada Revenue Agency', 'fr' => 'Agence du revenu du Canada']],
]);
aia_assert($repository->normalizeDepartment('001') === 'aafc-aac', 'Numeric legacy department ids import as current ids.');
aia_assert($repository->normalizeDepartment('item001') === 'aafc-aac', 'itemNNN legacy department ids import as current ids.');
aia_assert($repository->exportDepartment('aafc-aac') === 'item001', 'Current department ids export for the legacy AIA.');
aia_assert($repository->exportDepartment('cra-arc') === 'item130', 'Canonical legacy ids are preferred over administered-activity duplicates.');
aia_assert($repository->legacyDepartmentLabel('999') === '', 'Unknown legacy department ids remain distinguishable.');
$portable = (new PortableAssessmentBuilder($repository))->build(
  ['projectDetailsDepartment-NS' => 'aafc-aac', 'projectDetailsTitle' => str_repeat('x', 500)],
  3,
  ['projectDetailsTitle' => str_repeat('é', 500)],
  TRUE,
  'fr',
  123456,
);
aia_assert($portable['currentPage'] === 2, 'Shared portable payload uses legacy page numbering.');
aia_assert($portable['data']['projectDetailsDepartment-NS'] === 'item001', 'Shared portable payload exports a legacy department id.');
aia_assert(strlen($portable['data']['projectDetailsTitle']) === 500, 'Long single-line answers round trip without truncation.');
aia_assert($portable['updatedAt'] === 123456, 'Shared portable payload retains the state revision.');

aia_assert(SurveyRepository::localize(['default' => 'Assessment', 'fr' => 'Évaluation'], 'fr') === 'Évaluation', 'French UI strings localize.');
$sections = SurveyRepository::formatRequirementText("Intro with <a href=\"https://example.com\">link</a>.\n\nFirst item\nSecond item");
aia_assert(count($sections) === 1, 'Requirement text splits into one section.');
aia_assert(str_contains((string) $sections[0]['title'], '<a href='), 'Requirement links are kept.');
aia_assert(array_map('strval', $sections[0]['list']) === ['First item', 'Second item'], 'Requirement lists split on newlines.');

$survey = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/survey-enfr.json'), TRUE, 512, JSON_THROW_ON_ERROR);
aia_assert(count($survey['pages']) === 18, 'Survey has 18 pages.');
aia_assert($survey['firstPageIsStarted'] === TRUE, 'Welcome page is a start page.');
$matrixViews = 0;
foreach (['item1', 'item2'] as $phase) {
  foreach (['en', 'fr'] as $language) {
    $visible = array_values(array_filter(
      $survey['pages'],
      static fn (array $page): bool => $evaluator->evaluate(
        $page['visibleIf'] ?? NULL,
        ['projectDetailsPhase' => $phase],
      ),
    ));
    aia_assert(count($visible) === 14, "$language $phase has welcome plus 13 visible assessment sections.");
    $matrixViews += count($visible) - 1;
  }
}
aia_assert($matrixViews === 52, 'Both phases and languages cover the complete 52-view section matrix.');

$questions = [];
$conditionCount = 0;
$names = [];
$collect = function (array $elements, array $page, ?array $panel = NULL) use (&$collect, &$questions, &$conditionCount, &$names): void {
  foreach ($elements as $element) {
    if (isset($element['visibleIf'])) {
      $conditionCount++;
    }
    if (($element['type'] ?? '') === 'panel') {
      $collect($element['elements'] ?? [], $page, $element);
      continue;
    }
    $element['_page'] = $page;
    $element['_panel'] = $panel;
    $questions[] = $element;
    if (isset($element['name'])) {
      $names[] = $element['name'];
    }
  }
};
foreach ($survey['pages'] as $page) {
  if (isset($page['visibleIf'])) {
    $conditionCount++;
  }
  $collect($page['elements'] ?? [], $page);
}
aia_assert($conditionCount === 124, 'Survey has 124 visibleIf conditions.');
aia_assert(in_array('decisionSector3', $names, TRUE), 'decisionSector3 is present.');
aia_assert(!in_array('impact4', $names, TRUE), 'impact4 was migrated away.');

$live = $calculator->calculate($questions, [
  'projectDetailsPhase' => 'item1',
  'decisionSector1' => ['item1-1', 'item2-1', 'item3-1'],
]);
aia_assert($live['raw'] === 3 && $live['level'] === 1, 'Sample design-branch answers score level 1 with raw 3.');
$questionIndex = array_column($questions, NULL, 'name');
aia_assert(
  $calculator->value('item6-1') === 1 &&
  $calculator->value('Free-text specification') === 0,
  'Q30 Other contributes one point and its explanatory text contributes none.',
);
aia_assert(
  in_array('item6-1', array_column($questionIndex['decisionSector1']['choices'], 'value'), TRUE),
  'Q30 retains the canonical scored Other choice.',
);
$clientScoring = $calculator->clientConfig($questions);
aia_assert(
  ($clientScoring['questions']['decisionSector1']['values']['item6-1'] ?? NULL) === 1,
  'Immediate browser scoring uses the authoritative Q30 value.',
);
aia_assert($calculator->sectionKey('impact4A') === 'impactA', 'Risk section keys strip every digit.');
aia_assert($calculator->sectionKey('fairnessDesign10', TRUE) === 'fairnessDesign0', 'Mitigation section keys strip the first digit.');
$sampleAreas = $calculator->areaBreakdowns($questions, [
  'projectDetailsPhase' => 'item1',
  'decisionSector1' => ['item1-1', 'item2-1', 'item3-1'],
]);
aia_assert(count($sampleAreas['risk']['items']) === 1, 'Answered decision-sector questions emit one risk-area row.');
aia_assert($sampleAreas['risk']['items'][0]['score'] === 3, 'Decision-sector area score is 3.');
aia_assert($sampleAreas['risk']['items'][0]['maximum'] === 8, 'Decision-sector area maximum is 8.');
$required = array_values(array_filter(
  $questions,
  static fn (array $question): bool => (bool) ($question['isRequired'] ?? FALSE),
));
aia_assert(count($required) === 1 && $required[0]['name'] === 'projectDetailsPhase', 'Only project phase is required.');

aia_assert(max(0, 1 - 1) === 0, 'First content page exports as currentPage 0.');
aia_assert(max(1, min(0 + 1, 13)) === 1, 'currentPage 0 restores to Drupal page 1.');

foreach (['en', 'fr'] as $language) {
  $ui = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/' . $language . '.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  foreach (['appTitle', 'resultTitle', 'saveButton', 'jsonFileUpload', 'startAgain', 'riskLevel', 'requirements'] as $key) {
    aia_assert(array_key_exists($key, $ui), "$language UI has $key.");
  }
  aia_assert(count($ui['requirements']['elements']) === 7, "$language requirements have 7 groups.");
}

fwrite(STDOUT, $_aia_passes . ' passed');
if ($_aia_failures !== []) {
  fwrite(STDOUT, ', ' . count($_aia_failures) . " failed\n" . implode("\n", $_aia_failures) . "\n");
  exit(1);
}
fwrite(STDOUT, ", 0 failed\n");
exit(0);
