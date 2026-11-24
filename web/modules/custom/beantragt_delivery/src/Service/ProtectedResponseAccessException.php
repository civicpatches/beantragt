<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

/**
 * Reading a response with protected data needs the protected-data permission.
 *
 * Thrown when a staff member without that permission asks for a response
 * whose documents carry protected application data in clear text.
 */
final class ProtectedResponseAccessException extends \RuntimeException {}
