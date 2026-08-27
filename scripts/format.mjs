import { readFile, readdir, writeFile } from 'node:fs/promises';
import { dirname, extname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const check = process.argv.includes( '--check' );
let changed = false;

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
	if ( ! [ '.js', '.json', '.md', '.mjs', '.php', '.txt', '.yml' ].includes( extname( path ) ) ) {
		continue;
	}
	const source = await readFile( path, 'utf8' );
	const formatted = `${ source.replace( /\r\n/g, '\n' ).replace( /[ \t]+$/gm, '' ).trimEnd() }\n`;
	if ( source !== formatted ) {
		changed = true;
		if ( ! check ) {
			await writeFile( path, formatted );
		}
	}
}

if ( check && changed ) {
	process.stderr.write( 'Formatting changes are required.\n' );
	process.exitCode = 1;
} else {
	process.stdout.write( check ? 'Formatting is clean.\n' : 'Formatting normalized.\n' );
}
