<?php

declare(strict_types=1);

namespace Drupal\aia\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exports portable AIA progress files.
 */
final class AssessmentFileController extends ControllerBase {

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tempstore.private'));
  }

  /**
   * Returns session-backed assessment storage.
   */
  private function store(): PrivateTempStore {
    return $this->tempStoreFactory->get('aia_assessment');
  }

  /**
   * Downloads progress in the original application's JSON shape.
   */
  public function export(): Response {
    $payload = [
      'version' => 'v1.0.1',
      'currentPage' => max(0, (int) ($this->store()->get('page') ?? 0) - 1),
      'data' => $this->store()->get('answers') ?? [],
      'translationsOnResult' => $this->store()->get('translations') ?? [],
      'completed' => (bool) $this->store()->get('completed'),
      'answerLanguage' => $this->store()->get('answer_language')
      ?? $this->languageManager()->getCurrentLanguage()->getId(),
    ];
    $response = new Response(
      json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
    $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="AIA Results.json"');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
