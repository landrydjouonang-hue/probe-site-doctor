<?php
/**
 * Administrator account indicators check.
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
 * Reviews administrator accounts and registration settings: how many
 * administrators exist, whether a guessable "admin" login is present, and
 * whether new registrations become administrators.
 *
 * Passwords are never read or tested: WordPress stores only hashes, and
 * Site Doctor does not attempt to crack them.
 */
final class AdminAccountsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.admin-accounts';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'Administrator account indicators', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts administrator accounts and checks guessable logins and the registration role. Passwords are never examined.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$t = $this->thresholds( array( 'many_admins' => 5 ) );

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 200,
				'fields' => array( 'ID', 'user_login', 'display_name' ),
			)
		);

		$guessable = array();
		$exposed   = array();
		foreach ( $admins as $admin ) {
			$login = strtolower( (string) $admin->user_login );
			if ( in_array( $login, array( 'admin', 'administrator', 'root', 'webmaster', 'wordpress', 'test' ), true ) ) {
				$guessable[] = (string) $admin->user_login;
			}
			if ( 0 === strcasecmp( (string) $admin->display_name, (string) $admin->user_login ) ) {
				$exposed[] = (string) $admin->user_login;
			}
		}

		$can_register = (bool) get_option( 'users_can_register' );
		$default_role = (string) get_option( 'default_role' );
		$open_admin   = $can_register && 'administrator' === $default_role;

		$data = array(
			'administrators'          => count( $admins ),
			'guessable_logins'        => $guessable,
			'display_name_equals_login' => count( $exposed ),
			'registration_open'       => $can_register,
			'default_role'            => $default_role,
		);

		$severity = Severity::GOOD;
		$issues   = array();
		$actions  = array();

		if ( $open_admin ) {
			$severity  = Severity::CRITICAL;
			$issues[]  = __( 'Anyone can register an account and new accounts become administrators.', 'probe-site-doctor' );
			$actions[] = __( 'Change the default role under Settings → General immediately (Subscriber is the usual choice), and review the accounts that already exist.', 'probe-site-doctor' );
		} elseif ( $can_register && in_array( $default_role, array( 'editor', 'author' ), true ) ) {
			$severity  = $this->worst( $severity, Severity::WARNING );
			/* translators: %s: Role name. */
			$issues[]  = sprintf( __( 'Registration is open and new accounts receive the "%s" role, which can publish content.', 'probe-site-doctor' ), $default_role );
			$actions[] = __( 'Set the default role to Subscriber unless contributors really need publishing rights.', 'probe-site-doctor' );
		}

		if ( $guessable ) {
			$severity  = $this->worst( $severity, Severity::WARNING );
			$issues[]  = sprintf(
				/* translators: %s: Comma-separated list of user logins. */
				__( 'An administrator uses a commonly guessed login (%s), which is the first thing automated login attempts try.', 'probe-site-doctor' ),
				implode( ', ', $guessable )
			);
			$actions[] = __( 'Create a new administrator with an individual username, move content over if needed, then delete or downgrade the old account. Usernames cannot be renamed in WordPress.', 'probe-site-doctor' );
		}

		if ( count( $admins ) > $t['many_admins'] ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = sprintf(
				/* translators: %s: Number of administrators. */
				__( 'The site has %s administrator accounts. Every one of them can install code, so each is a way in.', 'probe-site-doctor' ),
				number_format_i18n( count( $admins ) )
			);
			$actions[] = __( 'Review the list and give people the lowest role that lets them do their work.', 'probe-site-doctor' );
		}

		if ( $exposed ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = sprintf(
				/* translators: %s: Number of accounts. */
				_n( '%s administrator shows their login as their public display name, so the username is visible wherever they are credited.', '%s administrators show their login as their public display name, so those usernames are visible wherever they are credited.', count( $exposed ), 'probe-site-doctor' ),
				number_format_i18n( count( $exposed ) )
			);
			$actions[] = __( 'Set a separate display name on those profiles. This is obscurity only: it does not stop a determined attacker.', 'probe-site-doctor' );
		}

		$summary = sprintf(
			/* translators: %s: Number of administrators. */
			_n( 'The site has %s administrator account.', 'The site has %s administrator accounts.', count( $admins ), 'probe-site-doctor' ),
			number_format_i18n( count( $admins ) )
		);

		if ( Severity::GOOD === $severity ) {
			return $this->good(
				__( 'Administrator accounts look sensible', 'probe-site-doctor' ),
				$summary . ' ' . __( 'No commonly guessed administrator logins, and registration settings are safe.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				$data
			);
		}

		return $this->result(
			$severity,
			$open_admin ? __( 'New registrations become administrators', 'probe-site-doctor' ) : __( 'Administrator accounts could be tightened', 'probe-site-doctor' ),
			$summary . ' ' . implode( ' ', $issues ) . ' ' . __( 'Passwords and two-factor settings are not examined.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
			implode( ' ', $actions ),
			$data
		)->with_impact(
			$open_admin ? Impact::HIGH : ( $guessable ? Impact::MEDIUM : Impact::LOW ),
			$open_admin
				? __( 'Anyone on the internet can take full control of the site until the default role is changed.', 'probe-site-doctor' )
				: __( 'These settings do not make the site vulnerable on their own; they make guessing attacks easier or widen the number of accounts worth attacking. Strong unique passwords and two-factor authentication matter more.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Adjust accounts and registration settings yourself. Site Doctor never changes users, roles or settings.', 'probe-site-doctor' ),
				array(
					__( 'Settings → General: check "Anyone can register" and the default role.', 'probe-site-doctor' ),
					__( 'Users: review who has the Administrator role and lower roles where possible.', 'probe-site-doctor' ),
					__( 'Replace guessable administrator logins by creating a new account, reassigning content, then deleting the old one.', 'probe-site-doctor' ),
					__( 'Require strong passwords and add two-factor authentication for administrators.', 'probe-site-doctor' ),
				)
			) )->as_hardening()
				->with_command( __( 'List administrator accounts with WP-CLI (changes nothing)', 'probe-site-doctor' ), 'wp user list --role=administrator --fields=ID,user_login,user_email,display_name', ManualCleanup::TYPE_WP_CLI )
		);
	}
}
