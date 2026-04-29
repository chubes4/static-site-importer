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
 * Adds Appearance -> Import Static Site.
 */
class Static_Site_Importer_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_static_site_importer_import', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Add admin page under Appearance.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_theme_page(
			'Import Static Site',
			'Import Static Site',
			'switch_themes',
			'static-site-importer',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render upload form.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You are not allowed to import static sites.', 'static-site-importer' ) );
		}

		$result = isset( $_GET['static_site_imported'] ) ? sanitize_text_field( wp_unslash( $_GET['static_site_imported'] ) ) : '';
		$error  = isset( $_GET['static_site_error'] ) ? sanitize_text_field( wp_unslash( $_GET['static_site_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Import Static HTML Site', 'static-site-importer' ); ?></h1>

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

			<p><?php echo esc_html__( 'Upload a static site ZIP containing an index.html file. The importer will convert the HTML into a WordPress block theme using Block Format Bridge.', 'static-site-importer' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'static_site_importer_import' ); ?>
				<input type="hidden" name="action" value="static_site_importer_import" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="static-site-zip"><?php echo esc_html__( 'Static site ZIP', 'static-site-importer' ); ?></label></th>
						<td><input type="file" id="static-site-zip" name="static_site_zip" accept=".zip" required /></td>
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

				<?php submit_button( __( 'Import Static Site', 'static-site-importer' ) ); ?>
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
			wp_die( esc_html__( 'You are not allowed to import static sites.', 'static-site-importer' ) );
		}

		check_admin_referer( 'static_site_importer_import' );

		if ( empty( $_FILES['static_site_zip']['tmp_name'] ) ) {
			self::redirect_error( 'No ZIP file uploaded.' );
		}

		$upload = wp_handle_upload( $_FILES['static_site_zip'], array( 'test_form' => false, 'mimes' => array( 'zip' => 'application/zip' ) ) );
		if ( isset( $upload['error'] ) ) {
			self::redirect_error( (string) $upload['error'] );
		}

		$work_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'static-site-importer/' . wp_generate_uuid4();
		wp_mkdir_p( $work_dir );

		$result = unzip_file( $upload['file'], $work_dir );
		if ( is_wp_error( $result ) ) {
			self::redirect_error( $result->get_error_message() );
		}

		$html_path = self::find_index_html( $work_dir );
		if ( ! $html_path ) {
			self::redirect_error( 'The uploaded ZIP does not contain an index.html file.' );
		}

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

		wp_safe_redirect( add_query_arg( 'static_site_imported', rawurlencode( $result['theme_name'] ), admin_url( 'themes.php?page=static-site-importer' ) ) );
		exit;
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
		wp_safe_redirect( add_query_arg( 'static_site_error', rawurlencode( $message ), admin_url( 'themes.php?page=static-site-importer' ) ) );
		exit;
	}
}
