<?php

declare(strict_types=1);

namespace Drupal\aia;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads federal institutions from the Open Government department list.
 *
 * Source: https://open.canada.ca/data/en/dataset/933c7f9d-deb0-4367-940d-06c38f494153
 */
final class InstitutionDirectory {

  public const CACHE_ID = 'aia:institutions:v2';

  public const RESOURCE_ID = '04cbec5c-5a3d-4d34-927d-e41c9e6e3736';

  public const ENDPOINT = 'https://open.canada.ca/data/en/api/3/action/datastore_search';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns bilingual SurveyJS-style department choices.
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public function choices(): array {
    $cached = $this->cache->get(self::CACHE_ID);
    if (is_object($cached) && is_array($cached->data) && $cached->data !== []) {
      return $cached->data;
    }

    try {
      $records = $this->fetchRecords();
      $choices = self::mapRecords($records);
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

    $this->cache->set(self::CACHE_ID, $choices, time() + 86400, ['aia']);
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
   * Keeps bundled values for matched names so saved answers still resolve.
   *
   * @param list<array{value: string, text: array{default: string, fr: string}}> $live
   * @param list<array{value: string, text: array{default: string, fr: string}}> $bundled
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public static function mergeChoices(array $live, array $bundled): array {
    if ($live === []) {
      return $bundled;
    }

    $usedValues = [];
    $merged = [];
    foreach ($live as $choice) {
      $match = self::findBundledMatch($choice, $bundled);
      if ($match !== NULL) {
        $choice['value'] = (string) $match['value'];
      }
      $usedValues[$choice['value']] = TRUE;
      $merged[] = $choice;
    }
    foreach ($bundled as $choice) {
      $value = (string) ($choice['value'] ?? '');
      if ($value !== '' && !isset($usedValues[$value])) {
        $merged[] = $choice;
        $usedValues[$value] = TRUE;
      }
    }
    return self::sortChoices($merged);
  }

  /**
   * Finds a bundled choice whose title matches a live institution.
   *
   * @param array{value: string, text: array{default: string, fr: string}} $live
   * @param list<array{value: string, text: array{default: string, fr: string}}> $bundled
   *
   * @return array{value: string, text: array{default: string, fr: string}}|null
   */
  private static function findBundledMatch(array $live, array $bundled): ?array {
    $liveTitles = [
      self::normalizeTitle((string) ($live['text']['default'] ?? '')),
      self::normalizeTitle((string) ($live['text']['fr'] ?? '')),
    ];
    $liveTitles = array_values(array_filter($liveTitles, static fn (string $title): bool => $title !== ''));

    foreach ($bundled as $choice) {
      $bundledTitles = [
        self::normalizeTitle((string) ($choice['text']['default'] ?? '')),
        self::normalizeTitle((string) ($choice['text']['fr'] ?? '')),
      ];
      foreach ($bundledTitles as $bundledTitle) {
        if ($bundledTitle === '') {
          continue;
        }
        foreach ($liveTitles as $liveTitle) {
          if ($bundledTitle === $liveTitle) {
            return $choice;
          }
          if (strlen($bundledTitle) >= 12 && (str_contains($liveTitle, $bundledTitle) || str_contains($bundledTitle, $liveTitle))) {
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
