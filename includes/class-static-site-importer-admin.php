<?php
/**
 * Admin UI.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Import HTML admin entry point.
 */
class Static_Site_Importer_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_import_page' ) );
		add_action( 'admin_head-themes.php', array( __CLASS__, 'render_themes_screen_button' ) );
		add_action( 'admin_post_static_site_importer_import', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Register the import page without adding a persistent Appearance submenu item.
	 *
	 * @return void
	 */
	public static function register_import_page(): void {
		add_submenu_page(
			null,
			'Import HTML',
			'Import HTML',
			'switch_themes',
			'static-site-importer',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Add an Import HTML button beside Add Theme on Appearance -> Themes.
	 *
	 * @return void
	 */
	public static function render_themes_screen_button(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return;
		}

		$url   = admin_url( 'admin.php?page=static-site-importer' );
		$label = __( 'Import HTML', 'static-site-importer' );
		?>
		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				var addThemeButton = document.querySelector( '.wrap .page-title-action[href*="theme-install.php"]' );
				var heading = document.querySelector( '.wrap .wp-heading-inline' );
				var button = document.createElement( 'a' );

				button.href = <?php echo wp_json_encode( $url ); ?>;
				button.className = 'page-title-action static-site-importer-import-html-action';
				button.textContent = <?php echo wp_json_encode( $label ); ?>;

				if ( addThemeButton && addThemeButton.parentNode ) {
					addThemeButton.parentNode.insertBefore( button, addThemeButton.nextSibling );
					return;
				}

				if ( heading && heading.parentNode ) {
					heading.parentNode.insertBefore( button, heading.nextSibling );
				}
			} );
		</script>
		<?php
	}

	/**
	 * Render upload form.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You are not allowed to import HTML.', 'static-site-importer' ) );
		}

		$result = isset( $_GET['static_site_imported'] ) ? sanitize_text_field( wp_unslash( $_GET['static_site_imported'] ) ) : '';
		$error  = isset( $_GET['static_site_error'] ) ? sanitize_text_field( wp_unslash( $_GET['static_site_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Import HTML', 'static-site-importer' ); ?></h1>

			<?php if ( '' !== $result ) : ?>
				<div class="notice notice-success"><p>
					<?php echo esc_html( sprintf( 'Imported block theme: %s', $result ) ); ?>
					<a href="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>"><?php echo esc_html__( 'View themes', 'static-site-importer' ); ?></a>
					<?php if ( current_user_can( 'edit_theme_options' ) ) : ?>
						| <a href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>"><?php echo esc_html__( 'Open Site Editor', 'static-site-importer' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<p><?php echo esc_html__( 'Paste HTML, upload a single HTML file, or upload a ZIP containing an index.html file. The importer will convert the HTML into a WordPress block theme using Block Format Bridge.', 'static-site-importer' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'static_site_importer_import' ); ?>
				<input type="hidden" name="action" value="static_site_importer_import" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="static-site-pasted-html"><?php echo esc_html__( 'Paste HTML', 'static-site-importer' ); ?></label></th>
						<td>
							<textarea id="static-site-pasted-html" name="static_site_pasted_html" class="large-text code" rows="14" placeholder="<!doctype html>"></textarea>
							<p class="description"><?php echo esc_html__( 'Use this for one-page HTML copied from an AI builder or template source. Leave empty to import an HTML file or ZIP instead.', 'static-site-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="static-site-html"><?php echo esc_html__( 'Single HTML file', 'static-site-importer' ); ?></label></th>
						<td>
							<input type="file" id="static-site-html" name="static_site_html" accept=".html,.htm" />
							<p class="description"><?php echo esc_html__( 'Use this for a standalone .html or .htm file. Pasted HTML takes precedence when both are provided.', 'static-site-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="static-site-zip"><?php echo esc_html__( 'HTML ZIP', 'static-site-importer' ); ?></label></th>
						<td>
							<input type="file" id="static-site-zip" name="static_site_zip" accept=".zip" />
							<p class="description"><?php echo esc_html__( 'Use this for a site folder packaged as a ZIP with an index.html file and sibling HTML pages. Pasted HTML and single HTML uploads take precedence when provided.', 'static-site-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="theme-name"><?php echo esc_html__( 'Theme name', 'static-site-importer' ); ?></label></th>
						<td><input type="text" class="regular-text" id="theme-name" name="theme_name" placeholder="WordPress Is Dead" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="theme-slug"><?php echo esc_html__( 'Theme slug', 'static-site-importer' ); ?></label></th>
						<td><input type="text" class="regular-text" id="theme-slug" name="theme_slug" placeholder="wordpress-is-dead" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Options', 'static-site-importer' ); ?></th>
						<td><label><input type="checkbox" name="activate" value="1" checked /> <?php echo esc_html__( 'Activate imported theme', 'static-site-importer' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button( __( 'Import HTML', 'static-site-importer' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle upload and import.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You are not allowed to import HTML.', 'static-site-importer' ) );
		}

		check_admin_referer( 'static_site_importer_import' );

		$pasted_html = isset( $_POST['static_site_pasted_html'] ) ? trim( (string) wp_unslash( $_POST['static_site_pasted_html'] ) ) : '';
		$html_path   = '' !== $pasted_html ? self::write_pasted_html( $pasted_html ) : self::prepare_uploaded_entry_file();

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$html_path,
			array(
				'name'      => isset( $_POST['theme_name'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_name'] ) ) : '',
				'slug'      => isset( $_POST['theme_slug'] ) ? sanitize_title( wp_unslash( $_POST['theme_slug'] ) ) : '',
				'activate'  => ! empty( $_POST['activate'] ),
				'overwrite' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect_error( $result->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( 'static_site_imported', rawurlencode( $result['theme_name'] ), admin_url( 'admin.php?page=static-site-importer' ) ) );
		exit;
	}

	/**
	 * Write pasted HTML to a generated import work directory.
	 *
	 * @param string $html Raw pasted HTML.
	 * @return string HTML entry path.
	 */
	private static function write_pasted_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			self::redirect_error( 'Paste HTML content, upload a single HTML file, or upload a ZIP containing index.html.' );
		}

		$work_dir  = self::create_work_dir();
		$html_path = trailingslashit( $work_dir ) . 'index.html';
		$result    = file_put_contents( $html_path, $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a generated upload work file for the existing importer.

		if ( false === $result ) {
			self::redirect_error( 'Failed to write pasted HTML to the import work directory.' );
		}

		return $html_path;
	}

	/**
	 * Prepare the uploaded HTML entry file.
	 *
	 * @return string HTML entry path.
	 */
	private static function prepare_uploaded_entry_file(): string {
		$work_dir = self::create_work_dir();

		if ( self::has_uploaded_file( 'static_site_html' ) ) {
			return self::prepare_uploaded_html_file( $work_dir );
		}

		if ( self::has_uploaded_file( 'static_site_zip' ) ) {
			return self::prepare_uploaded_zip_file( $work_dir );
		}

		self::redirect_error( 'Paste HTML content, upload a single HTML file, or upload a ZIP containing index.html.' );
	}

	/**
	 * Store a direct HTML upload in an importer work directory.
	 *
	 * @param string $work_dir Importer work directory.
	 * @return string HTML entry path.
	 */
	private static function prepare_uploaded_html_file( string $work_dir ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		$file = $_FILES['static_site_html'];
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'html', 'htm' ), true ) ) {
			self::redirect_error( 'Upload an .html or .htm file.' );
		}

		if ( empty( $file['size'] ) || empty( $file['tmp_name'] ) || ! is_readable( (string) $file['tmp_name'] ) ) {
			self::redirect_error( 'The uploaded HTML file is empty or unreadable.' );
		}

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'html' => 'text/html',
					'htm'  => 'text/html',
				),
			)
		);
		if ( isset( $upload['error'] ) ) {
			self::redirect_error( (string) $upload['error'] );
		}

		$target = trailingslashit( $work_dir ) . 'index.html';
		if ( ! copy( $upload['file'], $target ) ) {
			self::redirect_error( 'Could not store the uploaded HTML file.' );
		}

		return $target;
	}

	/**
	 * Extract an uploaded ZIP and return its index.html path.
	 *
	 * @param string $work_dir Importer work directory.
	 * @return string HTML entry path.
	 */
	private static function prepare_uploaded_zip_file( string $work_dir ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		$zip_file = $_FILES['static_site_zip'];
		$upload   = wp_handle_upload(
			$zip_file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'zip' => 'application/zip',
				),
			)
		);
		if ( isset( $upload['error'] ) ) {
			self::redirect_error( (string) $upload['error'] );
		}

		$result = unzip_file( $upload['file'], $work_dir );
		if ( is_wp_error( $result ) ) {
			self::redirect_error( $result->get_error_message() );
		}

		$html_path = self::find_index_html( $work_dir );
		if ( ! $html_path ) {
			self::redirect_error( 'The uploaded ZIP does not contain an index.html file.' );
		}

		return $html_path;
	}

	/**
	 * Create an importer work directory.
	 *
	 * @return string Directory path.
	 */
	private static function create_work_dir(): string {
		$work_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'static-site-importer/' . wp_generate_uuid4();
		wp_mkdir_p( $work_dir );

		return $work_dir;
	}

	/**
	 * Determine whether a named upload field has a file.
	 *
	 * @param string $field Upload field name.
	 * @return bool
	 */
	private static function has_uploaded_file( string $field ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		if ( ! isset( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		$error = $_FILES[ $field ]['error'] ?? UPLOAD_ERR_NO_FILE;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		return UPLOAD_ERR_NO_FILE !== $error && ! empty( $_FILES[ $field ]['tmp_name'] );
	}

	/**
	 * Find index.html in an extracted ZIP.
	 *
	 * @param string $dir Directory.
	 * @return string|null
	 */
	private static function find_index_html( string $dir ): ?string {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && 'index.html' === strtolower( $file->getFilename() ) ) {
				return $file->getPathname();
			}
		}

		return null;
	}

	/**
	 * Redirect to admin page with an error.
	 *
	 * @param string $message Error message.
	 * @return never
	 */
	private static function redirect_error( string $message ) {
		wp_safe_redirect( add_query_arg( 'static_site_error', rawurlencode( $message ), admin_url( 'admin.php?page=static-site-importer' ) ) );
		exit;
	}
}
