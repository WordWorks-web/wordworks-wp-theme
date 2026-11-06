/**
 * Side-by-side screenshots: reference (left) vs. candidate (right), per page.
 *
 *   node tools/compare.mjs --left https://wordworks.es --right https://lightblue-fox-316339.hostingersite.com \
 *        --out shots [--width 1440] [--pages home,contacto] [--motion]
 *
 *   --left/--right   base URLs (page paths come from deploy/manifest.json slugs),
 *                    or "design:<dir>" for the static design renders (tools/design_static.py).
 *   --motion         keep animations; scroll each page slowly first, and also save
 *                    a short filmstrip (top of the page after each scroll step).
 *
 * Writes <out>/<page>-<width>.png (left | right) and prints a pixel-diff score.
 */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';

const require = createRequire( import.meta.url );
const { chromium } = require( 'playwright' );
const ROOT = path.resolve( path.dirname( new URL( import.meta.url ).pathname ), '..' );
const manifest = JSON.parse( fs.readFileSync( path.join( ROOT, 'deploy/manifest.json' ), 'utf8' ) );
const DESIGN_FILE = { home: 'Main', 'nuestra-historia': 'NuestraHistoria', calidad: 'Calidad', equipo: 'Equipo', 'global-readiness': 'GlobalReadiness', 'ai-content-creation': 'AIContent', 'team-readiness': 'TeamReadiness', 'soluciones-linguisticas': 'SolucionesLinguisticas', blog: 'Blog', 'trabaja-con-nosotras': 'TrabajaConNosotras', contacto: 'Contacto', faq: 'FAQ', 'aviso-legal': 'AvisoLegal', 'politica-de-privacidad': 'Privacidad', 'politica-de-cookies': 'Cookies' };

const arg = ( k, d ) => {
	const i = process.argv.indexOf( '--' + k );
	return i > -1 ? process.argv[ i + 1 ] : d;
};
const left = arg( 'left' );
const right = arg( 'right' );
const out = path.resolve( arg( 'out', 'shots' ) );
const width = +arg( 'width', '1440' );
const motion = process.argv.includes( '--motion' );
const only = arg( 'pages' ) ? arg( 'pages' ).split( ',' ) : null;
fs.mkdirSync( out, { recursive: true } );

const urlFor = ( base, page ) => {
	if ( base.startsWith( 'design:' ) ) return 'file://' + path.resolve( base.slice( 7 ), DESIGN_FILE[ page.key ] + '.html' );
	return base.replace( /\/$/, '' ) + '/' + ( page.slug ? page.slug + '/' : '' );
};

const browser = await chromium.launch();
const ctx = await browser.newContext( {
	viewport: { width, height: width < 600 ? 844 : 900 },
	deviceScaleFactor: 1,
	reducedMotion: motion ? 'no-preference' : 'reduce',
	ignoreHTTPSErrors: false,
} );

async function shoot( url, file ) {
	const page = await ctx.newPage();
	await page.goto( url, { waitUntil: 'networkidle', timeout: 90000 } );
	await page.evaluate( () => document.fonts && document.fonts.ready );
	const frames = [];
	// Scroll slowly to the bottom so lazy images load and scroll animations run.
	const h = await page.evaluate( () => document.documentElement.scrollHeight );
	for ( let y = 0; y < h; y += 300 ) {
		await page.evaluate( ( yy ) => window.scrollTo( 0, yy ), y );
		await page.waitForTimeout( motion ? 350 : 60 );
		if ( motion && frames.length < 8 && y % 900 === 0 ) {
			const f = file.replace( /\.png$/, `-scroll${ frames.length }.png` );
			await page.screenshot( { path: f } );
			frames.push( f );
		}
	}
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.waitForTimeout( motion ? 1500 : 300 );
	if ( ! motion ) {
		await page.addStyleTag( { content: '*,*::before,*::after{animation:none!important;transition:none!important}.reveal{opacity:1!important;transform:none!important}' } );
	}
	await page.screenshot( { path: file, fullPage: true } );
	await page.close();
	return frames;
}

for ( const page of manifest.pages ) {
	if ( only && ! only.includes( page.key ) ) continue;
	const a = path.join( out, `${ page.key }-${ width }-L.png` );
	const b = path.join( out, `${ page.key }-${ width }-R.png` );
	try {
		await shoot( urlFor( left, page ), a );
		await shoot( urlFor( right, page ), b );
		const score = execFileSync( 'python3', [ path.join( ROOT, 'tools/sidebyside.py' ), a, b, path.join( out, `${ page.key }-${ width }.png` ) ] ).toString().trim();
		console.log( `${ page.key } @${ width }: ${ score }` );
	} catch ( e ) {
		console.log( `${ page.key } @${ width }: ERROR ${ e.message.split( '\n' )[ 0 ] }` );
	}
}
await browser.close();
