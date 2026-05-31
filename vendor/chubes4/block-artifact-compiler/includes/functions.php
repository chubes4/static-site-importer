<?php
/**
 * Public API functions.
 *
 * @package BlockArtifactCompiler
 */

if ( ! function_exists( 'bac_compile_website_artifact' ) ) {
	/**
	 * Compile a website artifact bundle into a WordPress-native artifact bundle.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	function bac_compile_website_artifact( array $artifact, array $options = array() ): array {
		$compiler = new Block_Artifact_Compiler();
		return $compiler->compile( $artifact, $options );
	}
}
