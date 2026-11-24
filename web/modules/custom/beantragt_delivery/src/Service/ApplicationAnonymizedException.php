<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

/**
 * An anonymized application receives no new response.
 *
 * Thrown by ExternalResponseService when the application, loaded fresh under
 * its mutation lock, was anonymized (retention run) after the caller loaded
 * it: nothing is stored and no provision mail is sent.
 */
final class ApplicationAnonymizedException extends \RuntimeException {}
