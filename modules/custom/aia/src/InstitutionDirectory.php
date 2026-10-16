<?php

declare(strict_types=1);

namespace Drupal\aia;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads federal institutions from a JSON file refreshed by the department API.
 *
 * Source: https://open.canada.ca/data/en/dataset/933c7f9d-deb0-4367-940d-06c38f494153
 */
final class InstitutionDirectory {

  public const RESOURCE_ID = '04cbec5c-5a3d-4d34-927d-e41c9e6e3736';

  public const ENDPOINT = 'https://open.canada.ca/data/en/api/3/action/datastore_search';

  public const FILENAME = 'institutions.json';

  public const REFRESH_INTERVAL = 86400;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Returns bilingual SurveyJS-style department choices from the JSON file.
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public function choices(): array {
    $choices = $this->readFile();
    if (!$this->isStale()) {
      return $choices;
    }

    $refreshed = $this->refresh($choices);
    return $refreshed !== [] ? $refreshed : $choices;
  }

  /**
   * Fetches the department API, writes institutions.json, and returns it.
   *
   * @param list<array{value: string, text: array{default: string, fr: string}}> $existing
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public function refresh(array $existing = []): array {
    try {
      $choices = self::mergeChoices(self::mapRecords($this->fetchRecords()), $existing !== [] ? $existing : $this->readFile());
    }
    catch (\Throwable $exception) {
      $this->logger()->warning('Unable to refresh the federal institution list: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    if ($choices === []) {
      return [];
    }

    $this->writeFile($choices);
    return $choices;
  }

  /**
   * Maps Open Government department records to questionnaire choices.
   *
   * @param list<array<string, mixed>> $records
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public static function mapRecords(array $records): array {
    $choices = [];
    foreach ($records as $record) {
      $english = trim((string) ($record['title_en'] ?? ''));
      $french = trim((string) ($record['title_fr'] ?? $english));
      if ($english === '' || preg_match('/^\d{5,}/', $english) === 1) {
        continue;
      }
      if (trim((string) ($record['department_number'] ?? '')) === '') {
        continue;
      }
      $value = trim((string) ($record['open_canada_id'] ?? ''));
      if ($value === '') {
        $value = 'og-' . self::normalizeCode($record['department_number']);
      }
      $choices[$value] = [
        'value' => $value,
        'text' => [
          'default' => $english,
          'fr' => $french !== '' ? $french : $english,
        ],
      ];
    }
    return self::sortChoices(array_values($choices));
  }

  /**
   * Keeps existing values for matched names so saved answers still resolve.
   *
   * @param list<array{value: string, text: array{default: string, fr: string}}> $live
   * @param list<array{value: string, text: array{default: string, fr: string}}> $existing
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public static function mergeChoices(array $live, array $existing): array {
    if ($live === []) {
      return $existing;
    }

    $usedValues = [];
    $merged = [];
    foreach ($live as $choice) {
      $match = self::findExistingMatch($choice, $existing);
      if ($match !== NULL) {
        $choice['value'] = (string) $match['value'];
      }
      $usedValues[$choice['value']] = TRUE;
      $merged[] = $choice;
    }
    foreach ($existing as $choice) {
      $value = (string) ($choice['value'] ?? '');
      if ($value !== '' && !isset($usedValues[$value])) {
        $merged[] = $choice;
        $usedValues[$value] = TRUE;
      }
    }
    return self::sortChoices($merged);
  }

  /**
   * Decodes department choices from institutions.json contents.
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public static function decodeFile(string $json): array {
    if (trim($json) === '') {
      return [];
    }
    $payload = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $choices = is_array($payload) && isset($payload['choices']) && is_array($payload['choices'])
      ? $payload['choices']
      : (is_array($payload) ? $payload : []);
    $normalized = [];
    foreach ($choices as $choice) {
      if (!is_array($choice)) {
        continue;
      }
      $value = trim((string) ($choice['value'] ?? ''));
      $english = trim((string) ($choice['text']['default'] ?? ''));
      $french = trim((string) ($choice['text']['fr'] ?? $english));
      if ($value === '' || $english === '') {
        continue;
      }
      $normalized[] = [
        'value' => $value,
        'text' => [
          'default' => $english,
          'fr' => $french !== '' ? $french : $english,
        ],
      ];
    }
    return $normalized;
  }

  /**
   * Encodes department choices as institutions.json contents.
   *
   * @param list<array{value: string, text: array{default: string, fr: string}}> $choices
   */
  public static function encodeFile(array $choices): string {
    return json_encode([
      'updated' => gmdate('c'),
      'source' => 'https://open.canada.ca/data/en/dataset/933c7f9d-deb0-4367-940d-06c38f494153',
      'choices' => array_values($choices),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
  }

  /**
   * Finds an existing choice whose title matches a live institution.
   *
   * @param array{value: string, text: array{default: string, fr: string}} $live
   * @param list<array{value: string, text: array{default: string, fr: string}}> $existing
   *
   * @return array{value: string, text: array{default: string, fr: string}}|null
   */
  private static function findExistingMatch(array $live, array $existing): ?array {
    $liveTitles = [
      self::normalizeTitle((string) ($live['text']['default'] ?? '')),
      self::normalizeTitle((string) ($live['text']['fr'] ?? '')),
    ];
    $liveTitles = array_values(array_filter($liveTitles, static fn (string $title): bool => $title !== ''));

    foreach ($existing as $choice) {
      $existingTitles = [
        self::normalizeTitle((string) ($choice['text']['default'] ?? '')),
        self::normalizeTitle((string) ($choice['text']['fr'] ?? '')),
      ];
      foreach ($existingTitles as $existingTitle) {
        if ($existingTitle === '') {
          continue;
        }
        foreach ($liveTitles as $liveTitle) {
          if ($existingTitle === $liveTitle) {
            return $choice;
          }
          if (strlen($existingTitle) >= 12 && (str_contains($liveTitle, $existingTitle) || str_contains($existingTitle, $liveTitle))) {
            return $choice;
          }
        }
      }
    }
    return NULL;
  }

  /**
   * Normalizes an institution title for bilingual matching.
   */
  public static function normalizeTitle(string $title): string {
    $title = mb_strtolower($title, 'UTF-8');
    $title = strtr($title, [
      'é' => 'e',
      'è' => 'e',
      'ê' => 'e',
      'ë' => 'e',
      'à' => 'a',
      'â' => 'a',
      'ô' => 'o',
      'ù' => 'u',
      'û' => 'u',
      'ç' => 'c',
      'î' => 'i',
      'ï' => 'i',
    ]);
    $title = preg_replace('/\([^)]*\)/', ' ', $title) ?? $title;
    $title = str_replace([
      'department of the',
      'department of',
      'ministere de la',
      'ministere des',
      'ministere du',
      'ministere de',
      'ministere',
      'department',
      'crown corporation',
      'societe d etat',
      'administered activities',
      'activites administrees',
      'of canada',
      'canada',
    ], ' ', $title);
    $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? $title;
    return trim(preg_replace('/\s+/', ' ', $title) ?? $title);
  }

  /**
   * Fetches every department record from the CKAN DataStore.
   *
   * @return list<array<string, mixed>>
   */
  private function fetchRecords(): array {
    $records = [];
    $offset = 0;
    $limit = 500;
    do {
      $response = $this->httpClient->request('GET', self::ENDPOINT, [
        'query' => [
          'resource_id' => self::RESOURCE_ID,
          'limit' => $limit,
          'offset' => $offset,
        ],
        'timeout' => 15,
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $page = $payload['result']['records'] ?? [];
      if (!is_array($page) || $page === []) {
        break;
      }
      foreach ($page as $record) {
        if (is_array($record)) {
          $records[] = $record;
        }
      }
      $offset += $limit;
      $total = (int) ($payload['result']['total'] ?? 0);
    } while ($offset < $total);

    return $records;
  }

  /**
   * Reads department choices from institutions.json.
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  private function readFile(): array {
    foreach ($this->filePaths() as $path) {
      if ($path === '' || !is_file($path)) {
        continue;
      }
      $contents = file_get_contents($path);
      if ($contents === FALSE || trim($contents) === '') {
        continue;
      }
      try {
        $choices = self::decodeFile($contents);
      }
      catch (\Throwable) {
        continue;
      }
      if ($choices !== []) {
        return $choices;
      }
    }
    return [];
  }

  /**
   * Writes department choices to institutions.json.
   *
   * @param list<array{value: string, text: array{default: string, fr: string}}> $choices
   */
  private function writeFile(array $choices): void {
    $json = self::encodeFile($choices);
    $written = FALSE;
    foreach ($this->writablePaths() as $path) {
      $directory = dirname($path);
      if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
        continue;
      }
      if (@file_put_contents($path, $json) !== FALSE) {
        $written = TRUE;
      }
    }
    if (!$written) {
      $this->logger()->warning('Unable to write the federal institution JSON file.');
    }
  }

  /**
   * Returns TRUE when the JSON file is missing or older than 24 hours.
   */
  private function isStale(): bool {
    foreach ($this->filePaths() as $path) {
      if ($path !== '' && is_file($path)) {
        return (time() - (int) filemtime($path)) > self::REFRESH_INTERVAL;
      }
    }
    return TRUE;
  }

  /**
   * Returns candidate paths, preferring the writable public copy.
   *
   * @return list<string>
   */
  private function filePaths(): array {
    return array_values(array_unique(array_filter([
      $this->publicPath(),
      $this->modulePath(),
    ])));
  }

  /**
   * Returns paths that can receive a refreshed copy of institutions.json.
   *
   * @return list<string>
   */
  private function writablePaths(): array {
    $paths = [$this->modulePath()];
    $public = $this->publicPath(TRUE);
    if ($public !== '') {
      array_unshift($paths, $public);
    }
    return array_values(array_unique(array_filter($paths)));
  }

  /**
   * Returns the shipped JSON path inside the module.
   */
  private function modulePath(): string {
    return $this->moduleExtensionList->getPath('aia') . '/data/' . self::FILENAME;
  }

  /**
   * Returns the writable public JSON path, if the files directory is available.
   */
  private function publicPath(bool $prepare = FALSE): string {
    $directory = 'public://aia';
    if ($prepare) {
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    $realpath = $this->fileSystem->realpath($directory);
    if (!is_string($realpath) || $realpath === '') {
      return '';
    }
    return $realpath . DIRECTORY_SEPARATOR . self::FILENAME;
  }

  /**
   * Pads numeric department codes when a stable slug is missing.
   */
  private static function normalizeCode(mixed $value): string {
    if ($value === NULL || $value === '') {
      return '';
    }
    $code = trim((string) $value);
    if ($code !== '' && ctype_digit($code)) {
      return str_pad($code, 3, '0', STR_PAD_LEFT);
    }
    return $code;
  }

  /**
   * Sorts choices by English title.
   *
   * @param list<array{value: string, text: array{default: string, fr: string}}> $choices
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  private static function sortChoices(array $choices): array {
    usort($choices, static fn (array $a, array $b): int => strcasecmp($a['text']['default'], $b['text']['default']));
    return $choices;
  }

  /**
   * Returns the AIA logger channel.
   */
  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('aia');
  }

}
