import { cp, mkdir, rm } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const dist = join( root, 'dist' );
const packageRoot = join( dist, 'fincobra-woocommerce' );
const files = [
	'assets',
	'fincobra-woocommerce.php',
	'includes',
	'LICENSE',
	'readme.txt',
	'uninstall.php',
];

await rm( dist, { force: true, recursive: true } );
await mkdir( packageRoot, { recursive: true } );
for ( const file of files ) {
	await cp( join( root, file ), join( packageRoot, file ), {
		recursive: true,
	} );
}

await new Promise( ( resolve, reject ) => {
	const child = spawn(
		'zip',
		[
			'-q',
			'-r',
			join( dist, 'fincobra-woocommerce.zip' ),
			'fincobra-woocommerce',
		],
		{ cwd: dist, stdio: 'inherit' },
	);
	child.on( 'error', reject );
	child.on( 'exit', ( code ) => {
		if ( code === 0 ) {
			resolve();
		} else {
			reject( new Error( `zip exited with code ${ code }` ) );
		}
	} );
} );

process.stdout.write( `${ join( dist, 'fincobra-woocommerce.zip' ) }\n` );
