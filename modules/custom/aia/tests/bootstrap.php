<?php

/**
 * @file
 * Autoloads AIA classes for PHPUnit without a full Drupal kernel.
 */

declare(strict_types=1);

use Drupal\Component\Utility\Xss;
use Drupal\Core\Render\Markup;

spl_autoload_register(static function (string $class): void {
  $prefix = 'Drupal\\aia\\';
  if (!str_starts_with($class, $prefix)) {
    return;
  }
  $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
  $file = dirname(__DIR__) . '/src/' . $relative . '.php';
  if (is_file($file)) {
    require_once $file;
  }
});

if (!class_exists(Markup::class, FALSE)) {
  require_once __DIR__ . '/stubs/Markup.php';
}
if (!class_exists(Xss::class, FALSE)) {
  require_once __DIR__ . '/stubs/Xss.php';
}
