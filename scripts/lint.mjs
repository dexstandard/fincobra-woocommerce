import { readFile, readdir } from 'node:fs/promises';
import { dirname, extname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const errors = [];

async function walk( directory ) {
	const entries = await readdir( directory, { withFileTypes: true } );
	const files = [];
	for ( const entry of entries ) {
		if ( [ '.git', 'dist', 'node_modules' ].includes( entry.name ) ) {
			continue;
		}
		const path = join( directory, entry.name );
		if ( entry.isDirectory() ) {
			files.push( ...( await walk( path ) ) );
		} else {
			files.push( path );
		}
	}
	return files;
}

for ( const path of await walk( root ) ) {
	if ( ! [ '.js', '.mjs', '.php' ].includes( extname( path ) ) ) {
		continue;
	}
	const source = await readFile( path, 'utf8' );
	if ( /\r/.test( source ) ) {
		errors.push( `${ path }: CRLF line endings` );
	}
	if ( /[ \t]+$/m.test( source ) ) {
		errors.push( `${ path }: trailing whitespace` );
	}
	if ( extname( path ) === '.php' && /\$_(?:GET|POST|REQUEST)\[[^\]]+\](?!\s*\))/.test( source ) && ! source.includes( 'wp_unslash' ) ) {
		errors.push( `${ path }: superglobal input must be unslashed and sanitized` );
	}
	if ( ! path.includes( '/scripts/' ) && /\bconsole\.(?:log|warn|error|info)\b/.test( source ) ) {
		errors.push( `${ path }: console logging is forbidden` );
	}
}

if ( errors.length > 0 ) {
	process.stderr.write( `${ errors.join( '\n' ) }\n` );
	process.exitCode = 1;
} else {
	process.stdout.write( 'WooCommerce plugin lint checks passed.\n' );
}
