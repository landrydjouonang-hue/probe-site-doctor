<?php
/**
 * Raised when a cleanup is attempted without valid confirmation.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup confirmation failure.
 */
final class CleanupNotConfirmedException extends \RuntimeException {}
