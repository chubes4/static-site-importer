#!/usr/bin/env node
/**
 * Smoke test: generated static importer theme artifacts pass Gutenberg block validation.
 *
 * Run after importing the wordpress-is-dead fixture theme:
 * npm run test:js-block-validation -- /path/to/wp-content/themes/wordpress-is-dead
 *
 * Or set STATIC_SITE_IMPORTER_THEME_DIR=/path/to/theme.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const process = require( 'node:process' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
  virtualConsole: new VirtualConsole(),
} );
globalThis.window = dom.window;
globalThis.document = dom.window.document;
Object.defineProperty( globalThis, 'navigator', {
  value: dom.window.navigator,
  configurable: true,
} );
globalThis.HTMLElement = dom.window.HTMLElement;
globalThis.Node = dom.window.Node;
globalThis.DOMParser = dom.window.DOMParser;
globalThis.MutationObserver = dom.window.MutationObserver;
globalThis.getComputedStyle = dom.window.getComputedStyle;
globalThis.requestAnimationFrame = ( callback ) => setTimeout( callback, 0 );
globalThis.cancelAnimationFrame = ( id ) => clearTimeout( id );

const { registerCoreBlocks } = require( '@wordpress/block-library' );
const { parse, validateBlock } = require( '@wordpress/blocks' );

registerCoreBlocks();

const repoRoot = path.resolve( __dirname, '..' );
const defaultThemeDir = process.env.WP_CONTENT_DIR
  ? path.join( process.env.WP_CONTENT_DIR, 'themes', 'wordpress-is-dead' )
  : path.join( repoRoot, 'wordpress-is-dead' );
const themeDir = path.resolve(
  process.argv[ 2 ] ||
    process.env.STATIC_SITE_IMPORTER_THEME_DIR ||
    defaultThemeDir
);

if ( ! fs.existsSync( themeDir ) ) {
  console.error( `Theme directory does not exist: ${ themeDir }` );
  console.error( 'Import the fixture first or pass a theme path as the first argument.' );
  process.exit( 1 );
}

const targetFiles = [
  'parts/header.html',
  'parts/footer.html',
  ...listFiles( path.join( themeDir, 'patterns' ), '.php' ).map( ( file ) => path.join( 'patterns', file ) ),
  ...listFiles( path.join( themeDir, 'templates' ), '.html' ).map( ( file ) => path.join( 'templates', file ) ),
];

const failures = [];
let blockCount = 0;

for ( const relativePath of targetFiles ) {
  const filePath = path.join( themeDir, relativePath );
  if ( ! fs.existsSync( filePath ) ) {
    failures.push( {
      file: relativePath,
      path: '(file)',
      reasons: [ `Missing generated artifact: ${ relativePath }` ],
    } );
    continue;
  }

  const content = extractBlockContent( fs.readFileSync( filePath, 'utf8' ) );
  const blocks = withConsoleSilenced( () => parse( content ) );
  blockCount += countBlocks( blocks );
  validateBlocks( blocks, relativePath );
}

if ( failures.length ) {
  for ( const failure of failures ) {
    console.error( `FAIL ${ failure.file } ${ failure.path }` );
    for ( const reason of failure.reasons ) {
      console.error( `  - ${ reason }` );
    }
  }
  console.error( `Block validation failed: ${ failures.length } invalid block(s) in ${ targetFiles.length } file(s).` );
  process.exit( 1 );
}

console.log( `OK: JS block validation smoke passed (${ blockCount } blocks across ${ targetFiles.length } files)` );

function listFiles( directory, extension ) {
  if ( ! fs.existsSync( directory ) ) {
    return [];
  }

  return fs
    .readdirSync( directory )
    .filter( ( file ) => file.endsWith( extension ) )
    .sort( ( left, right ) => left.localeCompare( right ) );
}

function extractBlockContent( content ) {
  const phpClose = content.indexOf( '?>' );
  return ( phpClose === -1 ? content : content.slice( phpClose + 2 ) ).trim();
}

function validateBlocks( blocks, file, trail = [] ) {
  blocks.forEach( ( block, index ) => {
    const blockPath = [ ...trail, `${ block.name || 'unknown' }[${ index }]` ];
    const [ isValid, validationIssues = [] ] = withConsoleSilenced( () => validateBlock( block ) );
    if ( ! isValid ) {
      failures.push( {
        file,
        path: blockPath.join( ' > ' ),
        reasons: normalizeIssues( validationIssues ),
      } );
    }

    validateBlocks( block.innerBlocks || [], file, blockPath );
  } );
}

function normalizeIssues( issues ) {
  if ( ! issues.length ) {
    return [ 'validateBlock() returned false without a reason.' ];
  }

  return issues.map( formatIssue );
}

function formatIssue( issue ) {
  if ( typeof issue === 'string' ) {
    return issue;
  }

  if ( Array.isArray( issue?.args ) ) {
    const [ template, ...args ] = issue.args;

    if ( String( template ).startsWith( 'Block validation failed for `%s`' ) ) {
      return `${ args[ 0 ] } save output does not match stored content; generated=${ truncate( args[ 2 ] ) }; stored=${ truncate( args[ 3 ] ) }`;
    }

    if ( String( template ).startsWith( 'Expected attributes %o' ) ) {
      return `Expected attributes ${ formatValue( args[ 0 ] ) }; saw ${ formatValue( args[ 1 ] ) }`;
    }

    if ( String( template ).startsWith( 'Expected token of type `%s`' ) ) {
      return `Expected ${ args[ 0 ] } ${ formatValue( args[ 1 ] ) }; saw ${ args[ 2 ] } ${ formatValue( args[ 3 ] ) }`;
    }

    if ( String( template ).startsWith( 'Expected end of content' ) ) {
      return `Expected end of content; saw ${ formatValue( args[ 0 ] ) }`;
    }

    return `${ template } ${ args.map( formatValue ).join( ' ' ) }`;
  }

  if ( issue?.message ) {
    return issue.message;
  }

  return truncate( JSON.stringify( issue ) );
}

function formatValue( value ) {
  if ( value?.type && value?.tagName ) {
    const attributes = ( value.attributes || [] )
      .map( ( [ name, attributeValue ] ) => `${ name }="${ attributeValue }"` )
      .join( ' ' );
    return `<${ value.tagName }${ attributes ? ` ${ attributes }` : '' }>`;
  }

  if ( Array.isArray( value ) ) {
    return value
      .map( ( item ) => ( Array.isArray( item ) ? `${ item[ 0 ] }="${ item[ 1 ] }"` : formatValue( item ) ) )
      .join( ', ' );
  }

  if ( 'string' === typeof value ) {
    return truncate( value );
  }

  return truncate( JSON.stringify( value ) );
}

function truncate( value, length = 180 ) {
  const stringValue = String( value ).replace( /\s+/g, ' ' ).trim();
  return stringValue.length > length ? `${ stringValue.slice( 0, length - 1 ) }…` : stringValue;
}

function countBlocks( blocks ) {
  return blocks.reduce( ( total, block ) => total + 1 + countBlocks( block.innerBlocks || [] ), 0 );
}

function withConsoleSilenced( callback ) {
  const methods = [ 'debug', 'error', 'group', 'groupCollapsed', 'groupEnd', 'info', 'log', 'warn' ];
  const originalConsole = new Map( methods.map( ( method ) => [ method, console[ method ] ] ) );
  const originalWindowConsole = window.console;
  const originalStderrWrite = process.stderr.write;
  const originalStdoutWrite = process.stdout.write;
  const silentConsole = { ...console };

  for ( const method of methods ) {
    console[ method ] = () => {};
    silentConsole[ method ] = () => {};
  }
  window.console = silentConsole;
  process.stderr.write = () => true;
  process.stdout.write = () => true;

  try {
    return callback();
  } finally {
    for ( const [ method, original ] of originalConsole ) {
      console[ method ] = original;
    }
    window.console = originalWindowConsole;
    process.stderr.write = originalStderrWrite;
    process.stdout.write = originalStdoutWrite;
  }
}
