<?php

declare(strict_types=1);

namespace Drupal\Tests\aia\Unit;

use Drupal\aia\InstitutionDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests mapping of the Open Government department API.
 */
#[CoversClass(InstitutionDirectory::class)]
final class InstitutionDirectoryTest extends TestCase {

  /**
   * Numbered shells are skipped and slugs stay stable.
   */
  public function testMapRecordsKeepsDepartmentCodes(): void {
    $choices = InstitutionDirectory::mapRecords([
      [
        'title_en' => 'Agriculture and Agri-Food Canada',
        'title_fr' => 'Agriculture et Agroalimentaire Canada',
        'department_number' => '235',
        'umd_number' => '1',
        'open_canada_id' => 'aafc-aac',
      ],
      [
        'title_en' => '16342451 Canada Inc.',
        'title_fr' => '16342451 Canada Inc.',
        'department_number' => NULL,
        'umd_number' => NULL,
        'open_canada_id' => '16342451-canada-inc',
      ],
      [
        'title_en' => 'Canada Revenue Agency',
        'title_fr' => 'Agence du revenu du Canada',
        'department_number' => '47',
        'umd_number' => '72',
        'open_canada_id' => 'cra-arc',
      ],
    ]);

    self::assertSame(['aafc-aac', 'cra-arc'], array_column($choices, 'value'));
    self::assertSame('Agriculture and Agri-Food Canada', $choices[0]['text']['default']);
    self::assertSame('Agriculture et Agroalimentaire Canada', $choices[0]['text']['fr']);
  }

  /**
   * Matched live titles keep bundled values used by saved answers.
   */
  public function testMergeChoicesPreservesBundledValues(): void {
    $live = InstitutionDirectory::mapRecords([
      [
        'title_en' => 'Agriculture and Agri-Food Canada',
        'title_fr' => 'Agriculture et Agroalimentaire Canada',
        'department_number' => '235',
        'open_canada_id' => 'aafc-aac',
      ],
      [
        'title_en' => 'Housing, Infrastructure and Communities Canada',
        'title_fr' => 'Logement, Infrastructures et Collectivités Canada',
        'department_number' => '99',
        'open_canada_id' => 'hicc-licc',
      ],
    ]);
    $merged = InstitutionDirectory::mergeChoices($live, [
      [
        'value' => '001',
        'text' => [
          'default' => 'Agriculture and Agri-Food (Department of)',
          'fr' => "Agriculture et Agroalimentaire (Ministère de l')",
        ],
      ],
    ]);

    self::assertSame(['001', 'hicc-licc'], array_column($merged, 'value'));
    self::assertSame('Agriculture and Agri-Food Canada', $merged[0]['text']['default']);
  }

  /**
   * institutions.json round-trips as the only department source.
   */
  public function testEncodeAndDecodeFile(): void {
    $choices = [
      [
        'value' => '001',
        'text' => [
          'default' => 'Agriculture and Agri-Food Canada',
          'fr' => 'Agriculture et Agroalimentaire Canada',
        ],
      ],
    ];
    $decoded = InstitutionDirectory::decodeFile(InstitutionDirectory::encodeFile($choices));
    self::assertSame($choices, $decoded);
  }

}
