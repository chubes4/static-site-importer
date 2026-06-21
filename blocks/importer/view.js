( function () {
	var roots = document.querySelectorAll( '[data-static-site-importer]' );

	var fileToBase64 = function ( file ) {
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function () {
				var result = typeof reader.result === 'string' ? reader.result : '';
				resolve( result.replace( /^data:[^,]*,/, '' ) );
			};
			reader.onerror = function () {
				reject( reader.error || new Error( 'Unable to read uploaded file.' ) );
			};
			reader.readAsDataURL( file );
		} );
	};

	var fileToText = function ( file ) {
		return typeof file.text === 'function' ? file.text() : new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function () {
				resolve( typeof reader.result === 'string' ? reader.result : '' );
			};
			reader.onerror = function () {
				reject( reader.error || new Error( 'Unable to read uploaded file.' ) );
			};
			reader.readAsText( file );
		} );
	};

	var uploadedFilePath = function ( file ) {
		return file.webkitRelativePath || file.name || 'upload';
	};

	var buildFiles = async function ( input ) {
		var selectedFiles = input && input.files ? Array.prototype.slice.call( input.files ) : [];
		return Promise.all( selectedFiles.map( async function ( file ) {
			var path = uploadedFilePath( file );
			var record = {
				path: path,
				name: file.name || path,
				type: file.type || '',
				size: file.size || 0,
			};

			if ( /\.html?$/i.test( path ) || /^text\//i.test( file.type || '' ) ) {
				record.content = await fileToText( file );
			} else {
				record.content_base64 = await fileToBase64( file );
			}

			return record;
		} ) );
	};

	var setReport = function ( root, report ) {
		var reportEl = root.querySelector( '[data-static-site-importer-report]' );
		if ( reportEl ) {
			reportEl.hidden = false;
			reportEl.value = JSON.stringify( report, null, 2 );
		}
	};

	var showStatus = function ( root, message ) {
		var status = root.querySelector( '[data-static-site-importer-status]' );
		var progress = root.querySelector( '[data-static-site-importer-progress]' );
		if ( status ) {
			status.hidden = false;
		}
		if ( progress ) {
			progress.textContent = message;
		}
	};

	var hasSource = function ( source ) {
		return Boolean(
			( source.files && source.files.length > 0 ) ||
			( source.html && source.html.trim() ) ||
			( source.url && source.url.trim() )
		);
	};

	roots.forEach( function ( root ) {
		var submit = root.querySelector( '[data-static-site-importer-submit]' );
		if ( ! submit ) {
			return;
		}

		submit.addEventListener( 'click', async function () {
			var sourceUrl = root.querySelector( '[data-static-site-importer-source-url]' );
			var html = root.querySelector( '[data-static-site-importer-source-html]' );
			var files = root.querySelector( '[data-static-site-importer-source-files]' );
			var restUrl = root.getAttribute( 'data-static-site-importer-rest-url' );
			var nonce = root.getAttribute( 'data-static-site-importer-nonce' );
			var provider = root.getAttribute( 'data-static-site-importer-provider' ) || '';
			var source = {
				url: sourceUrl ? sourceUrl.value : '',
				html: html ? html.value : '',
				files: await buildFiles( files ),
			};

			if ( ! hasSource( source ) ) {
				showStatus( root, 'Add a website URL, site files, or raw HTML to start.' );
				return;
			}

			showStatus( root, 'Importing site into WordPress...' );
			submit.disabled = true;

			try {
				var response = await fetch( restUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( {
						provider: provider,
						source: source,
						activate: true,
						overwrite: true,
					} ),
				} );
				var report = await response.json();
				setReport( root, report );
				showStatus( root, response.ok && report.success ? 'Import complete.' : 'Import failed.' );
			} catch ( error ) {
				setReport( root, { success: false, error: { message: error.message } } );
				showStatus( root, 'Import request failed.' );
			} finally {
				submit.disabled = false;
			}
		} );
	} );
} )();
