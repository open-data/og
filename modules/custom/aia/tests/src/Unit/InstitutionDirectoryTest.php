<?php

declare(strict_types=1);

namespace Drupal\Tests\aia\Unit;

use Drupal\aia\InstitutionDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests mapping of Open Government organizations.json.
 */
#[CoversClass(InstitutionDirectory::class)]
final class InstitutionDirectoryTest extends TestCase {

  /**
   * Organization ids and bilingual titles become questionnaire choices.
   */
  public function testMapOrganizations(): void {
    $choices = InstitutionDirectory::mapOrganizations([
      'cra-arc' => [
        'en' => 'Canada Revenue Agency',
        'fr' => 'Agence du revenu du Canada',
      ],
      'aafc-aac' => [
        'en' => 'Agriculture and Agri-Food Canada',
        'fr' => 'Agriculture et Agroalimentaire Canada',
      ],
    ]);

    self::assertSame(['aafc-aac', 'cra-arc'], array_column($choices, 'value'));
    self::assertSame('Agriculture and Agri-Food Canada', $choices[0]['text']['default']);
    self::assertSame('Agriculture et Agroalimentaire Canada', $choices[0]['text']['fr']);
  }

}
