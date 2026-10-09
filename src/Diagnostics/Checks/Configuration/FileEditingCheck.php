<?php
/**
 * File editing settings check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Configuration;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews whether plugin and theme files can be edited from the admin, and
 * whether file modifications are blocked altogether.
 *
 * Reads constants and settings; it never changes them.
 */
final class FileEditingCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.file-editing';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'File editing settings', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks the built-in plugin/theme file editor and the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constants.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$edit_blocked = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$mods_blocked = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
		$editor_shown = ! $edit_blocked && ! $mods_blocked;

		$data = array(
			'file_editor_available' => $editor_shown,
			'disallow_file_edit'    => $edit_blocked,
			'disallow_file_mods'    => $mods_blocked,
			'environment_type'      => wp_get_environment_type(),
		);

		$steps = ( new ManualCleanup(
			__( 'Turn off the built-in file editor by adding one line to wp-config.php. The editor is only a convenience; FTP, SSH or a deployment process can still change files.', 'probe-site-doctor' ),
			array(
				__( 'Open wp-config.php and add the line below above the "That\'s all, stop editing!" comment.', 'probe-site-doctor' ),
				__( 'Reload the admin and confirm that Appearance → Theme File Editor and Plugins → Plugin File Editor are gone.', 'probe-site-doctor' ),
				__( 'Keep a way to edit files outside WordPress (FTP/SSH) before you switch it off.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command( __( 'Disable the plugin and theme file editors', 'probe-site-doctor' ), "define( 'DISALLOW_FILE_EDIT', true );", ManualCleanup::TYPE_PHP );

		if ( $mods_blocked ) {
			return $this->info(
				__( 'All file modifications are blocked', 'probe-site-doctor' ),
				__( 'DISALLOW_FILE_MODS is enabled, so the file editors are hidden and WordPress cannot install or update plugins, themes or core from the admin. This is normal for sites deployed from version control, but it also means updates must be applied another way.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				__( 'Make sure updates are applied by your deployment process, and check the update recommendations in this report.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::LOW, __( 'Blocking file changes removes one way of tampering with code, but it also stops in-admin updates, so someone has to keep the site current another way.', 'probe-site-doctor' ) );
		}

		if ( $edit_blocked ) {
			return $this->good(
				__( 'The built-in file editor is disabled', 'probe-site-doctor' ),
				__( 'DISALLOW_FILE_EDIT is enabled, so plugin and theme files cannot be edited from the admin. Updates and installs still work.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				$data
			);
		}

		if ( $this->is_non_production() ) {
			return $this->info(
				__( 'The file editor is available (non-production environment)', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Environment type. */
					__( 'Plugin and theme files can be edited from the admin. The environment type is "%s", where this is usually intentional.', 'probe-site-doctor' ),
					wp_get_environment_type()
				) . ' ' . $this->indicator_note(),
				__( 'Disable the file editor in the configuration you use for the live site.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::LOW, __( 'On a development site the editor is convenient and the risk is limited.', 'probe-site-doctor' ) )
				->with_cleanup( $steps );
		}

		return $this->warning(
			__( 'Plugin and theme files can be edited from the admin', 'probe-site-doctor' ),
			__( 'The built-in file editors are available. Anyone who reaches an administrator account can change PHP code from the browser, which turns one compromised login into code execution. Editing live files by hand can also break the site with no undo.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
			__( 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php once you have FTP or SSH access as a fallback.', 'probe-site-doctor' ),
			$data
		)->with_impact( Impact::MEDIUM, __( 'This does not make the site vulnerable by itself: it removes a step for an attacker who already has an administrator session, and prevents accidental edits.', 'probe-site-doctor' ) )
			->with_cleanup( $steps );
	}
}
