<?php

declare(strict_types=1);

namespace Drupal\aia\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Redirects the old aia-eia-js URLs to /aia and /eia.
 */
final class LegacyPathController extends ControllerBase {

  /**
   * Redirects the old assessment path.
   */
  public function assessment(Request $request): LocalRedirectResponse {
    return $this->redirectTo('aia.assessment', 'aia.assessment_fr', [], $request);
  }

  /**
   * Redirects the old results path.
   */
  public function results(Request $request): LocalRedirectResponse {
    return $this->redirectTo('aia.results', 'aia.results_fr', [], $request);
  }

  /**
   * Redirects the old JSON export path.
   */
  public function export(Request $request): LocalRedirectResponse {
    return $this->redirectTo('aia.export', 'aia.export_fr', [], $request);
  }

  /**
   * Redirects the old PDF path.
   */
  public function pdf(Request $request, string $language): LocalRedirectResponse {
    $language = $language === 'fr' ? 'fr' : 'en';
    return $this->redirectTo('aia.pdf', 'aia.pdf_fr', ['language' => $language], $request, $language);
  }

  /**
   * Issues a 301 to the language-specific public path.
   */
  private function redirectTo(string $englishRoute, string $frenchRoute, array $parameters, Request $request, ?string $langcode = NULL): LocalRedirectResponse {
    $langcode ??= $request->query->get('lang') === 'fr' ? 'fr' : 'en';
    $route = $langcode === 'fr' ? $frenchRoute : $englishRoute;
    $path = Url::fromRoute($route, $parameters)->toString();
    $path = preg_replace('#^/(en|fr)(/|$)#', '/', $path) ?: $path;

    $response = new LocalRedirectResponse($path, 301);
    $cache = new CacheableMetadata();
    $cache->setCacheMaxAge(0);
    $cache->addCacheContexts(['url.query_args:lang']);
    $response->addCacheableDependency($cache);
    return $response;
  }

}
