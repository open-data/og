<?php

declare(strict_types=1);

namespace Drupal\aia;

use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads federal institutions from the Open Government organizations.json file.
 *
 * Source: modules/custom/og_ext_cron/choices/organizations.json
 */
final class InstitutionDirectory {

  public const FILENAME = 'organizations.json';

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns bilingual SurveyJS-style department choices.
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public function choices(): array {
    $path = $this->filePath();
    if ($path === '' || !is_file($path)) {
      $this->logger()->warning('Open Government organizations.json was not found.');
      return [];
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE || trim($contents) === '') {
      return [];
    }

    try {
      $payload = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $exception) {
      $this->logger()->warning('Unable to read organizations.json: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    return self::mapOrganizations(is_array($payload) ? $payload : []);
  }

  /**
   * Maps og_ext_cron organizations.json into questionnaire choices.
   *
   * @param array<string, mixed> $organizations
   *
   * @return list<array{value: string, text: array{default: string, fr: string}}>
   */
  public static function mapOrganizations(array $organizations): array {
    $choices = [];
    foreach ($organizations as $id => $titles) {
      $value = trim((string) $id);
      if ($value === '' || !is_array($titles)) {
        continue;
      }
      $english = trim((string) ($titles['en'] ?? ''));
      $french = trim((string) ($titles['fr'] ?? $english));
      if ($english === '') {
        continue;
      }
      $choices[] = [
        'value' => $value,
        'text' => [
          'default' => $english,
          'fr' => $french !== '' ? $french : $english,
        ],
      ];
    }
    usort($choices, static fn (array $a, array $b): int => strcasecmp($a['text']['default'], $b['text']['default']));
    return $choices;
  }

  /**
   * Returns the path to og_ext_cron/choices/organizations.json.
   */
  private function filePath(): string {
    try {
      return $this->moduleExtensionList->getPath('og_ext_cron') . '/choices/' . self::FILENAME;
    }
    catch (UnknownExtensionException) {
      return '';
    }
  }

  /**
   * Returns the AIA logger channel.
   */
  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('aia');
  }

}
